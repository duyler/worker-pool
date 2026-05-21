<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Master;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Server;
use Duyler\WorkerPool\Config\WorkerPoolConfig;
use Duyler\WorkerPool\Exception\WorkerPoolException;
use Duyler\WorkerPool\Process\ForkWrapperInterface;
use Duyler\WorkerPool\Process\ProcessInfo;
use Duyler\WorkerPool\Process\ProcessState;
use Duyler\WorkerPool\Socket\SocketWrapperInterface;
use Duyler\WorkerPool\Worker\EventDrivenWorkerInterface;
use Duyler\WorkerPool\Worker\WorkerCallbackInterface;
use Override;
use Psr\Log\LoggerInterface;
use Socket;

use function assert;
use function count;

use const AF_INET;
use const SOCK_STREAM;
use const SOL_SOCKET;
use const SOL_TCP;
use const SO_REUSEADDR;
use const SO_REUSEPORT;

/**
 * Shared Socket Master with kernel load balancing
 *
 * Architecture:
 * - Each worker has its own socket on same port (SO_REUSEPORT)
 * - Kernel automatically distributes connections
 * - No IPC overhead
 * - Simple and reliable
 *
 * Requirements:
 * - SO_REUSEPORT support (Linux, Docker, macOS via Docker)
 *
 * Use when:
 * - Want simple architecture
 * - Kernel load balancing is sufficient
 * - Maximum compatibility needed
 * - Running in Docker or need macOS support
 *
 * @see CentralizedMaster For centralized architecture with custom load balancing
 */
final class SharedSocketMaster extends AbstractMaster
{
    public function __construct(
        WorkerPoolConfig $config,
        private readonly ServerConfig $serverConfig,
        private readonly SocketWrapperInterface $socketWrapper,
        ForkWrapperInterface $forkWrapper,
        ?WorkerCallbackInterface $workerCallback = null,
        ?EventDrivenWorkerInterface $eventDrivenWorker = null,
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct($config, $forkWrapper, $logger, $workerCallback, $eventDrivenWorker);
    }

    #[Override]
    public function start(): void
    {
        $this->logger->info('Starting with SO_REUSEPORT architecture', [
            'workers' => $this->config->workerCount,
        ]);

        for ($i = 1; $i <= $this->config->workerCount; $i++) {
            $this->spawnWorker($i);
        }

        $this->run();
    }

    #[Override]
    public function stop(): void
    {
        parent::stop();
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function getMetrics(): array
    {
        $activeWorkers = 0;
        $totalConnections = 0;

        foreach ($this->getWorkers() as $worker) {
            if ($worker->isAlive()) {
                $activeWorkers++;
                $totalConnections += $worker->connections;
            }
        }

        return [
            'architecture' => 'shared_socket',
            'total_workers' => count($this->getWorkers()),
            'active_workers' => $activeWorkers,
            'total_connections' => $totalConnections,
            'is_running' => $this->isRunning(),
        ];
    }

    #[Override]
    protected function run(): void
    {
        $this->logger->info('Entering main loop');

        while (false === $this->shouldStop) {
            $this->signalHandler->dispatch();
            $this->checkWorkers();
            usleep($this->config->pollInterval);
        }

        $this->logger->info('Exiting main loop, waiting for workers');
        $this->waitForWorkers();
    }

    #[Override]
    protected function spawnWorker(int $workerId): void
    {
        $pid = $this->forkWrapper->fork();

        if (-1 === $pid) {
            throw new WorkerPoolException('Failed to fork worker process');
        }

        if (0 === $pid) {
            $this->logger->info('Worker process started', [
                'worker_id' => $workerId,
                'pid' => getmypid(),
            ]);

            if (null !== $this->eventDrivenWorker) {
                $this->runEventDrivenWorker($workerId);
            } elseif (null !== $this->workerCallback) {
                $this->runCallbackWorker($workerId);
            }

            $this->logger->info('Worker process exiting', ['worker_id' => $workerId]);
            exit(0);
        }

        $this->workerManager->updateWorker($workerId, new ProcessInfo(
            workerId: $workerId,
            pid: $pid,
            state: ProcessState::Ready,
            forkWrapper: $this->forkWrapper,
        ));

        $this->logger->info('Worker spawned', ['worker_id' => $workerId, 'pid' => $pid]);
    }

    private function runEventDrivenWorker(int $workerId): void
    {
        assert(null !== $this->eventDrivenWorker);

        $server = new Server($this->serverConfig);
        $server->setWorkerId($workerId);

        $socket = $this->createReusePortSocket($workerId);

        $server->setExternalSocketResource($socket);
        $this->logger->debug('External socket resource passed to Server', [
            'worker_id' => $workerId,
        ]);

        $server->enableNotification();
        $this->logger->debug('Notification enabled for worker', [
            'worker_id' => $workerId,
        ]);

        $this->logger->info('Starting event-driven worker', ['worker_id' => $workerId]);
        $this->eventDrivenWorker->run($workerId, $server);
    }

    private function createReusePortSocket(int $workerId): Socket
    {
        $socket = $this->socketWrapper->create(AF_INET, SOCK_STREAM, SOL_TCP);

        if (false === $socket) {
            throw new WorkerPoolException('Failed to create socket');
        }

        if (false === $this->socketWrapper->setOption($socket, SOL_SOCKET, SO_REUSEADDR, 1)) {
            $this->logger->error('Failed to set SO_REUSEADDR', ['worker_id' => $workerId]);
            throw new WorkerPoolException('Failed to set SO_REUSEADDR');
        }

        if (false === $this->socketWrapper->setOption($socket, SOL_SOCKET, SO_REUSEPORT, 1)) {
            $this->logger->error('Failed to set SO_REUSEPORT', ['worker_id' => $workerId]);
            throw new WorkerPoolException('Failed to set SO_REUSEPORT');
        }

        $host = $this->serverConfig->host;
        $port = $this->serverConfig->port;

        if (false === $this->socketWrapper->bind($socket, $host, $port)) {
            $error = $this->socketWrapper->strerror($this->socketWrapper->lastError($socket));
            $this->logger->error('Failed to bind socket', [
                'worker_id' => $workerId,
                'host' => $host,
                'port' => $port,
                'error' => $error,
            ]);
            throw new WorkerPoolException("Failed to bind socket: $error");
        }

        if (false === $this->socketWrapper->listen($socket, $this->serverConfig->socketBacklog)) {
            $this->logger->error('Failed to listen', [
                'worker_id' => $workerId,
                'error' => $this->socketWrapper->strerror($this->socketWrapper->lastError($socket)),
            ]);
            throw new WorkerPoolException('Failed to listen');
        }

        $this->socketWrapper->setNonBlock($socket);

        $this->logger->info('Worker socket ready', [
            'worker_id' => $workerId,
            'host' => $host,
            'port' => $port,
        ]);

        return $socket;
    }

    private function runCallbackWorker(int $workerId): void
    {
        assert(null !== $this->workerCallback);

        try {
            $socket = $this->createReusePortSocket($workerId);
        } catch (WorkerPoolException $e) {
            $this->logger->error($e->getMessage(), ['worker_id' => $workerId]);
            exit(1);
        }

        $this->logger->info('Worker listening', [
            'worker_id' => $workerId,
            'host' => $this->serverConfig->host,
            'port' => $this->serverConfig->port,
        ]);

        /** @phpstan-ignore-next-line */
        while (true) {
            $clientSocket = $this->socketWrapper->accept($socket);

            if (false !== $clientSocket) {
                $this->logger->debug('Worker accepted connection', ['worker_id' => $workerId]);

                $clientIp = '';
                $this->socketWrapper->getPeerName($clientSocket, $clientIp);

                $this->workerCallback->handle($clientSocket, [
                    'worker_id' => $workerId,
                    'client_ip' => $clientIp,
                ]);
            }

            usleep(1000);
        }
    }
}

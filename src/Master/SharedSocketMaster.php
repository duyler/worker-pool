<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Master;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Server;
use Duyler\WorkerPool\Config\WorkerPoolConfig;
use Duyler\WorkerPool\Exception\WorkerPoolException;
use Duyler\WorkerPool\Process\ProcessInfo;
use Duyler\WorkerPool\Process\ProcessState;
use Duyler\WorkerPool\Worker\EventDrivenWorkerInterface;
use Duyler\WorkerPool\Worker\WorkerCallbackInterface;
use InvalidArgumentException;
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
    private readonly WorkerManager $workerManager;

    public function __construct(
        WorkerPoolConfig $config,
        private readonly ServerConfig $serverConfig,
        private readonly ?WorkerCallbackInterface $workerCallback = null,
        private readonly ?EventDrivenWorkerInterface $eventDrivenWorker = null,
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct($config, $logger);

        if (null === $this->workerCallback && null === $this->eventDrivenWorker) {
            throw new InvalidArgumentException(
                'Either workerCallback or eventDrivenWorker must be provided',
            );
        }

        $this->workerManager = new WorkerManager($this->logger);
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
        $this->workerManager->stopAll();
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function getMetrics(): array
    {
        $activeWorkers = 0;
        $totalConnections = 0;

        foreach ($this->workers as $worker) {
            if ($worker->isAlive()) {
                $activeWorkers++;
                $totalConnections += $worker->connections;
            }
        }

        return [
            'architecture' => 'shared_socket',
            'total_workers' => count($this->workers),
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
        $pid = pcntl_fork();

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

        $this->workers[$workerId] = new ProcessInfo(
            workerId: $workerId,
            pid: $pid,
            state: ProcessState::Ready,
        );

        $this->logger->info('Worker spawned', ['worker_id' => $workerId, 'pid' => $pid]);
    }

    /**
     * Event-Driven Worker mode
     *
     * Runs a full application with its own event loop.
     * Master passes connections to Server, application polls hasRequest().
     */
    private function runEventDrivenWorker(int $workerId): void
    {
        assert(null !== $this->eventDrivenWorker);

        $server = new Server($this->serverConfig);
        $server->setWorkerId($workerId);

        $socket = $this->createSharedSocket($workerId);

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

    /**
     * Creates shared socket with SO_REUSEPORT
     */
    private function createSharedSocket(int $workerId): Socket
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        if (false === $socket) {
            throw new WorkerPoolException('Failed to create socket');
        }

        if (false === socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1)) {
            $this->logger->error('Failed to set SO_REUSEADDR', ['worker_id' => $workerId]);
            throw new WorkerPoolException('Failed to set SO_REUSEADDR');
        }

        if (false === socket_set_option($socket, SOL_SOCKET, SO_REUSEPORT, 1)) {
            $this->logger->error('Failed to set SO_REUSEPORT', ['worker_id' => $workerId]);
            throw new WorkerPoolException('Failed to set SO_REUSEPORT');
        }

        $host = $this->serverConfig->host;
        $port = $this->serverConfig->port;

        if (false === socket_bind($socket, $host, $port)) {
            $error = socket_strerror(socket_last_error($socket));
            $this->logger->error('Failed to bind socket', [
                'worker_id' => $workerId,
                'host' => $host,
                'port' => $port,
                'error' => $error,
            ]);
            throw new WorkerPoolException("Failed to bind socket: $error");
        }

        if (false === socket_listen($socket, $this->serverConfig->socketBacklog)) {
            $this->logger->error('Failed to listen', [
                'worker_id' => $workerId,
                'error' => socket_strerror(socket_last_error($socket)),
            ]);
            throw new WorkerPoolException('Failed to listen');
        }

        socket_set_nonblock($socket);

        $this->logger->info('Worker socket ready', [
            'worker_id' => $workerId,
            'host' => $host,
            'port' => $port,
        ]);

        return $socket;
    }

    /**
     * Callback Worker mode (legacy, for backward compatibility)
     *
     * Synchronous handling of each connection via callback.
     */
    private function runCallbackWorker(int $workerId): void
    {
        assert(null !== $this->workerCallback);

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        if (false === $socket) {
            $this->logger->error('Failed to create socket', ['worker_id' => $workerId]);
            exit(1);
        }

        if (false === socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1)) {
            $this->logger->error('Failed to set SO_REUSEADDR', ['worker_id' => $workerId]);
            exit(1);
        }

        if (false === socket_set_option($socket, SOL_SOCKET, SO_REUSEPORT, 1)) {
            $this->logger->error('Failed to set SO_REUSEPORT', ['worker_id' => $workerId]);
            exit(1);
        }

        $host = $this->serverConfig->host;
        $port = $this->serverConfig->port;

        if (false === socket_bind($socket, $host, $port)) {
            $error = socket_strerror(socket_last_error($socket));
            $this->logger->error('Failed to bind socket', [
                'worker_id' => $workerId,
                'host' => $host,
                'port' => $port,
                'error' => $error,
            ]);
            exit(1);
        }

        if (false === socket_listen($socket, $this->serverConfig->socketBacklog)) {
            $this->logger->error('Failed to listen', [
                'worker_id' => $workerId,
                'error' => socket_strerror(socket_last_error($socket)),
            ]);
            exit(1);
        }

        $this->logger->info('Worker listening', [
            'worker_id' => $workerId,
            'host' => $host,
            'port' => $port,
        ]);

        socket_set_nonblock($socket);

        /** @phpstan-ignore-next-line */
        while (true) {
            $clientSocket = socket_accept($socket);

            if (false !== $clientSocket) {
                $this->logger->debug('Worker accepted connection', ['worker_id' => $workerId]);

                $clientIp = '';
                socket_getpeername($clientSocket, $clientIp);

                $this->workerCallback->handle($clientSocket, [
                    'worker_id' => $workerId,
                    'client_ip' => $clientIp,
                ]);
            }

            usleep(1000);
        }
    }
}

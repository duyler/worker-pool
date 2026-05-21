<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Master;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Server;
use Duyler\WorkerPool\Balancer\BalancerInterface;
use Duyler\WorkerPool\Config\WorkerPoolConfig;
use Duyler\WorkerPool\Exception\WorkerPoolException;
use Duyler\WorkerPool\IPC\FdPasser;
use Duyler\WorkerPool\Process\ForkWrapperInterface;
use Duyler\WorkerPool\Process\ProcessInfo;
use Duyler\WorkerPool\Process\ProcessState;
use Duyler\WorkerPool\Socket\SocketMsgWrapperInterface;
use Duyler\WorkerPool\Socket\SocketWrapperInterface;
use Duyler\WorkerPool\Worker\EventDrivenWorkerInterface;
use Duyler\WorkerPool\Worker\WorkerCallbackInterface;
use Fiber;
use Override;
use Psr\Log\LoggerInterface;
use Socket;

use function assert;
use function count;
use function fclose;
use function pcntl_signal;
use function pcntl_signal_dispatch;

use const AF_UNIX;
use const SIGINT;
use const SIGTERM;
use const SOCKET_EINTR;
use const SOCK_STREAM;

final class CentralizedMaster extends AbstractMaster
{
    /** @var array<int, Socket> */
    private array $workerSockets = [];

    private ?SocketManager $socketManager = null;
    private ?ConnectionQueue $connectionQueue = null;
    private readonly FdPasser $fdPasser;
    private readonly ConnectionRouter $connectionRouter;

    public function __construct(
        WorkerPoolConfig $config,
        private readonly BalancerInterface $balancer,
        private readonly SocketWrapperInterface $socketWrapper,
        private readonly SocketMsgWrapperInterface $socketMsgWrapper,
        ForkWrapperInterface $forkWrapper,
        private readonly ?ServerConfig $serverConfig = null,
        ?WorkerCallbackInterface $workerCallback = null,
        ?EventDrivenWorkerInterface $eventDrivenWorker = null,
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct($config, $forkWrapper, $logger, $workerCallback, $eventDrivenWorker);

        $this->fdPasser = new FdPasser($this->socketWrapper, $this->socketMsgWrapper, $this->logger);
        $this->connectionRouter = new ConnectionRouter($this->socketWrapper, $this->balancer, $this->fdPasser, $this->logger);

        if (null !== $this->serverConfig) {
            $this->socketManager = new SocketManager($this->serverConfig, $this->socketWrapper, $this->logger);
            $this->connectionQueue = new ConnectionQueue(maxSize: 1000, socketWrapper: $this->socketWrapper);
        }
    }

    #[Override]
    public function start(): void
    {
        if (null !== $this->socketManager) {
            $this->logger->info('Starting socket manager');
            $this->socketManager->listen();
            $this->logger->info('Socket manager listening on port');
        } else {
            $this->logger->warning('No socket manager configured');
        }

        $this->logger->info('Spawning workers', ['count' => $this->config->workerCount]);
        for ($i = 1; $i <= $this->config->workerCount; $i++) {
            $this->spawnWorker($i);
        }

        $this->logger->info('Entering main loop');
        $this->run();
    }

    #[Override]
    public function stop(): void
    {
        parent::stop();
    }

    #[Override]
    public function getMetrics(): array
    {
        $aliveWorkers = 0;
        $totalConnections = 0;
        $totalRequests = 0;

        foreach ($this->getWorkers() as $worker) {
            if ($worker->isAlive()) {
                $aliveWorkers++;
                $totalConnections += $worker->connections;
                $totalRequests += $worker->totalRequests;
            }
        }

        return [
            'total_workers' => $this->config->workerCount,
            'alive_workers' => $aliveWorkers,
            'total_connections' => $totalConnections,
            'total_requests' => $totalRequests,
            'queue_size' => $this->connectionQueue?->size() ?? 0,
            'is_running' => $this->isRunning(),
        ];
    }

    public function getBalancer(): BalancerInterface
    {
        return $this->balancer;
    }

    #[Override]
    protected function run(): void
    {
        $this->installSigchldHandler();

        $iteration = 0;

        while (false === $this->shouldStop) {
            $this->signalHandler->dispatch();

            if (null !== $this->socketManager && null !== $this->connectionQueue) {
                $socket = $this->socketManager->getSocket();

                if (null !== $socket) {
                    $readSockets = [$socket];
                    $write = null;
                    $except = null;
                    $timeout = 0;
                    $microseconds = $this->config->pollInterval;

                    $changed = $this->socketWrapper->select($readSockets, $write, $except, $timeout, $microseconds);

                    if (false === $changed) {
                        $errorCode = $this->socketWrapper->lastError();
                        if ($errorCode !== SOCKET_EINTR) {
                            $this->logger->error('socket_select failed', [
                                'error' => $this->socketWrapper->strerror($errorCode),
                                'error_code' => $errorCode,
                            ]);
                        }
                    } elseif ($changed > 0) {
                        $this->acceptConnections();
                    }
                }

                $this->distributeConnections();
            } else {
                if (0 === $iteration) {
                    $this->logger->error('No socket manager in main loop');
                }
                usleep($this->config->pollInterval);
            }

            $this->checkWorkers();
            $this->processPendingRestarts();

            $iteration++;
            if ($iteration % 1000 === 0) {
                $this->logger->debug('Main loop iteration', [
                    'iteration' => $iteration,
                    'workers_alive' => count($this->getWorkers()),
                ]);
            }
        }

        $this->logger->info('Exiting main loop, waiting for workers');
        $this->waitForWorkers();
        $this->uninstallSigchldHandler();
    }

    #[Override]
    protected function checkWorkers(): void
    {
        $deadWorkerIds = $this->detectDeadWorkers();

        foreach ($deadWorkerIds as $workerId) {
            $this->balancer->onWorkerRemoved($workerId);
            unset($this->workerSockets[$workerId]);

            if ($this->config->autoRestart && false === $this->shouldStop) {
                $this->scheduleRestart($workerId);
            }
        }
    }

    #[Override]
    protected function spawnWorker(int $workerId): void
    {
        $sockets = [];
        $result = $this->socketWrapper->createPair(AF_UNIX, SOCK_STREAM, 0, $sockets);

        if (false === $result || 2 !== count($sockets)) {
            throw new WorkerPoolException("Failed to create socket pair for worker $workerId");
        }

        [$masterSocket, $workerSocket] = $sockets;

        $pid = $this->forkWrapper->fork();

        if (-1 === $pid) {
            throw new WorkerPoolException("Failed to fork worker $workerId");
        }

        if (0 === $pid) {
            $this->socketWrapper->close($masterSocket);

            if (null !== $this->socketManager) {
                $this->socketManager->detachFromWorker();
            }

            $this->logger->info('Worker process started', [
                'worker_id' => $workerId,
                'pid' => getmypid(),
            ]);

            if (null !== $this->eventDrivenWorker) {
                $this->runEventDrivenWorker($workerId, $workerSocket);
            } elseif (null !== $this->workerCallback) {
                $this->runCallbackWorker($workerId, $workerSocket);
            }

            $this->logger->info('Worker process exiting', ['worker_id' => $workerId]);
            exit(0);
        }

        $this->socketWrapper->close($workerSocket);

        $this->workerManager->updateWorker($workerId, new ProcessInfo(
            workerId: $workerId,
            pid: $pid,
            state: ProcessState::Ready,
            forkWrapper: $this->forkWrapper,
        ));

        $this->workerSockets[$workerId] = $masterSocket;
        $this->logger->info('Worker spawned', ['worker_id' => $workerId, 'pid' => $pid]);
    }

    private function acceptConnections(): void
    {
        /** @var int $callCount */
        static $callCount = 0;
        $callCount++;

        if ($callCount % 1000 === 0) {
            $this->logger->debug('Accept connections called', ['count' => $callCount]);
        }

        if (null === $this->socketManager || null === $this->connectionQueue) {
            if (1 === $callCount) {
                $this->logger->error('Socket manager or connection queue is null');
            }
            return;
        }

        $maxAccepts = $this->serverConfig?->maxAcceptsPerCycle ?? 10;
        for ($i = 0; $i < $maxAccepts; $i++) {
            $clientSocket = $this->socketManager->accept();

            if (null === $clientSocket) {
                break;
            }

            $this->logger->info('Accepted new connection');

            if ($this->connectionQueue->isFull()) {
                $this->logger->warning('Queue full, rejecting connection');
                $this->socketWrapper->close($clientSocket);
                break;
            }

            $this->connectionQueue->enqueue($clientSocket);
            $this->logger->debug('Connection queued', ['queue_size' => $this->connectionQueue->size()]);
        }
    }

    private function distributeConnections(): void
    {
        if (null === $this->connectionQueue) {
            return;
        }

        while (false === $this->connectionQueue->isEmpty()) {
            $clientSocket = $this->connectionQueue->dequeue();

            if (null === $clientSocket) {
                break;
            }

            $this->connectionRouter->route(
                clientSocket: $clientSocket,
                workers: $this->getWorkers(),
                workerSockets: $this->workerSockets,
            );
        }
    }

    private function runEventDrivenWorker(int $workerId, Socket $workerSocket): void
    {
        assert(null !== $this->eventDrivenWorker);

        $server = new Server($this->serverConfig ?? new ServerConfig());
        $server->setWorkerId($workerId);
        $server->setExternalSocketResource($workerSocket);

        $this->logger->debug('External socket resource set for worker', [
            'worker_id' => $workerId,
            'mode' => 'centralized',
            'note' => 'Unix socket for IPC - EvTimer fallback recommended',
        ]);

        $server->enableNotification();
        $this->logger->debug('Notification enabled for worker', [
            'worker_id' => $workerId,
            'mode' => 'centralized',
        ]);

        $fiber = new Fiber(function () use ($workerSocket, $server, $workerId): void {
            while (true) {
                $result = $this->fdPasser->receiveFd($workerSocket);

                if (null !== $result) {
                    $this->logger->debug('Worker received FD from master', [
                        'worker_id' => $workerId,
                    ]);

                    $clientSocket = $result['fd'];
                    /** @var array{client_ip?: string, worker_id: int, worker_pid?: int} $metadata */
                    $metadata = $result['metadata'];

                    if ($clientSocket instanceof Socket) {
                        $this->socketWrapper->setNonBlock($clientSocket);
                    } else {
                        stream_set_blocking($clientSocket, false);
                    }

                    $server->addExternalConnection($clientSocket, $metadata);
                }

                Fiber::suspend();
            }
        });

        $fiber->start();
        $server->registerFiber($fiber);

        $this->logger->info('Starting event-driven worker', ['worker_id' => $workerId]);
        $this->eventDrivenWorker->run($workerId, $server);
    }

    private function runCallbackWorker(int $workerId, Socket $workerSocket): void
    {
        $workerShouldStop = false;

        pcntl_signal(SIGTERM, function () use (&$workerShouldStop): void {
            $workerShouldStop = true;
        });

        pcntl_signal(SIGINT, function () use (&$workerShouldStop): void {
            $workerShouldStop = true;
        });

        $this->logger->info('Worker entering receive loop', ['worker_id' => $workerId]);

        while (false === $workerShouldStop && false === $this->shouldStop) {
            pcntl_signal_dispatch();

            $result = $this->fdPasser->receiveFd($workerSocket);

            if (null === $result) {
                usleep(1000);
                continue;
            }

            $this->logger->debug('Worker received FD from master', ['worker_id' => $workerId]);

            $clientSocket = $result['fd'];
            $metadata = $result['metadata'];

            if (null !== $this->workerCallback) {
                $this->workerCallback->handle($clientSocket, $metadata);
            } else {
                $this->logger->warning('Worker has no callback, closing socket', ['worker_id' => $workerId]);
                if ($clientSocket instanceof Socket) {
                    $this->socketWrapper->close($clientSocket);
                } else {
                    fclose($clientSocket);
                }
            }
        }
    }
}

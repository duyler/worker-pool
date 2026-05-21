<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Master;

use Duyler\WorkerPool\Config\WorkerPoolConfig;
use Duyler\WorkerPool\Process\ForkWrapperInterface;
use Duyler\WorkerPool\Process\ProcessInfo;
use Duyler\WorkerPool\Signal\SignalHandler;
use Duyler\WorkerPool\Worker\EventDrivenWorkerInterface;
use Duyler\WorkerPool\Worker\WorkerCallbackInterface;
use InvalidArgumentException;
use Override;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function count;
use function in_array;
use function pcntl_waitpid;

use const SIGCHLD;
use const SIGINT;
use const SIGTERM;
use const WNOHANG;

abstract class AbstractMaster implements MasterInterface
{
    protected bool $shouldStop = false;
    protected SignalHandler $signalHandler;
    protected LoggerInterface $logger;
    protected readonly WorkerManager $workerManager;

    /** @var array<int, float> */
    private array $pendingRestarts = [];

    public function __construct(
        protected readonly WorkerPoolConfig $config,
        protected readonly ForkWrapperInterface $forkWrapper,
        ?LoggerInterface $logger = null,
        protected readonly ?WorkerCallbackInterface $workerCallback = null,
        protected readonly ?EventDrivenWorkerInterface $eventDrivenWorker = null,
    ) {
        if (null === $this->workerCallback && null === $this->eventDrivenWorker) {
            throw new InvalidArgumentException(
                'Either workerCallback or eventDrivenWorker must be provided',
            );
        }

        $this->logger = $logger ?? new NullLogger();
        $this->signalHandler = new SignalHandler();
        $this->workerManager = new WorkerManager($this->forkWrapper, $this->logger);
        $this->setupSignals();
    }

    #[Override]
    public function stop(): void
    {
        $this->shouldStop = true;
        $this->workerManager->stopAll();
    }

    /**
     * @return array<int, ProcessInfo>
     */
    public function getWorkers(): array
    {
        return $this->workerManager->getWorkers();
    }

    public function getWorkerCount(): int
    {
        return count($this->workerManager->getWorkers());
    }

    #[Override]
    public function isRunning(): bool
    {
        return false === $this->shouldStop;
    }

    abstract protected function run(): void;

    abstract protected function spawnWorker(int $workerId): void;

    protected function checkWorkers(): void
    {
        $deadWorkerIds = $this->detectDeadWorkers();

        foreach ($deadWorkerIds as $workerId) {
            if ($this->config->autoRestart && false === $this->shouldStop) {
                $this->scheduleRestart($workerId);
            }
        }
    }

    protected function processPendingRestarts(): void
    {
        $now = microtime(true);

        foreach ($this->pendingRestarts as $workerId => $restartAt) {
            if ($now >= $restartAt) {
                unset($this->pendingRestarts[$workerId]);

                if (false === $this->shouldStop) {
                    $this->logger->info('Respawning worker', ['worker_id' => $workerId]);
                    $this->spawnWorker($workerId);
                }
            }
        }
    }

    protected function waitForWorkers(): void
    {
        $this->workerManager->waitAll();
    }

    protected function setupSignals(): void
    {
        $this->signalHandler->register(SIGTERM, function (): void {
            $this->logger->info('Received SIGTERM');
            $this->stop();
        });

        $this->signalHandler->register(SIGINT, function (): void {
            $this->logger->info('Received SIGINT');
            $this->stop();
        });
    }

    protected function installSigchldHandler(): void
    {
        $this->signalHandler->register(SIGCHLD, function (): void {
            $status = 0;
            while (pcntl_waitpid(-1, $status, WNOHANG) > 0) {
            }
        });
    }

    protected function uninstallSigchldHandler(): void
    {
        $this->signalHandler->unregister(SIGCHLD);
    }

    /**
     * @return array<int>
     */
    protected function detectDeadWorkers(): array
    {
        $deadWorkerIds = $this->workerManager->check();

        foreach ($this->workerManager->getWorkers() as $workerId => $worker) {
            if (false === $worker->isAlive() && false === in_array($workerId, $deadWorkerIds, true)) {
                $this->workerManager->removeWorker($workerId);
                $deadWorkerIds[] = $workerId;
            }
        }

        return $deadWorkerIds;
    }

    protected function scheduleRestart(int $workerId): void
    {
        if (0 === $this->config->restartDelay) {
            $this->logger->info('Respawning worker', ['worker_id' => $workerId]);
            $this->spawnWorker($workerId);
        } else {
            $this->logger->info('Scheduling worker restart', ['worker_id' => $workerId]);
            $this->pendingRestarts[$workerId] = microtime(true) + (float) $this->config->restartDelay;
        }
    }
}

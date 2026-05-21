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

use const SIGINT;
use const SIGTERM;

abstract class AbstractMaster implements MasterInterface
{
    protected bool $shouldStop = false;
    protected SignalHandler $signalHandler;
    protected LoggerInterface $logger;
    protected readonly WorkerManager $workerManager;

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
        $deadWorkerIds = $this->workerManager->check();

        foreach ($deadWorkerIds as $workerId) {
            if ($this->config->autoRestart && false === $this->shouldStop) {
                $this->logger->info('Respawning worker', ['worker_id' => $workerId]);
                sleep($this->config->restartDelay);
                $this->spawnWorker($workerId);
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
}

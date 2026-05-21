<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Master;

use Duyler\WorkerPool\Config\WorkerPoolConfig;
use Duyler\WorkerPool\Process\ForkWrapperInterface;
use Duyler\WorkerPool\Process\ProcessInfo;
use Duyler\WorkerPool\Signal\SignalHandler;
use Override;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function count;

use const SIGINT;
use const SIGTERM;
use const WNOHANG;

abstract class AbstractMaster implements MasterInterface
{
    protected bool $shouldStop = false;

    /**
     * @var array<int, ProcessInfo>
     */
    protected array $workers = [];

    protected SignalHandler $signalHandler;
    protected LoggerInterface $logger;

    public function __construct(
        protected readonly WorkerPoolConfig $config,
        protected readonly ForkWrapperInterface $forkWrapper,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->signalHandler = new SignalHandler();
        $this->setupSignals();
    }

    #[Override]
    public function stop(): void
    {
        $this->shouldStop = true;

        foreach ($this->workers as $worker) {
            if ($worker->pid > 0) {
                $this->forkWrapper->kill($worker->pid, SIGTERM);
            }
        }
    }

    /**
     * @return array<int, ProcessInfo>
     */
    public function getWorkers(): array
    {
        return $this->workers;
    }

    public function getWorkerCount(): int
    {
        return count($this->workers);
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
        $status = 0;
        foreach ($this->workers as $workerId => $worker) {
            $result = $this->forkWrapper->waitpid($worker->pid, $status, WNOHANG);

            if ($result === $worker->pid) {
                $this->logger->warning('Worker died', [
                    'worker_id' => $workerId,
                    'pid' => $worker->pid,
                ]);

                unset($this->workers[$workerId]);

                if ($this->config->autoRestart && false === $this->shouldStop) {
                    $this->logger->info('Respawning worker', ['worker_id' => $workerId]);
                    sleep($this->config->restartDelay);
                    $this->spawnWorker($workerId);
                }
            }
        }
    }

    protected function waitForWorkers(): void
    {
        $status = 0;
        foreach ($this->workers as $worker) {
            $this->forkWrapper->waitpid($worker->pid, $status);
        }
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

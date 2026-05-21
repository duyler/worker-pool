<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Master;

use Duyler\WorkerPool\Exception\WorkerPoolException;
use Duyler\WorkerPool\Process\ForkWrapperInterface;
use Duyler\WorkerPool\Process\ProcessInfo;
use Duyler\WorkerPool\Process\ProcessState;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use const SIGTERM;
use const WNOHANG;

final class WorkerManager
{
    /**
     * @var array<int, ProcessInfo>
     */
    private array $workers = [];

    public function __construct(
        private readonly ForkWrapperInterface $forkWrapper,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function spawn(int $workerId, callable $workerProcess): ProcessInfo
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
            $workerProcess($workerId);
            exit(0);
        }

        $processInfo = new ProcessInfo(
            workerId: $workerId,
            pid: $pid,
            state: ProcessState::Ready,
            forkWrapper: $this->forkWrapper,
        );

        $this->workers[$workerId] = $processInfo;

        $this->logger->info('Worker spawned', [
            'worker_id' => $workerId,
            'pid' => $pid,
        ]);

        return $processInfo;
    }

    /**
     * @return array<int, ProcessInfo>
     */
    public function getWorkers(): array
    {
        return $this->workers;
    }

    public function getWorker(int $workerId): ?ProcessInfo
    {
        return $this->workers[$workerId] ?? null;
    }

    public function removeWorker(int $workerId): void
    {
        unset($this->workers[$workerId]);
    }

    public function updateWorker(int $workerId, ProcessInfo $processInfo): void
    {
        $this->workers[$workerId] = $processInfo;
    }

    public function countAlive(): int
    {
        $count = 0;

        foreach ($this->workers as $worker) {
            if ($worker->isAlive()) {
                $count++;
            }
        }

        return $count;
    }

    public function check(bool $shouldRestart = true): void
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
            }
        }
    }

    public function stopAll(): void
    {
        foreach ($this->workers as $worker) {
            if ($worker->pid > 0) {
                $this->forkWrapper->kill($worker->pid, SIGTERM);
            }
        }
    }

    public function waitAll(): void
    {
        $status = 0;
        foreach ($this->workers as $worker) {
            $this->forkWrapper->waitpid($worker->pid, $status);
        }
    }
}

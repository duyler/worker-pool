<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Process;

final readonly class ProcessInfo
{
    public float $startedAt;
    public float $lastActivityAt;

    public function __construct(
        public int $workerId,
        public int $pid,
        public ProcessState $state,
        private ForkWrapperInterface $forkWrapper,
        public int $connections = 0,
        public int $totalRequests = 0,
        ?float $startedAt = null,
        ?float $lastActivityAt = null,
        public int $memoryUsage = 0,
    ) {
        $now = microtime(true);
        $this->startedAt = $startedAt ?? $now;
        $this->lastActivityAt = $lastActivityAt ?? $this->startedAt;
    }

    public function withState(ProcessState $state): self
    {
        return new self(
            workerId: $this->workerId,
            pid: $this->pid,
            state: $state,
            forkWrapper: $this->forkWrapper,
            connections: $this->connections,
            totalRequests: $this->totalRequests,
            startedAt: $this->startedAt,
            lastActivityAt: $this->lastActivityAt,
            memoryUsage: $this->memoryUsage,
        );
    }

    public function withConnections(int $connections): self
    {
        return new self(
            workerId: $this->workerId,
            pid: $this->pid,
            state: $this->state,
            forkWrapper: $this->forkWrapper,
            connections: $connections,
            totalRequests: $this->totalRequests,
            startedAt: $this->startedAt,
            lastActivityAt: microtime(true),
            memoryUsage: $this->memoryUsage,
        );
    }

    public function withIncrementedRequests(): self
    {
        return new self(
            workerId: $this->workerId,
            pid: $this->pid,
            state: $this->state,
            forkWrapper: $this->forkWrapper,
            connections: $this->connections,
            totalRequests: $this->totalRequests + 1,
            startedAt: $this->startedAt,
            lastActivityAt: microtime(true),
            memoryUsage: $this->memoryUsage,
        );
    }

    public function withMemoryUsage(int $memoryUsage): self
    {
        return new self(
            workerId: $this->workerId,
            pid: $this->pid,
            state: $this->state,
            forkWrapper: $this->forkWrapper,
            connections: $this->connections,
            totalRequests: $this->totalRequests,
            startedAt: $this->startedAt,
            lastActivityAt: microtime(true),
            memoryUsage: $memoryUsage,
        );
    }

    public function getUptime(): float
    {
        return microtime(true) - $this->startedAt;
    }

    public function getIdleTime(): float
    {
        return microtime(true) - $this->lastActivityAt;
    }

    public function isAlive(): bool
    {
        if (0 >= $this->pid) {
            return false;
        }

        return $this->forkWrapper->kill($this->pid, 0);
    }

    public function toArray(): array
    {
        return [
            'worker_id' => $this->workerId,
            'pid' => $this->pid,
            'state' => $this->state->value,
            'connections' => $this->connections,
            'total_requests' => $this->totalRequests,
            'started_at' => $this->startedAt,
            'last_activity_at' => $this->lastActivityAt,
            'memory_usage' => $this->memoryUsage,
            'uptime' => $this->getUptime(),
            'idle_time' => $this->getIdleTime(),
            'is_alive' => $this->isAlive(),
        ];
    }
}

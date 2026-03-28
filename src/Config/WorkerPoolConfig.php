<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Config;

use Duyler\WorkerPool\Util\SystemInfo;
use InvalidArgumentException;

final readonly class WorkerPoolConfig
{
    public int $workerCount;

    public function __construct(
        int $workerCount = 0,
        public BalancerType $balancer = BalancerType::LeastConnections,
        public int $backlog = 128,
        public int $maxQueueSize = 1000,
        public int $maxIpcMessageSize = 1048576,
        public bool $enableStickySession = false,
        public bool $enableGracefulReload = false,
        public bool $autoRestart = true,
        public int $restartDelay = 1,
        public int $fallbackCpuCores = 4,
        public int $pollInterval = 1000,
        public ?string $socketPath = null,
        public ?int $port = null,
        public ?string $host = null,
    ) {
        if (0 === $workerCount) {
            $systemInfo = new SystemInfo();
            $this->workerCount = $systemInfo->getCpuCores($this->fallbackCpuCores);
        } else {
            $this->workerCount = $workerCount;
        }

        $this->validate();
    }

    public static function auto(
        BalancerType $balancer = BalancerType::LeastConnections,
    ): self {
        return new self(
            workerCount: 0,
            balancer: $balancer,
        );
    }

    private function validate(): void
    {
        if ($this->workerCount < 1) {
            throw new InvalidArgumentException(
                "Worker count must be positive, got: {$this->workerCount}",
            );
        }

        if ($this->workerCount > 1024) {
            throw new InvalidArgumentException(
                "Worker count too large (max 1024), got: {$this->workerCount}",
            );
        }

        if ($this->backlog < 1) {
            throw new InvalidArgumentException('Backlog must be positive');
        }

        if ($this->maxQueueSize < 1) {
            throw new InvalidArgumentException('Max queue size must be positive');
        }

        if ($this->restartDelay < 0) {
            throw new InvalidArgumentException('Restart delay must be non-negative');
        }

        if ($this->maxIpcMessageSize < 1024) {
            throw new InvalidArgumentException('Max IPC message size must be at least 1024 bytes');
        }

        if ($this->fallbackCpuCores < 1) {
            throw new InvalidArgumentException('Fallback CPU cores must be positive');
        }

        if ($this->pollInterval < 100) {
            throw new InvalidArgumentException('Poll interval must be at least 100 microseconds');
        }
    }
}

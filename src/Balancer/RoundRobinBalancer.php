<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Balancer;

use Override;

use function count;

final class RoundRobinBalancer implements BalancerInterface
{
    private int $currentIndex = 0;

    /**
     * @var array<int>
     */
    private array $workerIds = [];

    #[Override]
    public function selectWorker(array $connections): ?int
    {
        if ($connections === []) {
            return null;
        }

        $this->workerIds = array_keys($connections);

        if ($this->currentIndex >= count($this->workerIds)) {
            $this->currentIndex = 0;
        }

        $workerId = $this->workerIds[$this->currentIndex];
        $this->currentIndex++;

        return $workerId;
    }

    #[Override]
    public function onConnectionEstablished(int $workerId): void {}

    #[Override]
    public function onConnectionClosed(int $workerId): void {}

    #[Override]
    public function reset(): void
    {
        $this->currentIndex = 0;
        $this->workerIds = [];
    }

    #[Override]
    public function onWorkerRemoved(int $workerId): void
    {
        $index = array_search($workerId, $this->workerIds, true);

        if (false !== $index && $index < $this->currentIndex) {
            $this->currentIndex--;
        }
    }

    public function getCurrentIndex(): int
    {
        return $this->currentIndex;
    }
}

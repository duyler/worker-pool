<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Balancer;

use Override;

final class LeastConnectionsBalancer implements BalancerInterface
{
    /** @var array<int, int> */
    private array $connections = [];

    #[Override]
    public function selectWorker(array $connections): ?int
    {
        if ($connections === []) {
            return null;
        }

        $this->connections = $connections;

        $minConnections = min($connections);
        $workersWithMinConnections = array_keys($connections, $minConnections, true);

        return $workersWithMinConnections[array_rand($workersWithMinConnections)];
    }

    #[Override]
    public function onConnectionEstablished(int $workerId): void
    {
        if (!isset($this->connections[$workerId])) {
            $this->connections[$workerId] = 0;
        }

        $this->connections[$workerId]++;
    }

    #[Override]
    public function onConnectionClosed(int $workerId): void
    {
        if (!isset($this->connections[$workerId])) {
            return;
        }

        $this->connections[$workerId]--;

        if ($this->connections[$workerId] < 0) {
            $this->connections[$workerId] = 0;
        }
    }

    #[Override]
    public function reset(): void
    {
        $this->connections = [];
    }

    #[Override]
    public function onWorkerRemoved(int $workerId): void
    {
        unset($this->connections[$workerId]);
    }

    public function getConnections(): array
    {
        return $this->connections;
    }
}

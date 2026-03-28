<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Balancer;

interface BalancerInterface
{
    /**
     * Select next available worker ID based on balancing algorithm
     *
     * @param array<int, int> $connections Map of worker_id => active_connections_count
     * @return int|null Worker ID or null if no workers available
     */
    public function selectWorker(array $connections): ?int;

    /**
     * Notify balancer that connection was established with worker
     */
    public function onConnectionEstablished(int $workerId): void;

    /**
     * Notify balancer that connection was closed on worker
     */
    public function onConnectionClosed(int $workerId): void;

    /**
     * Notify balancer that worker was removed
     */
    public function onWorkerRemoved(int $workerId): void;

    /**
     * Reset balancer state
     */
    public function reset(): void;
}

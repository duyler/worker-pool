<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Master;

interface MasterInterface
{
    public function start(): void;

    public function stop(): void;

    public function isRunning(): bool;

    /**
     * @return array<string, mixed>
     */
    public function getMetrics(): array;
}

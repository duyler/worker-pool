<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Worker;

interface WorkerCallbackInterface
{
    /**
     * @param resource|object $clientSocket Client socket (Socket object or stream resource)
     * @param array<string, mixed> $metadata
     */
    public function handle(mixed $clientSocket, array $metadata): void;
}

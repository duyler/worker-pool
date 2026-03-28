<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Contract;

/**
 * Worker process context.
 *
 * Provides worker process access to shared resources and functions.
 * Concrete implementation depends on worker type (HTTP, WebSocket, Queue, etc.).
 *
 * @template-covariant T of array<string, mixed>
 *
 * @example
 * ```php
 * class HttpWorkerContext implements WorkerContextInterface {
 *     public function getWorkerId(): int { return $this->workerId; }
 *     public function getWorkerPid(): ?int { return posix_getpid(); }
 *     public function getMetadata(): array { return ['type' => 'http']; }
 *     public function isActive(): bool { return $this->active; }
 *     public function getSocketResource(): mixed { return $this->socket; }
 *     public function notifyEventLoop(): void { $this->eventLoop->notify(); }
 * }
 * ```
 */
interface WorkerContextInterface
{
    /**
     * Returns worker process identifier.
     */
    public function getWorkerId(): int;

    /**
     * Returns worker process PID.
     */
    public function getWorkerPid(): ?int;

    /**
     * Returns worker process metadata.
     *
     * @return T
     */
    public function getMetadata(): array;

    /**
     * Checks if worker is active.
     */
    public function isActive(): bool;

    /**
     * Returns socket resource for Event Loop monitoring.
     *
     * @return resource|object|null
     */
    public function getSocketResource(): mixed;

    /**
     * Notifies Event Loop about pending work.
     */
    public function notifyEventLoop(): void;
}

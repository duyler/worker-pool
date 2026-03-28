<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Contract;

use Socket;

/**
 * Factory for creating worker process contexts.
 *
 * Allows HTTP package or other implementations to create specific contexts.
 *
 * @example
 * ```php
 * class HttpWorkerFactory implements WorkerFactoryInterface {
 *     public function createWorkerContext(int $workerId, ?Socket $socket = null): WorkerContextInterface {
 *         return new HttpWorkerContext($workerId, $socket, $this->server);
 *     }
 * }
 * ```
 */
interface WorkerFactoryInterface
{
    /**
     * Creates context for worker process.
     *
     * @param int $workerId Worker identifier
     * @param Socket|null $socket Socket for worker (optional)
     */
    public function createWorkerContext(int $workerId, ?Socket $socket = null): WorkerContextInterface;
}

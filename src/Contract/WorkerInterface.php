<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Contract;

/**
 * Event-driven worker process interface.
 *
 * Worker runs once at process start and works infinitely,
 * handling events through its own Event Loop.
 *
 * @example
 * ```php
 * class HttpWorker implements WorkerInterface {
 *     public function run(int $workerId, WorkerContextInterface $context): void {
 *         $eventLoop = new EventLoop();
 *         $eventLoop->addSocket($context->getSocketResource());
 *         while ($context->isActive()) {
 *             $eventLoop->run();
 *         }
 *     }
 * }
 * ```
 */
interface WorkerInterface
{
    /**
     * Starts worker process.
     *
     * Called ONCE at worker start and never returns.
     * Application should start Event Loop inside this method.
     *
     * @param int $workerId Worker identifier (1, 2, 3, ..., N)
     * @param WorkerContextInterface $context Worker context for interaction
     */
    public function run(int $workerId, WorkerContextInterface $context): void;
}

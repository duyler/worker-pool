<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Process;

interface ForkWrapperInterface
{
    /**
     * Fork the currently running process
     */
    public function fork(): int;

    /**
     * Wait on a child process and return its status
     */
    public function waitpid(int $pid, int &$status, int $options = 0): int;

    /**
     * Send a signal to a process
     */
    public function kill(int $pid, int $signal): bool;
}

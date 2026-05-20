<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Process;

use Override;

use function pcntl_fork;
use function pcntl_waitpid;
use function posix_kill;

final class ForkWrapper implements ForkWrapperInterface
{
    #[Override]
    public function fork(): int
    {
        return pcntl_fork();
    }

    #[Override]
    public function waitpid(int $pid, int &$status, int $options = 0): int
    {
        return pcntl_waitpid($pid, $status, $options);
    }

    #[Override]
    public function kill(int $pid, int $signal): bool
    {
        return posix_kill($pid, $signal);
    }
}

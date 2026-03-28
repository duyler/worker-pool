<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Exception;

final class WorkerPoolException extends WorkerPoolExceptionBase
{
    protected string $errorCode = 'WORKER_POOL_ERROR';
}

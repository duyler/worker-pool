<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Exception;

final class IPCException extends WorkerPoolExceptionBase
{
    protected string $errorCode = 'IPC_ERROR';
}

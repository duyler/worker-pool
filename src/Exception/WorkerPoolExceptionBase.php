<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Exception;

use Exception;
use Throwable;

abstract class WorkerPoolExceptionBase extends Exception
{
    protected string $errorCode = 'UNKNOWN_ERROR';

    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        private readonly array $context = [],
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getContext(): array
    {
        return $this->context;
    }
}

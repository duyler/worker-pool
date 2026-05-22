<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use Duyler\WorkerPool\Exception\WorkerPoolExceptionBase;

use Duyler\WorkerPool\Exception\IPCException;
use Duyler\WorkerPool\Exception\WorkerPoolException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(WorkerPoolException::class)]
#[CoversClass(IPCException::class)]
#[CoversClass(WorkerPoolExceptionBase::class)]
final class ExceptionCoverageTest extends TestCase
{
    #[Test]
    public function workerPoolExceptionReturnsErrorCode(): void
    {
        $exception = new WorkerPoolException('test message');
        $this->assertSame('WORKER_POOL_ERROR', $exception->getErrorCode());
        $this->assertSame('test message', $exception->getMessage());
    }

    #[Test]
    public function workerPoolExceptionReturnsContext(): void
    {
        $context = ['worker_id' => 1, 'reason' => 'crash'];
        $exception = new WorkerPoolException('Worker failed', 0, null, $context);
        $this->assertSame($context, $exception->getContext());
    }

    #[Test]
    public function workerPoolExceptionEmptyContext(): void
    {
        $exception = new WorkerPoolException('msg');
        $this->assertSame([], $exception->getContext());
    }

    #[Test]
    public function ipcExceptionReturnsErrorCode(): void
    {
        $exception = new IPCException('IPC failure');
        $this->assertSame('IPC_ERROR', $exception->getErrorCode());
        $this->assertSame('IPC failure', $exception->getMessage());
    }

    #[Test]
    public function ipcExceptionReturnsContext(): void
    {
        $context = ['channel' => 'unix'];
        $exception = new IPCException('fail', 42, null, $context);
        $this->assertSame($context, $exception->getContext());
        $this->assertSame(42, $exception->getCode());
    }

    #[Test]
    public function workerPoolExceptionWithPrevious(): void
    {
        $previous = new RuntimeException('original');
        $exception = new WorkerPoolException('wrapped', 0, $previous);
        $this->assertSame($previous, $exception->getPrevious());
    }

    #[Test]
    public function ipcExceptionWithPrevious(): void
    {
        $previous = new RuntimeException('original');
        $exception = new IPCException('wrapped', 1, $previous, ['key' => 'val']);
        $this->assertSame($previous, $exception->getPrevious());
        $this->assertSame(1, $exception->getCode());
        $this->assertSame(['key' => 'val'], $exception->getContext());
    }
}

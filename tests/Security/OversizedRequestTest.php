<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Security;

use Duyler\WorkerPool\Exception\WorkerPoolException;
use Duyler\WorkerPool\Socket\SocketWrapperInterface;
use Duyler\WorkerPool\Worker\HttpWorkerAdapter;
use Override;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Socket;

use function str_repeat;
use function strlen;

use const AF_INET;
use const SOCK_STREAM;
use const SOL_TCP;

#[Group('security')]
#[CoversClass(HttpWorkerAdapter::class)]
#[UsesClass(WorkerPoolException::class)]
#[AllowMockObjectsWithoutExpectations]
final class OversizedRequestTest extends TestCase
{
    private SocketWrapperInterface&MockObject $socketWrapper;
    private HttpWorkerAdapter $adapter;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->socketWrapper = $this->createMock(SocketWrapperInterface::class);
        $this->adapter = new HttpWorkerAdapter($this->socketWrapper);
    }

    #[Test]
    public function handles_request_body_exceeding_max_size(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        $headers = "POST /upload HTTP/1.1\r\nHost: localhost\r\nContent-Length: 20000000\r\n\r\n";
        $chunkSize = 2097152;
        $readCount = 0;

        $this->socketWrapper->method('read')->willReturnCallback(function () use ($headers, $chunkSize, &$readCount): string|false {
            ++$readCount;
            if (1 === $readCount) {
                return $headers . str_repeat('X', $chunkSize);
            }
            if ($readCount <= 6) {
                return str_repeat('X', $chunkSize);
            }
            return false;
        });

        $writtenData = '';
        $this->socketWrapper->method('write')->willReturnCallback(function (Socket $s, string $data) use (&$writtenData): int {
            $writtenData .= $data;
            return strlen($data);
        });

        $this->socketWrapper->method('setOption')->willReturn(true);
        $this->adapter->handleConnection($socket);

        $this->assertStringContainsString('500', $writtenData);
    }

    #[Test]
    public function socket_closed_after_oversized_request(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        $headers = "POST /upload HTTP/1.1\r\nHost: localhost\r\nContent-Length: 20000000\r\n\r\n";
        $chunkSize = 2097152;
        $readCount = 0;

        $this->socketWrapper->method('read')->willReturnCallback(function () use ($headers, $chunkSize, &$readCount): string|false {
            ++$readCount;
            if (1 === $readCount) {
                return $headers . str_repeat('X', $chunkSize);
            }
            if ($readCount <= 6) {
                return str_repeat('X', $chunkSize);
            }
            return false;
        });

        $this->socketWrapper->method('write')->willReturn(0);
        $this->socketWrapper->method('setOption')->willReturn(true);
        $this->socketWrapper->expects($this->once())->method('close')->with($socket);

        $this->adapter->handleConnection($socket);
    }

    #[Test]
    public function adapter_handles_headers_without_body_gracefully(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        $readCount = 0;
        $this->socketWrapper->method('read')->willReturnCallback(function () use (&$readCount): string|false {
            ++$readCount;
            if (1 === $readCount) {
                return str_repeat('A', 16385);
            }
            return false;
        });

        $writtenData = '';
        $this->socketWrapper->method('write')->willReturnCallback(function (Socket $s, string $data) use (&$writtenData): int {
            $writtenData .= $data;
            return strlen($data);
        });

        $this->socketWrapper->method('setOption')->willReturn(true);
        $this->adapter->handleConnection($socket);

        $this->assertStringContainsString('400', $writtenData);
    }
}

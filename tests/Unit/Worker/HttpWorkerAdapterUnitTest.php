<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\Worker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

use Duyler\WorkerPool\Exception\WorkerPoolException;
use Duyler\WorkerPool\Socket\SocketWrapperInterface;
use Duyler\WorkerPool\Worker\HttpWorkerAdapter;
use Override;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

use Socket;

use function strlen;

use const AF_INET;
use const SOCK_STREAM;
use const SOL_TCP;

#[CoversClass(HttpWorkerAdapter::class)]
#[UsesClass(WorkerPoolException::class)]
#[AllowMockObjectsWithoutExpectations]
final class HttpWorkerAdapterUnitTest extends TestCase
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
    public function handles_valid_get_request(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $request = "GET / HTTP/1.1\r\nHost: localhost\r\n\r\n";

        $readCount = 0;
        $this->socketWrapper->method('read')->willReturnCallback(function () use ($request, &$readCount): string|false {
            $readCount++;
            return 1 === $readCount ? $request : false;
        });

        $writtenData = '';
        $this->socketWrapper->method('write')->willReturnCallback(function (Socket $s, string $data) use (&$writtenData): int {
            $writtenData .= $data;
            return strlen($data);
        });

        $this->socketWrapper->method('setOption')->willReturn(true);
        $this->adapter->handleConnection($socket);

        $this->assertStringContainsString('200 OK', $writtenData);
        $this->assertStringContainsString('Hello from Worker Pool!', $writtenData);
    }

    #[Test]
    public function handles_post_request(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $body = '{"key":"value"}';
        $request = "POST /api HTTP/1.1\r\nHost: localhost\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body;

        $readCount = 0;
        $this->socketWrapper->method('read')->willReturnCallback(function () use ($request, &$readCount): string|false {
            $readCount++;
            return 1 === $readCount ? $request : false;
        });

        $writtenData = '';
        $this->socketWrapper->method('write')->willReturnCallback(function (Socket $s, string $data) use (&$writtenData): int {
            $writtenData .= $data;
            return strlen($data);
        });

        $this->socketWrapper->method('setOption')->willReturn(true);
        $this->adapter->handleConnection($socket);

        $this->assertStringContainsString('200 OK', $writtenData);
    }

    #[Test]
    public function sends_400_on_empty_read(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        $this->socketWrapper->method('read')->willReturn(false);

        $writtenData = '';
        $this->socketWrapper->method('write')->willReturnCallback(function (Socket $s, string $data) use (&$writtenData): int {
            $writtenData .= $data;
            return strlen($data);
        });

        $this->socketWrapper->method('setOption')->willReturn(true);
        $this->adapter->handleConnection($socket);

        $this->assertStringContainsString('400', $writtenData);
    }

    #[Test]
    public function sends_400_on_empty_string(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        $this->socketWrapper->method('read')->willReturn('');

        $writtenData = '';
        $this->socketWrapper->method('write')->willReturnCallback(function (Socket $s, string $data) use (&$writtenData): int {
            $writtenData .= $data;
            return strlen($data);
        });

        $this->socketWrapper->method('setOption')->willReturn(true);
        $this->adapter->handleConnection($socket);

        $this->assertStringContainsString('400', $writtenData);
    }

    #[Test]
    public function sends_500_on_exception(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        $this->socketWrapper->method('read')->willThrowException(new WorkerPoolException('Test error'));

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
    public function closes_socket_in_finally(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        $this->socketWrapper->method('read')->willReturn(false);
        $this->socketWrapper->method('write')->willReturn(0);
        $this->socketWrapper->method('setOption')->willReturn(true);
        $this->socketWrapper->expects($this->once())->method('close')->with($socket);

        $this->adapter->handleConnection($socket);
    }

    #[Test]
    public function response_includes_content_length(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $request = "GET / HTTP/1.1\r\nHost: localhost\r\n\r\n";

        $readCount = 0;
        $this->socketWrapper->method('read')->willReturnCallback(function () use ($request, &$readCount): string|false {
            $readCount++;
            return 1 === $readCount ? $request : false;
        });

        $writtenData = '';
        $this->socketWrapper->method('write')->willReturnCallback(function (Socket $s, string $data) use (&$writtenData): int {
            $writtenData .= $data;
            return strlen($data);
        });

        $this->socketWrapper->method('setOption')->willReturn(true);
        $this->adapter->handleConnection($socket);

        $this->assertStringContainsString('Content-Length:', $writtenData);
    }

    #[Test]
    public function handles_chunked_read(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        $readCount = 0;
        $this->socketWrapper->method('read')->willReturnCallback(function () use (&$readCount): string|false {
            $readCount++;
            if (1 === $readCount) {
                return "GET / HTTP/1.1\r\nHost: localhost\r\n";
            }
            if (2 === $readCount) {
                return "\r\n";
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

        $this->assertStringContainsString('200 OK', $writtenData);
    }

    #[Test]
    public function sends_400_on_invalid_http(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        $readCount = 0;
        $this->socketWrapper->method('read')->willReturnCallback(function () use (&$readCount): string|false {
            $readCount++;
            if (1 === $readCount) {
                return "INVALID DATA";
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

    #[Test]
    public function read_request_breaks_on_headers_too_large(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        $readCount = 0;
        $this->socketWrapper->method('read')->willReturnCallback(function () use (&$readCount): string|false {
            $readCount++;
            if ($readCount <= 5) {
                return str_repeat('A', 4096);
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

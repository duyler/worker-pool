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
final class MalformedHttpRequestTest extends TestCase
{
    private SocketWrapperInterface&MockObject $socketWrapper;
    private HttpWorkerAdapter $adapter;
    private string $writtenData;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->socketWrapper = $this->createMock(SocketWrapperInterface::class);
        $this->adapter = new HttpWorkerAdapter($this->socketWrapper);
        $this->writtenData = '';
    }

    #[Test]
    public function handles_binary_garbage(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $binaryData = "\x00\x01\x02\x03\xFF\xFE\xFD\xFC";

        $this->setupReadAndWrite($binaryData);

        $this->adapter->handleConnection($socket);

        $this->assertStringContainsString('400', $this->writtenData);
    }

    #[Test]
    public function handles_null_byte_injection_in_uri(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $request = "GET /path\x00/injected HTTP/1.1\r\nHost: localhost\r\n\r\n";

        $this->setupReadAndWrite($request);

        $this->adapter->handleConnection($socket);

        $this->assertTrue('' !== $this->writtenData);
    }

    #[Test]
    public function handles_invalid_http_method(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $request = "INVALID / HTTP/1.1\r\nHost: localhost\r\n\r\n";

        $this->setupReadAndWrite($request);

        $this->adapter->handleConnection($socket);

        $this->assertTrue('' !== $this->writtenData);
    }

    #[Test]
    public function handles_missing_host_header(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $request = "GET / HTTP/1.1\r\n\r\n";

        $this->setupReadAndWrite($request);

        $this->adapter->handleConnection($socket);

        $this->assertTrue('' !== $this->writtenData);
    }

    #[Test]
    public function handles_extremely_long_uri(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $longUri = '/' . str_repeat('a', 8000);
        $request = "GET {$longUri} HTTP/1.1\r\nHost: localhost\r\n\r\n";

        $this->setupReadAndWrite($request);

        $this->adapter->handleConnection($socket);

        $this->assertTrue('' !== $this->writtenData);
    }

    #[Test]
    public function handles_duplicate_content_length(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $request = "POST / HTTP/1.1\r\nHost: localhost\r\nContent-Length: 5\r\nContent-Length: 10\r\n\r\nhello";

        $this->setupReadAndWrite($request);

        $this->adapter->handleConnection($socket);

        $this->assertTrue('' !== $this->writtenData);
    }

    #[Test]
    public function handles_special_characters_in_headers(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $request = "GET / HTTP/1.1\r\nHost: localhost\r\nX-Custom: <script>alert('xss')</script>\r\n\r\n";

        $this->setupReadAndWrite($request);

        $this->adapter->handleConnection($socket);

        $this->assertStringContainsString('200', $this->writtenData);
    }

    #[Test]
    public function handles_incomplete_request_line(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        $this->setupReadAndWrite("GET /\r\n\r\n");

        $this->adapter->handleConnection($socket);

        $this->assertStringContainsString('400', $this->writtenData);
    }

    #[Test]
    public function handles_raw_newlines_without_cr(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $request = "GET / HTTP/1.1\nHost: localhost\n\n";

        $this->setupReadAndWrite($request);

        $this->adapter->handleConnection($socket);

        $this->assertTrue('' !== $this->writtenData);
    }

    #[Test]
    public function handles_empty_request_line_with_headers(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        $this->setupReadAndWrite("\r\nHost: localhost\r\n\r\n");

        $this->adapter->handleConnection($socket);

        $this->assertStringContainsString('400', $this->writtenData);
    }

    #[Test]
    public function handles_http_version_missing(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        $this->setupReadAndWrite("GET /\r\nHost: localhost\r\n\r\n");

        $this->adapter->handleConnection($socket);

        $this->assertStringContainsString('400', $this->writtenData);
    }

    #[Test]
    public function socket_closed_after_malformed_request(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        $this->socketWrapper->method('read')->willReturn(false);
        $this->socketWrapper->method('write')->willReturn(0);
        $this->socketWrapper->method('setOption')->willReturn(true);
        $this->socketWrapper->expects($this->once())->method('close')->with($socket);

        $this->adapter->handleConnection($socket);
    }

    private function setupReadAndWrite(string $data): void
    {
        $readCount = 0;
        $this->socketWrapper->method('read')->willReturnCallback(function () use ($data, &$readCount): string|false {
            ++$readCount;
            return 1 === $readCount ? $data : false;
        });

        $writtenData = &$this->writtenData;
        $this->socketWrapper->method('write')->willReturnCallback(function (Socket $socket, string $data) use (&$writtenData): int {
            $writtenData .= $data;
            return strlen($data);
        });

        $this->socketWrapper->method('setOption')->willReturn(true);
    }
}

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

use function strlen;

use const AF_INET;
use const SOCK_STREAM;
use const SOL_SOCKET;
use const SOL_TCP;
use const SO_RCVTIMEO;
use const SO_SNDTIMEO;

#[Group('security')]
#[CoversClass(HttpWorkerAdapter::class)]
#[UsesClass(WorkerPoolException::class)]
#[AllowMockObjectsWithoutExpectations]
final class SlowlorisProtectionTest extends TestCase
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
    public function socket_receive_timeout_is_configured(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        $this->socketWrapper->expects($this->exactly(2))->method('setOption')->willReturnCallback(function (Socket $s, int $level, int $name, int|array $value): bool {
            if (SOL_SOCKET === $level && SO_RCVTIMEO === $name) {
                $this->assertSame(['sec' => 30, 'usec' => 0], $value);
            }
            if (SOL_SOCKET === $level && SO_SNDTIMEO === $name) {
                $this->assertSame(['sec' => 30, 'usec' => 0], $value);
            }
            return true;
        });

        $this->socketWrapper->method('read')->willReturn(false);
        $this->socketWrapper->method('write')->willReturn(0);

        $this->adapter->handleConnection($socket);
    }

    #[Test]
    public function handles_partial_request_gracefully(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        $readCount = 0;
        $this->socketWrapper->method('read')->willReturnCallback(function () use (&$readCount): string|false {
            ++$readCount;
            if (1 === $readCount) {
                return "GET / HTTP/1.1\r\nHost: localhost";
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
    public function handles_byte_by_byte_arrival(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        $data = "GET / HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $offset = 0;

        $this->socketWrapper->method('read')->willReturnCallback(function () use ($data, &$offset): string|false {
            if ($offset >= strlen($data)) {
                return false;
            }
            $char = $data[$offset];
            ++$offset;
            return $char;
        });

        $writtenData = '';
        $this->socketWrapper->method('write')->willReturnCallback(function (Socket $s, string $d) use (&$writtenData): int {
            $writtenData .= $d;
            return strlen($d);
        });

        $this->socketWrapper->method('setOption')->willReturn(true);
        $this->adapter->handleConnection($socket);

        $this->assertStringContainsString('200', $writtenData);
    }

    #[Test]
    public function socket_closed_after_timeout(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        $this->socketWrapper->method('read')->willReturn('');
        $this->socketWrapper->method('write')->willReturn(0);
        $this->socketWrapper->method('setOption')->willReturn(true);
        $this->socketWrapper->expects($this->once())->method('close')->with($socket);

        $this->adapter->handleConnection($socket);
    }

    #[Test]
    public function socket_closed_after_incomplete_headers(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        $readCount = 0;
        $this->socketWrapper->method('read')->willReturnCallback(function () use (&$readCount): string|false {
            ++$readCount;
            return 1 === $readCount ? "GET / HT" : false;
        });

        $this->socketWrapper->method('write')->willReturn(0);
        $this->socketWrapper->method('setOption')->willReturn(true);
        $this->socketWrapper->expects($this->once())->method('close')->with($socket);

        $this->adapter->handleConnection($socket);
    }

    #[Test]
    public function multiple_connections_handled_independently(): void
    {
        $adapter1 = new HttpWorkerAdapter($this->socketWrapper);
        $adapter2 = new HttpWorkerAdapter($this->socketWrapper);

        $socket1 = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $socket2 = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        $writtenData1 = '';
        $writtenData2 = '';

        $readCount = 0;
        $this->socketWrapper->method('read')->willReturnCallback(function () use (&$readCount): string|false {
            ++$readCount;
            if (1 === $readCount) {
                return false;
            }
            if (2 === $readCount) {
                return "GET / HTTP/1.1\r\nHost: localhost\r\n\r\n";
            }
            return false;
        });

        $this->socketWrapper->method('write')->willReturnCallback(function (Socket $socket, string $data) use ($socket1, $socket2, &$writtenData1, &$writtenData2): int {
            if ($socket === $socket1) {
                $writtenData1 .= $data;
            }
            if ($socket === $socket2) {
                $writtenData2 .= $data;
            }
            return strlen($data);
        });

        $this->socketWrapper->method('setOption')->willReturn(true);

        $adapter1->handleConnection($socket1);
        $adapter2->handleConnection($socket2);

        $this->assertStringContainsString('400', $writtenData1);
        $this->assertStringContainsString('200', $writtenData2);
    }
}

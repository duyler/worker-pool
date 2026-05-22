<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\IPC;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

use Duyler\WorkerPool\Exception\IPCException;
use Duyler\WorkerPool\IPC\Message;
use Duyler\WorkerPool\IPC\MessageType;
use Duyler\WorkerPool\IPC\UnixSocketChannel;
use Duyler\WorkerPool\Socket\SocketWrapperInterface;
use Override;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

use Socket;

use function strlen;

use const AF_UNIX;
use const PHP_BINARY_READ;
use const SOCK_STREAM;

#[CoversClass(UnixSocketChannel::class)]
#[UsesClass(IPCException::class)]
#[UsesClass(Message::class)]
#[AllowMockObjectsWithoutExpectations]
final class UnixSocketChannelUnitTest extends TestCase
{
    private SocketWrapperInterface&MockObject $socketWrapper;
    private string $socketPath;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->socketWrapper = $this->createMock(SocketWrapperInterface::class);
        $this->socketPath = sys_get_temp_dir() . '/test_ch_' . uniqid() . '.sock';
    }

    #[Override]
    protected function tearDown(): void
    {
        if (file_exists($this->socketPath)) {
            @unlink($this->socketPath);
        }
        parent::tearDown();
    }

    #[Test]
    public function connect_as_server_creates_socket(): void
    {
        $socket = socket_create(AF_UNIX, SOCK_STREAM, 0);

        $this->socketWrapper->expects($this->once())->method('create')
            ->with(AF_UNIX, SOCK_STREAM, 0)->willReturn($socket);
        $this->socketWrapper->method('bind')->willReturn(true);
        $this->socketWrapper->method('listen')->willReturn(true);

        $channel = new UnixSocketChannel($this->socketPath, $this->socketWrapper, isServer: true);
        $this->assertTrue($channel->connect());
        $this->assertTrue($channel->isConnected());
    }

    #[Test]
    public function connect_as_client_connects(): void
    {
        $socket = socket_create(AF_UNIX, SOCK_STREAM, 0);

        $this->socketWrapper->method('create')->willReturn($socket);
        $this->socketWrapper->method('connect')->willReturn(true);

        $channel = new UnixSocketChannel($this->socketPath, $this->socketWrapper, isServer: false);
        $this->assertTrue($channel->connect());
        $this->assertTrue($channel->isConnected());
    }

    #[Test]
    public function connect_throws_on_create_failure(): void
    {
        $this->socketWrapper->method('create')->willReturn(false);
        $this->socketWrapper->method('lastError')->willReturn(1);
        $this->socketWrapper->method('strerror')->willReturn('Error');

        $channel = new UnixSocketChannel($this->socketPath, $this->socketWrapper);

        $this->expectException(IPCException::class);
        $channel->connect();
    }

    #[Test]
    public function connect_throws_on_bind_failure(): void
    {
        $socket = socket_create(AF_UNIX, SOCK_STREAM, 0);

        $this->socketWrapper->method('create')->willReturn($socket);
        $this->socketWrapper->method('bind')->willReturn(false);
        $this->socketWrapper->method('lastError')->willReturn(1);
        $this->socketWrapper->method('strerror')->willReturn('Error');

        $channel = new UnixSocketChannel($this->socketPath, $this->socketWrapper, isServer: true);

        $this->expectException(IPCException::class);
        $channel->connect();
    }

    #[Test]
    public function connect_throws_on_listen_failure(): void
    {
        $socket = socket_create(AF_UNIX, SOCK_STREAM, 0);

        $this->socketWrapper->method('create')->willReturn($socket);
        $this->socketWrapper->method('bind')->willReturn(true);
        $this->socketWrapper->method('listen')->willReturn(false);
        $this->socketWrapper->method('lastError')->willReturn(1);
        $this->socketWrapper->method('strerror')->willReturn('Error');

        $channel = new UnixSocketChannel($this->socketPath, $this->socketWrapper, isServer: true);

        $this->expectException(IPCException::class);
        $channel->connect();
    }

    #[Test]
    public function connect_throws_on_connect_failure(): void
    {
        $socket = socket_create(AF_UNIX, SOCK_STREAM, 0);

        $this->socketWrapper->method('create')->willReturn($socket);
        $this->socketWrapper->method('connect')->willReturn(false);
        $this->socketWrapper->method('lastError')->willReturn(1);
        $this->socketWrapper->method('strerror')->willReturn('Error');

        $channel = new UnixSocketChannel($this->socketPath, $this->socketWrapper, isServer: false);

        $this->expectException(IPCException::class);
        $channel->connect();
    }

    #[Test]
    public function send_throws_when_not_connected(): void
    {
        $channel = new UnixSocketChannel($this->socketPath, $this->socketWrapper);

        $this->expectException(IPCException::class);
        $channel->send(Message::workerReady(workerId: 1));
    }

    #[Test]
    public function receive_throws_when_not_connected(): void
    {
        $channel = new UnixSocketChannel($this->socketPath, $this->socketWrapper);

        $this->expectException(IPCException::class);
        $channel->receive();
    }

    #[Test]
    public function accept_throws_on_non_server(): void
    {
        $socket = socket_create(AF_UNIX, SOCK_STREAM, 0);
        $this->socketWrapper->method('create')->willReturn($socket);
        $this->socketWrapper->method('connect')->willReturn(true);

        $channel = new UnixSocketChannel($this->socketPath, $this->socketWrapper, isServer: false);
        $channel->connect();

        $this->expectException(IPCException::class);
        $channel->accept();
    }

    #[Test]
    public function accept_returns_null_when_no_clients(): void
    {
        $socket = socket_create(AF_UNIX, SOCK_STREAM, 0);
        $this->socketWrapper->method('create')->willReturn($socket);
        $this->socketWrapper->method('bind')->willReturn(true);
        $this->socketWrapper->method('listen')->willReturn(true);
        $this->socketWrapper->method('accept')->willReturn(false);

        $channel = new UnixSocketChannel($this->socketPath, $this->socketWrapper, isServer: true);
        $channel->connect();

        $this->assertNull($channel->accept());
    }

    #[Test]
    public function close_clears_state(): void
    {
        $socket = socket_create(AF_UNIX, SOCK_STREAM, 0);
        $this->socketWrapper->method('create')->willReturn($socket);
        $this->socketWrapper->method('connect')->willReturn(true);

        $channel = new UnixSocketChannel($this->socketPath, $this->socketWrapper, isServer: false);
        $channel->connect();
        $this->assertTrue($channel->isConnected());

        $channel->close();
        $this->assertFalse($channel->isConnected());
        $this->assertNull($channel->getSocket());
    }

    #[Test]
    public function send_writes_serialized_message(): void
    {
        $socket = socket_create(AF_UNIX, SOCK_STREAM, 0);
        $this->socketWrapper->method('create')->willReturn($socket);
        $this->socketWrapper->method('connect')->willReturn(true);

        $message = Message::workerReady(workerId: 1);
        $serialized = $message->serialize();
        $expectedPacket = pack('N', strlen($serialized)) . $serialized;

        $this->socketWrapper->expects($this->once())->method('write')
            ->with($socket, $expectedPacket, strlen($expectedPacket))
            ->willReturn(strlen($expectedPacket));

        $channel = new UnixSocketChannel($this->socketPath, $this->socketWrapper, isServer: false);
        $channel->connect();

        $this->assertTrue($channel->send($message));
    }

    #[Test]
    public function send_returns_false_on_write_failure(): void
    {
        $socket = socket_create(AF_UNIX, SOCK_STREAM, 0);
        $this->socketWrapper->method('create')->willReturn($socket);
        $this->socketWrapper->method('connect')->willReturn(true);
        $this->socketWrapper->method('write')->willReturn(false);

        $channel = new UnixSocketChannel($this->socketPath, $this->socketWrapper, isServer: false);
        $channel->connect();

        $this->assertFalse($channel->send(Message::workerReady(workerId: 1)));
    }

    #[Test]
    public function receive_returns_null_on_empty_read(): void
    {
        $socket = socket_create(AF_UNIX, SOCK_STREAM, 0);
        $this->socketWrapper->method('create')->willReturn($socket);
        $this->socketWrapper->method('connect')->willReturn(true);
        $this->socketWrapper->method('read')->willReturn(false);

        $channel = new UnixSocketChannel($this->socketPath, $this->socketWrapper, isServer: false);
        $channel->connect();

        $this->assertNull($channel->receive());
    }

    #[Test]
    public function receive_returns_message(): void
    {
        $socket = socket_create(AF_UNIX, SOCK_STREAM, 0);
        $this->socketWrapper->method('create')->willReturn($socket);
        $this->socketWrapper->method('connect')->willReturn(true);

        $message = Message::workerReady(workerId: 1);
        $serialized = $message->serialize();
        $header = pack('N', strlen($serialized));

        $readCall = 0;
        $this->socketWrapper->method('read')->willReturnCallback(function (Socket $s, int $length, int $type = PHP_BINARY_READ) use ($header, $serialized, &$readCall): string|false {
            $readCall++;
            if (1 === $readCall) {
                return $header;
            }
            return $serialized;
        });

        $channel = new UnixSocketChannel($this->socketPath, $this->socketWrapper, isServer: false);
        $channel->connect();

        $result = $channel->receive();
        $this->assertNotNull($result);
        $this->assertSame(MessageType::WorkerReady, $result->type);
    }

    #[Test]
    public function receive_returns_null_on_short_header(): void
    {
        $socket = socket_create(AF_UNIX, SOCK_STREAM, 0);
        $this->socketWrapper->method('create')->willReturn($socket);
        $this->socketWrapper->method('connect')->willReturn(true);
        $this->socketWrapper->method('read')->willReturn('ab');

        $channel = new UnixSocketChannel($this->socketPath, $this->socketWrapper, isServer: false);
        $channel->connect();

        $this->assertNull($channel->receive());
    }

    #[Test]
    public function receive_returns_null_on_empty_string_read(): void
    {
        $socket = socket_create(AF_UNIX, SOCK_STREAM, 0);
        $this->socketWrapper->method('create')->willReturn($socket);
        $this->socketWrapper->method('connect')->willReturn(true);
        $this->socketWrapper->method('read')->willReturn('');

        $channel = new UnixSocketChannel($this->socketPath, $this->socketWrapper, isServer: false);
        $channel->connect();

        $this->assertNull($channel->receive());
    }

    #[Test]
    public function close_removes_socket_file_for_server(): void
    {
        $socket = socket_create(AF_UNIX, SOCK_STREAM, 0);
        $this->socketWrapper->method('create')->willReturn($socket);
        $this->socketWrapper->method('bind')->willReturn(true);
        $this->socketWrapper->method('listen')->willReturn(true);

        $channel = new UnixSocketChannel($this->socketPath, $this->socketWrapper, isServer: true);
        $channel->connect();

        touch($this->socketPath);
        $this->assertFileExists($this->socketPath);

        $channel->close();
        $this->assertFileDoesNotExist($this->socketPath);
    }

    #[Test]
    public function receive_throws_on_invalid_length(): void
    {
        $socket = socket_create(AF_UNIX, SOCK_STREAM, 0);
        $this->socketWrapper->method('create')->willReturn($socket);
        $this->socketWrapper->method('connect')->willReturn(true);

        $header = pack('N', 0);
        $this->socketWrapper->method('read')->willReturn($header);

        $channel = new UnixSocketChannel($this->socketPath, $this->socketWrapper, isServer: false);
        $channel->connect();

        $this->expectException(IPCException::class);
        $channel->receive();
    }

    #[Test]
    public function receive_returns_null_on_data_read_failure(): void
    {
        $socket = socket_create(AF_UNIX, SOCK_STREAM, 0);
        $this->socketWrapper->method('create')->willReturn($socket);
        $this->socketWrapper->method('connect')->willReturn(true);

        $header = pack('N', 10);
        $readCall = 0;
        $this->socketWrapper->method('read')->willReturnCallback(function () use ($header, &$readCall): string|false {
            $readCall++;
            if (1 === $readCall) {
                return $header;
            }
            return false;
        });

        $channel = new UnixSocketChannel($this->socketPath, $this->socketWrapper, isServer: false);
        $channel->connect();

        $this->assertNull($channel->receive());
    }
}

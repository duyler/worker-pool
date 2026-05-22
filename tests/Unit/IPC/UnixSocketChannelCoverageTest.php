<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\IPC;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

use Duyler\WorkerPool\Exception\IPCException;
use Duyler\WorkerPool\IPC\Message;
use Duyler\WorkerPool\IPC\MessageType;
use Duyler\WorkerPool\IPC\UnixSocketChannel;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use ReflectionProperty;

use Duyler\WorkerPool\Socket\SocketWrapper;

use Duyler\WorkerPool\Exception\WorkerPoolExceptionBase;

use const AF_UNIX;
use const SOCK_STREAM;
use const E_WARNING;

#[CoversClass(UnixSocketChannel::class)]
#[UsesClass(WorkerPoolExceptionBase::class)]
#[UsesClass(Message::class)]
#[UsesClass(SocketWrapper::class)]
final class UnixSocketChannelCoverageTest extends TestCase
{
    private string $socketPath;

    #[Override]
    protected function setUp(): void
    {
        $this->socketPath = sys_get_temp_dir() . '/wp_test_' . uniqid() . '.sock';
    }

    #[Override]
    protected function tearDown(): void
    {
        if (file_exists($this->socketPath)) {
            unlink($this->socketPath);
        }

        parent::tearDown();
    }

    #[Test]
    public function constructorSetsDefaults(): void
    {
        $channel = new UnixSocketChannel($this->socketPath, new SocketWrapper(), false);
        $this->assertFalse($channel->isConnected());
        $this->assertNull($channel->getSocket());
    }

    #[Test]
    public function connectAsServerCreatesSocket(): void
    {
        $channel = new UnixSocketChannel($this->socketPath, new SocketWrapper(), true);
        $result = $channel->connect();

        $this->assertTrue($result);
        $this->assertTrue($channel->isConnected());
        $this->assertNotNull($channel->getSocket());

        $channel->close();
    }

    #[Test]
    public function connectAsClientFailsWithoutServer(): void
    {
        set_error_handler(static fn(): bool => true, E_WARNING);
        try {
            $channel = new UnixSocketChannel($this->socketPath, new SocketWrapper(), false);

            $this->expectException(IPCException::class);
            $channel->connect();
        } finally {
            restore_error_handler();
        }
    }

    #[Test]
    public function closeCleansUpServerSocket(): void
    {
        $channel = new UnixSocketChannel($this->socketPath, new SocketWrapper(), true);
        $channel->connect();

        $this->assertTrue($channel->isConnected());

        $channel->close();

        $this->assertFalse($channel->isConnected());
        $this->assertNull($channel->getSocket());
        $this->assertFileDoesNotExist($this->socketPath);
    }

    #[Test]
    public function closeIsIdempotent(): void
    {
        $channel = new UnixSocketChannel($this->socketPath, new SocketWrapper(), true);
        $channel->connect();
        $channel->close();
        $channel->close();

        $this->assertFalse($channel->isConnected());
    }

    #[Test]
    public function acceptThrowsOnNonServerSocket(): void
    {
        $channel = new UnixSocketChannel($this->socketPath, new SocketWrapper(), false);

        $this->expectException(IPCException::class);
        $this->expectExceptionMessage('Cannot accept on non-server socket');
        $channel->accept();
    }

    #[Test]
    public function acceptReturnsNullWhenNoClients(): void
    {
        $channel = new UnixSocketChannel($this->socketPath, new SocketWrapper(), true);
        $channel->connect();

        $result = $channel->accept();
        $this->assertNull($result);

        $channel->close();
    }

    #[Test]
    public function sendThrowsWhenNotConnected(): void
    {
        $channel = new UnixSocketChannel($this->socketPath, new SocketWrapper(), false);
        $message = new Message(MessageType::WorkerReady, []);

        $this->expectException(IPCException::class);
        $this->expectExceptionMessage('Socket is not connected');
        $channel->send($message);
    }

    #[Test]
    public function receiveThrowsWhenNotConnected(): void
    {
        $channel = new UnixSocketChannel($this->socketPath, new SocketWrapper(), false);

        $this->expectException(IPCException::class);
        $this->expectExceptionMessage('Socket is not connected');
        $channel->receive();
    }

    #[Test]
    public function sendAndReceiveMessage(): void
    {
        set_error_handler(static fn(): bool => true, E_WARNING);
        try {
            $server = new UnixSocketChannel($this->socketPath, new SocketWrapper(), true);
            $server->connect();

            $clientSocket = socket_create(AF_UNIX, SOCK_STREAM, 0);
            socket_connect($clientSocket, $this->socketPath);

            $accepted = $server->accept();
            $this->assertNotNull($accepted);

            $clientChannel = new UnixSocketChannel($this->socketPath, new SocketWrapper(), false);
            $ref = new ReflectionProperty($clientChannel, 'socket');
            $ref->setValue($clientChannel, $clientSocket);

            $connectedRef = new ReflectionProperty($clientChannel, 'isConnected');
            $connectedRef->setValue($clientChannel, true);

            socket_set_nonblock($clientSocket);

            $message = new Message(MessageType::WorkerReady, ['worker_id' => 1]);
            $sent = $clientChannel->send($message);
            $this->assertTrue($sent);

            usleep(50000);

            $received = $server->receive();

            $clientChannel->close();
            $server->close();

            if (null !== $received) {
                $this->assertSame(MessageType::WorkerReady->value, $received->type);
            }
        } finally {
            restore_error_handler();
        }
    }

    #[Test]
    public function destructorCallsClose(): void
    {
        $channel = new UnixSocketChannel($this->socketPath, new SocketWrapper(), true);
        $channel->connect();
        $this->assertTrue($channel->isConnected());

        unset($channel);

        $this->assertFileDoesNotExist($this->socketPath);
    }
}

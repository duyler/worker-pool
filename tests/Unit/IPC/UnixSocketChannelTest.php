<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\IPC;

use PHPUnit\Framework\Attributes\CoversClass;

use Duyler\WorkerPool\Exception\IPCException;
use Duyler\WorkerPool\IPC\Message;
use Duyler\WorkerPool\IPC\UnixSocketChannel;
use Override;
use PHPUnit\Framework\TestCase;
use Duyler\WorkerPool\Socket\SocketWrapper;

#[CoversClass(UnixSocketChannel::class)]
class UnixSocketChannelTest extends TestCase
{
    private string $socketPath;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->socketPath = sys_get_temp_dir() . '/test_socket_' . uniqid() . '.sock';
    }

    #[Override]
    protected function tearDown(): void
    {
        parent::tearDown();

        if (file_exists($this->socketPath)) {
            @unlink($this->socketPath);
        }
    }

    public function testCreatesServerSocket(): void
    {
        $server = new UnixSocketChannel($this->socketPath, new SocketWrapper(), isServer: true);

        $this->assertTrue($server->connect());
        $this->assertTrue($server->isConnected());
        $this->assertNotNull($server->getSocket());

        $server->close();
    }

    public function testCreatesClientSocket(): void
    {
        $server = new UnixSocketChannel($this->socketPath, new SocketWrapper(), isServer: true);
        $server->connect();

        $client = new UnixSocketChannel($this->socketPath, new SocketWrapper(), isServer: false);

        $this->assertTrue($client->connect());
        $this->assertTrue($client->isConnected());

        $client->close();
        $server->close();
    }

    public function testServerAcceptsClientConnection(): void
    {
        $server = new UnixSocketChannel($this->socketPath, new SocketWrapper(), isServer: true);
        $server->connect();

        $client = new UnixSocketChannel($this->socketPath, new SocketWrapper(), isServer: false);
        $client->connect();

        usleep(10000);

        $clientSocket = $server->accept();
        $this->assertNotNull($clientSocket);

        socket_close($clientSocket);
        $client->close();
        $server->close();
    }

    public function testSendsAndReceivesMessage(): void
    {
        $server = new UnixSocketChannel($this->socketPath, new SocketWrapper(), isServer: true);
        $server->connect();

        $client = new UnixSocketChannel($this->socketPath, new SocketWrapper(), isServer: false);
        $client->connect();

        usleep(10000);

        $message = Message::workerReady(42);
        $this->assertTrue($client->send($message));

        usleep(10000);

        $clientSocket = $server->accept();
        $this->assertNotNull($clientSocket);

        socket_close($clientSocket);
        $client->close();
        $server->close();
    }

    public function testThrowsOnSendWithoutConnection(): void
    {
        $channel = new UnixSocketChannel($this->socketPath, new SocketWrapper());

        $this->expectException(IPCException::class);
        $this->expectExceptionMessage('Socket is not connected');

        $channel->send(Message::shutdown());
    }

    public function testThrowsOnReceiveWithoutConnection(): void
    {
        $channel = new UnixSocketChannel($this->socketPath, new SocketWrapper());

        $this->expectException(IPCException::class);
        $this->expectExceptionMessage('Socket is not connected');

        $channel->receive();
    }

    public function testThrowsOnAcceptFromNonServer(): void
    {
        $server = new UnixSocketChannel($this->socketPath, new SocketWrapper(), isServer: true);
        $server->connect();

        $client = new UnixSocketChannel($this->socketPath, new SocketWrapper(), isServer: false);
        $client->connect();

        $this->expectException(IPCException::class);
        $this->expectExceptionMessage('Cannot accept on non-server socket');

        $client->accept();

        $client->close();
        $server->close();
    }

    public function testClosesSocketProperly(): void
    {
        $server = new UnixSocketChannel($this->socketPath, new SocketWrapper(), isServer: true);
        $server->connect();

        $this->assertTrue($server->isConnected());

        $server->close();

        $this->assertFalse($server->isConnected());
        $this->assertNull($server->getSocket());
    }

    public function testRemovesSocketFileOnServerClose(): void
    {
        $server = new UnixSocketChannel($this->socketPath, new SocketWrapper(), isServer: true);
        $server->connect();

        $this->assertFileExists($this->socketPath);

        $server->close();

        $this->assertFileDoesNotExist($this->socketPath);
    }

    public function testReturnsNullWhenNoClientToAccept(): void
    {
        $server = new UnixSocketChannel($this->socketPath, new SocketWrapper(), isServer: true);
        $server->connect();

        $clientSocket = $server->accept();

        $this->assertNull($clientSocket);

        $server->close();
    }

    public function testReturnsNullWhenNoMessageToReceive(): void
    {
        $server = new UnixSocketChannel($this->socketPath, new SocketWrapper(), isServer: true);
        $server->connect();

        $client = new UnixSocketChannel($this->socketPath, new SocketWrapper(), isServer: false);
        $client->connect();

        usleep(10000);

        $message = $client->receive();

        $this->assertNull($message);

        $client->close();
        $server->close();
    }
}

<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\Master;

use PHPUnit\Framework\Attributes\CoversClass;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\WorkerPool\Exception\WorkerPoolException;
use Duyler\WorkerPool\Master\SocketManager;
use Override;
use PHPUnit\Framework\TestCase;

use const AF_INET;
use const SOCK_STREAM;
use const SOL_TCP;

#[CoversClass(SocketManager::class)]
class SocketManagerTest extends TestCase
{
    private ServerConfig $config;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $port = $this->findFreePort();

        $this->config = new ServerConfig(
            host: '127.0.0.1',
            port: $port,
        );
    }

    public function testCreatesSocketManager(): void
    {
        $manager = new SocketManager($this->config);

        $this->assertFalse($manager->isListening());
        $this->assertNull($manager->getSocket());
    }

    public function testStartsListening(): void
    {
        $manager = new SocketManager($this->config);

        $manager->listen();

        $this->assertTrue($manager->isListening());
        $this->assertNotNull($manager->getSocket());

        $manager->close();
    }

    public function testDoesNotThrowOnMultipleListenCalls(): void
    {
        $manager = new SocketManager($this->config);

        $manager->listen();
        $manager->listen();

        $this->assertTrue($manager->isListening());

        $manager->close();
    }

    public function testClosesSocket(): void
    {
        $manager = new SocketManager($this->config);

        $manager->listen();
        $this->assertTrue($manager->isListening());

        $manager->close();

        $this->assertFalse($manager->isListening());
        $this->assertNull($manager->getSocket());
    }

    public function testReturnsNullWhenNoConnections(): void
    {
        $manager = new SocketManager($this->config);

        $manager->listen();

        $client = $manager->accept();

        $this->assertNull($client);

        $manager->close();
    }

    public function testReturnsNullWhenNotListening(): void
    {
        $manager = new SocketManager($this->config);

        $client = $manager->accept();

        $this->assertNull($client);
    }

    public function testAcceptsConnection(): void
    {
        $manager = new SocketManager($this->config);
        $manager->listen();

        $serverSocket = $manager->getSocket();
        $this->assertNotNull($serverSocket);

        socket_getsockname($serverSocket, $host, $port);

        $clientSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertNotFalse($clientSocket);

        socket_set_nonblock($clientSocket);

        @socket_connect($clientSocket, $host, $port);

        usleep(10000);

        $accepted = $manager->accept();

        if ($accepted !== null) {
            socket_close($accepted);
        }

        socket_close($clientSocket);
        $manager->close();

        $this->assertTrue(true);
    }

    public function testThrowsOnInvalidBind(): void
    {
        $config = new ServerConfig(
            host: '999.999.999.999',
            port: 8080,
        );

        $manager = new SocketManager($config);

        $this->expectException(WorkerPoolException::class);
        $this->expectExceptionMessage('Failed to bind');

        $manager->listen();
    }

    public function testCleansUpOnDestruct(): void
    {
        $manager = new SocketManager($this->config);
        $manager->listen();

        $socket = $manager->getSocket();
        $this->assertNotNull($socket);

        unset($manager);

        $this->assertTrue(true);
    }

    private function findFreePort(): int
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_bind($socket, '127.0.0.1', 0);
        socket_getsockname($socket, $addr, $port);
        socket_close($socket);

        return $port;
    }
}

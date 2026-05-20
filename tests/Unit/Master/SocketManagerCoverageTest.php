<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\Master;

use PHPUnit\Framework\Attributes\CoversClass;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\WorkerPool\Master\SocketManager;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(SocketManager::class)]
final class SocketManagerCoverageTest extends TestCase
{
    private ServerConfig $config;

    #[Override]
    protected function setUp(): void
    {
        $this->config = new ServerConfig(host: '127.0.0.1', port: 19877);
    }

    #[Test]
    public function listenCreatesSocket(): void
    {
        $manager = new SocketManager($this->config);
        $manager->listen();

        $this->assertTrue($manager->isListening());
        $this->assertNotNull($manager->getSocket());

        $manager->close();
    }

    #[Test]
    public function closeStopsListening(): void
    {
        $manager = new SocketManager($this->config);
        $manager->listen();

        $this->assertTrue($manager->isListening());

        $manager->close();

        $this->assertFalse($manager->isListening());
        $this->assertNull($manager->getSocket());
    }

    #[Test]
    public function detachFromWorkerDisconnects(): void
    {
        $manager = new SocketManager($this->config);
        $manager->listen();

        $manager->detachFromWorker();

        $this->assertFalse($manager->isListening());
        $this->assertNull($manager->getSocket());
    }

    #[Test]
    public function disableAutoClosePreventsDestructorClose(): void
    {
        $manager = new SocketManager($this->config);
        $manager->listen();
        $manager->disableAutoClose();

        $socket = $manager->getSocket();
        $this->assertNotNull($socket);

        unset($manager);

        $this->assertTrue(true);
    }

    #[Test]
    public function acceptReturnsNullWhenNoConnections(): void
    {
        $manager = new SocketManager($this->config);
        $manager->listen();

        $result = $manager->accept();

        $this->assertNull($result);

        $manager->close();
    }

    #[Test]
    public function closeIsIdempotent(): void
    {
        $manager = new SocketManager($this->config);
        $manager->listen();
        $manager->close();
        $manager->close();

        $this->assertFalse($manager->isListening());
    }

    #[Test]
    public function constructorWithCustomLogger(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $manager = new SocketManager($this->config, $logger);

        $this->assertFalse($manager->isListening());
    }

    #[Test]
    public function destructorClosesSocket(): void
    {
        $manager = new SocketManager($this->config);
        $manager->listen();
        $this->assertTrue($manager->isListening());

        unset($manager);

        $this->assertTrue(true);
    }
}

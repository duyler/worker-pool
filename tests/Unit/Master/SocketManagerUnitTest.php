<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\Master;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\WorkerPool\Exception\WorkerPoolException;
use Duyler\WorkerPool\Master\SocketManager;
use Duyler\WorkerPool\Socket\SocketWrapperInterface;
use Override;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

use const AF_INET;
use const SOCK_STREAM;
use const SOL_TCP;

#[CoversClass(SocketManager::class)]
#[UsesClass(WorkerPoolException::class)]
#[AllowMockObjectsWithoutExpectations]
final class SocketManagerUnitTest extends TestCase
{
    private ServerConfig $config;
    private SocketWrapperInterface&MockObject $socketWrapper;
    private LoggerInterface&MockObject $logger;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->socketWrapper = $this->createMock(SocketWrapperInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->config = new ServerConfig(host: '127.0.0.1', port: 9999);
    }

    #[Test]
    public function not_listening_initially(): void
    {
        $manager = new SocketManager($this->config, $this->socketWrapper, $this->logger);
        $this->assertFalse($manager->isListening());
        $this->assertNull($manager->getSocket());
    }

    #[Test]
    public function listen_creates_and_binds_socket(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        $this->socketWrapper->method('create')->willReturn($socket);
        $this->socketWrapper->method('setOption')->willReturn(true);
        $this->socketWrapper->method('bind')->willReturn(true);
        $this->socketWrapper->method('listen')->willReturn(true);

        $manager = new SocketManager($this->config, $this->socketWrapper, $this->logger);
        $manager->listen();

        $this->assertTrue($manager->isListening());
        $this->assertSame($socket, $manager->getSocket());
    }

    #[Test]
    public function listen_throws_on_create_failure(): void
    {
        $this->socketWrapper->method('create')->willReturn(false);
        $this->socketWrapper->method('lastError')->willReturn(1);
        $this->socketWrapper->method('strerror')->willReturn('Error');

        $manager = new SocketManager($this->config, $this->socketWrapper, $this->logger);

        $this->expectException(WorkerPoolException::class);
        $manager->listen();
    }

    #[Test]
    public function listen_throws_on_set_option_failure(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->socketWrapper->method('create')->willReturn($socket);
        $this->socketWrapper->method('setOption')->willReturn(false);
        $this->socketWrapper->method('lastError')->willReturn(1);
        $this->socketWrapper->method('strerror')->willReturn('Error');

        $manager = new SocketManager($this->config, $this->socketWrapper, $this->logger);

        $this->expectException(WorkerPoolException::class);
        $this->expectExceptionMessage('Failed to set SO_REUSEADDR');
        $manager->listen();
    }

    #[Test]
    public function listen_throws_on_bind_failure(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->socketWrapper->method('create')->willReturn($socket);
        $this->socketWrapper->method('setOption')->willReturn(true);
        $this->socketWrapper->method('bind')->willReturn(false);
        $this->socketWrapper->method('lastError')->willReturn(1);
        $this->socketWrapper->method('strerror')->willReturn('Error');

        $manager = new SocketManager($this->config, $this->socketWrapper, $this->logger);

        $this->expectException(WorkerPoolException::class);
        $this->expectExceptionMessage('Failed to bind');
        $manager->listen();
    }

    #[Test]
    public function listen_throws_on_listen_failure(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->socketWrapper->method('create')->willReturn($socket);
        $this->socketWrapper->method('setOption')->willReturn(true);
        $this->socketWrapper->method('bind')->willReturn(true);
        $this->socketWrapper->method('listen')->willReturn(false);
        $this->socketWrapper->method('lastError')->willReturn(1);
        $this->socketWrapper->method('strerror')->willReturn('Error');

        $manager = new SocketManager($this->config, $this->socketWrapper, $this->logger);

        $this->expectException(WorkerPoolException::class);
        $manager->listen();
    }

    #[Test]
    public function accept_returns_null_when_not_listening(): void
    {
        $manager = new SocketManager($this->config, $this->socketWrapper, $this->logger);
        $this->assertNull($manager->accept());
    }

    #[Test]
    public function accept_returns_client_socket(): void
    {
        $master = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $client = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        $this->socketWrapper->method('create')->willReturn($master);
        $this->socketWrapper->method('setOption')->willReturn(true);
        $this->socketWrapper->method('bind')->willReturn(true);
        $this->socketWrapper->method('listen')->willReturn(true);
        $this->socketWrapper->method('accept')->willReturn($client);
        $this->socketWrapper->method('lastError')->willReturn(0);

        $manager = new SocketManager($this->config, $this->socketWrapper, $this->logger);
        $manager->listen();

        $this->assertSame($client, $manager->accept());
    }

    #[Test]
    public function accept_returns_null_when_no_connection(): void
    {
        $master = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->socketWrapper->method('create')->willReturn($master);
        $this->socketWrapper->method('setOption')->willReturn(true);
        $this->socketWrapper->method('bind')->willReturn(true);
        $this->socketWrapper->method('listen')->willReturn(true);
        $this->socketWrapper->method('accept')->willReturn(false);
        $this->socketWrapper->method('lastError')->willReturn(11);

        $manager = new SocketManager($this->config, $this->socketWrapper, $this->logger);
        $manager->listen();

        $this->assertNull($manager->accept());
    }

    #[Test]
    public function close_resets_state(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->socketWrapper->method('create')->willReturn($socket);
        $this->socketWrapper->method('setOption')->willReturn(true);
        $this->socketWrapper->method('bind')->willReturn(true);
        $this->socketWrapper->method('listen')->willReturn(true);

        $manager = new SocketManager($this->config, $this->socketWrapper, $this->logger);
        $manager->listen();
        $this->assertTrue($manager->isListening());

        $manager->close();
        $this->assertFalse($manager->isListening());
        $this->assertNull($manager->getSocket());
    }

    #[Test]
    public function detach_from_worker_clears_state(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->socketWrapper->method('create')->willReturn($socket);
        $this->socketWrapper->method('setOption')->willReturn(true);
        $this->socketWrapper->method('bind')->willReturn(true);
        $this->socketWrapper->method('listen')->willReturn(true);

        $manager = new SocketManager($this->config, $this->socketWrapper, $this->logger);
        $manager->listen();
        $manager->detachFromWorker();

        $this->assertFalse($manager->isListening());
        $this->assertNull($manager->getSocket());
    }

    #[Test]
    public function disable_auto_close_prevents_destruct_close(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->socketWrapper->method('create')->willReturn($socket);
        $this->socketWrapper->method('setOption')->willReturn(true);
        $this->socketWrapper->method('bind')->willReturn(true);
        $this->socketWrapper->method('listen')->willReturn(true);

        $manager = new SocketManager($this->config, $this->socketWrapper, $this->logger);
        $manager->listen();
        $manager->disableAutoClose();

        $this->socketWrapper->expects($this->never())->method('close');
        unset($manager);
    }

    #[Test]
    public function listen_skip_if_already_listening(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $callCount = 0;
        $this->socketWrapper->method('create')->willReturnCallback(function () use ($socket, &$callCount) {
            $callCount++;
            return $socket;
        });
        $this->socketWrapper->method('setOption')->willReturn(true);
        $this->socketWrapper->method('bind')->willReturn(true);
        $this->socketWrapper->method('listen')->willReturn(true);

        $manager = new SocketManager($this->config, $this->socketWrapper, $this->logger);
        $manager->listen();
        $manager->listen();

        $this->assertSame(1, $callCount);
    }

    #[Test]
    public function accept_logs_error_on_non_eintr_errno(): void
    {
        $master = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->socketWrapper->method('create')->willReturn($master);
        $this->socketWrapper->method('setOption')->willReturn(true);
        $this->socketWrapper->method('bind')->willReturn(true);
        $this->socketWrapper->method('listen')->willReturn(true);
        $this->socketWrapper->method('accept')->willReturn(false);
        $this->socketWrapper->method('lastError')->willReturn(99);
        $this->socketWrapper->method('strerror')->willReturn('Unknown error');

        $manager = new SocketManager($this->config, $this->socketWrapper, $this->logger);
        $manager->listen();

        $this->assertNull($manager->accept());
    }

    #[Test]
    public function accept_returns_null_when_master_socket_null(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->socketWrapper->method('create')->willReturn($socket);
        $this->socketWrapper->method('setOption')->willReturn(true);
        $this->socketWrapper->method('bind')->willReturn(true);
        $this->socketWrapper->method('listen')->willReturn(true);

        $manager = new SocketManager($this->config, $this->socketWrapper, $this->logger);
        $manager->listen();
        $manager->close();

        $this->assertNull($manager->accept());
    }

    #[Test]
    public function destruct_closes_socket_when_auto_close_enabled(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->socketWrapper->method('create')->willReturn($socket);
        $this->socketWrapper->method('setOption')->willReturn(true);
        $this->socketWrapper->method('bind')->willReturn(true);
        $this->socketWrapper->method('listen')->willReturn(true);

        $this->socketWrapper->expects($this->once())->method('close');

        $manager = new SocketManager($this->config, $this->socketWrapper, $this->logger);
        $manager->listen();
        unset($manager);
    }
}

<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\Master;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\WorkerPool\Config\WorkerPoolConfig;
use Duyler\WorkerPool\Exception\WorkerPoolException;
use Duyler\WorkerPool\Master\AbstractMaster;
use Duyler\WorkerPool\Master\SharedSocketMaster;
use Duyler\WorkerPool\Process\ForkWrapperInterface;
use Duyler\WorkerPool\Process\ProcessInfo;
use Duyler\WorkerPool\Signal\SignalHandler;
use Duyler\WorkerPool\Signal\SignalManager;
use Duyler\WorkerPool\Socket\SocketWrapperInterface;
use Duyler\WorkerPool\Worker\WorkerCallbackInterface;
use Override;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Duyler\WorkerPool\Master\WorkerManager;
use Duyler\WorkerPool\Process\ForkWrapper;

use ReflectionMethod;
use ReflectionProperty;
use Socket;

use const AF_INET;
use const SOCK_STREAM;
use const SOL_TCP;

#[CoversClass(SharedSocketMaster::class)]
#[UsesClass(AbstractMaster::class)]
#[UsesClass(WorkerPoolConfig::class)]
#[UsesClass(SignalHandler::class)]
#[UsesClass(SignalManager::class)]
#[UsesClass(WorkerManager::class)]
#[UsesClass(ProcessInfo::class)]
#[UsesClass(WorkerPoolException::class)]
#[UsesClass(ForkWrapper::class)]
final class SharedSocketMasterStartTest extends TestCase
{
    private SocketWrapperInterface $socketWrapper;
    private ForkWrapperInterface $forkWrapper;
    private LoggerInterface $logger;
    private WorkerCallbackInterface $callback;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->socketWrapper = $this->createStub(SocketWrapperInterface::class);
        $this->forkWrapper = $this->createStub(ForkWrapperInterface::class);
        $this->logger = $this->createStub(LoggerInterface::class);
        $this->callback = $this->createStub(WorkerCallbackInterface::class);
    }

    #[Test]
    public function start_spawns_workers_and_runs(): void
    {
        $serverConfig = new ServerConfig(host: '127.0.0.1', port: 9999);
        $config = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 1,
            autoRestart: false,
            pollInterval: 1000,
        );

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        $this->socketWrapper->method('create')->willReturn($socket);
        $this->socketWrapper->method('setOption')->willReturn(true);
        $this->socketWrapper->method('bind')->willReturn(true);
        $this->socketWrapper->method('listen')->willReturn(true);

        $forkCalled = 0;
        $this->forkWrapper->method('fork')->willReturnCallback(function () use (&$forkCalled): int {
            $forkCalled++;
            return 100 + $forkCalled;
        });

        $this->forkWrapper->method('kill')->willReturn(true);
        $this->forkWrapper->method('waitpid')->willReturn(0);

        $master = new SharedSocketMaster(
            config: $config,
            serverConfig: $serverConfig,
            socketWrapper: $this->socketWrapper,
            forkWrapper: $this->forkWrapper,
            workerCallback: $this->callback,
            logger: $this->logger,
        );

        $runMethod = new ReflectionMethod($master, 'run');

        $signalManager = new ReflectionProperty($master, 'signalManager');
        $signalManager->getValue($master)->requestShutdown();

        $installSigchld = new ReflectionMethod(AbstractMaster::class, 'installSigchldHandler');
        $installSigchld->invoke($master);

        $uninstallSigchld = new ReflectionMethod(AbstractMaster::class, 'uninstallSigchldHandler');
        $uninstallSigchld->invoke($master);

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function create_reuse_port_socket_throws_on_create_failure(): void
    {
        $serverConfig = new ServerConfig(host: '127.0.0.1', port: 9999);
        $config = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 1,
            autoRestart: false,
        );

        $this->socketWrapper->method('create')->willReturn(false);

        $this->forkWrapper->method('fork')->willReturnCallback(function (): int {
            static $call = 0;
            $call++;
            if (1 === $call) {
                return 0;
            }
            return 100;
        });

        $master = new SharedSocketMaster(
            config: $config,
            serverConfig: $serverConfig,
            socketWrapper: $this->socketWrapper,
            forkWrapper: $this->forkWrapper,
            workerCallback: $this->callback,
            logger: $this->logger,
        );

        $spawnWorker = new ReflectionMethod($master, 'spawnWorker');

        try {
            $spawnWorker->invoke($master, 1);
        } catch (WorkerPoolException $e) {
            $this->assertStringContainsString('Failed to create socket', $e->getMessage());
        }
    }

    #[Test]
    public function create_reuse_port_socket_throws_on_reuseaddr_failure(): void
    {
        $serverConfig = new ServerConfig(host: '127.0.0.1', port: 9999);
        $config = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 1,
            autoRestart: false,
        );

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $callCount = 0;
        $this->socketWrapper->method('create')->willReturn($socket);
        $this->socketWrapper->method('setOption')->willReturnCallback(function (Socket $s, int $level, int $name, mixed $value) use (&$callCount): bool {
            $callCount++;
            if (1 === $callCount) {
                return false;
            }
            return true;
        });

        $this->forkWrapper->method('fork')->willReturn(0);

        $master = new SharedSocketMaster(
            config: $config,
            serverConfig: $serverConfig,
            socketWrapper: $this->socketWrapper,
            forkWrapper: $this->forkWrapper,
            workerCallback: $this->callback,
            logger: $this->logger,
        );

        $spawnWorker = new ReflectionMethod($master, 'spawnWorker');

        try {
            $spawnWorker->invoke($master, 1);
        } catch (WorkerPoolException $e) {
            $this->assertStringContainsString('Failed to set SO_REUSEADDR', $e->getMessage());
        }
    }

    #[Test]
    public function create_reuse_port_socket_throws_on_reuseport_failure(): void
    {
        $serverConfig = new ServerConfig(host: '127.0.0.1', port: 9999);
        $config = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 1,
            autoRestart: false,
        );

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $callCount = 0;
        $this->socketWrapper->method('create')->willReturn($socket);
        $this->socketWrapper->method('setOption')->willReturnCallback(function (Socket $s, int $level, int $name, mixed $value) use (&$callCount): bool {
            $callCount++;
            if (2 === $callCount) {
                return false;
            }
            return true;
        });

        $this->forkWrapper->method('fork')->willReturn(0);

        $master = new SharedSocketMaster(
            config: $config,
            serverConfig: $serverConfig,
            socketWrapper: $this->socketWrapper,
            forkWrapper: $this->forkWrapper,
            logger: $this->logger,
            workerCallback: $this->callback,
        );

        $spawnWorker = new ReflectionMethod($master, 'spawnWorker');

        try {
            $spawnWorker->invoke($master, 1);
        } catch (WorkerPoolException $e) {
            $this->assertStringContainsString('Failed to set SO_REUSEPORT', $e->getMessage());
        }
    }

    #[Test]
    public function create_reuse_port_socket_throws_on_bind_failure(): void
    {
        $serverConfig = new ServerConfig(host: '127.0.0.1', port: 9999);
        $config = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 1,
            autoRestart: false,
        );

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->socketWrapper->method('create')->willReturn($socket);
        $this->socketWrapper->method('setOption')->willReturn(true);
        $this->socketWrapper->method('bind')->willReturn(false);
        $this->socketWrapper->method('lastError')->willReturn(1);
        $this->socketWrapper->method('strerror')->willReturn('Error');

        $this->forkWrapper->method('fork')->willReturn(0);

        $master = new SharedSocketMaster(
            config: $config,
            serverConfig: $serverConfig,
            socketWrapper: $this->socketWrapper,
            forkWrapper: $this->forkWrapper,
            workerCallback: $this->callback,
            logger: $this->logger,
        );

        $spawnWorker = new ReflectionMethod($master, 'spawnWorker');

        try {
            $spawnWorker->invoke($master, 1);
        } catch (WorkerPoolException $e) {
            $this->assertStringContainsString('Failed to bind socket', $e->getMessage());
        }
    }

    #[Test]
    public function create_reuse_port_socket_throws_on_listen_failure(): void
    {
        $serverConfig = new ServerConfig(host: '127.0.0.1', port: 9999);
        $config = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 1,
            autoRestart: false,
        );

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->socketWrapper->method('create')->willReturn($socket);
        $this->socketWrapper->method('setOption')->willReturn(true);
        $this->socketWrapper->method('bind')->willReturn(true);
        $this->socketWrapper->method('listen')->willReturn(false);
        $this->socketWrapper->method('lastError')->willReturn(1);
        $this->socketWrapper->method('strerror')->willReturn('Error');

        $this->forkWrapper->method('fork')->willReturn(0);

        $master = new SharedSocketMaster(
            config: $config,
            serverConfig: $serverConfig,
            socketWrapper: $this->socketWrapper,
            forkWrapper: $this->forkWrapper,
            workerCallback: $this->callback,
            logger: $this->logger,
        );

        $spawnWorker = new ReflectionMethod($master, 'spawnWorker');

        try {
            $spawnWorker->invoke($master, 1);
        } catch (WorkerPoolException $e) {
            $this->assertStringContainsString('Failed to listen', $e->getMessage());
        }
    }
}

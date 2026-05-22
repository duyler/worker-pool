<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\Master;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\Test;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\WorkerPool\Config\WorkerPoolConfig;
use Duyler\WorkerPool\Master\SharedSocketMaster;
use Duyler\WorkerPool\Process\ForkWrapper;
use Duyler\WorkerPool\Socket\SocketWrapper;
use Duyler\WorkerPool\Worker\WorkerCallbackInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Duyler\WorkerPool\Master\WorkerManager;
use Duyler\WorkerPool\Signal\SignalHandler;
use Duyler\WorkerPool\Signal\SignalManager;

#[Group('pcntl')]
#[CoversClass(SharedSocketMaster::class)]
#[UsesClass(WorkerPoolConfig::class)]
#[UsesClass(WorkerManager::class)]
#[UsesClass(SignalHandler::class)]
#[UsesClass(SignalManager::class)]
class SharedSocketMasterExtendedTest extends TestCase
{
    private WorkerPoolConfig $config;
    private ServerConfig $serverConfig;
    private SocketWrapper $socketWrapper;
    private ForkWrapper $forkWrapper;

    protected function setUp(): void
    {
        $this->serverConfig = new ServerConfig(
            host: '127.0.0.1',
            port: 8080,
        );

        $this->config = new WorkerPoolConfig(
            serverConfig: $this->serverConfig,
            workerCount: 1,
            autoRestart: false,
        );

        $this->socketWrapper = new SocketWrapper();
        $this->forkWrapper = new ForkWrapper();
    }

    #[Test]
    public function throws_exception_when_neither_worker_callback_nor_event_driven_worker_provided(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Either workerCallback or eventDrivenWorker must be provided');

        new SharedSocketMaster($this->config, $this->serverConfig, $this->socketWrapper, $this->forkWrapper);
    }

    #[Test]
    public function creates_master_with_worker_callback(): void
    {
        $workerCallback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void {}
        };

        $master = new SharedSocketMaster(
            $this->config,
            $this->serverConfig,
            $this->socketWrapper,
            $this->forkWrapper,
            workerCallback: $workerCallback,
        );

        $this->assertInstanceOf(SharedSocketMaster::class, $master);
        $this->assertSame(0, $master->getWorkerCount());
    }

    #[Test]
    public function get_metrics_returns_correct_structure(): void
    {
        $workerCallback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void {}
        };

        $master = new SharedSocketMaster(
            $this->config,
            $this->serverConfig,
            $this->socketWrapper,
            $this->forkWrapper,
            workerCallback: $workerCallback,
        );

        $metrics = $master->getMetrics();

        $this->assertIsArray($metrics);
        $this->assertArrayHasKey('architecture', $metrics);
        $this->assertArrayHasKey('total_workers', $metrics);
        $this->assertArrayHasKey('active_workers', $metrics);
        $this->assertArrayHasKey('total_connections', $metrics);
        $this->assertArrayHasKey('is_running', $metrics);
        $this->assertSame('shared_socket', $metrics['architecture']);
        $this->assertSame(0, $metrics['total_workers']);
        $this->assertSame(0, $metrics['active_workers']);
        $this->assertSame(0, $metrics['total_connections']);
        $this->assertTrue($metrics['is_running']);
    }

    #[Test]
    public function stop_changes_running_state(): void
    {
        $workerCallback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void {}
        };

        $master = new SharedSocketMaster(
            $this->config,
            $this->serverConfig,
            $this->socketWrapper,
            $this->forkWrapper,
            workerCallback: $workerCallback,
        );

        $this->assertTrue($master->isRunning());

        $master->stop();

        $this->assertFalse($master->isRunning());
    }

    #[Test]
    public function is_running_returns_true_initially(): void
    {
        $workerCallback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void {}
        };

        $master = new SharedSocketMaster(
            $this->config,
            $this->serverConfig,
            $this->socketWrapper,
            $this->forkWrapper,
            workerCallback: $workerCallback,
        );

        $this->assertTrue($master->isRunning());
    }

    #[Test]
    public function get_workers_returns_empty_array_initially(): void
    {
        $workerCallback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void {}
        };

        $master = new SharedSocketMaster(
            $this->config,
            $this->serverConfig,
            $this->socketWrapper,
            $this->forkWrapper,
            workerCallback: $workerCallback,
        );

        $workers = $master->getWorkers();
        $this->assertIsArray($workers);
        $this->assertEmpty($workers);
    }

    #[Test]
    public function creates_master_with_logger(): void
    {
        $logger = $this->createStub(LoggerInterface::class);

        $workerCallback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void {}
        };

        $master = new SharedSocketMaster(
            $this->config,
            $this->serverConfig,
            $this->socketWrapper,
            $this->forkWrapper,
            workerCallback: $workerCallback,
            logger: $logger,
        );

        $this->assertInstanceOf(SharedSocketMaster::class, $master);
    }

    #[Test]
    public function handles_multiple_workers(): void
    {
        $serverConfig = new ServerConfig(
            host: '127.0.0.1',
            port: 8080,
        );

        $config = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 4,
            autoRestart: false,
        );

        $workerCallback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void {}
        };

        $master = new SharedSocketMaster(
            $config,
            $serverConfig,
            $this->socketWrapper,
            $this->forkWrapper,
            workerCallback: $workerCallback,
        );

        $this->assertInstanceOf(SharedSocketMaster::class, $master);
    }

    #[Test]
    public function handles_auto_restart_enabled(): void
    {
        $serverConfig = new ServerConfig(
            host: '127.0.0.1',
            port: 8080,
        );

        $config = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 2,
            autoRestart: true,
            restartDelay: 1,
        );

        $workerCallback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void {}
        };

        $master = new SharedSocketMaster(
            $config,
            $serverConfig,
            $this->socketWrapper,
            $this->forkWrapper,
            workerCallback: $workerCallback,
        );

        $this->assertInstanceOf(SharedSocketMaster::class, $master);
    }

    #[Test]
    public function handles_poll_interval_config(): void
    {
        $serverConfig = new ServerConfig(
            host: '127.0.0.1',
            port: 8080,
        );

        $config = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 1,
            autoRestart: false,
            pollInterval: 10000,
        );

        $workerCallback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void {}
        };

        $master = new SharedSocketMaster(
            $config,
            $serverConfig,
            $this->socketWrapper,
            $this->forkWrapper,
            workerCallback: $workerCallback,
        );

        $this->assertInstanceOf(SharedSocketMaster::class, $master);
    }

    #[Test]
    public function handles_max_queue_size_config(): void
    {
        $serverConfig = new ServerConfig(
            host: '127.0.0.1',
            port: 8080,
        );

        $config = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 1,
            autoRestart: false,
            maxQueueSize: 2000,
        );

        $workerCallback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void {}
        };

        $master = new SharedSocketMaster(
            $config,
            $serverConfig,
            $this->socketWrapper,
            $this->forkWrapper,
            workerCallback: $workerCallback,
        );

        $this->assertInstanceOf(SharedSocketMaster::class, $master);
    }

    #[Test]
    public function handles_max_ipc_message_size_config(): void
    {
        $serverConfig = new ServerConfig(
            host: '127.0.0.1',
            port: 8080,
        );

        $config = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 1,
            autoRestart: false,
            maxIpcMessageSize: 2097152,
        );

        $workerCallback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void {}
        };

        $master = new SharedSocketMaster(
            $config,
            $serverConfig,
            $this->socketWrapper,
            $this->forkWrapper,
            workerCallback: $workerCallback,
        );

        $this->assertInstanceOf(SharedSocketMaster::class, $master);
    }
}

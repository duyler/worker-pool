<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\Master;

use PHPUnit\Framework\Attributes\CoversClass;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\WorkerPool\Config\WorkerPoolConfig;
use Duyler\WorkerPool\Master\SharedSocketMaster;
use Duyler\WorkerPool\Worker\WorkerCallbackInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[Group('pcntl')]
#[CoversClass(SharedSocketMaster::class)]
class SharedSocketMasterExtendedTest extends TestCase
{
    private WorkerPoolConfig $config;
    private ServerConfig $serverConfig;

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
    }

    public function testThrowsExceptionWhenNeitherWorkerCallbackNorEventDrivenWorkerProvided(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Either workerCallback or eventDrivenWorker must be provided');

        new SharedSocketMaster($this->config, $this->serverConfig);
    }

    public function testCreatesMasterWithWorkerCallback(): void
    {
        $workerCallback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void {}
        };

        $master = new SharedSocketMaster(
            $this->config,
            $this->serverConfig,
            workerCallback: $workerCallback,
        );

        $this->assertInstanceOf(SharedSocketMaster::class, $master);
        $this->assertSame(0, $master->getWorkerCount());
    }

    public function testGetMetricsReturnsCorrectStructure(): void
    {
        $workerCallback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void {}
        };

        $master = new SharedSocketMaster(
            $this->config,
            $this->serverConfig,
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

    public function testStopChangesRunningState(): void
    {
        $workerCallback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void {}
        };

        $master = new SharedSocketMaster(
            $this->config,
            $this->serverConfig,
            workerCallback: $workerCallback,
        );

        $this->assertTrue($master->isRunning());

        $master->stop();

        $this->assertFalse($master->isRunning());
    }

    public function testIsRunningReturnsTrueInitially(): void
    {
        $workerCallback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void {}
        };

        $master = new SharedSocketMaster(
            $this->config,
            $this->serverConfig,
            workerCallback: $workerCallback,
        );

        $this->assertTrue($master->isRunning());
    }

    public function testGetWorkersReturnsEmptyArrayInitially(): void
    {
        $workerCallback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void {}
        };

        $master = new SharedSocketMaster(
            $this->config,
            $this->serverConfig,
            workerCallback: $workerCallback,
        );

        $workers = $master->getWorkers();
        $this->assertIsArray($workers);
        $this->assertEmpty($workers);
    }

    public function testCreatesMasterWithLogger(): void
    {
        $logger = $this->createMock(LoggerInterface::class);

        $workerCallback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void {}
        };

        $master = new SharedSocketMaster(
            $this->config,
            $this->serverConfig,
            workerCallback: $workerCallback,
            logger: $logger,
        );

        $this->assertInstanceOf(SharedSocketMaster::class, $master);
    }

    public function testHandlesMultipleWorkers(): void
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
            workerCallback: $workerCallback,
        );

        $this->assertInstanceOf(SharedSocketMaster::class, $master);
    }

    public function testHandlesAutoRestartEnabled(): void
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
            workerCallback: $workerCallback,
        );

        $this->assertInstanceOf(SharedSocketMaster::class, $master);
    }

    public function testHandlesPollIntervalConfig(): void
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
            workerCallback: $workerCallback,
        );

        $this->assertInstanceOf(SharedSocketMaster::class, $master);
    }

    public function testHandlesMaxQueueSizeConfig(): void
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
            workerCallback: $workerCallback,
        );

        $this->assertInstanceOf(SharedSocketMaster::class, $master);
    }

    public function testHandlesMaxIpcMessageSizeConfig(): void
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
            workerCallback: $workerCallback,
        );

        $this->assertInstanceOf(SharedSocketMaster::class, $master);
    }
}

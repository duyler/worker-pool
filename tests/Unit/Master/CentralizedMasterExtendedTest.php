<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\Master;

use PHPUnit\Framework\Attributes\CoversClass;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\WorkerPool\Balancer\LeastConnectionsBalancer;
use Duyler\WorkerPool\Config\WorkerPoolConfig;
use Duyler\WorkerPool\Master\CentralizedMaster;
use Duyler\WorkerPool\Process\ForkWrapper;
use Duyler\WorkerPool\Socket\SocketMsgWrapper;
use Duyler\WorkerPool\Socket\SocketWrapper;
use Duyler\WorkerPool\Worker\WorkerCallbackInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[Group('pcntl')]
#[CoversClass(CentralizedMaster::class)]
class CentralizedMasterExtendedTest extends TestCase
{
    private WorkerPoolConfig $config;
    private LeastConnectionsBalancer $balancer;
    private SocketWrapper $socketWrapper;
    private SocketMsgWrapper $socketMsgWrapper;
    private ForkWrapper $forkWrapper;

    protected function setUp(): void
    {
        $serverConfig = new ServerConfig(
            host: '127.0.0.1',
            port: 8080,
        );

        $this->config = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 1,
            autoRestart: false,
        );

        $this->balancer = new LeastConnectionsBalancer();
        $this->socketWrapper = new SocketWrapper();
        $this->socketMsgWrapper = new SocketMsgWrapper();
        $this->forkWrapper = new ForkWrapper();
    }

    public function testThrowsExceptionWhenNeitherWorkerCallbackNorEventDrivenWorkerProvided(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Either workerCallback or eventDrivenWorker must be provided');

        new CentralizedMaster($this->config, $this->balancer, $this->socketWrapper, $this->socketMsgWrapper, $this->forkWrapper);
    }

    public function testCreatesMasterWithWorkerCallback(): void
    {
        $workerCallback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void {}
        };

        $master = new CentralizedMaster(
            $this->config,
            $this->balancer,
            $this->socketWrapper,
            $this->socketMsgWrapper,
            $this->forkWrapper,
            workerCallback: $workerCallback,
        );

        $this->assertInstanceOf(CentralizedMaster::class, $master);
        $this->assertSame(0, $master->getWorkerCount());
    }

    public function testGetBalancerReturnsCorrectInstance(): void
    {
        $workerCallback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void {}
        };

        $master = new CentralizedMaster(
            $this->config,
            $this->balancer,
            $this->socketWrapper,
            $this->socketMsgWrapper,
            $this->forkWrapper,
            workerCallback: $workerCallback,
        );

        $balancer = $master->getBalancer();
        $this->assertInstanceOf(LeastConnectionsBalancer::class, $balancer);
        $this->assertSame($this->balancer, $balancer);
    }

    public function testGetMetricsReturnsCorrectStructure(): void
    {
        $workerCallback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void {}
        };

        $master = new CentralizedMaster(
            $this->config,
            $this->balancer,
            $this->socketWrapper,
            $this->socketMsgWrapper,
            $this->forkWrapper,
            workerCallback: $workerCallback,
        );

        $metrics = $master->getMetrics();

        $this->assertIsArray($metrics);
        $this->assertArrayHasKey('total_workers', $metrics);
        $this->assertArrayHasKey('alive_workers', $metrics);
        $this->assertArrayHasKey('total_connections', $metrics);
        $this->assertArrayHasKey('total_requests', $metrics);
        $this->assertArrayHasKey('queue_size', $metrics);
        $this->assertArrayHasKey('is_running', $metrics);
        $this->assertSame(1, $metrics['total_workers']);
        $this->assertSame(0, $metrics['alive_workers']);
        $this->assertSame(0, $metrics['total_connections']);
        $this->assertSame(0, $metrics['total_requests']);
        $this->assertTrue($metrics['is_running']);
    }

    public function testStopChangesRunningState(): void
    {
        $workerCallback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void {}
        };

        $master = new CentralizedMaster(
            $this->config,
            $this->balancer,
            $this->socketWrapper,
            $this->socketMsgWrapper,
            $this->forkWrapper,
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

        $master = new CentralizedMaster(
            $this->config,
            $this->balancer,
            $this->socketWrapper,
            $this->socketMsgWrapper,
            $this->forkWrapper,
            workerCallback: $workerCallback,
        );

        $this->assertTrue($master->isRunning());
    }

    public function testGetWorkersReturnsEmptyArrayInitially(): void
    {
        $workerCallback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void {}
        };

        $master = new CentralizedMaster(
            $this->config,
            $this->balancer,
            $this->socketWrapper,
            $this->socketMsgWrapper,
            $this->forkWrapper,
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

        $master = new CentralizedMaster(
            $this->config,
            $this->balancer,
            $this->socketWrapper,
            $this->socketMsgWrapper,
            $this->forkWrapper,
            workerCallback: $workerCallback,
            logger: $logger,
        );

        $this->assertInstanceOf(CentralizedMaster::class, $master);
    }

    public function testHandlesMaxConnectionsConfig(): void
    {
        $serverConfig = new ServerConfig(
            host: '127.0.0.1',
            port: 8080,
            maxConnections: 500,
        );

        $config = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 2,
            autoRestart: false,
        );

        $workerCallback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void {}
        };

        $master = new CentralizedMaster(
            $config,
            $this->balancer,
            $this->socketWrapper,
            $this->socketMsgWrapper,
            $this->forkWrapper,
            serverConfig: $serverConfig,
            workerCallback: $workerCallback,
        );

        $this->assertInstanceOf(CentralizedMaster::class, $master);
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
            pollInterval: 5000,
        );

        $workerCallback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void {}
        };

        $master = new CentralizedMaster(
            $config,
            $this->balancer,
            $this->socketWrapper,
            $this->socketMsgWrapper,
            $this->forkWrapper,
            serverConfig: $serverConfig,
            workerCallback: $workerCallback,
        );

        $this->assertInstanceOf(CentralizedMaster::class, $master);
    }

    public function testHandlesRestartDelayConfig(): void
    {
        $serverConfig = new ServerConfig(
            host: '127.0.0.1',
            port: 8080,
        );

        $config = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 1,
            autoRestart: true,
            restartDelay: 5,
        );

        $workerCallback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void {}
        };

        $master = new CentralizedMaster(
            $config,
            $this->balancer,
            $this->socketWrapper,
            $this->socketMsgWrapper,
            $this->forkWrapper,
            serverConfig: $serverConfig,
            workerCallback: $workerCallback,
        );

        $this->assertInstanceOf(CentralizedMaster::class, $master);
    }
}

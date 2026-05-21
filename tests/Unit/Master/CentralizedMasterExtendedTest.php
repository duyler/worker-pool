<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\Master;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

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

    #[Test]
    public function throws_exception_when_neither_worker_callback_nor_event_driven_worker_provided(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Either workerCallback or eventDrivenWorker must be provided');

        new CentralizedMaster($this->config, $this->balancer, $this->socketWrapper, $this->socketMsgWrapper, $this->forkWrapper);
    }

    #[Test]
    public function creates_master_with_worker_callback(): void
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

    #[Test]
    public function get_balancer_returns_correct_instance(): void
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

    #[Test]
    public function get_metrics_returns_correct_structure(): void
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

    #[Test]
    public function stop_changes_running_state(): void
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

    #[Test]
    public function is_running_returns_true_initially(): void
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

    #[Test]
    public function get_workers_returns_empty_array_initially(): void
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

    #[Test]
    public function creates_master_with_logger(): void
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

    #[Test]
    public function handles_max_connections_config(): void
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

    #[Test]
    public function handles_restart_delay_config(): void
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

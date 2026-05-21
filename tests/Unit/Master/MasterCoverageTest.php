<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\Master;

use PHPUnit\Framework\Attributes\CoversClass;
use Duyler\WorkerPool\Master\AbstractMaster;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\WorkerPool\Balancer\LeastConnectionsBalancer;
use Duyler\WorkerPool\Balancer\RoundRobinBalancer;
use Duyler\WorkerPool\Config\WorkerPoolConfig;
use Duyler\WorkerPool\Master\CentralizedMaster;
use Duyler\WorkerPool\Master\SharedSocketMaster;
use Duyler\WorkerPool\Process\ForkWrapper;
use Duyler\WorkerPool\Process\ProcessInfo;
use Duyler\WorkerPool\Process\ProcessState;
use Duyler\WorkerPool\Socket\SocketMsgWrapper;
use Duyler\WorkerPool\Socket\SocketWrapper;
use Duyler\WorkerPool\Worker\WorkerCallbackInterface;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionProperty;

#[CoversClass(AbstractMaster::class)]
final class MasterCoverageTest extends TestCase
{
    private ServerConfig $serverConfig;
    private WorkerPoolConfig $config;
    private SocketWrapper $socketWrapper;
    private SocketMsgWrapper $socketMsgWrapper;
    private ForkWrapper $forkWrapper;

    #[Override]
    protected function setUp(): void
    {
        $this->serverConfig = new ServerConfig(host: '127.0.0.1', port: 19876);
        $this->config = new WorkerPoolConfig(
            serverConfig: $this->serverConfig,
            workerCount: 2,
        );
        $this->socketWrapper = new SocketWrapper();
        $this->socketMsgWrapper = new SocketMsgWrapper();
        $this->forkWrapper = new ForkWrapper();
    }

    #[Test]
    public function centralizedMasterGetMetricsWithWorkers(): void
    {
        $callback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void {}
        };

        $balancer = new RoundRobinBalancer(2);
        $logger = $this->createMock(LoggerInterface::class);

        $master = new CentralizedMaster(
            config: $this->config,
            balancer: $balancer,
            socketWrapper: $this->socketWrapper,
            socketMsgWrapper: $this->socketMsgWrapper,
            forkWrapper: $this->forkWrapper,
            serverConfig: $this->serverConfig,
            workerCallback: $callback,
            logger: $logger,
        );

        $ref = new ReflectionProperty($master, 'workers');
        $ref->setValue($master, [
            1 => new ProcessInfo(1, getmypid(), ProcessState::Ready, $this->forkWrapper, 5, 10),
            2 => new ProcessInfo(2, 999999, ProcessState::Stopped, $this->forkWrapper, 0, 0),
        ]);

        $metrics = $master->getMetrics();

        $this->assertSame(2, $metrics['total_workers']);
        $this->assertSame(1, $metrics['alive_workers']);
        $this->assertSame(5, $metrics['total_connections']);
        $this->assertSame(10, $metrics['total_requests']);
        $this->assertArrayHasKey('is_running', $metrics);
    }

    #[Test]
    public function centralizedMasterGetBalancer(): void
    {
        $callback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void {}
        };

        $balancer = new LeastConnectionsBalancer();
        $master = new CentralizedMaster(
            config: $this->config,
            balancer: $balancer,
            socketWrapper: $this->socketWrapper,
            socketMsgWrapper: $this->socketMsgWrapper,
            forkWrapper: $this->forkWrapper,
            serverConfig: $this->serverConfig,
            workerCallback: $callback,
        );

        $this->assertSame($balancer, $master->getBalancer());
    }

    #[Test]
    public function sharedSocketMasterGetMetricsWithWorkers(): void
    {
        $callback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void {}
        };

        $master = new SharedSocketMaster(
            config: $this->config,
            serverConfig: $this->serverConfig,
            socketWrapper: $this->socketWrapper,
            forkWrapper: $this->forkWrapper,
            workerCallback: $callback,
        );

        $ref = new ReflectionProperty($master, 'workers');
        $ref->setValue($master, [
            1 => new ProcessInfo(1, getmypid(), ProcessState::Ready, $this->forkWrapper, 3),
            2 => new ProcessInfo(2, getmypid(), ProcessState::Busy, $this->forkWrapper, 7),
        ]);

        $metrics = $master->getMetrics();

        $this->assertSame('shared_socket', $metrics['architecture']);
        $this->assertSame(2, $metrics['total_workers']);
        $this->assertSame(2, $metrics['active_workers']);
        $this->assertSame(10, $metrics['total_connections']);
        $this->assertTrue($metrics['is_running']);
    }

    #[Test]
    public function centralizedMasterStopSetsShouldStop(): void
    {
        $callback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void {}
        };

        $master = new CentralizedMaster(
            config: $this->config,
            balancer: new RoundRobinBalancer(1),
            socketWrapper: $this->socketWrapper,
            socketMsgWrapper: $this->socketMsgWrapper,
            forkWrapper: $this->forkWrapper,
            serverConfig: $this->serverConfig,
            workerCallback: $callback,
        );

        $this->assertTrue($master->isRunning());
        $master->stop();
        $this->assertFalse($master->isRunning());
    }

    #[Test]
    public function sharedSocketMasterStopSetsShouldStop(): void
    {
        $callback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void {}
        };

        $master = new SharedSocketMaster(
            config: $this->config,
            serverConfig: $this->serverConfig,
            socketWrapper: $this->socketWrapper,
            forkWrapper: $this->forkWrapper,
            workerCallback: $callback,
        );

        $this->assertTrue($master->isRunning());
        $master->stop();
        $this->assertFalse($master->isRunning());
    }

    #[Test]
    public function centralizedMasterGetWorkerCount(): void
    {
        $callback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void {}
        };

        $master = new CentralizedMaster(
            config: $this->config,
            balancer: new RoundRobinBalancer(1),
            socketWrapper: $this->socketWrapper,
            socketMsgWrapper: $this->socketMsgWrapper,
            forkWrapper: $this->forkWrapper,
            serverConfig: $this->serverConfig,
            workerCallback: $callback,
        );

        $this->assertSame(0, $master->getWorkerCount());

        $ref = new ReflectionProperty($master, 'workers');
        $ref->setValue($master, [
            1 => new ProcessInfo(1, getmypid(), ProcessState::Ready, $this->forkWrapper),
        ]);

        $this->assertSame(1, $master->getWorkerCount());
    }

    #[Test]
    public function centralizedMasterGetWorkers(): void
    {
        $callback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void {}
        };

        $master = new CentralizedMaster(
            config: $this->config,
            balancer: new RoundRobinBalancer(1),
            socketWrapper: $this->socketWrapper,
            socketMsgWrapper: $this->socketMsgWrapper,
            forkWrapper: $this->forkWrapper,
            serverConfig: $this->serverConfig,
            workerCallback: $callback,
        );

        $workers = $master->getWorkers();
        $this->assertIsArray($workers);
    }
}

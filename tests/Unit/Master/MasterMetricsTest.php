<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\Master;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\Test;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\WorkerPool\Balancer\LeastConnectionsBalancer;
use Duyler\WorkerPool\Config\WorkerPoolConfig;
use Duyler\WorkerPool\Master\CentralizedMaster;
use Duyler\WorkerPool\Master\SharedSocketMaster;
use Duyler\WorkerPool\Process\ForkWrapper;
use Duyler\WorkerPool\Socket\SocketMsgWrapper;
use Duyler\WorkerPool\Socket\SocketWrapper;
use Duyler\WorkerPool\Worker\WorkerCallbackInterface;
use Override;
use PHPUnit\Framework\TestCase;
use Duyler\WorkerPool\IPC\FdPasser;
use Duyler\WorkerPool\Master\ConnectionRouter;
use Duyler\WorkerPool\Master\WorkerManager;
use Duyler\WorkerPool\Signal\SignalHandler;
use Duyler\WorkerPool\Signal\SignalManager;

#[CoversClass(SharedSocketMaster::class)]
#[CoversClass(CentralizedMaster::class)]
#[UsesClass(WorkerPoolConfig::class)]
#[UsesClass(FdPasser::class)]
#[UsesClass(ConnectionRouter::class)]
#[UsesClass(WorkerManager::class)]
#[UsesClass(SignalHandler::class)]
#[UsesClass(SignalManager::class)]
final class MasterMetricsTest extends TestCase
{
    private WorkerPoolConfig $config;
    private ServerConfig $serverConfig;
    private WorkerCallbackInterface $callback;
    private SocketWrapper $socketWrapper;
    private SocketMsgWrapper $socketMsgWrapper;
    private ForkWrapper $forkWrapper;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->serverConfig = new ServerConfig(
            host: '127.0.0.1',
            port: 9999,
        );

        $this->config = new WorkerPoolConfig(
            serverConfig: $this->serverConfig,
            workerCount: 2,
            autoRestart: false,
        );

        $this->callback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void
            {
                socket_close($clientSocket);
            }
        };

        $this->socketWrapper = new SocketWrapper();
        $this->socketMsgWrapper = new SocketMsgWrapper();
        $this->forkWrapper = new ForkWrapper();
    }

    #[Test]
    public function centralized_master_returns_metrics(): void
    {
        $balancer = new LeastConnectionsBalancer();
        $master = new CentralizedMaster(
            config: $this->config,
            balancer: $balancer,
            socketWrapper: $this->socketWrapper,
            socketMsgWrapper: $this->socketMsgWrapper,
            forkWrapper: $this->forkWrapper,
            workerCallback: $this->callback,
        );

        $metrics = $master->getMetrics();

        $this->assertIsArray($metrics);
        $this->assertArrayHasKey('total_workers', $metrics);
        $this->assertArrayHasKey('alive_workers', $metrics);
        $this->assertArrayHasKey('total_connections', $metrics);
        $this->assertArrayHasKey('total_requests', $metrics);
        $this->assertArrayHasKey('queue_size', $metrics);
        $this->assertArrayHasKey('is_running', $metrics);

        $this->assertSame(2, $metrics['total_workers']);
        $this->assertSame(0, $metrics['alive_workers']);
        $this->assertSame(0, $metrics['total_connections']);
        $this->assertSame(0, $metrics['total_requests']);
        $this->assertSame(0, $metrics['queue_size']);
        $this->assertTrue($metrics['is_running']);
    }

    #[Test]
    public function shared_socket_master_returns_metrics(): void
    {
        $master = new SharedSocketMaster(
            config: $this->config,
            serverConfig: $this->serverConfig,
            socketWrapper: $this->socketWrapper,
            forkWrapper: $this->forkWrapper,
            workerCallback: $this->callback,
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
    public function metrics_include_architecture_info(): void
    {
        $balancer = new LeastConnectionsBalancer();
        $centralizedMaster = new CentralizedMaster(
            config: $this->config,
            balancer: $balancer,
            socketWrapper: $this->socketWrapper,
            socketMsgWrapper: $this->socketMsgWrapper,
            forkWrapper: $this->forkWrapper,
            workerCallback: $this->callback,
        );

        $sharedSocketMaster = new SharedSocketMaster(
            config: $this->config,
            serverConfig: $this->serverConfig,
            socketWrapper: $this->socketWrapper,
            forkWrapper: $this->forkWrapper,
            workerCallback: $this->callback,
        );

        $centralizedMetrics = $centralizedMaster->getMetrics();
        $sharedSocketMetrics = $sharedSocketMaster->getMetrics();

        $this->assertArrayNotHasKey('architecture', $centralizedMetrics);
        $this->assertArrayHasKey('architecture', $sharedSocketMetrics);
        $this->assertSame('shared_socket', $sharedSocketMetrics['architecture']);
    }

    #[Test]
    public function metrics_reflect_running_state(): void
    {
        $balancer = new LeastConnectionsBalancer();
        $master = new CentralizedMaster(
            config: $this->config,
            balancer: $balancer,
            socketWrapper: $this->socketWrapper,
            socketMsgWrapper: $this->socketMsgWrapper,
            forkWrapper: $this->forkWrapper,
            workerCallback: $this->callback,
        );

        $this->assertTrue($master->isRunning());

        $metrics = $master->getMetrics();
        $this->assertTrue($metrics['is_running']);

        $master->stop();

        $this->assertFalse($master->isRunning());
        $metrics = $master->getMetrics();
        $this->assertFalse($metrics['is_running']);
    }
}

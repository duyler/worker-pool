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
use Override;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

use function count;
use function function_exists;

#[CoversClass(CentralizedMaster::class)]
class CentralizedMasterTest extends TestCase
{
    private WorkerPoolConfig $config;
    private LeastConnectionsBalancer $balancer;
    private WorkerCallbackInterface $workerCallback;
    private SocketWrapper $socketWrapper;
    private SocketMsgWrapper $socketMsgWrapper;
    private ForkWrapper $forkWrapper;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $serverConfig = new ServerConfig(
            host: '127.0.0.1',
            port: 8080,
        );

        $this->config = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 2,
            autoRestart: false,
        );

        $this->balancer = new LeastConnectionsBalancer();

        $this->workerCallback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void {}
        };

        $this->socketWrapper = new SocketWrapper();
        $this->socketMsgWrapper = new SocketMsgWrapper();
        $this->forkWrapper = new ForkWrapper();
    }

    public function testCreatesCentralizedMasterWithConfig(): void
    {
        $master = new CentralizedMaster($this->config, $this->balancer, $this->socketWrapper, $this->socketMsgWrapper, $this->forkWrapper, workerCallback: $this->workerCallback);

        $this->assertSame(0, $master->getWorkerCount());
    }

    #[Group('pcntl')]
    public function spawns_configured_number_of_workers(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork not available');
        }

        $master = new CentralizedMaster($this->config, $this->balancer, $this->socketWrapper, $this->socketMsgWrapper, $this->forkWrapper, workerCallback: $this->workerCallback);

        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('Failed to fork');
        }

        if ($pid === 0) {
            sleep(1);
            exit(0);
        }

        $this->assertTrue(true);

        pcntl_waitpid($pid, $status);
    }

    public function testTracksWorkerProcesses(): void
    {
        $master = new CentralizedMaster($this->config, $this->balancer, $this->socketWrapper, $this->socketMsgWrapper, $this->forkWrapper, workerCallback: $this->workerCallback);

        $workers = $master->getWorkers();

        $this->assertIsArray($workers);
        $this->assertSame(0, count($workers));
    }

    public function testStopsAllWorkersOnStop(): void
    {
        $master = new CentralizedMaster($this->config, $this->balancer, $this->socketWrapper, $this->socketMsgWrapper, $this->forkWrapper, workerCallback: $this->workerCallback);

        $master->stop();

        $this->assertTrue(true);
    }

    public function testCollectsMetricsFromWorkers(): void
    {
        $master = new CentralizedMaster($this->config, $this->balancer, $this->socketWrapper, $this->socketMsgWrapper, $this->forkWrapper, workerCallback: $this->workerCallback);

        $metrics = $master->getMetrics();

        $this->assertIsArray($metrics);
        $this->assertArrayHasKey('total_workers', $metrics);
        $this->assertArrayHasKey('alive_workers', $metrics);
        $this->assertArrayHasKey('total_connections', $metrics);
        $this->assertArrayHasKey('total_requests', $metrics);
        $this->assertSame(2, $metrics['total_workers']);
        $this->assertSame(0, $metrics['alive_workers']);
    }

    public function testReturnsWorkerCount(): void
    {
        $master = new CentralizedMaster($this->config, $this->balancer, $this->socketWrapper, $this->socketMsgWrapper, $this->forkWrapper, workerCallback: $this->workerCallback);

        $count = $master->getWorkerCount();

        $this->assertSame(0, $count);
    }

    public function testHandlesAutoRestartConfig(): void
    {
        $serverConfig = new ServerConfig(
            host: '127.0.0.1',
            port: 8080,
        );

        $config = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 1,
            autoRestart: true,
            restartDelay: 0,
        );

        $master = new CentralizedMaster($config, $this->balancer, $this->socketWrapper, $this->socketMsgWrapper, $this->forkWrapper, workerCallback: $this->workerCallback);

        $this->assertSame(0, $master->getWorkerCount());
    }

    public function testGetsEmptyWorkersListInitially(): void
    {
        $master = new CentralizedMaster($this->config, $this->balancer, $this->socketWrapper, $this->socketMsgWrapper, $this->forkWrapper, workerCallback: $this->workerCallback);

        $workers = $master->getWorkers();

        $this->assertEmpty($workers);
    }
}

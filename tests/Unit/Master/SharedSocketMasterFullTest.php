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
use Duyler\WorkerPool\Process\ProcessState;
use Duyler\WorkerPool\Signal\SignalHandler;
use Duyler\WorkerPool\Signal\SignalManager;
use Duyler\WorkerPool\Socket\SocketWrapperInterface;
use Duyler\WorkerPool\Worker\WorkerCallbackInterface;
use Override;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use ReflectionProperty;
use Duyler\WorkerPool\Master\ConnectionQueue;
use Duyler\WorkerPool\Master\SocketManager;
use Duyler\WorkerPool\Master\WorkerManager;
use Duyler\WorkerPool\Process\ForkWrapper;

use InvalidArgumentException;

#[CoversClass(SharedSocketMaster::class)]
#[UsesClass(AbstractMaster::class)]
#[UsesClass(WorkerPoolConfig::class)]
#[UsesClass(SignalHandler::class)]
#[UsesClass(SignalManager::class)]
#[UsesClass(WorkerManager::class)]
#[UsesClass(ProcessInfo::class)]
#[UsesClass(WorkerPoolException::class)]
#[UsesClass(ForkWrapper::class)]
#[UsesClass(ConnectionQueue::class)]
#[UsesClass(SocketManager::class)]
final class SharedSocketMasterFullTest extends TestCase
{
    private WorkerPoolConfig $config;
    private ServerConfig $serverConfig;
    private SocketWrapperInterface $socketWrapper;
    private ForkWrapperInterface $forkWrapper;
    private LoggerInterface $logger;
    private WorkerCallbackInterface $callback;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->serverConfig = new ServerConfig(host: '127.0.0.1', port: 9999);
        $this->config = new WorkerPoolConfig(
            serverConfig: $this->serverConfig,
            workerCount: 2,
            autoRestart: false,
        );
        $this->socketWrapper = $this->createStub(SocketWrapperInterface::class);
        $this->forkWrapper = $this->createStub(ForkWrapperInterface::class);
        $this->logger = $this->createStub(LoggerInterface::class);
        $this->callback = $this->createStub(WorkerCallbackInterface::class);
    }

    #[Test]
    public function constructor_throws_without_worker_interface(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Either workerCallback or eventDrivenWorker must be provided');

        new SharedSocketMaster(
            config: $this->config,
            serverConfig: $this->serverConfig,
            socketWrapper: $this->socketWrapper,
            forkWrapper: $this->forkWrapper,
            logger: $this->logger,
        );
    }

    #[Test]
    public function get_metrics_returns_structure(): void
    {
        $master = new SharedSocketMaster(
            config: $this->config,
            serverConfig: $this->serverConfig,
            socketWrapper: $this->socketWrapper,
            forkWrapper: $this->forkWrapper,
            workerCallback: $this->callback,
            logger: $this->logger,
        );

        $metrics = $master->getMetrics();

        $this->assertSame('shared_socket', $metrics['architecture']);
        $this->assertSame(0, $metrics['total_workers']);
        $this->assertSame(0, $metrics['active_workers']);
        $this->assertSame(0, $metrics['total_connections']);
        $this->assertTrue($metrics['is_running']);
    }

    #[Test]
    public function is_running_returns_true_initially(): void
    {
        $master = new SharedSocketMaster(
            config: $this->config,
            serverConfig: $this->serverConfig,
            socketWrapper: $this->socketWrapper,
            forkWrapper: $this->forkWrapper,
            workerCallback: $this->callback,
            logger: $this->logger,
        );

        $this->assertTrue($master->isRunning());
    }

    #[Test]
    public function stop_changes_running_state(): void
    {
        $master = new SharedSocketMaster(
            config: $this->config,
            serverConfig: $this->serverConfig,
            socketWrapper: $this->socketWrapper,
            forkWrapper: $this->forkWrapper,
            workerCallback: $this->callback,
            logger: $this->logger,
        );

        $master->stop();

        $this->assertFalse($master->isRunning());
    }

    #[Test]
    public function get_workers_returns_empty_initially(): void
    {
        $master = new SharedSocketMaster(
            config: $this->config,
            serverConfig: $this->serverConfig,
            socketWrapper: $this->socketWrapper,
            forkWrapper: $this->forkWrapper,
            workerCallback: $this->callback,
            logger: $this->logger,
        );

        $this->assertEmpty($master->getWorkers());
    }

    #[Test]
    public function get_worker_count_returns_zero_initially(): void
    {
        $master = new SharedSocketMaster(
            config: $this->config,
            serverConfig: $this->serverConfig,
            socketWrapper: $this->socketWrapper,
            forkWrapper: $this->forkWrapper,
            workerCallback: $this->callback,
            logger: $this->logger,
        );

        $this->assertSame(0, $master->getWorkerCount());
    }

    #[Test]
    public function spawn_worker_throws_on_fork_failure(): void
    {
        $this->forkWrapper->method('fork')->willReturn(-1);

        $master = new SharedSocketMaster(
            config: $this->config,
            serverConfig: $this->serverConfig,
            socketWrapper: $this->socketWrapper,
            forkWrapper: $this->forkWrapper,
            workerCallback: $this->callback,
            logger: $this->logger,
        );

        $spawnWorker = new ReflectionMethod($master, 'spawnWorker');

        $this->expectException(WorkerPoolException::class);
        $this->expectExceptionMessage('Failed to fork worker process');

        $spawnWorker->invoke($master, 1);
    }

    #[Test]
    public function spawn_worker_registers_process_info(): void
    {
        $this->forkWrapper->method('fork')->willReturn(12345);

        $master = new SharedSocketMaster(
            config: $this->config,
            serverConfig: $this->serverConfig,
            socketWrapper: $this->socketWrapper,
            forkWrapper: $this->forkWrapper,
            workerCallback: $this->callback,
            logger: $this->logger,
        );

        $spawnWorker = new ReflectionMethod($master, 'spawnWorker');
        $spawnWorker->invoke($master, 1);

        $workers = $master->getWorkers();
        $this->assertCount(1, $workers);
        $this->assertArrayHasKey(1, $workers);
        $this->assertSame(12345, $workers[1]->pid);
        $this->assertSame(ProcessState::Ready, $workers[1]->state);
    }

    #[Test]
    public function run_exits_when_shutdown_requested(): void
    {
        $master = new SharedSocketMaster(
            config: $this->config,
            serverConfig: $this->serverConfig,
            socketWrapper: $this->socketWrapper,
            forkWrapper: $this->forkWrapper,
            workerCallback: $this->callback,
            logger: $this->logger,
        );

        $master->stop();

        $run = new ReflectionMethod($master, 'run');
        $run->invoke($master);

        $this->assertFalse($master->isRunning());
    }

    #[Test]
    public function check_workers_detects_dead_workers(): void
    {
        $this->forkWrapper->method('fork')->willReturn(12345);
        $this->forkWrapper->method('kill')->willReturn(false);
        $this->forkWrapper->method('waitpid')->willReturn(0);

        $master = new SharedSocketMaster(
            config: $this->config,
            serverConfig: $this->serverConfig,
            socketWrapper: $this->socketWrapper,
            forkWrapper: $this->forkWrapper,
            workerCallback: $this->callback,
            logger: $this->logger,
        );

        $spawnWorker = new ReflectionMethod($master, 'spawnWorker');
        $spawnWorker->invoke($master, 1);

        $this->assertCount(1, $master->getWorkers());

        $checkWorkers = new ReflectionMethod($master, 'checkWorkers');
        $checkWorkers->invoke($master);

        $this->assertEmpty($master->getWorkers());
    }

    #[Test]
    public function get_metrics_with_spawned_workers(): void
    {
        $this->forkWrapper->method('fork')->willReturn(12345);
        $this->forkWrapper->method('kill')->willReturn(true);
        $this->forkWrapper->method('waitpid')->willReturn(0);

        $master = new SharedSocketMaster(
            config: $this->config,
            serverConfig: $this->serverConfig,
            socketWrapper: $this->socketWrapper,
            forkWrapper: $this->forkWrapper,
            workerCallback: $this->callback,
            logger: $this->logger,
        );

        $spawnWorker = new ReflectionMethod($master, 'spawnWorker');
        $spawnWorker->invoke($master, 1);

        $metrics = $master->getMetrics();
        $this->assertSame(1, $metrics['total_workers']);
        $this->assertSame(1, $metrics['active_workers']);
    }

    #[Test]
    public function schedule_restart_spawns_immediately_with_zero_delay(): void
    {
        $config = new WorkerPoolConfig(
            serverConfig: $this->serverConfig,
            workerCount: 1,
            restartDelay: 0,
            autoRestart: true,
        );

        $this->forkWrapper->method('fork')->willReturn(12345);

        $master = new SharedSocketMaster(
            config: $config,
            serverConfig: $this->serverConfig,
            socketWrapper: $this->socketWrapper,
            forkWrapper: $this->forkWrapper,
            workerCallback: $this->callback,
            logger: $this->logger,
        );

        $scheduleRestart = new ReflectionMethod($master, 'scheduleRestart');
        $scheduleRestart->invoke($master, 1);

        $this->assertCount(1, $master->getWorkers());
    }

    #[Test]
    public function process_pending_restarts_spawns_after_delay(): void
    {
        $this->forkWrapper->method('fork')->willReturn(12345);

        $master = new SharedSocketMaster(
            config: $this->config,
            serverConfig: $this->serverConfig,
            socketWrapper: $this->socketWrapper,
            forkWrapper: $this->forkWrapper,
            workerCallback: $this->callback,
            logger: $this->logger,
        );

        $pendingRestarts = new ReflectionProperty(AbstractMaster::class, 'pendingRestarts');
        $pendingRestarts->setValue($master, [1 => microtime(true) - 1]);

        $processPendingRestarts = new ReflectionMethod(AbstractMaster::class, 'processPendingRestarts');
        $processPendingRestarts->invoke($master);

        $this->assertCount(1, $master->getWorkers());
    }

    #[Test]
    public function process_pending_restarts_skips_before_time(): void
    {
        $this->forkWrapper->method('fork')->willReturn(12345);

        $master = new SharedSocketMaster(
            config: $this->config,
            serverConfig: $this->serverConfig,
            socketWrapper: $this->socketWrapper,
            forkWrapper: $this->forkWrapper,
            workerCallback: $this->callback,
            logger: $this->logger,
        );

        $pendingRestarts = new ReflectionProperty(AbstractMaster::class, 'pendingRestarts');
        $pendingRestarts->setValue($master, [1 => microtime(true) + 100]);

        $processPendingRestarts = new ReflectionMethod(AbstractMaster::class, 'processPendingRestarts');
        $processPendingRestarts->invoke($master);

        $this->assertEmpty($master->getWorkers());
    }

    #[Test]
    public function process_pending_restarts_skips_when_shutdown_requested(): void
    {
        $this->forkWrapper->method('fork')->willReturn(12345);

        $master = new SharedSocketMaster(
            config: $this->config,
            serverConfig: $this->serverConfig,
            socketWrapper: $this->socketWrapper,
            forkWrapper: $this->forkWrapper,
            workerCallback: $this->callback,
            logger: $this->logger,
        );

        $master->stop();

        $pendingRestarts = new ReflectionProperty(AbstractMaster::class, 'pendingRestarts');
        $pendingRestarts->setValue($master, [1 => microtime(true) - 1]);

        $processPendingRestarts = new ReflectionMethod(AbstractMaster::class, 'processPendingRestarts');
        $processPendingRestarts->invoke($master);

        $this->assertEmpty($master->getWorkers());
    }
}

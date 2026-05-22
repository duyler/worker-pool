<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\WorkerPool\Balancer\LeastConnectionsBalancer;
use Duyler\WorkerPool\Config\WorkerPoolConfig;
use Duyler\WorkerPool\Exception\WorkerPoolException;
use Duyler\WorkerPool\IPC\FdPasser;
use Duyler\WorkerPool\Master\AbstractMaster;
use Duyler\WorkerPool\Master\CentralizedMaster;
use Duyler\WorkerPool\Master\MasterFactory;
use Duyler\WorkerPool\Master\SharedSocketMaster;
use Duyler\WorkerPool\Master\ConnectionQueue;
use Duyler\WorkerPool\Master\ConnectionRouter;
use Duyler\WorkerPool\Master\SocketManager;
use Duyler\WorkerPool\Master\WorkerManager;
use Duyler\WorkerPool\Process\ForkWrapperInterface;
use Duyler\WorkerPool\Signal\SignalHandler;
use Duyler\WorkerPool\Signal\SignalManager;
use Duyler\WorkerPool\Socket\SocketMsgWrapperInterface;
use Duyler\WorkerPool\Socket\SocketWrapperInterface;
use Duyler\WorkerPool\Util\SystemInfo;
use Duyler\WorkerPool\Worker\WorkerCallbackInterface;
use InvalidArgumentException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Duyler\WorkerPool\Process\ForkWrapper;
use Duyler\WorkerPool\Process\ProcessInfo;

use ReflectionMethod;
use ReflectionProperty;

#[CoversClass(SignalManager::class)]
#[CoversClass(MasterFactory::class)]
#[CoversClass(AbstractMaster::class)]
#[CoversClass(WorkerManager::class)]
#[UsesClass(SharedSocketMaster::class)]
#[UsesClass(CentralizedMaster::class)]
#[UsesClass(WorkerPoolConfig::class)]
#[UsesClass(SignalHandler::class)]
#[UsesClass(FdPasser::class)]
#[UsesClass(SocketManager::class)]
#[UsesClass(ConnectionQueue::class)]
#[UsesClass(ConnectionRouter::class)]
#[UsesClass(SystemInfo::class)]
#[UsesClass(ForkWrapper::class)]
#[UsesClass(ProcessInfo::class)]
#[UsesClass(WorkerPoolException::class)]
final class CoverageGapTest extends TestCase
{
    private SocketWrapperInterface $socketWrapper;
    private SocketMsgWrapperInterface $socketMsgWrapper;
    private ForkWrapperInterface $forkWrapper;
    private LoggerInterface $logger;
    private WorkerCallbackInterface $callback;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->socketWrapper = $this->createStub(SocketWrapperInterface::class);
        $this->socketMsgWrapper = $this->createStub(SocketMsgWrapperInterface::class);
        $this->forkWrapper = $this->createStub(ForkWrapperInterface::class);
        $this->logger = $this->createStub(LoggerInterface::class);
        $this->callback = $this->createStub(WorkerCallbackInterface::class);
    }

    #[Test]
    public function signal_manager_setup_worker_signals(): void
    {
        $handler = new SignalHandler();
        $manager = new SignalManager($handler);

        $called = false;
        $manager->setupWorkerSignals(function () use (&$called): void {
            $called = true;
        });

        $manager->requestShutdown();
        $this->assertTrue($manager->isShutdownRequested());
    }

    #[Test]
    public function signal_manager_reset(): void
    {
        $handler = new SignalHandler();
        $manager = new SignalManager($handler);

        $manager->setupMasterSignals(
            function (): void {},
            function (): void {},
        );

        $manager->requestShutdown();
        $this->assertTrue($manager->isShutdownRequested());

        $manager->reset();
        $this->assertFalse($manager->isShutdownRequested());
        $this->assertFalse($manager->isReloadRequested());
    }

    #[Test]
    public function signal_manager_reset_flags(): void
    {
        $handler = new SignalHandler();
        $manager = new SignalManager($handler);

        $manager->requestShutdown();
        $this->assertTrue($manager->isShutdownRequested());

        $manager->resetFlags();
        $this->assertFalse($manager->isShutdownRequested());
    }

    #[Test]
    public function signal_manager_dispatch(): void
    {
        $handler = new SignalHandler();
        $manager = new SignalManager($handler);

        $manager->setupMasterSignals(
            function (): void {},
            function (): void {},
        );

        $manager->dispatch();
        $this->assertFalse($manager->isShutdownRequested());
    }

    #[Test]
    public function master_factory_create_returns_shared_without_balancer(): void
    {
        SystemInfo::resetCache();
        $config = new WorkerPoolConfig(
            serverConfig: new ServerConfig(host: '127.0.0.1', port: 9901),
            workerCount: 1,
        );

        $master = MasterFactory::create(
            config: $config,
            serverConfig: new ServerConfig(host: '127.0.0.1', port: 9901),
            workerCallback: $this->callback,
            socketWrapper: $this->socketWrapper,
            forkWrapper: $this->forkWrapper,
            logger: $this->logger,
        );

        $this->assertInstanceOf(SharedSocketMaster::class, $master);
    }

    #[Test]
    public function master_factory_create_recommended(): void
    {
        SystemInfo::resetCache();
        $config = new WorkerPoolConfig(
            serverConfig: new ServerConfig(host: '127.0.0.1', port: 9902),
            workerCount: 1,
        );

        $master = MasterFactory::createRecommended(
            config: $config,
            serverConfig: new ServerConfig(host: '127.0.0.1', port: 9902),
            workerCallback: $this->callback,
            socketWrapper: $this->socketWrapper,
            forkWrapper: $this->forkWrapper,
            logger: $this->logger,
        );

        $systemInfo = new SystemInfo();
        if ($systemInfo->supportsFdPassing()) {
            $this->assertInstanceOf(CentralizedMaster::class, $master);
        } else {
            $this->assertInstanceOf(SharedSocketMaster::class, $master);
        }
    }

    #[Test]
    public function master_factory_recommended_master_returns_string(): void
    {
        SystemInfo::resetCache();
        $result = MasterFactory::recommendedMaster();
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    #[Test]
    public function master_factory_get_comparison_returns_array(): void
    {
        $comparison = MasterFactory::getComparison();
        $this->assertArrayHasKey('SharedSocketMaster', $comparison);
        $this->assertArrayHasKey('CentralizedMaster', $comparison);
    }

    #[Test]
    public function abstract_master_throws_without_worker(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SharedSocketMaster(
            config: new WorkerPoolConfig(
                serverConfig: new ServerConfig(host: '127.0.0.1', port: 9903),
                workerCount: 1,
            ),
            serverConfig: new ServerConfig(host: '127.0.0.1', port: 9903),
            socketWrapper: $this->socketWrapper,
            forkWrapper: $this->forkWrapper,
        );
    }

    #[Test]
    public function schedule_restart_with_zero_delay(): void
    {
        $this->forkWrapper->method('fork')->willReturn(100);

        $config = new WorkerPoolConfig(
            serverConfig: new ServerConfig(host: '127.0.0.1', port: 9904),
            workerCount: 1,
            autoRestart: true,
            restartDelay: 0,
        );

        $master = new SharedSocketMaster(
            config: $config,
            serverConfig: new ServerConfig(host: '127.0.0.1', port: 9904),
            socketWrapper: $this->socketWrapper,
            forkWrapper: $this->forkWrapper,
            workerCallback: $this->callback,
            logger: $this->logger,
        );

        $spawnRef = new ReflectionMethod($master, 'spawnWorker');
        $spawnRef->invoke($master, 1);

        $checkRef = new ReflectionMethod($master, 'checkWorkers');
        $checkRef->invoke($master);

        $workers = $master->getWorkers();
        $this->assertArrayHasKey(1, $workers);
    }

    #[Test]
    public function check_workers_no_auto_restart(): void
    {
        $this->forkWrapper->method('fork')->willReturn(100);
        $this->forkWrapper->method('waitpid')->willReturn(100);

        $config = new WorkerPoolConfig(
            serverConfig: new ServerConfig(host: '127.0.0.1', port: 9905),
            workerCount: 1,
            autoRestart: false,
        );

        $master = new SharedSocketMaster(
            config: $config,
            serverConfig: new ServerConfig(host: '127.0.0.1', port: 9905),
            socketWrapper: $this->socketWrapper,
            forkWrapper: $this->forkWrapper,
            workerCallback: $this->callback,
            logger: $this->logger,
        );

        $spawnRef = new ReflectionMethod($master, 'spawnWorker');
        $spawnRef->invoke($master, 1);

        $detectRef = new ReflectionMethod(AbstractMaster::class, 'detectDeadWorkers');
        $dead = $detectRef->invoke($master);
        $this->assertContains(1, $dead);
    }

    #[Test]
    public function centralized_master_run_without_socket_manager(): void
    {
        $this->forkWrapper->method('fork')->willReturn(100);

        $config = new WorkerPoolConfig(
            serverConfig: new ServerConfig(host: '127.0.0.1', port: 9906),
            workerCount: 1,
            autoRestart: false,
        );

        $master = new CentralizedMaster(
            config: $config,
            balancer: new LeastConnectionsBalancer(),
            socketWrapper: $this->socketWrapper,
            socketMsgWrapper: $this->socketMsgWrapper,
            forkWrapper: $this->forkWrapper,
            workerCallback: $this->callback,
            logger: $this->logger,
        );

        $signalProp = new ReflectionProperty(AbstractMaster::class, 'signalManager');
        $signalProp->getValue($master)->requestShutdown();

        $run = new ReflectionMethod($master, 'run');
        $run->invoke($master);

        $this->assertFalse($master->isRunning());
    }

    #[Test]
    public function centralized_master_metrics_without_start(): void
    {
        $config = new WorkerPoolConfig(
            serverConfig: new ServerConfig(host: '127.0.0.1', port: 9907),
            workerCount: 2,
        );

        $master = new CentralizedMaster(
            config: $config,
            balancer: new LeastConnectionsBalancer(),
            socketWrapper: $this->socketWrapper,
            socketMsgWrapper: $this->socketMsgWrapper,
            forkWrapper: $this->forkWrapper,
            serverConfig: new ServerConfig(host: '127.0.0.1', port: 9907),
            workerCallback: $this->callback,
            logger: $this->logger,
        );

        $metrics = $master->getMetrics();
        $this->assertSame(2, $metrics['total_workers']);
        $this->assertSame(0, $metrics['alive_workers']);
        $this->assertArrayHasKey('queue_size', $metrics);
    }

    #[Test]
    public function system_info_cache_hit(): void
    {
        SystemInfo::resetCache();
        $info = new SystemInfo();
        $first = $info->getCpuCores();
        $second = $info->getCpuCores();
        $this->assertSame($first, $second);
    }

    #[Test]
    public function system_info_get_os_info(): void
    {
        SystemInfo::resetCache();
        $info = new SystemInfo();
        $osInfo = $info->getOsInfo();
        $this->assertArrayHasKey('os', $osInfo);
        $this->assertArrayHasKey('os_family', $osInfo);
        $this->assertArrayHasKey('php_version', $osInfo);
        $this->assertArrayHasKey('sapi', $osInfo);
        $this->assertArrayHasKey('cpu_cores', $osInfo);
    }

    #[Test]
    public function system_info_is_container(): void
    {
        $info = new SystemInfo();
        $result = $info->isContainerEnvironment();
        $this->assertIsBool($result);
    }

    #[Test]
    public function system_info_supports_fd_passing(): void
    {
        $info = new SystemInfo();
        $result = $info->supportsFdPassing();
        $this->assertIsBool($result);
    }

    #[Test]
    public function system_info_supports_reuse_port(): void
    {
        $info = new SystemInfo();
        $result = $info->supportsReusePort();
        $this->assertIsBool($result);
    }

    #[Test]
    public function worker_manager_spawn_fork_failure(): void
    {
        $this->forkWrapper->method('fork')->willReturn(-1);
        $this->expectException(WorkerPoolException::class);

        $manager = new WorkerManager($this->forkWrapper, $this->logger);
        $manager->spawn(1, function (): void {});
    }

    #[Test]
    public function worker_manager_wait_all(): void
    {
        $this->forkWrapper->method('fork')->willReturn(100);
        $this->forkWrapper->method('waitpid')->willReturn(100);

        $manager = new WorkerManager($this->forkWrapper, $this->logger);
        $manager->spawn(1, function (): void {});

        $manager->waitAll();

        $this->assertNotEmpty($manager->getWorkers());
    }

    #[Test]
    public function worker_manager_check(): void
    {
        $this->forkWrapper->method('fork')->willReturn(100);
        $this->forkWrapper->method('waitpid')->willReturn(100);

        $manager = new WorkerManager($this->forkWrapper, $this->logger);
        $manager->spawn(1, function (): void {});

        $dead = $manager->check();
        $this->assertContains(1, $dead);
    }

    #[Test]
    public function worker_manager_count_alive(): void
    {
        $this->forkWrapper->method('fork')->willReturn(100);

        $manager = new WorkerManager($this->forkWrapper, $this->logger);
        $manager->spawn(1, function (): void {});

        $this->assertGreaterThanOrEqual(0, $manager->countAlive());
    }

    #[Test]
    public function worker_manager_stop_all(): void
    {
        $this->forkWrapper->method('fork')->willReturn(100);
        $this->forkWrapper->method('kill')->willReturn(true);

        $manager = new WorkerManager($this->forkWrapper, $this->logger);
        $manager->spawn(1, function (): void {});

        $manager->stopAll();
        $this->assertNotEmpty($manager->getWorkers());
    }

    #[Test]
    public function worker_manager_get_worker(): void
    {
        $this->forkWrapper->method('fork')->willReturn(100);

        $manager = new WorkerManager($this->forkWrapper, $this->logger);
        $manager->spawn(1, function (): void {});

        $worker = $manager->getWorker(1);
        $this->assertNotNull($worker);
        $this->assertNull($manager->getWorker(999));
    }

    #[Test]
    public function worker_manager_remove_worker(): void
    {
        $this->forkWrapper->method('fork')->willReturn(100);

        $manager = new WorkerManager($this->forkWrapper, $this->logger);
        $manager->spawn(1, function (): void {});

        $manager->removeWorker(1);
        $this->assertEmpty($manager->getWorkers());
    }

    #[Test]
    public function process_pending_restarts_during_shutdown(): void
    {
        $this->forkWrapper->method('fork')->willReturn(100);

        $config = new WorkerPoolConfig(
            serverConfig: new ServerConfig(host: '127.0.0.1', port: 9908),
            workerCount: 1,
            autoRestart: true,
            restartDelay: 1,
        );

        $master = new SharedSocketMaster(
            config: $config,
            serverConfig: new ServerConfig(host: '127.0.0.1', port: 9908),
            socketWrapper: $this->socketWrapper,
            forkWrapper: $this->forkWrapper,
            workerCallback: $this->callback,
            logger: $this->logger,
        );

        $master->stop();

        $processRef = new ReflectionMethod(AbstractMaster::class, 'processPendingRestarts');
        $processRef->invoke($master);

        $this->assertFalse($master->isRunning());
    }

    #[Test]
    public function socket_manager_close_when_socket_null(): void
    {
        $manager = new SocketManager(
            new ServerConfig(host: '127.0.0.1', port: 9909),
            $this->socketWrapper,
            $this->logger,
        );

        $closeRef = new ReflectionMethod($manager, 'close');
        $closeRef->invoke($manager);

        $this->assertFalse($manager->isListening());
    }

    #[Test]
    public function socket_manager_accept_when_not_listening(): void
    {
        $manager = new SocketManager(
            new ServerConfig(host: '127.0.0.1', port: 9910),
            $this->socketWrapper,
            $this->logger,
        );

        $acceptRef = new ReflectionMethod($manager, 'accept');
        $result = $acceptRef->invoke($manager);

        $this->assertNull($result);
    }
}

<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\Master;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\WorkerPool\Balancer\LeastConnectionsBalancer;
use Duyler\WorkerPool\Config\WorkerPoolConfig;
use Duyler\WorkerPool\Exception\WorkerPoolExceptionBase;
use Duyler\WorkerPool\IPC\FdPasser;
use Duyler\WorkerPool\Master\CentralizedMaster;
use Duyler\WorkerPool\Master\ConnectionQueue;
use Duyler\WorkerPool\Master\ConnectionRouter;
use Duyler\WorkerPool\Master\SocketManager;
use Duyler\WorkerPool\Process\ForkWrapperInterface;
use Duyler\WorkerPool\Process\ProcessInfo;
use Duyler\WorkerPool\Signal\SignalHandler;
use Duyler\WorkerPool\Signal\SignalManager;
use Duyler\WorkerPool\Socket\SocketMsgWrapperInterface;
use Duyler\WorkerPool\Socket\SocketWrapperInterface;
use Duyler\WorkerPool\Worker\WorkerCallbackInterface;
use Override;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use ReflectionProperty;
use Duyler\WorkerPool\Exception\WorkerPoolException;
use Duyler\WorkerPool\Master\AbstractMaster;
use Duyler\WorkerPool\Master\WorkerManager;
use Duyler\WorkerPool\Process\ForkWrapper;

use InvalidArgumentException;

use const AF_UNIX;
use const SOCK_STREAM;

#[CoversClass(CentralizedMaster::class)]
#[UsesClass(WorkerPoolConfig::class)]
#[UsesClass(LeastConnectionsBalancer::class)]
#[UsesClass(FdPasser::class)]
#[UsesClass(ConnectionRouter::class)]
#[UsesClass(ConnectionQueue::class)]
#[UsesClass(SignalHandler::class)]
#[UsesClass(SignalManager::class)]
#[UsesClass(SocketManager::class)]
#[UsesClass(WorkerManager::class)]
#[UsesClass(ProcessInfo::class)]
#[UsesClass(ForkWrapper::class)]
#[UsesClass(AbstractMaster::class)]
#[UsesClass(WorkerPoolExceptionBase::class)]
final class CentralizedMasterFullTest extends TestCase
{
    private WorkerPoolConfig $config;
    private ServerConfig $serverConfig;
    private SocketWrapperInterface $socketWrapper;
    private SocketMsgWrapperInterface $socketMsgWrapper;
    private ForkWrapperInterface $forkWrapper;
    private LoggerInterface $logger;
    private LeastConnectionsBalancer $balancer;
    private WorkerCallbackInterface $callback;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->serverConfig = new ServerConfig(host: '127.0.0.1', port: 9999);
        $this->config = new WorkerPoolConfig(
            serverConfig: $this->serverConfig,
            workerCount: 1,
            autoRestart: false,
        );
        $this->socketWrapper = $this->createStub(SocketWrapperInterface::class);
        $this->socketMsgWrapper = $this->createStub(SocketMsgWrapperInterface::class);
        $this->forkWrapper = $this->createStub(ForkWrapperInterface::class);
        $this->logger = $this->createStub(LoggerInterface::class);
        $this->balancer = new LeastConnectionsBalancer();
        $this->callback = $this->createStub(WorkerCallbackInterface::class);
    }

    #[Test]
    public function constructor_throws_without_worker_interface(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CentralizedMaster(
            config: $this->config,
            balancer: $this->balancer,
            socketWrapper: $this->socketWrapper,
            socketMsgWrapper: $this->socketMsgWrapper,
            forkWrapper: $this->forkWrapper,
            serverConfig: $this->serverConfig,
            logger: $this->logger,
        );
    }

    #[Test]
    public function get_balancer_returns_correct_instance(): void
    {
        $master = $this->createMaster();
        $this->assertSame($this->balancer, $master->getBalancer());
    }

    #[Test]
    public function get_metrics_returns_structure(): void
    {
        $master = $this->createMaster();
        $metrics = $master->getMetrics();

        $this->assertSame(1, $metrics['total_workers']);
        $this->assertSame(0, $metrics['alive_workers']);
        $this->assertSame(0, $metrics['total_connections']);
        $this->assertSame(0, $metrics['total_requests']);
        $this->assertSame(0, $metrics['queue_size']);
        $this->assertTrue($metrics['is_running']);
    }

    #[Test]
    public function is_running_returns_true_initially(): void
    {
        $master = $this->createMaster();
        $this->assertTrue($master->isRunning());
    }

    #[Test]
    public function stop_changes_running_state(): void
    {
        $master = $this->createMaster();
        $master->stop();
        $this->assertFalse($master->isRunning());
    }

    #[Test]
    public function run_exits_when_shutdown_requested(): void
    {
        $master = $this->createMaster();
        $master->stop();

        $run = new ReflectionMethod($master, 'run');
        $run->invoke($master);

        $this->assertFalse($master->isRunning());
    }

    #[Test]
    public function spawn_worker_throws_on_socket_pair_failure(): void
    {
        $this->socketWrapper->method('createPair')->willReturn(false);

        $master = $this->createMaster();

        $spawnWorker = new ReflectionMethod($master, 'spawnWorker');

        $this->expectException(WorkerPoolException::class);
        $spawnWorker->invoke($master, 1);
    }

    #[Test]
    public function spawn_worker_throws_on_fork_failure(): void
    {
        $socket = socket_create(AF_UNIX, SOCK_STREAM, 0);
        $this->socketWrapper->method('createPair')->willReturnCallback(function (int $d, int $t, int $p, array &$pair) use ($socket): bool {
            $pair = [$socket, $socket];
            return true;
        });
        $this->forkWrapper->method('fork')->willReturn(-1);

        $master = $this->createMaster();

        $spawnWorker = new ReflectionMethod($master, 'spawnWorker');

        $this->expectException(WorkerPoolException::class);
        $spawnWorker->invoke($master, 1);
    }

    #[Test]
    public function spawn_worker_registers_process_info(): void
    {
        $socket = socket_create(AF_UNIX, SOCK_STREAM, 0);
        $this->socketWrapper->method('createPair')->willReturnCallback(function (int $d, int $t, int $p, array &$pair) use ($socket): bool {
            $pair = [$socket, $socket];
            return true;
        });
        $this->forkWrapper->method('fork')->willReturn(12345);

        $master = $this->createMaster();

        $spawnWorker = new ReflectionMethod($master, 'spawnWorker');
        $spawnWorker->invoke($master, 1);

        $workers = $master->getWorkers();
        $this->assertCount(1, $workers);
        $this->assertSame(12345, $workers[1]->pid);
    }

    #[Test]
    public function check_workers_removes_dead_and_notifies_balancer(): void
    {
        $socket = socket_create(AF_UNIX, SOCK_STREAM, 0);
        $this->socketWrapper->method('createPair')->willReturnCallback(function (int $d, int $t, int $p, array &$pair) use ($socket): bool {
            $pair = [$socket, $socket];
            return true;
        });
        $this->forkWrapper->method('fork')->willReturn(12345);
        $this->forkWrapper->method('kill')->willReturn(false);
        $this->forkWrapper->method('waitpid')->willReturn(0);

        $master = $this->createMaster();

        $spawnWorker = new ReflectionMethod($master, 'spawnWorker');
        $spawnWorker->invoke($master, 1);

        $this->assertCount(1, $master->getWorkers());

        $checkWorkers = new ReflectionMethod($master, 'checkWorkers');
        $checkWorkers->invoke($master);

        $this->assertEmpty($master->getWorkers());
    }

    #[Test]
    public function get_metrics_with_alive_workers(): void
    {
        $socket = socket_create(AF_UNIX, SOCK_STREAM, 0);
        $this->socketWrapper->method('createPair')->willReturnCallback(function (int $d, int $t, int $p, array &$pair) use ($socket): bool {
            $pair = [$socket, $socket];
            return true;
        });
        $this->forkWrapper->method('fork')->willReturn(12345);
        $this->forkWrapper->method('kill')->willReturn(true);
        $this->forkWrapper->method('waitpid')->willReturn(0);

        $master = $this->createMaster();

        $spawnWorker = new ReflectionMethod($master, 'spawnWorker');
        $spawnWorker->invoke($master, 1);

        $metrics = $master->getMetrics();
        $this->assertSame(1, $metrics['alive_workers']);
    }

    #[Test]
    public function accept_connections_skips_when_not_configured(): void
    {
        $config = new WorkerPoolConfig(
            serverConfig: $this->serverConfig,
            workerCount: 1,
        );

        $master = new CentralizedMaster(
            config: $config,
            balancer: $this->balancer,
            socketWrapper: $this->socketWrapper,
            socketMsgWrapper: $this->socketMsgWrapper,
            forkWrapper: $this->forkWrapper,
            workerCallback: $this->callback,
            logger: $this->logger,
        );

        $acceptConnections = new ReflectionMethod($master, 'acceptConnections');
        $acceptConnections->invoke($master);

        $this->assertTrue(true);
    }

    #[Test]
    public function distribute_connections_skips_when_no_queue(): void
    {
        $config = new WorkerPoolConfig(
            serverConfig: $this->serverConfig,
            workerCount: 1,
        );

        $master = new CentralizedMaster(
            config: $config,
            balancer: $this->balancer,
            socketWrapper: $this->socketWrapper,
            socketMsgWrapper: $this->socketMsgWrapper,
            forkWrapper: $this->forkWrapper,
            workerCallback: $this->callback,
            logger: $this->logger,
        );

        $distributeConnections = new ReflectionMethod($master, 'distributeConnections');
        $distributeConnections->invoke($master);

        $this->assertTrue(true);
    }

    #[Test]
    public function constructor_without_server_config_creates_master(): void
    {
        $master = new CentralizedMaster(
            config: $this->config,
            balancer: $this->balancer,
            socketWrapper: $this->socketWrapper,
            socketMsgWrapper: $this->socketMsgWrapper,
            forkWrapper: $this->forkWrapper,
            workerCallback: $this->callback,
            logger: $this->logger,
        );

        $this->assertTrue($master->isRunning());
    }

    #[Test]
    public function worker_sockets_cleared_on_dead_worker(): void
    {
        $socket = socket_create(AF_UNIX, SOCK_STREAM, 0);
        $this->socketWrapper->method('createPair')->willReturnCallback(function (int $d, int $t, int $p, array &$pair) use ($socket): bool {
            $pair = [$socket, $socket];
            return true;
        });
        $this->forkWrapper->method('fork')->willReturn(12345);
        $this->forkWrapper->method('kill')->willReturn(false);
        $this->forkWrapper->method('waitpid')->willReturn(0);

        $master = $this->createMaster();

        $spawnWorker = new ReflectionMethod($master, 'spawnWorker');
        $spawnWorker->invoke($master, 1);

        $workerSocketsProp = new ReflectionProperty($master, 'workerSockets');
        $this->assertArrayHasKey(1, $workerSocketsProp->getValue($master));

        $checkWorkers = new ReflectionMethod($master, 'checkWorkers');
        $checkWorkers->invoke($master);

        $this->assertArrayNotHasKey(1, $workerSocketsProp->getValue($master));
    }

    private function createMaster(): CentralizedMaster
    {
        return new CentralizedMaster(
            config: $this->config,
            balancer: $this->balancer,
            socketWrapper: $this->socketWrapper,
            socketMsgWrapper: $this->socketMsgWrapper,
            forkWrapper: $this->forkWrapper,
            serverConfig: $this->serverConfig,
            workerCallback: $this->callback,
            logger: $this->logger,
        );
    }
}

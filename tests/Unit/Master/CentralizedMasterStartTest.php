<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\Master;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\WorkerPool\Balancer\LeastConnectionsBalancer;
use Duyler\WorkerPool\Config\WorkerPoolConfig;
use Duyler\WorkerPool\Exception\WorkerPoolException;
use Duyler\WorkerPool\IPC\FdPasser;
use Duyler\WorkerPool\Master\AbstractMaster;
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
use Duyler\WorkerPool\Master\WorkerManager;
use Duyler\WorkerPool\Process\ForkWrapper;
use Duyler\WorkerPool\Socket\SocketMsgWrapper;
use Duyler\WorkerPool\Socket\SocketWrapper;

use ReflectionMethod;
use ReflectionProperty;

use const AF_INET;
use const AF_UNIX;
use const SOCK_STREAM;
use const SOL_TCP;

#[CoversClass(CentralizedMaster::class)]
#[UsesClass(AbstractMaster::class)]
#[UsesClass(WorkerPoolConfig::class)]
#[UsesClass(SignalHandler::class)]
#[UsesClass(SignalManager::class)]
#[UsesClass(WorkerManager::class)]
#[UsesClass(ProcessInfo::class)]
#[UsesClass(WorkerPoolException::class)]
#[UsesClass(ForkWrapper::class)]
#[UsesClass(SocketManager::class)]
#[UsesClass(ConnectionQueue::class)]
#[UsesClass(ConnectionRouter::class)]
#[UsesClass(FdPasser::class)]
#[UsesClass(SocketWrapper::class)]
#[UsesClass(SocketMsgWrapper::class)]
#[UsesClass(LeastConnectionsBalancer::class)]
final class CentralizedMasterStartTest extends TestCase
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
    public function start_with_socket_manager_listens(): void
    {
        $serverConfig = new ServerConfig(host: '127.0.0.1', port: 9999);
        $config = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 1,
            autoRestart: false,
            pollInterval: 1000,
        );

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        $this->socketWrapper->method('create')->willReturn($socket);
        $this->socketWrapper->method('setOption')->willReturn(true);
        $this->socketWrapper->method('bind')->willReturn(true);
        $this->socketWrapper->method('listen')->willReturn(true);
        $this->socketWrapper->method('lastError')->willReturn(0);
        $this->socketWrapper->method('strerror')->willReturn('');
        $this->socketWrapper->method('select')->willReturn(false);

        $pair = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        $this->socketWrapper->method('createPair')->willReturnCallback(function (int $d, int $t, int $p, array &$s) use ($pair): bool {
            $s[0] = $pair[0];
            $s[1] = $pair[1];
            return true;
        });

        $this->forkWrapper->method('fork')->willReturn(100);
        $this->forkWrapper->method('kill')->willReturn(true);
        $this->forkWrapper->method('waitpid')->willReturn(0);

        $master = new CentralizedMaster(
            config: $config,
            balancer: new LeastConnectionsBalancer(),
            socketWrapper: $this->socketWrapper,
            socketMsgWrapper: $this->socketMsgWrapper,
            forkWrapper: $this->forkWrapper,
            serverConfig: $serverConfig,
            workerCallback: $this->callback,
            logger: $this->logger,
        );

        $signalProp = new ReflectionProperty(AbstractMaster::class, 'signalManager');
        $signalProp->getValue($master)->requestShutdown();

        $start = new ReflectionMethod($master, 'start');
        $start->invoke($master);

        $this->assertFalse($master->isRunning());
    }

    #[Test]
    public function start_without_socket_manager_logs_warning(): void
    {
        $serverConfig = new ServerConfig(host: '127.0.0.1', port: 9999);
        $config = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 1,
            autoRestart: false,
            pollInterval: 1000,
        );

        $pair = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        $this->socketWrapper->method('createPair')->willReturnCallback(function (int $d, int $t, int $p, array &$s) use ($pair): bool {
            $s[0] = $pair[0];
            $s[1] = $pair[1];
            return true;
        });

        $this->forkWrapper->method('fork')->willReturn(100);
        $this->forkWrapper->method('kill')->willReturn(true);
        $this->forkWrapper->method('waitpid')->willReturn(0);

        $master = new CentralizedMaster(
            config: $config,
            balancer: new LeastConnectionsBalancer(),
            socketWrapper: $this->socketWrapper,
            socketMsgWrapper: $this->socketMsgWrapper,
            forkWrapper: $this->forkWrapper,
            serverConfig: null,
            workerCallback: $this->callback,
            logger: $this->logger,
        );

        $signalProp = new ReflectionProperty(AbstractMaster::class, 'signalManager');
        $signalProp->getValue($master)->requestShutdown();

        $start = new ReflectionMethod($master, 'start');
        $start->invoke($master);

        $this->assertFalse($master->isRunning());
    }

    #[Test]
    public function spawn_worker_creates_socket_pair(): void
    {
        $serverConfig = new ServerConfig(host: '127.0.0.1', port: 9999);
        $config = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 1,
            autoRestart: false,
        );

        $pair = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        [$s1, $s2] = $pair;

        $this->socketWrapper->method('createPair')->willReturnCallback(function (int $domain, int $type, int $proto, array &$sockets) use ($s1, $s2): bool {
            $sockets[0] = $s1;
            $sockets[1] = $s2;
            return true;
        });
        $this->forkWrapper->method('fork')->willReturn(200);

        $master = new CentralizedMaster(
            config: $config,
            balancer: new LeastConnectionsBalancer(),
            socketWrapper: $this->socketWrapper,
            socketMsgWrapper: $this->socketMsgWrapper,
            forkWrapper: $this->forkWrapper,
            serverConfig: $serverConfig,
            workerCallback: $this->callback,
            logger: $this->logger,
        );

        $spawnWorker = new ReflectionMethod($master, 'spawnWorker');
        $spawnWorker->invoke($master, 1);

        $workers = $master->getWorkers();
        $this->assertArrayHasKey(1, $workers);
        $this->assertSame(200, $workers[1]->pid);
    }

    #[Test]
    public function spawn_worker_throws_on_create_pair_failure(): void
    {
        $serverConfig = new ServerConfig(host: '127.0.0.1', port: 9999);
        $config = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 1,
            autoRestart: false,
        );

        $this->socketWrapper->method('createPair')->willReturn(false);

        $master = new CentralizedMaster(
            config: $config,
            balancer: new LeastConnectionsBalancer(),
            socketWrapper: $this->socketWrapper,
            socketMsgWrapper: $this->socketMsgWrapper,
            forkWrapper: $this->forkWrapper,
            serverConfig: $serverConfig,
            workerCallback: $this->callback,
            logger: $this->logger,
        );

        $spawnWorker = new ReflectionMethod($master, 'spawnWorker');

        $this->expectException(WorkerPoolException::class);
        $this->expectExceptionMessage('Failed to create socket pair for worker 1');

        $spawnWorker->invoke($master, 1);
    }

    #[Test]
    public function spawn_worker_throws_on_fork_failure(): void
    {
        $serverConfig = new ServerConfig(host: '127.0.0.1', port: 9999);
        $config = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 1,
            autoRestart: false,
        );

        $pair = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        $this->socketWrapper->method('createPair')->willReturnCallback(function (int $d, int $t, int $p, array &$s) use ($pair): bool {
            $s[0] = $pair[0];
            $s[1] = $pair[1];
            return true;
        });
        $this->forkWrapper->method('fork')->willReturn(-1);

        $master = new CentralizedMaster(
            config: $config,
            balancer: new LeastConnectionsBalancer(),
            socketWrapper: $this->socketWrapper,
            socketMsgWrapper: $this->socketMsgWrapper,
            forkWrapper: $this->forkWrapper,
            serverConfig: $serverConfig,
            workerCallback: $this->callback,
            logger: $this->logger,
        );

        $spawnWorker = new ReflectionMethod($master, 'spawnWorker');

        $this->expectException(WorkerPoolException::class);
        $this->expectExceptionMessage('Failed to fork worker 1');

        $spawnWorker->invoke($master, 1);
    }

    #[Test]
    public function check_workers_balancer_removes_dead_worker(): void
    {
        $serverConfig = new ServerConfig(host: '127.0.0.1', port: 9999);
        $config = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 1,
            autoRestart: false,
        );

        $pair = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        $this->socketWrapper->method('createPair')->willReturnCallback(function (int $d, int $t, int $p, array &$s) use ($pair): bool {
            $s[0] = $pair[0];
            $s[1] = $pair[1];
            return true;
        });

        $this->forkWrapper->method('fork')->willReturn(999999);
        $this->forkWrapper->method('kill')->willReturnCallback(function (int $pid, int $sig): bool {
            if (0 === $sig && 999999 === $pid) {
                return false;
            }
            return true;
        });

        $master = new CentralizedMaster(
            config: $config,
            balancer: new LeastConnectionsBalancer(),
            socketWrapper: $this->socketWrapper,
            socketMsgWrapper: $this->socketMsgWrapper,
            forkWrapper: $this->forkWrapper,
            serverConfig: $serverConfig,
            workerCallback: $this->callback,
            logger: $this->logger,
        );

        $spawnWorker = new ReflectionMethod($master, 'spawnWorker');
        $spawnWorker->invoke($master, 1);

        $workerSocketsProp = new ReflectionProperty($master, 'workerSockets');
        $this->assertArrayHasKey(1, $workerSocketsProp->getValue($master));

        $checkWorkers = new ReflectionMethod($master, 'checkWorkers');
        $checkWorkers->invoke($master);

        $this->assertArrayNotHasKey(1, $workerSocketsProp->getValue($master));
    }

    #[Test]
    public function accept_connections_dequeues_when_queue_full(): void
    {
        $serverConfig = new ServerConfig(host: '127.0.0.1', port: 9999);
        $config = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 1,
            autoRestart: false,
            maxQueueSize: 1,
        );

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->socketWrapper->method('create')->willReturn($socket);
        $this->socketWrapper->method('setOption')->willReturn(true);
        $this->socketWrapper->method('bind')->willReturn(true);
        $this->socketWrapper->method('listen')->willReturn(true);
        $this->socketWrapper->method('lastError')->willReturn(0);

        $clientSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        $acceptCallCount = 0;
        $this->socketWrapper->method('accept')->willReturnCallback(function () use ($clientSocket, &$acceptCallCount): mixed {
            $acceptCallCount++;
            if (1 === $acceptCallCount) {
                return $clientSocket;
            }
            return false;
        });

        $pair = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        $this->socketWrapper->method('createPair')->willReturnCallback(function (int $d, int $t, int $p, array &$s) use ($pair): bool {
            $s[0] = $pair[0];
            $s[1] = $pair[1];
            return true;
        });

        $this->forkWrapper->method('fork')->willReturn(100);

        $master = new CentralizedMaster(
            config: $config,
            balancer: new LeastConnectionsBalancer(),
            socketWrapper: $this->socketWrapper,
            socketMsgWrapper: $this->socketMsgWrapper,
            forkWrapper: $this->forkWrapper,
            serverConfig: $serverConfig,
            workerCallback: $this->callback,
            logger: $this->logger,
        );

        $sm = new ReflectionProperty($master, 'socketManager');
        $socketManager = $sm->getValue($master);
        $listenMethod = new ReflectionMethod($socketManager, 'listen');
        $listenMethod->invoke($socketManager);

        $acceptConnections = new ReflectionMethod($master, 'acceptConnections');
        $acceptConnections->invoke($master);

        $this->assertSame(2, $acceptCallCount);
    }

    #[Test]
    public function distribute_connections_with_empty_queue(): void
    {
        $serverConfig = new ServerConfig(host: '127.0.0.1', port: 9999);
        $config = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 1,
            autoRestart: false,
        );

        $this->forkWrapper->method('fork')->willReturn(100);

        $master = new CentralizedMaster(
            config: $config,
            balancer: new LeastConnectionsBalancer(),
            socketWrapper: $this->socketWrapper,
            socketMsgWrapper: $this->socketMsgWrapper,
            forkWrapper: $this->forkWrapper,
            serverConfig: $serverConfig,
            workerCallback: $this->callback,
            logger: $this->logger,
        );

        $distributeConnections = new ReflectionMethod($master, 'distributeConnections');
        $distributeConnections->invoke($master);

        $this->assertCount(0, $master->getWorkers());
    }

    #[Test]
    public function get_metrics_returns_correct_structure(): void
    {
        $serverConfig = new ServerConfig(host: '127.0.0.1', port: 9999);
        $config = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 1,
            autoRestart: false,
        );

        $pair = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        $this->socketWrapper->method('createPair')->willReturnCallback(function (int $d, int $t, int $p, array &$s) use ($pair): bool {
            $s[0] = $pair[0];
            $s[1] = $pair[1];
            return true;
        });

        $this->forkWrapper->method('fork')->willReturn(500);

        $master = new CentralizedMaster(
            config: $config,
            balancer: new LeastConnectionsBalancer(),
            socketWrapper: $this->socketWrapper,
            socketMsgWrapper: $this->socketMsgWrapper,
            forkWrapper: $this->forkWrapper,
            serverConfig: $serverConfig,
            workerCallback: $this->callback,
            logger: $this->logger,
        );

        $spawnWorker = new ReflectionMethod($master, 'spawnWorker');
        $spawnWorker->invoke($master, 1);

        $metrics = $master->getMetrics();

        $this->assertSame(1, $metrics['total_workers']);
        $this->assertSame(0, $metrics['total_connections']);
        $this->assertSame(0, $metrics['total_requests']);
        $this->assertSame(0, $metrics['queue_size']);
    }

    #[Test]
    public function get_metrics_shows_not_running_after_stop(): void
    {
        $serverConfig = new ServerConfig(host: '127.0.0.1', port: 9999);
        $config = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 1,
            autoRestart: false,
        );

        $pair = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        $this->socketWrapper->method('createPair')->willReturnCallback(function (int $d, int $t, int $p, array &$s) use ($pair): bool {
            $s[0] = $pair[0];
            $s[1] = $pair[1];
            return true;
        });

        $this->forkWrapper->method('fork')->willReturn(500);
        $this->forkWrapper->method('kill')->willReturn(true);

        $master = new CentralizedMaster(
            config: $config,
            balancer: new LeastConnectionsBalancer(),
            socketWrapper: $this->socketWrapper,
            socketMsgWrapper: $this->socketMsgWrapper,
            forkWrapper: $this->forkWrapper,
            serverConfig: $serverConfig,
            workerCallback: $this->callback,
            logger: $this->logger,
        );

        $master->stop();

        $metrics = $master->getMetrics();
        $this->assertFalse($metrics['is_running']);
    }

    #[Test]
    public function get_balancer_returns_instance(): void
    {
        $serverConfig = new ServerConfig(host: '127.0.0.1', port: 9999);
        $config = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 1,
            autoRestart: false,
        );

        $balancer = new LeastConnectionsBalancer();

        $master = new CentralizedMaster(
            config: $config,
            balancer: $balancer,
            socketWrapper: $this->socketWrapper,
            socketMsgWrapper: $this->socketMsgWrapper,
            forkWrapper: $this->forkWrapper,
            serverConfig: $serverConfig,
            workerCallback: $this->callback,
            logger: $this->logger,
        );

        $this->assertSame($balancer, $master->getBalancer());
    }

    #[Test]
    public function run_with_select_error_logs_non_eintr(): void
    {
        $serverConfig = new ServerConfig(host: '127.0.0.1', port: 9999);
        $config = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 1,
            autoRestart: false,
            pollInterval: 1000,
        );

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->socketWrapper->method('create')->willReturn($socket);
        $this->socketWrapper->method('setOption')->willReturn(true);
        $this->socketWrapper->method('bind')->willReturn(true);
        $this->socketWrapper->method('listen')->willReturn(true);
        $this->socketWrapper->method('lastError')->willReturn(99);
        $this->socketWrapper->method('strerror')->willReturn('Test error');
        $this->socketWrapper->method('select')->willReturn(false);

        $pair = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        $this->socketWrapper->method('createPair')->willReturnCallback(function (int $d, int $t, int $p, array &$s) use ($pair): bool {
            $s[0] = $pair[0];
            $s[1] = $pair[1];
            return true;
        });

        $this->forkWrapper->method('fork')->willReturn(600);
        $this->forkWrapper->method('kill')->willReturn(true);
        $this->forkWrapper->method('waitpid')->willReturn(0);

        $master = new CentralizedMaster(
            config: $config,
            balancer: new LeastConnectionsBalancer(),
            socketWrapper: $this->socketWrapper,
            socketMsgWrapper: $this->socketMsgWrapper,
            forkWrapper: $this->forkWrapper,
            serverConfig: $serverConfig,
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
    public function run_with_no_socket_manager_uses_usleep(): void
    {
        $serverConfig = new ServerConfig(host: '127.0.0.1', port: 9999);
        $config = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 1,
            autoRestart: false,
            pollInterval: 1000,
        );

        $pair = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        $this->socketWrapper->method('createPair')->willReturnCallback(function (int $d, int $t, int $p, array &$s) use ($pair): bool {
            $s[0] = $pair[0];
            $s[1] = $pair[1];
            return true;
        });

        $this->forkWrapper->method('fork')->willReturn(700);
        $this->forkWrapper->method('kill')->willReturn(true);
        $this->forkWrapper->method('waitpid')->willReturn(0);

        $master = new CentralizedMaster(
            config: $config,
            balancer: new LeastConnectionsBalancer(),
            socketWrapper: $this->socketWrapper,
            socketMsgWrapper: $this->socketMsgWrapper,
            forkWrapper: $this->forkWrapper,
            serverConfig: null,
            workerCallback: $this->callback,
            logger: $this->logger,
        );

        $signalProp = new ReflectionProperty(AbstractMaster::class, 'signalManager');
        $signalProp->getValue($master)->requestShutdown();

        $run = new ReflectionMethod($master, 'run');
        $run->invoke($master);

        $this->assertFalse($master->isRunning());
    }
}

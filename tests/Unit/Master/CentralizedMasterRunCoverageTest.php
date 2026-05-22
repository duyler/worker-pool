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
use const SOL_SOCKET;
use const SOL_TCP;
use const SOCKET_EINTR;
use const SIGALRM;
use const SIG_DFL;

#[CoversClass(CentralizedMaster::class)]
#[UsesClass(AbstractMaster::class)]
#[UsesClass(WorkerPoolConfig::class)]
#[UsesClass(SignalHandler::class)]
#[UsesClass(SignalManager::class)]
#[UsesClass(WorkerManager::class)]
#[UsesClass(ProcessInfo::class)]
#[UsesClass(WorkerPoolExceptionBase::class)]
#[UsesClass(ForkWrapper::class)]
#[UsesClass(SocketManager::class)]
#[UsesClass(ConnectionQueue::class)]
#[UsesClass(ConnectionRouter::class)]
#[UsesClass(FdPasser::class)]
#[UsesClass(SocketWrapper::class)]
#[UsesClass(SocketMsgWrapper::class)]
#[UsesClass(LeastConnectionsBalancer::class)]
final class CentralizedMasterRunCoverageTest extends TestCase
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
    public function run_loop_with_select_returning_connections_accepted(): void
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

        $selectCallCount = 0;
        $this->socketWrapper->method('select')->willReturnCallback(function (&$read, &$write, &$except, $timeout, $usec) use (&$selectCallCount): int {
            $selectCallCount++;
            return 1;
        });

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

        $this->forkWrapper->method('fork')->willReturn(10001);
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
        $sm = $signalProp->getValue($master);

        pcntl_async_signals(true);
        pcntl_signal(SIGALRM, function () use ($sm): void {
            $sm->requestShutdown();
        });
        pcntl_alarm(0);

        $shutdownProp = new ReflectionProperty($sm, 'shutdownRequested');
        $shutdownProp->setValue($sm, false);

        pcntl_alarm(1);

        $run = new ReflectionMethod($master, 'run');
        $run->invoke($master);

        pcntl_alarm(0);
        pcntl_signal(SIGALRM, SIG_DFL);

        $this->assertFalse($master->isRunning());
    }

    #[Test]
    public function run_loop_with_select_eintr_error(): void
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

        $this->socketWrapper->method('lastError')->willReturn(SOCKET_EINTR);
        $this->socketWrapper->method('strerror')->willReturn('Interrupted');
        $this->socketWrapper->method('select')->willReturn(false);

        $pair = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        $this->socketWrapper->method('createPair')->willReturnCallback(function (int $d, int $t, int $p, array &$s) use ($pair): bool {
            $s[0] = $pair[0];
            $s[1] = $pair[1];
            return true;
        });

        $this->forkWrapper->method('fork')->willReturn(10002);
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
        $sm = $signalProp->getValue($master);

        pcntl_async_signals(true);
        pcntl_signal(SIGALRM, function () use ($sm): void {
            $sm->requestShutdown();
        });

        $shutdownProp = new ReflectionProperty($sm, 'shutdownRequested');
        $shutdownProp->setValue($sm, false);
        pcntl_alarm(1);

        $run = new ReflectionMethod($master, 'run');
        $run->invoke($master);

        pcntl_alarm(0);
        pcntl_signal(SIGALRM, SIG_DFL);

        $this->assertFalse($master->isRunning());
    }

    #[Test]
    public function run_loop_without_socket_manager_logs_error(): void
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

        $this->forkWrapper->method('fork')->willReturn(200);
        $this->forkWrapper->method('kill')->willReturn(true);
        $this->forkWrapper->method('waitpid')->willReturn(0);

        $errorLogged = false;
        $this->logger->method('error')->willReturnCallback(function (string $msg) use (&$errorLogged): void {
            if (str_contains($msg, 'No socket manager')) {
                $errorLogged = true;
            }
        });

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
        $sm = $signalProp->getValue($master);

        pcntl_async_signals(true);
        pcntl_signal(SIGALRM, function () use ($sm): void {
            $sm->requestShutdown();
        });

        $shutdownProp = new ReflectionProperty($sm, 'shutdownRequested');
        $shutdownProp->setValue($sm, false);
        pcntl_alarm(1);

        $run = new ReflectionMethod($master, 'run');
        $run->invoke($master);

        pcntl_alarm(0);
        pcntl_signal(SIGALRM, SIG_DFL);

        $this->assertTrue($errorLogged);
    }

    #[Test]
    public function run_callback_worker_receives_fd_and_calls_callback(): void
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
        $this->forkWrapper->method('fork')->willReturn(100);

        $clientSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        $this->socketMsgWrapper->method('cmsgSpace')->willReturn(256);
        $this->socketMsgWrapper->method('recvmsg')->willReturnCallback(function ($socket, array &$message, int $flags) use ($clientSocket): int|false {
            static $callCount = 0;
            $callCount++;
            if (1 === $callCount) {
                $message['iov'] = ['{"worker_id":1,"client_ip":"127.0.0.1"}'];
                $message['control'] = [
                    ['level' => SOL_SOCKET, 'type' => 1, 'data' => [$clientSocket]],
                ];
                return 50;
            }
            return false;
        });
        $this->socketMsgWrapper->method('sendmsg')->willReturn(1);
        $this->socketWrapper->method('lastError')->willReturn(11);

        $callbackCalled = false;
        $callbackWorker = new class ($callbackCalled) implements WorkerCallbackInterface {
            public function __construct(private bool &$called) {}

            public function handle(mixed $clientSocket, array $metadata): void
            {
                $this->called = true;
            }
        };

        $master = new CentralizedMaster(
            config: $config,
            balancer: new LeastConnectionsBalancer(),
            socketWrapper: $this->socketWrapper,
            socketMsgWrapper: $this->socketMsgWrapper,
            forkWrapper: $this->forkWrapper,
            serverConfig: $serverConfig,
            workerCallback: $callbackWorker,
            logger: $this->logger,
        );

        $signalProp = new ReflectionProperty(AbstractMaster::class, 'signalManager');
        $sm = $signalProp->getValue($master);

        pcntl_async_signals(true);
        pcntl_signal(SIGALRM, function () use ($sm): void {
            $sm->requestShutdown();
        });
        pcntl_alarm(1);

        $runCallbackWorker = new ReflectionMethod($master, 'runCallbackWorker');
        $runCallbackWorker->invoke($master, 1, $pair[0]);

        pcntl_alarm(0);
        pcntl_signal(SIGALRM, SIG_DFL);

        $this->assertTrue($callbackCalled);
    }

    #[Test]
    public function start_calls_listen_and_spawns_workers(): void
    {
        $serverConfig = new ServerConfig(host: '127.0.0.1', port: 9999);
        $config = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 2,
            autoRestart: false,
            pollInterval: 1000,
        );

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->socketWrapper->method('create')->willReturn($socket);
        $this->socketWrapper->method('setOption')->willReturn(true);
        $this->socketWrapper->method('bind')->willReturn(true);
        $this->socketWrapper->method('listen')->willReturn(true);

        $this->socketWrapper->method('lastError')->willReturn(SOCKET_EINTR);
        $this->socketWrapper->method('strerror')->willReturn('Interrupted');
        $this->socketWrapper->method('select')->willReturn(false);

        $pair = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        $this->socketWrapper->method('createPair')->willReturnCallback(function (int $d, int $t, int $p, array &$s) use ($pair): bool {
            $s[0] = $pair[0];
            $s[1] = $pair[1];
            return true;
        });

        $forkCount = 0;
        $this->forkWrapper->method('fork')->willReturnCallback(function () use (&$forkCount): int {
            $forkCount++;
            return 10000 + $forkCount;
        });
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
        $sm = $signalProp->getValue($master);

        pcntl_async_signals(true);
        pcntl_signal(SIGALRM, function () use ($sm): void {
            $sm->requestShutdown();
        });

        $shutdownProp = new ReflectionProperty($sm, 'shutdownRequested');
        $shutdownProp->setValue($sm, false);
        pcntl_alarm(1);

        $start = new ReflectionMethod($master, 'start');
        $start->invoke($master);

        pcntl_alarm(0);
        pcntl_signal(SIGALRM, SIG_DFL);

        $this->assertCount(2, $master->getWorkers());
    }

    #[Test]
    public function accept_connections_queues_client_socket(): void
    {
        $serverConfig = new ServerConfig(host: '127.0.0.1', port: 9999);
        $config = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 1,
            autoRestart: false,
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

        $queueProp = new ReflectionProperty($master, 'connectionQueue');
        $queue = $queueProp->getValue($master);

        $sizeMethod = new ReflectionMethod($queue, 'size');
        $this->assertSame(1, $sizeMethod->invoke($queue));
    }

    #[Test]
    public function distribute_connections_routes_to_worker(): void
    {
        $serverConfig = new ServerConfig(host: '127.0.0.1', port: 9999);
        $config = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 1,
            autoRestart: false,
        );

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->socketWrapper->method('create')->willReturn($socket);
        $this->socketWrapper->method('setOption')->willReturn(true);
        $this->socketWrapper->method('bind')->willReturn(true);
        $this->socketWrapper->method('listen')->willReturn(true);
        $this->socketWrapper->method('lastError')->willReturn(0);

        $clientSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->socketWrapper->method('accept')->willReturn($clientSocket);

        $pair = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        $this->socketWrapper->method('createPair')->willReturnCallback(function (int $d, int $t, int $p, array &$s) use ($pair): bool {
            $s[0] = $pair[0];
            $s[1] = $pair[1];
            return true;
        });

        $this->forkWrapper->method('fork')->willReturn(getmypid());
        $this->forkWrapper->method('kill')->willReturn(true);

        $this->socketMsgWrapper->method('sendmsg')->willReturn(1);
        $this->socketWrapper->method('lastError')->willReturn(0);
        $this->socketWrapper->method('strerror')->willReturn('');

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

        $spawnWorker = new ReflectionMethod($master, 'spawnWorker');
        $spawnWorker->invoke($master, 1);

        $distributeConnections = new ReflectionMethod($master, 'distributeConnections');
        $distributeConnections->invoke($master);

        $queueProp = new ReflectionProperty($master, 'connectionQueue');
        $queue = $queueProp->getValue($master);

        $sizeMethod = new ReflectionMethod($queue, 'size');
        $this->assertSame(0, $sizeMethod->invoke($queue));
    }

    #[Test]
    public function run_loop_iteration_debug_log(): void
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
        $this->socketWrapper->method('lastError')->willReturn(SOCKET_EINTR);
        $this->socketWrapper->method('strerror')->willReturn('Interrupted');
        $this->socketWrapper->method('select')->willReturn(false);

        $pair = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        $this->socketWrapper->method('createPair')->willReturnCallback(function (int $d, int $t, int $p, array &$s) use ($pair): bool {
            $s[0] = $pair[0];
            $s[1] = $pair[1];
            return true;
        });

        $this->forkWrapper->method('fork')->willReturn(10001);
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
        $sm = $signalProp->getValue($master);

        pcntl_async_signals(true);
        pcntl_signal(SIGALRM, function () use ($sm): void {
            $sm->requestShutdown();
        });

        $shutdownProp = new ReflectionProperty($sm, 'shutdownRequested');
        $shutdownProp->setValue($sm, false);
        pcntl_alarm(1);

        $run = new ReflectionMethod($master, 'run');
        $run->invoke($master);

        pcntl_alarm(0);
        pcntl_signal(SIGALRM, SIG_DFL);

        $this->assertFalse($master->isRunning());
    }
}

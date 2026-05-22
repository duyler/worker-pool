<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\Master;

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
use Duyler\WorkerPool\Master\WorkerManager;
use Duyler\WorkerPool\Process\ForkWrapperInterface;
use Duyler\WorkerPool\Process\ProcessInfo;
use Duyler\WorkerPool\Signal\SignalHandler;
use Duyler\WorkerPool\Signal\SignalManager;
use Duyler\WorkerPool\Socket\SocketMsgWrapper;
use Duyler\WorkerPool\Socket\SocketMsgWrapperInterface;
use Duyler\WorkerPool\Socket\SocketWrapper;
use Duyler\WorkerPool\Socket\SocketWrapperInterface;
use Duyler\WorkerPool\Worker\EventDrivenWorkerInterface;
use Duyler\WorkerPool\Worker\WorkerCallbackInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Duyler\HttpServer\ErrorHandler\ErrorHandler;
use Duyler\HttpServer\Server;
use Duyler\HttpServer\ServerInterface;
use Duyler\WorkerPool\Process\ForkWrapper;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Psr\Log\NullLogger;

use ReflectionMethod;
use ReflectionProperty;

use function function_exists;

use const AF_INET;
use const AF_UNIX;
use const SCM_RIGHTS;
use const SOCK_STREAM;
use const SOL_SOCKET;
use const SOL_TCP;
use const SIGALRM;
use const SIG_DFL;

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
#[AllowMockObjectsWithoutExpectations]
final class CentralizedMasterRestartTest extends TestCase
{
    private SocketWrapperInterface&MockObject $socketWrapper;
    private SocketMsgWrapperInterface&MockObject $socketMsgWrapper;
    private ForkWrapperInterface&MockObject $forkWrapper;
    private LoggerInterface&MockObject $logger;
    private ?Server $capturedServer = null;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->socketWrapper = $this->createMock(SocketWrapperInterface::class);
        $this->socketMsgWrapper = $this->createMock(SocketMsgWrapperInterface::class);
        $this->forkWrapper = $this->createMock(ForkWrapperInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    #[Test]
    public function schedule_restart_with_delay_schedules_pending(): void
    {
        $serverConfig = new ServerConfig(host: '127.0.0.1', port: 9999);
        $config = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 1,
            autoRestart: true,
            restartDelay: 1,
        );

        $pair = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        $this->socketWrapper->method('createPair')->willReturnCallback(function (int $d, int $t, int $p, array &$s) use ($pair): bool {
            $s[0] = $pair[0];
            $s[1] = $pair[1];
            return true;
        });
        $this->forkWrapper->method('fork')->willReturn(100);
        $this->forkWrapper->method('kill')->willReturnCallback(function (int $pid, int $sig): bool {
            if (0 === $sig && 100 === $pid) {
                return false;
            }
            return true;
        });

        $callback = $this->createMock(WorkerCallbackInterface::class);
        $master = new CentralizedMaster(
            config: $config,
            balancer: new LeastConnectionsBalancer(),
            socketWrapper: $this->socketWrapper,
            socketMsgWrapper: $this->socketMsgWrapper,
            forkWrapper: $this->forkWrapper,
            serverConfig: $serverConfig,
            workerCallback: $callback,
            logger: $this->logger,
        );

        $spawnWorker = new ReflectionMethod($master, 'spawnWorker');
        $spawnWorker->invoke($master, 1);

        $checkWorkers = new ReflectionMethod($master, 'checkWorkers');
        $checkWorkers->invoke($master);

        $pendingProp = new ReflectionProperty(AbstractMaster::class, 'pendingRestarts');
        $pending = $pendingProp->getValue($master);
        $this->assertArrayHasKey(1, $pending);
    }

    #[Test]
    public function process_pending_restarts_respawns_worker_after_delay(): void
    {
        $serverConfig = new ServerConfig(host: '127.0.0.1', port: 9999);
        $config = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 1,
            autoRestart: true,
            restartDelay: 0,
        );

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
            return 100 + $forkCount;
        });
        $this->forkWrapper->method('kill')->willReturn(true);

        $callback = $this->createMock(WorkerCallbackInterface::class);
        $master = new CentralizedMaster(
            config: $config,
            balancer: new LeastConnectionsBalancer(),
            socketWrapper: $this->socketWrapper,
            socketMsgWrapper: $this->socketMsgWrapper,
            forkWrapper: $this->forkWrapper,
            serverConfig: $serverConfig,
            workerCallback: $callback,
            logger: $this->logger,
        );

        $pendingProp = new ReflectionProperty(AbstractMaster::class, 'pendingRestarts');
        $pendingProp->setValue($master, [1 => microtime(true) - 1]);

        $processPending = new ReflectionMethod(AbstractMaster::class, 'processPendingRestarts');
        $processPending->invoke($master);

        $pending = $pendingProp->getValue($master);
        $this->assertArrayNotHasKey(1, $pending);
        $this->assertSame(1, $forkCount);
    }

    #[Test]
    public function accept_connections_queue_full_rejects(): void
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
        $this->socketWrapper->method('getPeerName')->willReturn(true);

        $client1 = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $client2 = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $callCount = 0;
        $this->socketWrapper->method('accept')->willReturnCallback(function () use ($client1, $client2, &$callCount): mixed {
            $callCount++;
            if (1 === $callCount) {
                return $client1;
            }
            if (2 === $callCount) {
                return $client2;
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
        $this->socketMsgWrapper->method('sendmsg')->willReturn(10);

        $callback = $this->createMock(WorkerCallbackInterface::class);
        $master = new CentralizedMaster(
            config: $config,
            balancer: new LeastConnectionsBalancer(),
            socketWrapper: $this->socketWrapper,
            socketMsgWrapper: $this->socketMsgWrapper,
            forkWrapper: $this->forkWrapper,
            serverConfig: $serverConfig,
            workerCallback: $callback,
            logger: $this->logger,
        );

        $spawnWorker = new ReflectionMethod($master, 'spawnWorker');
        $spawnWorker->invoke($master, 1);

        $socketManagerProp = new ReflectionProperty($master, 'socketManager');
        $sm = $socketManagerProp->getValue($master);
        $listen = new ReflectionMethod($sm, 'listen');
        $listen->invoke($sm);

        $accept = new ReflectionMethod($master, 'acceptConnections');
        $accept->invoke($master);

        $queueProp = new ReflectionProperty($master, 'connectionQueue');
        $queue = $queueProp->getValue($master);
        $this->assertSame(1, $queue->size());
    }

    #[Test]
    public function run_event_driven_worker_receives_fd_in_fiber(): void
    {
        if (false === function_exists('socket_sendmsg')) {
            $this->markTestSkipped('socket_sendmsg not available');
        }

        $serverConfig = new ServerConfig(host: '127.0.0.1', port: 9999);
        $config = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 1,
            autoRestart: false,
        );

        $pair = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        [$masterSocket, $workerSocket] = $pair;

        $this->socketWrapper->method('createPair')->willReturnCallback(function (int $d, int $t, int $p, array &$s) use ($pair): bool {
            $s[0] = $pair[0];
            $s[1] = $pair[1];
            return true;
        });
        $this->forkWrapper->method('fork')->willReturn(100);

        $fiberReceived = false;
        $capturedServer = null;
        $eventWorker = new class ($fiberReceived, $capturedServer) implements EventDrivenWorkerInterface {
            public bool $fiberReceived;
            public ?ServerInterface $capturedServer = null;

            public function __construct(bool &$fiberReceived, ?ServerInterface &$capturedServer)
            {
                $this->fiberReceived = &$fiberReceived;
                $this->capturedServer = &$capturedServer;
            }

            public function run(int $workerId, ServerInterface $server): void
            {
                $this->fiberReceived = true;
                $this->capturedServer = $server;
            }
        };

        $master = new CentralizedMaster(
            config: $config,
            balancer: new LeastConnectionsBalancer(),
            socketWrapper: new SocketWrapper(),
            socketMsgWrapper: new SocketMsgWrapper(),
            forkWrapper: $this->forkWrapper,
            serverConfig: $serverConfig,
            eventDrivenWorker: $eventWorker,
            workerCallback: null,
            logger: $this->logger,
        );

        $clientSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $msg = [
            'iov' => [json_encode(['client_ip' => '127.0.0.1', 'worker_id' => 1])],
            'control' => [
                [
                    'level' => SOL_SOCKET,
                    'type' => SCM_RIGHTS,
                    'data' => [$clientSocket],
                ],
            ],
        ];
        socket_sendmsg($masterSocket, $msg, 0);

        pcntl_async_signals(true);
        pcntl_signal(SIGALRM, function () use ($master): void {
            $signalProp = new ReflectionProperty(AbstractMaster::class, 'signalManager');
            $sm = $signalProp->getValue($master);
            $sm->requestShutdown();
        });
        pcntl_alarm(1);

        $runEventDriven = new ReflectionMethod($master, 'runEventDrivenWorker');
        $runEventDriven->invoke($master, 1, $workerSocket);

        pcntl_alarm(0);
        pcntl_signal(SIGALRM, SIG_DFL);

        $this->assertTrue($eventWorker->fiberReceived);

        if (null !== $eventWorker->capturedServer) {
            $eventWorker->capturedServer->reset();
        }
        new ErrorHandler(new NullLogger())->reset();
    }
}

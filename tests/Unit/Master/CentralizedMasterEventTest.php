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
use Duyler\WorkerPool\Worker\EventDrivenWorkerInterface;
use Duyler\HttpServer\ErrorHandler\ErrorHandler;
use Duyler\WorkerPool\Worker\WorkerCallbackInterface;
use Override;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Duyler\WorkerPool\Master\WorkerManager;
use Duyler\WorkerPool\Process\ForkWrapper;
use Duyler\WorkerPool\Socket\SocketMsgWrapper;
use Duyler\WorkerPool\Socket\SocketWrapper;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Psr\Log\NullLogger;

use ReflectionMethod;
use ReflectionProperty;

use const AF_INET;
use const AF_UNIX;
use const SOCK_STREAM;
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
final class CentralizedMasterEventTest extends TestCase
{
    private SocketWrapperInterface&MockObject $socketWrapper;
    private SocketMsgWrapperInterface&MockObject $socketMsgWrapper;
    private ForkWrapperInterface&MockObject $forkWrapper;
    private LoggerInterface&MockObject $logger;
    private EventDrivenWorkerInterface&MockObject $eventDrivenWorker;
    private ?object $capturedServer = null;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->socketWrapper = $this->createMock(SocketWrapperInterface::class);
        $this->socketMsgWrapper = $this->createMock(SocketMsgWrapperInterface::class);
        $this->forkWrapper = $this->createMock(ForkWrapperInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->eventDrivenWorker = $this->createMock(EventDrivenWorkerInterface::class);
    }

    #[Override]
    protected function tearDown(): void
    {
        if (null !== $this->capturedServer) {
            $this->capturedServer->reset();
            $this->capturedServer = null;
        }
        (new ErrorHandler(new NullLogger()))->reset();
        parent::tearDown();
    }

    #[Test]
    public function run_event_driven_worker_creates_server_and_fiber(): void
    {
        $serverConfig = new ServerConfig(host: '127.0.0.1', port: 9999);
        $config = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 1,
            autoRestart: false,
        );

        $this->socketMsgWrapper->method('recvmsg')->willReturn(false);
        $this->socketMsgWrapper->method('cmsgSpace')->willReturn(256);

        $this->eventDrivenWorker->expects($this->once())->method('run')->willReturnCallback(function (int $workerId, object $server): void {
            $this->capturedServer = $server;
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
            eventDrivenWorker: $this->eventDrivenWorker,
            logger: $this->logger,
        );

        $runEventDrivenWorker = new ReflectionMethod($master, 'runEventDrivenWorker');

        $sockets = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $sockets);

        $runEventDrivenWorker->invoke($master, 1, $sockets[0]);

        $this->assertNotNull($this->capturedServer);
    }

    #[Test]
    public function run_callback_worker_receives_fd_and_handles(): void
    {
        $serverConfig = new ServerConfig(host: '127.0.0.1', port: 9999);
        $config = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 1,
            autoRestart: false,
        );

        $clientSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        $pair = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        $this->socketWrapper->method('createPair')->willReturnCallback(function (int $d, int $t, int $p, array &$s) use ($pair): bool {
            $s[0] = $pair[0];
            $s[1] = $pair[1];
            return true;
        });
        $this->forkWrapper->method('fork')->willReturn(100);

        $handled = false;
        $callback = $this->createMock(WorkerCallbackInterface::class);
        $callback->method('handle')->willReturnCallback(function () use (&$handled): void {
            $handled = true;
        });

        $recvmsgCallCount = 0;
        $this->socketMsgWrapper->method('cmsgSpace')->willReturn(256);
        $this->socketMsgWrapper->method('recvmsg')->willReturnCallback(function () use ($clientSocket, &$recvmsgCallCount): mixed {
            $recvmsgCallCount++;
            return false;
        });

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

        $signalProp = new ReflectionProperty(AbstractMaster::class, 'signalManager');
        $sm = $signalProp->getValue($master);

        pcntl_async_signals(true);
        pcntl_signal(SIGALRM, function () use ($sm): void {
            $sm->requestShutdown();
        });
        pcntl_alarm(1);

        $runCallbackWorker = new ReflectionMethod($master, 'runCallbackWorker');

        $sockets = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $sockets);

        $runCallbackWorker->invoke($master, 1, $sockets[0]);

        pcntl_alarm(0);
        pcntl_signal(SIGALRM, SIG_DFL);

        $this->assertTrue($sm->isShutdownRequested());
    }
}

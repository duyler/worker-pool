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
use Duyler\WorkerPool\Worker\EventDrivenWorkerInterface;

use ReflectionMethod;
use ReflectionProperty;

use const AF_UNIX;
use const SOCK_STREAM;
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
final class CentralizedMasterCallbackTest extends TestCase
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
    public function run_callback_worker_exits_on_shutdown(): void
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
        $this->socketMsgWrapper->method('recvmsg')->willReturn(false);

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
        pcntl_alarm(1);

        $runCallbackWorker = new ReflectionMethod($master, 'runCallbackWorker');
        $runCallbackWorker->invoke($master, 1, $pair[0]);

        pcntl_alarm(0);
        pcntl_signal(SIGALRM, SIG_DFL);

        $this->assertTrue($sm->isShutdownRequested());
    }

    #[Test]
    public function run_callback_worker_handles_null_callback(): void
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

        $eventDrivenWorker = $this->createStub(EventDrivenWorkerInterface::class);

        $master = new CentralizedMaster(
            config: $config,
            balancer: new LeastConnectionsBalancer(),
            socketWrapper: $this->socketWrapper,
            socketMsgWrapper: $this->socketMsgWrapper,
            forkWrapper: $this->forkWrapper,
            serverConfig: $serverConfig,
            eventDrivenWorker: $eventDrivenWorker,
            logger: $this->logger,
        );

        $signalProp = new ReflectionProperty(AbstractMaster::class, 'signalManager');
        $signalProp->getValue($master)->requestShutdown();

        $runCallbackWorker = new ReflectionMethod($master, 'runCallbackWorker');
        $runCallbackWorker->invoke($master, 1, $pair[0]);

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function accept_connections_returns_early_without_socket_manager(): void
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

        $acceptConnections = new ReflectionMethod($master, 'acceptConnections');
        $acceptConnections->invoke($master);

        $this->assertTrue($master->isRunning());
    }

    #[Test]
    public function distribute_connections_returns_early_without_queue(): void
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

        $distributeConnections = new ReflectionMethod($master, 'distributeConnections');
        $distributeConnections->invoke($master);

        $this->assertTrue($master->isRunning());
    }
}

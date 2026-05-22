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
use Duyler\WorkerPool\Signal\SignalHandler;
use Duyler\WorkerPool\Signal\SignalManager;
use Duyler\WorkerPool\Socket\SocketWrapperInterface;
use Duyler\WorkerPool\Worker\EventDrivenWorkerInterface;
use Override;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Duyler\HttpServer\ErrorHandler\ErrorHandler;
use Duyler\WorkerPool\Master\WorkerManager;
use Duyler\WorkerPool\Process\ForkWrapper;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Psr\Log\NullLogger;

use ReflectionMethod;
use Socket;

use const AF_INET;
use const SOCK_STREAM;
use const SOL_TCP;

#[CoversClass(SharedSocketMaster::class)]
#[UsesClass(AbstractMaster::class)]
#[UsesClass(WorkerPoolConfig::class)]
#[UsesClass(SignalHandler::class)]
#[UsesClass(SignalManager::class)]
#[UsesClass(WorkerManager::class)]
#[UsesClass(ProcessInfo::class)]
#[UsesClass(WorkerPoolException::class)]
#[UsesClass(ForkWrapper::class)]
#[AllowMockObjectsWithoutExpectations]
final class SharedSocketMasterEventTest extends TestCase
{
    private SocketWrapperInterface&MockObject $socketWrapper;
    private ForkWrapperInterface&MockObject $forkWrapper;
    private LoggerInterface&MockObject $logger;
    private EventDrivenWorkerInterface&MockObject $eventDrivenWorker;
    private ?object $capturedServer = null;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->socketWrapper = $this->createMock(SocketWrapperInterface::class);
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
    public function run_event_driven_worker_creates_server(): void
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
        $this->forkWrapper->method('fork')->willReturn(100);

        $this->eventDrivenWorker->expects($this->once())->method('run')->willReturnCallback(function (int $workerId, object $server): void {
            $this->capturedServer = $server;
        });

        $master = new SharedSocketMaster(
            config: $config,
            serverConfig: $serverConfig,
            socketWrapper: $this->socketWrapper,
            forkWrapper: $this->forkWrapper,
            eventDrivenWorker: $this->eventDrivenWorker,
            logger: $this->logger,
        );

        $runEventDrivenWorker = new ReflectionMethod($master, 'runEventDrivenWorker');
        $runEventDrivenWorker->invoke($master, 1);

        $this->assertNotNull($this->capturedServer);
    }

    #[Test]
    public function create_reuse_port_socket_full_path(): void
    {
        $serverConfig = new ServerConfig(host: '127.0.0.1', port: 9998);
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
        $this->forkWrapper->method('fork')->willReturn(100);

        $master = new SharedSocketMaster(
            config: $config,
            serverConfig: $serverConfig,
            socketWrapper: $this->socketWrapper,
            forkWrapper: $this->forkWrapper,
            eventDrivenWorker: $this->eventDrivenWorker,
            logger: $this->logger,
        );

        $createSocket = new ReflectionMethod($master, 'createReusePortSocket');
        $result = $createSocket->invoke($master, 1);

        $this->assertInstanceOf(Socket::class, $result);
    }
}

<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\Master;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\WorkerPool\Config\WorkerPoolConfig;
use Duyler\WorkerPool\Exception\WorkerPoolExceptionBase;
use Duyler\WorkerPool\Master\AbstractMaster;
use Duyler\WorkerPool\Master\SharedSocketMaster;
use Duyler\WorkerPool\Process\ForkWrapperInterface;
use Duyler\WorkerPool\Process\ProcessInfo;
use Duyler\WorkerPool\Signal\SignalHandler;
use Duyler\WorkerPool\Signal\SignalManager;
use Duyler\WorkerPool\Socket\SocketWrapperInterface;
use Duyler\WorkerPool\Worker\WorkerCallbackInterface;
use Override;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Duyler\WorkerPool\Master\WorkerManager;
use Duyler\WorkerPool\Process\ForkWrapper;

use ReflectionMethod;
use ReflectionProperty;

use const AF_INET;
use const SOCK_STREAM;
use const SOL_TCP;
use const SIGALRM;
use const SIG_DFL;

#[CoversClass(SharedSocketMaster::class)]
#[UsesClass(AbstractMaster::class)]
#[UsesClass(WorkerPoolConfig::class)]
#[UsesClass(SignalHandler::class)]
#[UsesClass(SignalManager::class)]
#[UsesClass(WorkerManager::class)]
#[UsesClass(ProcessInfo::class)]
#[UsesClass(WorkerPoolExceptionBase::class)]
#[UsesClass(ForkWrapper::class)]
final class SharedSocketMasterRunCoverageTest extends TestCase
{
    private SocketWrapperInterface $socketWrapper;
    private ForkWrapperInterface $forkWrapper;
    private LoggerInterface $logger;
    private WorkerCallbackInterface $callback;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->socketWrapper = $this->createStub(SocketWrapperInterface::class);
        $this->forkWrapper = $this->createStub(ForkWrapperInterface::class);
        $this->logger = $this->createStub(LoggerInterface::class);
        $this->callback = $this->createStub(WorkerCallbackInterface::class);
    }

    #[Test]
    public function run_loop_with_workers_and_check(): void
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

        $this->forkWrapper->method('fork')->willReturn(getmypid());
        $this->forkWrapper->method('kill')->willReturn(true);
        $this->forkWrapper->method('waitpid')->willReturn(0);

        $master = new SharedSocketMaster(
            config: $config,
            serverConfig: $serverConfig,
            socketWrapper: $this->socketWrapper,
            forkWrapper: $this->forkWrapper,
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
    public function run_callback_worker_accepts_and_handles_connection(): void
    {
        $serverConfig = new ServerConfig(host: '127.0.0.1', port: 9999);
        $config = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 1,
            autoRestart: false,
        );

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $clientSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->socketWrapper->method('create')->willReturn($socket);
        $this->socketWrapper->method('setOption')->willReturn(true);
        $this->socketWrapper->method('bind')->willReturn(true);
        $this->socketWrapper->method('listen')->willReturn(true);

        $acceptCallCount = 0;
        $this->socketWrapper->method('accept')->willReturnCallback(function () use ($clientSocket, &$acceptCallCount): mixed {
            $acceptCallCount++;
            if (1 === $acceptCallCount) {
                return $clientSocket;
            }
            return false;
        });
        $this->socketWrapper->method('getPeerName')->willReturnCallback(function (mixed $socket, string &$ip): bool {
            $ip = '127.0.0.1';
            return true;
        });

        $this->forkWrapper->method('fork')->willReturn(100);

        $callbackCalled = false;
        $callbackWithMetadata = null;
        $callbackWorker = new class ($callbackCalled, $callbackWithMetadata) implements WorkerCallbackInterface {
            public function __construct(private bool &$called, private ?array &$metadata) {}

            public function handle(mixed $clientSocket, array $metadata): void
            {
                $this->called = true;
                $this->metadata = $metadata;
            }
        };

        $master = new SharedSocketMaster(
            config: $config,
            serverConfig: $serverConfig,
            socketWrapper: $this->socketWrapper,
            forkWrapper: $this->forkWrapper,
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
        $runCallbackWorker->invoke($master, 1);

        pcntl_alarm(0);
        pcntl_signal(SIGALRM, SIG_DFL);

        $this->assertTrue($callbackCalled);
        $this->assertSame(1, $callbackWithMetadata['worker_id']);
        $this->assertSame('127.0.0.1', $callbackWithMetadata['client_ip']);
    }

    #[Test]
    public function start_spawns_and_runs(): void
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

        $this->forkWrapper->method('fork')->willReturn(getmypid());
        $this->forkWrapper->method('kill')->willReturn(true);
        $this->forkWrapper->method('waitpid')->willReturn(0);

        $master = new SharedSocketMaster(
            config: $config,
            serverConfig: $serverConfig,
            socketWrapper: $this->socketWrapper,
            forkWrapper: $this->forkWrapper,
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

        $this->assertCount(1, $master->getWorkers());
    }

    #[Test]
    public function get_metrics_with_alive_workers(): void
    {
        $serverConfig = new ServerConfig(host: '127.0.0.1', port: 9999);
        $config = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 2,
            autoRestart: false,
        );

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->socketWrapper->method('create')->willReturn($socket);
        $this->socketWrapper->method('setOption')->willReturn(true);
        $this->socketWrapper->method('bind')->willReturn(true);
        $this->socketWrapper->method('listen')->willReturn(true);

        $this->forkWrapper->method('fork')->willReturn(getmypid());
        $this->forkWrapper->method('kill')->willReturn(true);

        $master = new SharedSocketMaster(
            config: $config,
            serverConfig: $serverConfig,
            socketWrapper: $this->socketWrapper,
            forkWrapper: $this->forkWrapper,
            workerCallback: $this->callback,
            logger: $this->logger,
        );

        $spawnWorker = new ReflectionMethod($master, 'spawnWorker');
        $spawnWorker->invoke($master, 1);
        $spawnWorker->invoke($master, 2);

        $metrics = $master->getMetrics();

        $this->assertSame('shared_socket', $metrics['architecture']);
        $this->assertSame(2, $metrics['total_workers']);
        $this->assertSame(2, $metrics['active_workers']);
        $this->assertSame(0, $metrics['total_connections']);
        $this->assertTrue($metrics['is_running']);
    }
}

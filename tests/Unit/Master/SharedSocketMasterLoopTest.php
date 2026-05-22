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
#[UsesClass(WorkerPoolException::class)]
#[UsesClass(ForkWrapper::class)]
final class SharedSocketMasterLoopTest extends TestCase
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
    public function run_callback_worker_accepts_client(): void
    {
        $serverConfig = new ServerConfig(host: '127.0.0.1', port: 9999);
        $config = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 1,
            autoRestart: false,
        );

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $clientSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        $acceptCount = 0;
        $this->socketWrapper->method('create')->willReturn($socket);
        $this->socketWrapper->method('setOption')->willReturn(true);
        $this->socketWrapper->method('bind')->willReturn(true);
        $this->socketWrapper->method('listen')->willReturn(true);
        $this->socketWrapper->method('accept')->willReturnCallback(function () use ($clientSocket, &$acceptCount): mixed {
            $acceptCount++;
            if (1 === $acceptCount) {
                return $clientSocket;
            }
            return false;
        });
        $this->socketWrapper->method('getPeerName')->willReturnCallback(function (mixed $sock, string &$addr): mixed {
            $addr = '127.0.0.1';
            return true;
        });
        $this->forkWrapper->method('fork')->willReturn(100);

        $handled = false;
        $this->callback->method('handle')->willReturnCallback(function () use (&$handled): void {
            $handled = true;
        });

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
        pcntl_alarm(1);

        $runCallbackWorker = new ReflectionMethod($master, 'runCallbackWorker');
        $runCallbackWorker->invoke($master, 1);

        pcntl_alarm(0);
        pcntl_signal(SIGALRM, SIG_DFL);

        $this->assertTrue($handled);
    }

    #[Test]
    public function run_loop_iterates_once_then_exits(): void
    {
        $serverConfig = new ServerConfig(host: '127.0.0.1', port: 9999);
        $config = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 1,
            autoRestart: false,
            pollInterval: 1000,
        );

        $this->forkWrapper->method('fork')->willReturn(100);
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
        pcntl_alarm(1);

        $run = new ReflectionMethod($master, 'run');
        $run->invoke($master);

        pcntl_alarm(0);
        pcntl_signal(SIGALRM, SIG_DFL);

        $this->assertFalse($master->isRunning());
    }

    #[Test]
    public function spawn_worker_parent_path_registers_worker(): void
    {
        $serverConfig = new ServerConfig(host: '127.0.0.1', port: 9999);
        $config = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 2,
            autoRestart: false,
        );

        $forkCount = 0;
        $this->forkWrapper->method('fork')->willReturnCallback(function () use (&$forkCount): int {
            $forkCount++;
            return 100 + $forkCount;
        });

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

        $workers = $master->getWorkers();
        $this->assertCount(2, $workers);
        $this->assertArrayHasKey(1, $workers);
        $this->assertArrayHasKey(2, $workers);
        $this->assertSame(101, $workers[1]->pid);
        $this->assertSame(102, $workers[2]->pid);
    }
}

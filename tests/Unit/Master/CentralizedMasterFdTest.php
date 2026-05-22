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
use Duyler\WorkerPool\Socket\SocketWrapper;
use Duyler\WorkerPool\Worker\WorkerCallbackInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Socket;
use Duyler\WorkerPool\Process\ForkWrapper;
use Duyler\WorkerPool\Worker\EventDrivenWorkerInterface;

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
final class CentralizedMasterFdTest extends TestCase
{
    private ForkWrapperInterface $forkWrapper;
    private LoggerInterface $logger;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->forkWrapper = $this->createStub(ForkWrapperInterface::class);
        $this->logger = $this->createStub(LoggerInterface::class);
    }

    #[Test]
    public function run_callback_worker_receives_fd_and_calls_handle(): void
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

        $this->forkWrapper->method('fork')->willReturn(100);

        $callback = new class implements WorkerCallbackInterface {
            public bool $handled = false;

            public function handle(mixed $clientSocket, array $metadata): void
            {
                $this->handled = true;
                if ($clientSocket instanceof Socket) {
                    socket_close($clientSocket);
                }
            }
        };

        $master = new CentralizedMaster(
            config: $config,
            balancer: new LeastConnectionsBalancer(),
            socketWrapper: new SocketWrapper(),
            socketMsgWrapper: new SocketMsgWrapper(),
            forkWrapper: $this->forkWrapper,
            serverConfig: $serverConfig,
            workerCallback: $callback,
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

        $runCallbackWorker = new ReflectionMethod($master, 'runCallbackWorker');
        $runCallbackWorker->invoke($master, 1, $workerSocket);

        pcntl_alarm(0);
        pcntl_signal(SIGALRM, SIG_DFL);

        $this->assertTrue($callback->handled);
    }

    #[Test]
    public function run_callback_worker_no_callback_logs_warning(): void
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

        $this->forkWrapper->method('fork')->willReturn(100);

        $eventDrivenWorker = $this->createStub(EventDrivenWorkerInterface::class);

        $master = new CentralizedMaster(
            config: $config,
            balancer: new LeastConnectionsBalancer(),
            socketWrapper: new SocketWrapper(),
            socketMsgWrapper: new SocketMsgWrapper(),
            forkWrapper: $this->forkWrapper,
            serverConfig: $serverConfig,
            workerCallback: null,
            eventDrivenWorker: $eventDrivenWorker,
            logger: $this->logger,
        );

        $clientSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $msg = [
            'iov' => [json_encode(['client_ip' => '127.0.0.1'])],
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

        $runCallbackWorker = new ReflectionMethod($master, 'runCallbackWorker');
        $runCallbackWorker->invoke($master, 1, $workerSocket);

        pcntl_alarm(0);
        pcntl_signal(SIGALRM, SIG_DFL);

        $this->assertFalse($master->isRunning());
    }
}

<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\ErrorHandler\ErrorHandler;
use Duyler\WorkerPool\Balancer\RoundRobinBalancer;
use Duyler\WorkerPool\Config\WorkerPoolConfig;
use Duyler\WorkerPool\Master\CentralizedMaster;
use Duyler\WorkerPool\Process\ProcessInfo;
use Duyler\WorkerPool\Process\ProcessState;
use Duyler\WorkerPool\Worker\WorkerCallbackInterface;
use Override;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use InvalidArgumentException;
use ReflectionMethod;
use ReflectionProperty;
use Psr\Log\NullLogger;

use Duyler\WorkerPool\Process\ForkWrapper;
use Duyler\WorkerPool\Socket\SocketWrapper;
use Duyler\WorkerPool\Socket\SocketMsgWrapper;

use Duyler\WorkerPool\IPC\FdPasser;
use Duyler\WorkerPool\Master\ConnectionQueue;
use Duyler\WorkerPool\Master\ConnectionRouter;
use Duyler\WorkerPool\Master\SocketManager;
use Duyler\WorkerPool\Master\WorkerManager;
use Duyler\WorkerPool\Signal\SignalHandler;
use Duyler\WorkerPool\Signal\SignalManager;

use const SIGTERM;

#[Group('pcntl')]
#[CoversClass(CentralizedMaster::class)]
#[UsesClass(RoundRobinBalancer::class)]
#[UsesClass(WorkerPoolConfig::class)]
#[UsesClass(FdPasser::class)]
#[UsesClass(ConnectionQueue::class)]
#[UsesClass(ConnectionRouter::class)]
#[UsesClass(SocketManager::class)]
#[UsesClass(WorkerManager::class)]
#[UsesClass(ForkWrapper::class)]
#[UsesClass(ProcessInfo::class)]
#[UsesClass(SignalHandler::class)]
#[UsesClass(SignalManager::class)]
final class CentralizedMasterCoverageTest extends TestCase
{
    private ServerConfig $sc;

    #[Override]
    protected function setUp(): void
    {
        $this->sc = new ServerConfig(host: '127.0.0.1', port: 19800);
    }

    #[Override]
    protected function tearDown(): void
    {
        new ErrorHandler(new NullLogger())->reset();
        parent::tearDown();
    }

    #[Test]
    public function constructor_throws_without_callback(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CentralizedMaster(
            config: new WorkerPoolConfig(serverConfig: $this->sc, workerCount: 1),
            balancer: new RoundRobinBalancer(1),
            socketWrapper: new SocketWrapper(),
            socketMsgWrapper: new SocketMsgWrapper(),
            forkWrapper: new ForkWrapper(),
        );
    }

    #[Test]
    public function constructor_with_server_config(): void
    {
        $callback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void {}
        };

        $config = new WorkerPoolConfig(serverConfig: $this->sc, workerCount: 1);
        $balancer = new RoundRobinBalancer(1);

        $master = new CentralizedMaster(
            config: $config,
            balancer: $balancer,
            socketWrapper: new SocketWrapper(),
            socketMsgWrapper: new SocketMsgWrapper(),
            forkWrapper: new ForkWrapper(),
            serverConfig: $this->sc,
            workerCallback: $callback,
        );

        $this->assertSame(0, $master->getWorkerCount());
    }

    #[Test]
    public function stop_kills_all_workers(): void
    {
        $callback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void {}
        };

        $config = new WorkerPoolConfig(serverConfig: $this->sc, workerCount: 2);
        $balancer = new RoundRobinBalancer(2);

        $master = new CentralizedMaster(
            config: $config,
            balancer: $balancer,
            socketWrapper: new SocketWrapper(),
            socketMsgWrapper: new SocketMsgWrapper(),
            forkWrapper: new ForkWrapper(),
            workerCallback: $callback,
        );

        $pid1 = pcntl_fork();
        if (0 === $pid1) {
            sleep(30);
            exit(0);
        }

        $pid2 = pcntl_fork();
        if (0 === $pid2) {
            sleep(30);
            exit(0);
        }

        $wmRef = new ReflectionProperty($master, 'workerManager');
        $workerManager = $wmRef->getValue($master);
        $workerManager->updateWorker(1, new ProcessInfo(1, $pid1, ProcessState::Ready, new ForkWrapper()));
        $workerManager->updateWorker(2, new ProcessInfo(2, $pid2, ProcessState::Ready, new ForkWrapper()));

        $this->assertTrue($master->isRunning());
        $master->stop();
        $this->assertFalse($master->isRunning());

        pcntl_waitpid($pid1, $s1);
        pcntl_waitpid($pid2, $s2);
    }

    #[Test]
    public function run_loop_exits_immediately_when_shutdown_requested(): void
    {
        $callback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void {}
        };

        $config = new WorkerPoolConfig(serverConfig: $this->sc, workerCount: 1);
        $balancer = new RoundRobinBalancer(1);

        $master = new CentralizedMaster(
            config: $config,
            balancer: $balancer,
            socketWrapper: new SocketWrapper(),
            socketMsgWrapper: new SocketMsgWrapper(),
            forkWrapper: new ForkWrapper(),
            workerCallback: $callback,
        );

        $signalManagerRef = new ReflectionProperty($master, 'signalManager');
        $signalManager = $signalManagerRef->getValue($master);
        $signalManager->requestShutdown();

        $runRef = new ReflectionMethod($master, 'run');
        $runRef->invoke($master);

        $this->assertFalse($master->isRunning());
    }

    #[Test]
    public function check_workers_detects_dead_worker_no_restart(): void
    {
        $callback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void {}
        };

        $config = new WorkerPoolConfig(serverConfig: $this->sc, workerCount: 1, autoRestart: false);
        $balancer = new RoundRobinBalancer(1);

        $master = new CentralizedMaster(
            config: $config,
            balancer: $balancer,
            socketWrapper: new SocketWrapper(),
            socketMsgWrapper: new SocketMsgWrapper(),
            forkWrapper: new ForkWrapper(),
            workerCallback: $callback,
        );

        $pid = pcntl_fork();
        if (0 === $pid) {
            exit(0);
        }

        $wmRef = new ReflectionProperty($master, 'workerManager');
        $workerManager = $wmRef->getValue($master);
        $workerManager->updateWorker(1, new ProcessInfo(1, $pid, ProcessState::Ready, new ForkWrapper()));

        usleep(50000);

        $checkRef = new ReflectionMethod($master, 'checkWorkers');
        $checkRef->invoke($master);

        $this->assertCount(0, $master->getWorkers());
    }

    #[Test]
    public function get_metrics_with_alive_workers(): void
    {
        $callback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void {}
        };

        $config = new WorkerPoolConfig(serverConfig: $this->sc, workerCount: 1);
        $balancer = new RoundRobinBalancer(1);

        $master = new CentralizedMaster(
            config: $config,
            balancer: $balancer,
            socketWrapper: new SocketWrapper(),
            socketMsgWrapper: new SocketMsgWrapper(),
            forkWrapper: new ForkWrapper(),
            workerCallback: $callback,
        );

        $pid = pcntl_fork();
        if (0 === $pid) {
            sleep(30);
            exit(0);
        }

        $wmRef = new ReflectionProperty($master, 'workerManager');
        $workerManager = $wmRef->getValue($master);
        $workerManager->updateWorker(1, new ProcessInfo(1, $pid, ProcessState::Ready, new ForkWrapper()));

        $metrics = $master->getMetrics();
        $this->assertSame(1, $metrics['total_workers']);
        $this->assertSame(1, $metrics['alive_workers']);
        $this->assertSame(0, $metrics['total_connections']);
        $this->assertSame(0, $metrics['total_requests']);
        $this->assertTrue($metrics['is_running']);
        $this->assertSame(0, $metrics['queue_size']);

        posix_kill($pid, SIGTERM);
        pcntl_waitpid($pid, $s);
    }

    #[Test]
    public function get_balancer_returns_correct_instance(): void
    {
        $callback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void {}
        };

        $balancer = new RoundRobinBalancer(1);
        $config = new WorkerPoolConfig(serverConfig: $this->sc, workerCount: 1);

        $master = new CentralizedMaster(
            config: $config,
            balancer: $balancer,
            socketWrapper: new SocketWrapper(),
            socketMsgWrapper: new SocketMsgWrapper(),
            forkWrapper: new ForkWrapper(),
            workerCallback: $callback,
        );

        $this->assertSame($balancer, $master->getBalancer());
    }

    #[Test]
    public function accept_connections_via_reflection(): void
    {
        $callback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void {}
        };

        $serverConfig = new ServerConfig(host: '127.0.0.1', port: 19802);
        $config = new WorkerPoolConfig(serverConfig: $serverConfig, workerCount: 1);
        $balancer = new RoundRobinBalancer(1);

        $master = new CentralizedMaster(
            config: $config,
            balancer: $balancer,
            socketWrapper: new SocketWrapper(),
            socketMsgWrapper: new SocketMsgWrapper(),
            forkWrapper: new ForkWrapper(),
            serverConfig: $serverConfig,
            workerCallback: $callback,
        );

        $acceptRef = new ReflectionMethod($master, 'acceptConnections');
        $acceptRef->invoke($master);
        $acceptRef->invoke($master);

        $metrics = $master->getMetrics();
        $this->assertSame(0, $metrics['queue_size']);
    }

    #[Test]
    public function distribute_connections_via_reflection(): void
    {
        $callback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void {}
        };

        $serverConfig = new ServerConfig(host: '127.0.0.1', port: 19803);
        $config = new WorkerPoolConfig(serverConfig: $serverConfig, workerCount: 1);
        $balancer = new RoundRobinBalancer(1);

        $master = new CentralizedMaster(
            config: $config,
            balancer: $balancer,
            socketWrapper: new SocketWrapper(),
            socketMsgWrapper: new SocketMsgWrapper(),
            forkWrapper: new ForkWrapper(),
            serverConfig: $serverConfig,
            workerCallback: $callback,
        );

        $distributeRef = new ReflectionMethod($master, 'distributeConnections');
        $distributeRef->invoke($master);

        $metrics = $master->getMetrics();
        $this->assertSame(0, $metrics['queue_size']);
    }
}

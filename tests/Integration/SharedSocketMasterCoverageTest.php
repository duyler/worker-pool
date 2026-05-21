<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\ErrorHandler\ErrorHandler;
use Duyler\WorkerPool\Config\WorkerPoolConfig;
use Duyler\WorkerPool\Master\SharedSocketMaster;
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

use const SIGTERM;

#[Group('pcntl')]
#[CoversClass(SharedSocketMaster::class)]
final class SharedSocketMasterCoverageTest extends TestCase
{
    private ServerConfig $sc;

    #[Override]
    protected function setUp(): void
    {
        $this->sc = new ServerConfig(host: '127.0.0.1', port: 19900);
    }

    #[Override]
    protected function tearDown(): void
    {
        (new ErrorHandler(new NullLogger()))->reset();
        parent::tearDown();
    }

    #[Test]
    public function constructor_throws_without_callback(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SharedSocketMaster(
            config: new WorkerPoolConfig(serverConfig: $this->sc, workerCount: 1),
            serverConfig: $this->sc,
            socketWrapper: new SocketWrapper(),
            forkWrapper: new ForkWrapper(),
        );
    }

    #[Test]
    public function stop_kills_workers(): void
    {
        $callback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void {}
        };

        $config = new WorkerPoolConfig(serverConfig: $this->sc, workerCount: 2);

        $master = new SharedSocketMaster(
            config: $config,
            serverConfig: $this->sc,
            socketWrapper: new SocketWrapper(),
            forkWrapper: new ForkWrapper(),
            workerCallback: $callback,
        );

        $pid1 = pcntl_fork();
        if (0 === $pid1) {
            usleep(5000000);
            exit(0);
        }

        $pid2 = pcntl_fork();
        if (0 === $pid2) {
            usleep(5000000);
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

        $master = new SharedSocketMaster(
            config: $config,
            serverConfig: $this->sc,
            socketWrapper: new SocketWrapper(),
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
    public function check_workers_detects_dead_worker(): void
    {
        $callback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void {}
        };

        $config = new WorkerPoolConfig(serverConfig: $this->sc, workerCount: 1, autoRestart: false);

        $master = new SharedSocketMaster(
            config: $config,
            serverConfig: $this->sc,
            socketWrapper: new SocketWrapper(),
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
    public function get_metrics_with_workers(): void
    {
        $callback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void {}
        };

        $config = new WorkerPoolConfig(serverConfig: $this->sc, workerCount: 1);

        $master = new SharedSocketMaster(
            config: $config,
            serverConfig: $this->sc,
            socketWrapper: new SocketWrapper(),
            forkWrapper: new ForkWrapper(),
            workerCallback: $callback,
        );

        $pid = pcntl_fork();
        if (0 === $pid) {
            usleep(5000000);
            exit(0);
        }

        $wmRef = new ReflectionProperty($master, 'workerManager');
        $workerManager = $wmRef->getValue($master);
        $workerManager->updateWorker(1, new ProcessInfo(1, $pid, ProcessState::Ready, new ForkWrapper()));

        $metrics = $master->getMetrics();
        $this->assertSame('shared_socket', $metrics['architecture']);
        $this->assertSame(1, $metrics['total_workers']);
        $this->assertSame(1, $metrics['active_workers']);
        $this->assertSame(0, $metrics['total_connections']);
        $this->assertTrue($metrics['is_running']);

        posix_kill($pid, SIGTERM);
        pcntl_waitpid($pid, $s);
    }
}

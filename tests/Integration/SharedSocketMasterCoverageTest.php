<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Integration;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\ErrorHandler;
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

use const SIGTERM;

#[Group('pcntl')]
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
        ErrorHandler::reset();
        parent::tearDown();
    }

    #[Test]
    public function constructorThrowsWithoutCallback(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SharedSocketMaster(
            config: new WorkerPoolConfig(serverConfig: $this->sc, workerCount: 1),
            serverConfig: $this->sc,
        );
    }

    #[Test]
    public function stopKillsWorkers(): void
    {
        $callback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void {}
        };

        $config = new WorkerPoolConfig(serverConfig: $this->sc, workerCount: 2);

        $master = new SharedSocketMaster(
            config: $config,
            serverConfig: $this->sc,
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

        $workersRef = new ReflectionProperty($master, 'workers');
        $workersRef->setValue($master, [
            1 => new ProcessInfo(1, $pid1, ProcessState::Ready),
            2 => new ProcessInfo(2, $pid2, ProcessState::Ready),
        ]);

        $this->assertTrue($master->isRunning());
        $master->stop();
        $this->assertFalse($master->isRunning());

        pcntl_waitpid($pid1, $s1);
        pcntl_waitpid($pid2, $s2);
    }

    #[Test]
    public function runLoopExitsImmediatelyWhenShouldStopIsTrue(): void
    {
        $callback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void {}
        };

        $config = new WorkerPoolConfig(serverConfig: $this->sc, workerCount: 1);

        $master = new SharedSocketMaster(
            config: $config,
            serverConfig: $this->sc,
            workerCallback: $callback,
        );

        $shouldStopRef = new ReflectionProperty($master, 'shouldStop');
        $shouldStopRef->setValue($master, true);

        $runRef = new ReflectionMethod($master, 'run');
        $runRef->invoke($master);

        $this->assertFalse($master->isRunning());
    }

    #[Test]
    public function checkWorkersDetectsDeadWorker(): void
    {
        $callback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void {}
        };

        $config = new WorkerPoolConfig(serverConfig: $this->sc, workerCount: 1, autoRestart: false);

        $master = new SharedSocketMaster(
            config: $config,
            serverConfig: $this->sc,
            workerCallback: $callback,
        );

        $pid = pcntl_fork();
        if (0 === $pid) {
            exit(0);
        }

        $workersRef = new ReflectionProperty($master, 'workers');
        $workersRef->setValue($master, [1 => new ProcessInfo(1, $pid, ProcessState::Ready)]);

        usleep(50000);

        $checkRef = new ReflectionMethod($master, 'checkWorkers');
        $checkRef->invoke($master);

        $this->assertCount(0, $master->getWorkers());
    }

    #[Test]
    public function getMetricsWithWorkers(): void
    {
        $callback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void {}
        };

        $config = new WorkerPoolConfig(serverConfig: $this->sc, workerCount: 1);

        $master = new SharedSocketMaster(
            config: $config,
            serverConfig: $this->sc,
            workerCallback: $callback,
        );

        $pid = pcntl_fork();
        if (0 === $pid) {
            usleep(5000000);
            exit(0);
        }

        $workersRef = new ReflectionProperty($master, 'workers');
        $workersRef->setValue($master, [1 => new ProcessInfo(1, $pid, ProcessState::Ready)]);

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

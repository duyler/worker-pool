<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Integration;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\ErrorHandler;
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

use const SIGTERM;

#[Group('pcntl')]
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
        ErrorHandler::reset();
        parent::tearDown();
    }

    #[Test]
    public function constructorThrowsWithoutCallback(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CentralizedMaster(
            config: new WorkerPoolConfig(serverConfig: $this->sc, workerCount: 1),
            balancer: new RoundRobinBalancer(1),
        );
    }

    #[Test]
    public function constructorWithServerConfig(): void
    {
        $callback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void {}
        };

        $config = new WorkerPoolConfig(serverConfig: $this->sc, workerCount: 1);
        $balancer = new RoundRobinBalancer(1);

        $master = new CentralizedMaster(
            config: $config,
            balancer: $balancer,
            serverConfig: $this->sc,
            workerCallback: $callback,
        );

        $this->assertSame(0, $master->getWorkerCount());
    }

    #[Test]
    public function stopKillsAllWorkers(): void
    {
        $callback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void {}
        };

        $config = new WorkerPoolConfig(serverConfig: $this->sc, workerCount: 2);
        $balancer = new RoundRobinBalancer(2);

        $master = new CentralizedMaster(
            config: $config,
            balancer: $balancer,
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
        $balancer = new RoundRobinBalancer(1);

        $master = new CentralizedMaster(
            config: $config,
            balancer: $balancer,
            workerCallback: $callback,
        );

        $shouldStopRef = new ReflectionProperty($master, 'shouldStop');
        $shouldStopRef->setValue($master, true);

        $runRef = new ReflectionMethod($master, 'run');
        $runRef->invoke($master);

        $this->assertFalse($master->isRunning());
    }

    #[Test]
    public function checkWorkersDetectsDeadWorkerNoRestart(): void
    {
        $callback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void {}
        };

        $config = new WorkerPoolConfig(serverConfig: $this->sc, workerCount: 1, autoRestart: false);
        $balancer = new RoundRobinBalancer(1);

        $master = new CentralizedMaster(
            config: $config,
            balancer: $balancer,
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
    public function getMetricsWithAliveWorkers(): void
    {
        $callback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void {}
        };

        $config = new WorkerPoolConfig(serverConfig: $this->sc, workerCount: 1);
        $balancer = new RoundRobinBalancer(1);

        $master = new CentralizedMaster(
            config: $config,
            balancer: $balancer,
            workerCallback: $callback,
        );

        $pid = pcntl_fork();
        if (0 === $pid) {
            sleep(30);
            exit(0);
        }

        $workersRef = new ReflectionProperty($master, 'workers');
        $workersRef->setValue($master, [1 => new ProcessInfo(1, $pid, ProcessState::Ready)]);

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
    public function getBalancerReturnsCorrectInstance(): void
    {
        $callback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void {}
        };

        $balancer = new RoundRobinBalancer(1);
        $config = new WorkerPoolConfig(serverConfig: $this->sc, workerCount: 1);

        $master = new CentralizedMaster(
            config: $config,
            balancer: $balancer,
            workerCallback: $callback,
        );

        $this->assertSame($balancer, $master->getBalancer());
    }

    #[Test]
    public function acceptConnectionsViaReflection(): void
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
    public function distributeConnectionsViaReflection(): void
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
            serverConfig: $serverConfig,
            workerCallback: $callback,
        );

        $distributeRef = new ReflectionMethod($master, 'distributeConnections');
        $distributeRef->invoke($master);

        $metrics = $master->getMetrics();
        $this->assertSame(0, $metrics['queue_size']);
    }
}

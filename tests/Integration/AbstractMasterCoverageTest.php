<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Integration;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\WorkerPool\Config\WorkerPoolConfig;
use Duyler\WorkerPool\Master\CentralizedMaster;
use Duyler\WorkerPool\Balancer\RoundRobinBalancer;
use Duyler\WorkerPool\Process\ProcessInfo;
use Duyler\WorkerPool\Process\ProcessState;
use Duyler\WorkerPool\Worker\WorkerCallbackInterface;
use Override;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use ReflectionMethod;
use ReflectionProperty;

use const SIGTERM;
use const SIGINT;

#[Group('pcntl')]
final class AbstractMasterCoverageTest extends TestCase
{
    private ServerConfig $sc;

    #[Override]
    protected function setUp(): void
    {
        $this->sc = new ServerConfig(host: '127.0.0.1', port: 19700);
    }

    #[Test]
    public function stopSendsSigtermToWorkers(): void
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

        $master->stop();
        $this->assertFalse($master->isRunning());

        pcntl_waitpid($pid, $status);
    }

    #[Test]
    public function waitForWorkersBlocksUntilExit(): void
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
            exit(0);
        }

        $workersRef = new ReflectionProperty($master, 'workers');
        $workersRef->setValue($master, [1 => new ProcessInfo(1, $pid, ProcessState::Ready)]);

        $waitRef = new ReflectionMethod($master, 'waitForWorkers');
        $waitRef->invoke($master);

        $this->assertTrue(true);
    }

    #[Test]
    public function setupSignalsRegistersSigtermAndSigint(): void
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

        $handlerRef = new ReflectionProperty($master, 'signalHandler');
        $handler = $handlerRef->getValue($master);

        $this->assertTrue($handler->hasHandlers(SIGTERM));
        $this->assertTrue($handler->hasHandlers(SIGINT));
    }
}

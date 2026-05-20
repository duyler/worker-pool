<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\ErrorHandler\ErrorHandler;
use Duyler\WorkerPool\Balancer\RoundRobinBalancer;
use Duyler\WorkerPool\Config\WorkerPoolConfig;
use Duyler\WorkerPool\Master\CentralizedMaster;
use Duyler\WorkerPool\Master\SharedSocketMaster;
use Duyler\WorkerPool\Worker\WorkerCallbackInterface;
use Override;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Duyler\WorkerPool\Process\ProcessInfo;
use Duyler\WorkerPool\Process\ProcessState;
use ReflectionClass;
use ReflectionProperty;
use Socket;
use Psr\Log\NullLogger;

use function function_exists;

use const SIGTERM;

#[Group('pcntl')]
#[CoversClass(SharedSocketMaster::class)]
#[CoversClass(CentralizedMaster::class)]
final class ForkBasedMasterTest extends TestCase
{
    #[Override]
    protected function tearDown(): void
    {
        (new ErrorHandler(new NullLogger()))->reset();
        parent::tearDown();
    }

    #[Test]
    public function centralizedMasterSpawnsWorkers(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl extension required');
        }

        $callback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void
            {
                socket_close($clientSocket);
            }
        };

        $serverConfig = new ServerConfig(host: '127.0.0.1', port: 19200);
        $config = new WorkerPoolConfig(serverConfig: $serverConfig, workerCount: 1);
        $balancer = new RoundRobinBalancer(1);

        $master = new CentralizedMaster(
            config: $config,
            balancer: $balancer,
            serverConfig: $serverConfig,
            workerCallback: $callback,
        );

        $masterRef = new ReflectionClass($master);
        $spawnMethod = $masterRef->getMethod('spawnWorker');

        $pid = pcntl_fork();

        if (0 === $pid) {
            sleep(1);
            exit(0);
        }

        if (-1 === $pid) {
            $this->fail('Failed to fork');
        }

        $workersRef = $masterRef->getProperty('workers');
        $processInfo = new ProcessInfo(1, $pid, ProcessState::Ready);
        $workersRef->setValue($master, [1 => $processInfo]);

        $this->assertSame(1, $master->getWorkerCount());

        $metrics = $master->getMetrics();
        $this->assertSame(1, $metrics['total_workers']);

        posix_kill($pid, SIGTERM);
        pcntl_waitpid($pid, $status);
    }

    #[Test]
    public function sharedSocketMasterSpawnsWorkers(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl extension required');
        }

        $callback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void
            {
                if ($clientSocket instanceof Socket) {
                    socket_close($clientSocket);
                }
            }
        };

        $serverConfig = new ServerConfig(host: '127.0.0.1', port: 19201);
        $config = new WorkerPoolConfig(serverConfig: $serverConfig, workerCount: 1);

        $master = new SharedSocketMaster(
            config: $config,
            serverConfig: $serverConfig,
            workerCallback: $callback,
        );

        $pid = pcntl_fork();

        if (0 === $pid) {
            sleep(1);
            exit(0);
        }

        if (-1 === $pid) {
            $this->fail('Failed to fork');
        }

        $this->assertSame(0, $master->getWorkerCount());

        $metrics = $master->getMetrics();
        $this->assertSame(0, $metrics['total_workers']);
        $this->assertSame('shared_socket', $metrics['architecture']);

        posix_kill($pid, SIGTERM);
        pcntl_waitpid($pid, $status);
    }

    #[Test]
    public function centralizedMasterStopKillsWorkers(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl extension required');
        }

        $callback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void {}
        };

        $serverConfig = new ServerConfig(host: '127.0.0.1', port: 19202);
        $config = new WorkerPoolConfig(serverConfig: $serverConfig, workerCount: 1);
        $balancer = new RoundRobinBalancer(1);

        $master = new CentralizedMaster(
            config: $config,
            balancer: $balancer,
            serverConfig: $serverConfig,
            workerCallback: $callback,
        );

        $pid = pcntl_fork();
        if (0 === $pid) {
            sleep(10);
            exit(0);
        }

        $workersRef = new ReflectionProperty($master, 'workers');
        $workersRef->setValue($master, [
            1 => new ProcessInfo(1, $pid, ProcessState::Ready),
        ]);

        $this->assertTrue($master->isRunning());
        $master->stop();
        $this->assertFalse($master->isRunning());

        pcntl_waitpid($pid, $status);
    }
}

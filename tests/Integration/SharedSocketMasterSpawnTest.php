<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\WorkerPool\Config\WorkerPoolConfig;
use Duyler\WorkerPool\Master\SharedSocketMaster;
use Duyler\WorkerPool\Worker\WorkerCallbackInterface;
use Override;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use ReflectionMethod;

use Duyler\WorkerPool\Socket\SocketWrapper;
use Duyler\WorkerPool\Process\ForkWrapper;

use const SIGKILL;

#[Group('pcntl')]
#[CoversClass(SharedSocketMaster::class)]
final class SharedSocketMasterSpawnTest extends TestCase
{
    private ServerConfig $sc;

    #[Override]
    protected function setUp(): void
    {
        $this->sc = new ServerConfig(host: '127.0.0.1', port: 19500);
    }

    #[Test]
    public function spawnWorkerForksAndRegisters(): void
    {
        $callback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void
            {
                usleep(5000000);
            }
        };

        $config = new WorkerPoolConfig(serverConfig: $this->sc, workerCount: 1);
        $master = new SharedSocketMaster(
            config: $config,
            serverConfig: $this->sc,
            socketWrapper: new SocketWrapper(),
            forkWrapper: new ForkWrapper(),
            workerCallback: $callback,
        );

        $spawnRef = new ReflectionMethod($master, 'spawnWorker');
        $spawnRef->invoke($master, 1);

        $workers = $master->getWorkers();
        $this->assertCount(1, $workers);
        $this->assertGreaterThan(0, $workers[1]->pid);

        posix_kill($workers[1]->pid, SIGKILL);
        pcntl_waitpid($workers[1]->pid, $status);
    }

    #[Test]
    public function checkWorkersDetectsKilledWorker(): void
    {
        $callback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void
            {
                usleep(5000000);
            }
        };

        $config = new WorkerPoolConfig(serverConfig: $this->sc, workerCount: 1, autoRestart: false);
        $master = new SharedSocketMaster(
            config: $config,
            serverConfig: $this->sc,
            socketWrapper: new SocketWrapper(),
            forkWrapper: new ForkWrapper(),
            workerCallback: $callback,
        );

        $spawnRef = new ReflectionMethod($master, 'spawnWorker');
        $spawnRef->invoke($master, 1);

        $pid = $master->getWorkers()[1]->pid;
        posix_kill($pid, SIGKILL);
        usleep(10000);

        $checkRef = new ReflectionMethod($master, 'checkWorkers');
        $checkRef->invoke($master);

        $this->assertCount(0, $master->getWorkers());
    }

    #[Test]
    public function getMetricsAfterSpawn(): void
    {
        $callback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void
            {
                usleep(5000000);
            }
        };

        $config = new WorkerPoolConfig(serverConfig: $this->sc, workerCount: 1);
        $master = new SharedSocketMaster(
            config: $config,
            serverConfig: $this->sc,
            socketWrapper: new SocketWrapper(),
            forkWrapper: new ForkWrapper(),
            workerCallback: $callback,
        );

        $spawnRef = new ReflectionMethod($master, 'spawnWorker');
        $spawnRef->invoke($master, 1);
        $spawnRef->invoke($master, 2);

        $metrics = $master->getMetrics();
        $this->assertSame(2, $metrics['total_workers']);
        $this->assertSame(2, $metrics['active_workers']);

        foreach ($master->getWorkers() as $worker) {
            posix_kill($worker->pid, SIGKILL);
            pcntl_waitpid($worker->pid, $s);
        }
    }
}

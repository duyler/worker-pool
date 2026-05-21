<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\WorkerPool\Balancer\LeastConnectionsBalancer;
use Duyler\WorkerPool\Balancer\RoundRobinBalancer;
use Duyler\WorkerPool\Config\WorkerPoolConfig;
use Duyler\WorkerPool\IPC\FdPasser;
use Duyler\WorkerPool\Master\CentralizedMaster;
use Duyler\WorkerPool\Master\ConnectionRouter;
use Duyler\WorkerPool\Process\ProcessInfo;
use Duyler\WorkerPool\Process\ProcessState;
use Duyler\WorkerPool\Socket\SocketMsgWrapper;
use Duyler\WorkerPool\Socket\SocketWrapper;
use Duyler\WorkerPool\Worker\WorkerCallbackInterface;
use Override;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use ReflectionMethod;
use ReflectionProperty;

use Duyler\WorkerPool\Process\ForkWrapper;

use const AF_UNIX;
use const SOCK_STREAM;
use const SIGKILL;

#[Group('pcntl')]
#[CoversClass(CentralizedMaster::class)]
final class CentralizedMasterSpawnTest extends TestCase
{
    private ServerConfig $sc;

    #[Override]
    protected function setUp(): void
    {
        $this->sc = new ServerConfig(host: '127.0.0.1', port: 19600);
    }

    #[Test]
    public function spawn_worker_forks_and_registers(): void
    {
        $callback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void
            {
                usleep(5000000);
            }
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

        $spawnRef = new ReflectionMethod($master, 'spawnWorker');
        $spawnRef->invoke($master, 1);

        $workers = $master->getWorkers();
        $this->assertCount(1, $workers);
        $this->assertGreaterThan(0, $workers[1]->pid);

        posix_kill($workers[1]->pid, SIGKILL);
        pcntl_waitpid($workers[1]->pid, $status);
    }

    #[Test]
    public function spawn_worker_without_server_config(): void
    {
        $callback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void
            {
                usleep(5000000);
            }
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

        $spawnRef = new ReflectionMethod($master, 'spawnWorker');
        $spawnRef->invoke($master, 1);

        $workers = $master->getWorkers();
        $this->assertCount(1, $workers);

        posix_kill($workers[1]->pid, SIGKILL);
        pcntl_waitpid($workers[1]->pid, $status);
    }

    #[Test]
    public function spawn_worker_creates_socket_pair(): void
    {
        $callback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void
            {
                usleep(5000000);
            }
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

        $spawnRef = new ReflectionMethod($master, 'spawnWorker');
        $spawnRef->invoke($master, 1);

        $workerSocketsRef = new ReflectionProperty($master, 'workerSockets');
        $workerSockets = $workerSocketsRef->getValue($master);

        $this->assertCount(1, $workerSockets);
        $this->assertArrayHasKey(1, $workerSockets);

        posix_kill($master->getWorkers()[1]->pid, SIGKILL);
        pcntl_waitpid($master->getWorkers()[1]->pid, $status);
    }

    #[Test]
    public function check_workers_detects_killed_worker(): void
    {
        $callback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void
            {
                usleep(5000000);
            }
        };

        $config = new WorkerPoolConfig(serverConfig: $this->sc, workerCount: 1, autoRestart: false);
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
    public function check_workers_auto_restarts_killed_worker(): void
    {
        $callback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void
            {
                usleep(5000000);
            }
        };

        $config = new WorkerPoolConfig(serverConfig: $this->sc, workerCount: 1, autoRestart: true, restartDelay: 0);
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

        $spawnRef = new ReflectionMethod($master, 'spawnWorker');
        $spawnRef->invoke($master, 1);

        $oldPid = $master->getWorkers()[1]->pid;
        posix_kill($oldPid, SIGKILL);
        usleep(10000);

        $checkRef = new ReflectionMethod($master, 'checkWorkers');
        $checkRef->invoke($master);

        $newWorkers = $master->getWorkers();
        $this->assertCount(1, $newWorkers);
        $this->assertNotSame($oldPid, $newWorkers[1]->pid);

        posix_kill($newWorkers[1]->pid, SIGKILL);
        pcntl_waitpid($newWorkers[1]->pid, $s);
    }

    #[Test]
    public function connection_router_route_with_no_alive_workers(): void
    {
        $balancer = new LeastConnectionsBalancer();
        $socketWrapper = new SocketWrapper();
        $socketMsgWrapper = new SocketMsgWrapper();
        $router = new ConnectionRouter(
            $socketWrapper,
            $balancer,
            new FdPasser($socketWrapper, $socketMsgWrapper),
        );

        $sockets = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $sockets);
        [$clientSocket] = $sockets;

        $workers = [
            1 => new ProcessInfo(1, 99999, ProcessState::Stopped, new ForkWrapper()),
        ];
        $workerSockets = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $workerSockets);
        $workerSocketsMap = [1 => $workerSockets[0]];

        $result = $router->route($clientSocket, $workers, $workerSocketsMap);
        $this->assertFalse($result);
    }
}

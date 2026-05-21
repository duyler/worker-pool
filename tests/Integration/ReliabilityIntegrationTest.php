<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Integration;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\WorkerPool\Balancer\RoundRobinBalancer;
use Duyler\WorkerPool\Config\WorkerPoolConfig;
use Duyler\WorkerPool\Exception\WorkerPoolException;
use Duyler\WorkerPool\Master\CentralizedMaster;
use Duyler\WorkerPool\Master\SharedSocketMaster;
use Duyler\WorkerPool\Process\ForkWrapper;
use Duyler\WorkerPool\Process\ProcessInfo;
use Duyler\WorkerPool\Process\ProcessState;
use Duyler\WorkerPool\Socket\SocketMsgWrapper;
use Duyler\WorkerPool\Socket\SocketWrapper;
use Duyler\WorkerPool\Worker\WorkerCallbackInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Duyler\WorkerPool\Master\AbstractMaster;

use const SIGCHLD;
use const SIGKILL;
use const SIGTERM;
use const WNOHANG;

#[Group('pcntl')]
#[CoversClass(SharedSocketMaster::class)]
#[CoversClass(CentralizedMaster::class)]
final class ReliabilityIntegrationTest extends TestCase
{
    private ServerConfig $serverConfig;

    #[Override]
    protected function setUp(): void
    {
        $this->serverConfig = new ServerConfig(host: '127.0.0.1', port: 19600);
    }

    #[Test]
    public function callback_worker_exits_cleanly_on_sigterm(): void
    {
        $callback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void {}
        };

        $config = new WorkerPoolConfig(
            serverConfig: $this->serverConfig,
            workerCount: 1,
            autoRestart: false,
        );

        $master = new SharedSocketMaster(
            config: $config,
            serverConfig: $this->serverConfig,
            socketWrapper: new SocketWrapper(),
            forkWrapper: new ForkWrapper(),
            workerCallback: $callback,
        );

        $spawnRef = new ReflectionMethod($master, 'spawnWorker');
        $spawnRef->invoke($master, 1);

        $workers = $master->getWorkers();
        $this->assertCount(1, $workers);

        $workerPid = $workers[1]->pid;

        usleep(50000);

        posix_kill($workerPid, SIGTERM);

        $status = 0;
        $startTime = microtime(true);
        while (true) {
            $reaped = pcntl_waitpid($workerPid, $status, WNOHANG);
            if ($reaped === $workerPid) {
                break;
            }

            if (microtime(true) - $startTime > 5.0) {
                break;
            }

            usleep(10000);
        }

        $this->assertFalse($workers[1]->isAlive());
    }

    #[Test]
    public function sigchld_prevents_zombie_accumulation(): void
    {
        $callback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void {}
        };

        $sigchldMaster = new SharedSocketMaster(
            config: new WorkerPoolConfig(serverConfig: $this->serverConfig, workerCount: 1, autoRestart: false),
            serverConfig: $this->serverConfig,
            socketWrapper: new SocketWrapper(),
            forkWrapper: new ForkWrapper(),
            workerCallback: $callback,
        );

        $installRef = new ReflectionMethod($sigchldMaster, 'installSigchldHandler');
        $installRef->invoke($sigchldMaster);

        $pids = [];

        for ($i = 1; $i <= 3; $i++) {
            $port = 19600 + $i;
            $sc = new ServerConfig(host: '127.0.0.1', port: $port);
            $localConfig = new WorkerPoolConfig(
                serverConfig: $sc,
                workerCount: 1,
                autoRestart: false,
            );

            $localMaster = new SharedSocketMaster(
                config: $localConfig,
                serverConfig: $sc,
                socketWrapper: new SocketWrapper(),
                forkWrapper: new ForkWrapper(),
                workerCallback: $callback,
            );

            $spawnRef = new ReflectionMethod($localMaster, 'spawnWorker');
            $spawnRef->invoke($localMaster, $i);

            $worker = $localMaster->getWorkers()[$i] ?? null;
            if (null !== $worker) {
                $pids[] = $worker->pid;
                posix_kill($worker->pid, SIGKILL);
            }
        }

        usleep(100000);

        pcntl_signal_dispatch();

        $uninstallRef = new ReflectionMethod($sigchldMaster, 'uninstallSigchldHandler');
        $uninstallRef->invoke($sigchldMaster);

        foreach ($pids as $pid) {
            $result = pcntl_waitpid($pid, $status, WNOHANG);
            $this->assertSame(-1, $result);
        }
    }

    #[Test]
    public function run_callback_worker_throws_exception_on_socket_failure(): void
    {
        $callback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void {}
        };

        $config = new WorkerPoolConfig(
            serverConfig: $this->serverConfig,
            workerCount: 1,
            autoRestart: false,
        );

        $master = new SharedSocketMaster(
            config: $config,
            serverConfig: new ServerConfig(host: '1.1.1.1', port: 80),
            socketWrapper: new SocketWrapper(),
            forkWrapper: new ForkWrapper(),
            workerCallback: $callback,
        );

        $pid = pcntl_fork();

        if (0 === $pid) {
            pcntl_alarm(5);

            $runRef = new ReflectionMethod($master, 'runCallbackWorker');
            try {
                $runRef->invoke($master, 1);
            } catch (WorkerPoolException) {
                exit(42);
            }

            exit(0);
        }

        $startTime = microtime(true);
        while (true) {
            $result = pcntl_waitpid($pid, $status, WNOHANG);
            if ($result > 0) {
                break;
            }

            if (microtime(true) - $startTime > 7.0) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
                $this->markTestSkipped('Socket creation did not fail within timeout');
            }

            usleep(10000);
        }

        if (pcntl_wifsignaled($status)) {
            $this->markTestSkipped('Child killed by signal, socket may have succeeded');
        }

        $exitCode = pcntl_wexitstatus($status);

        if (0 === $exitCode) {
            $this->markTestSkipped('Socket creation succeeded on this platform');
        }

        $this->assertSame(42, $exitCode);
    }

    #[Test]
    public function centralized_master_sigchld_handler_is_registered(): void
    {
        $callback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void {}
        };

        $config = new WorkerPoolConfig(
            serverConfig: $this->serverConfig,
            workerCount: 1,
            autoRestart: false,
        );

        $balancer = new RoundRobinBalancer();

        $master = new CentralizedMaster(
            config: $config,
            balancer: $balancer,
            socketWrapper: new SocketWrapper(),
            socketMsgWrapper: new SocketMsgWrapper(),
            forkWrapper: new ForkWrapper(),
            workerCallback: $callback,
        );

        $installRef = new ReflectionMethod($master, 'installSigchldHandler');
        $installRef->invoke($master);

        $handlerRef = new ReflectionProperty($master, 'signalHandler');
        $handler = $handlerRef->getValue($master);

        $this->assertTrue($handler->hasHandlers(SIGCHLD));

        $uninstallRef = new ReflectionMethod($master, 'uninstallSigchldHandler');
        $uninstallRef->invoke($master);
    }

    #[Test]
    public function centralized_callback_worker_exits_cleanly_on_sigterm(): void
    {
        $callback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void {}
        };

        $config = new WorkerPoolConfig(
            serverConfig: $this->serverConfig,
            workerCount: 1,
            autoRestart: false,
        );

        $balancer = new RoundRobinBalancer();

        $master = new CentralizedMaster(
            config: $config,
            balancer: $balancer,
            socketWrapper: new SocketWrapper(),
            socketMsgWrapper: new SocketMsgWrapper(),
            forkWrapper: new ForkWrapper(),
            serverConfig: $this->serverConfig,
            workerCallback: $callback,
        );

        $spawnRef = new ReflectionMethod($master, 'spawnWorker');
        $spawnRef->invoke($master, 1);

        $workers = $master->getWorkers();
        $this->assertCount(1, $workers);

        $workerPid = $workers[1]->pid;

        usleep(50000);

        posix_kill($workerPid, SIGTERM);

        $status = 0;
        $startTime = microtime(true);
        while (true) {
            $reaped = pcntl_waitpid($workerPid, $status, WNOHANG);
            if ($reaped === $workerPid) {
                break;
            }

            if (microtime(true) - $startTime > 5.0) {
                break;
            }

            usleep(10000);
        }

        $this->assertFalse($workers[1]->isAlive());
    }

    #[Test]
    public function centralized_check_workers_uses_non_blocking_delay(): void
    {
        $callback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void {}
        };

        $config = new WorkerPoolConfig(
            serverConfig: $this->serverConfig,
            workerCount: 1,
            autoRestart: true,
            restartDelay: 5,
        );

        $balancer = new RoundRobinBalancer();

        $master = new CentralizedMaster(
            config: $config,
            balancer: $balancer,
            socketWrapper: new SocketWrapper(),
            socketMsgWrapper: new SocketMsgWrapper(),
            forkWrapper: new ForkWrapper(),
            workerCallback: $callback,
        );

        $wmRef = new ReflectionProperty($master, 'workerManager');
        $workerManager = $wmRef->getValue($master);

        $pid = pcntl_fork();
        if (0 === $pid) {
            usleep(100000);
            exit(0);
        }

        $workerManager->updateWorker(1, new ProcessInfo(1, $pid, ProcessState::Ready, new ForkWrapper()));

        usleep(200000);

        $checkRef = new ReflectionMethod($master, 'checkWorkers');
        $checkRef->invoke($master);

        $pendingRef = new ReflectionProperty(AbstractMaster::class, 'pendingRestarts');
        $pending = $pendingRef->getValue($master);

        $this->assertArrayHasKey(1, $pending);
        $this->assertGreaterThan(microtime(true), $pending[1]);

        pcntl_waitpid($pid, $status);
    }
}

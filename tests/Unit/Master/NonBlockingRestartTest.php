<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\Master;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\WorkerPool\Config\WorkerPoolConfig;
use Duyler\WorkerPool\Master\AbstractMaster;
use Duyler\WorkerPool\Process\ForkWrapper;
use Duyler\WorkerPool\Process\ProcessInfo;
use Duyler\WorkerPool\Process\ProcessState;
use Duyler\WorkerPool\Worker\WorkerCallbackInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

use Duyler\WorkerPool\Master\WorkerManager;
use Duyler\WorkerPool\Signal\SignalHandler;
use Duyler\WorkerPool\Signal\SignalManager;

use const SIGCHLD;
use const WNOHANG;

#[Group('pcntl')]
#[CoversClass(AbstractMaster::class)]
#[UsesClass(WorkerPoolConfig::class)]
#[UsesClass(WorkerManager::class)]
#[UsesClass(ForkWrapper::class)]
#[UsesClass(ProcessInfo::class)]
#[UsesClass(SignalHandler::class)]
#[UsesClass(SignalManager::class)]
final class NonBlockingRestartTest extends TestCase
{
    private ServerConfig $serverConfig;

    #[Override]
    protected function setUp(): void
    {
        $this->serverConfig = new ServerConfig(host: '127.0.0.1', port: 19800);
    }

    #[Test]
    public function process_pending_restarts_removes_past_timestamp(): void
    {
        $master = $this->createTestableMaster(restartDelay: 1);
        $master->stop();

        $this->setPendingRestarts($master, [
            1 => microtime(true) - 10.0,
            2 => microtime(true) + 100.0,
        ]);

        $this->callProcessPendingRestarts($master);

        $pending = $this->getPendingRestarts($master);

        $this->assertArrayNotHasKey(1, $pending);
        $this->assertArrayHasKey(2, $pending);
    }

    #[Test]
    public function process_pending_restarts_spawns_worker_when_time_arrives(): void
    {
        $master = $this->createTestableMaster(restartDelay: 1);

        $this->setPendingRestarts($master, [
            1 => microtime(true) - 1.0,
        ]);

        $this->callProcessPendingRestarts($master);

        $this->assertSame([1], $master->spawnedWorkers);
    }

    #[Test]
    public function process_pending_restarts_does_not_spawn_before_time(): void
    {
        $master = $this->createTestableMaster(restartDelay: 1);

        $this->setPendingRestarts($master, [
            1 => microtime(true) + 3600.0,
        ]);

        $this->callProcessPendingRestarts($master);

        $this->assertSame([], $master->spawnedWorkers);
        $this->assertArrayHasKey(1, $this->getPendingRestarts($master));
    }

    #[Test]
    public function process_pending_restarts_does_not_spawn_when_stopped(): void
    {
        $master = $this->createTestableMaster(restartDelay: 1);
        $master->stop();

        $this->setPendingRestarts($master, [
            1 => microtime(true) - 10.0,
        ]);

        $this->callProcessPendingRestarts($master);

        $this->assertSame([], $master->spawnedWorkers);
    }

    #[Test]
    public function process_pending_restarts_handles_multiple_workers(): void
    {
        $master = $this->createTestableMaster(restartDelay: 1);

        $past = microtime(true) - 1.0;
        $future = microtime(true) + 3600.0;

        $this->setPendingRestarts($master, [
            1 => $past,
            2 => $future,
            3 => $past,
        ]);

        $this->callProcessPendingRestarts($master);

        $this->assertSame([1, 3], $master->spawnedWorkers);
        $this->assertArrayHasKey(2, $this->getPendingRestarts($master));
    }

    #[Test]
    public function check_workers_schedules_restart_with_delay(): void
    {
        $master = $this->createTestableMaster(restartDelay: 5);

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

        $pending = $this->getPendingRestarts($master);

        $this->assertArrayHasKey(1, $pending);
        $this->assertGreaterThan(microtime(true), $pending[1]);

        pcntl_waitpid($pid, $status);
    }

    #[Test]
    public function check_workers_spawns_immediately_with_zero_delay(): void
    {
        $master = $this->createTestableMaster(restartDelay: 0);

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

        $this->assertSame([1], $master->spawnedWorkers);

        pcntl_waitpid($pid, $status);
    }

    #[Test]
    public function check_workers_does_not_restart_when_disabled(): void
    {
        $master = $this->createTestableMaster(restartDelay: 0, autoRestart: false);

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

        $this->assertSame([], $master->spawnedWorkers);
        $this->assertEmpty($this->getPendingRestarts($master));

        pcntl_waitpid($pid, $status);
    }

    #[Test]
    public function sigchld_handler_is_registered(): void
    {
        $master = $this->createTestableMaster(restartDelay: 1);
        $master->installSigchldHandler();

        $handlerRef = new ReflectionProperty($master, 'signalHandler');
        $handler = $handlerRef->getValue($master);

        $this->assertTrue($handler->hasHandlers(SIGCHLD));

        $master->uninstallSigchldHandler();
    }

    #[Test]
    public function sigchld_handler_reaps_zombie_processes(): void
    {
        $master = $this->createTestableMaster(restartDelay: 1);
        $master->installSigchldHandler();

        $pid = pcntl_fork();
        if (0 === $pid) {
            exit(0);
        }

        usleep(50000);

        pcntl_signal_dispatch();

        $result = pcntl_waitpid($pid, $status, WNOHANG);

        $master->uninstallSigchldHandler();

        $this->assertSame(-1, $result);
    }

    #[Test]
    public function detect_dead_workers_finds_reaped_workers(): void
    {
        $master = $this->createTestableMaster(restartDelay: 1, autoRestart: false);
        $master->installSigchldHandler();

        $wmRef = new ReflectionProperty($master, 'workerManager');
        $workerManager = $wmRef->getValue($master);

        $pid = pcntl_fork();
        if (0 === $pid) {
            exit(0);
        }

        $workerManager->updateWorker(1, new ProcessInfo(1, $pid, ProcessState::Ready, new ForkWrapper()));

        usleep(50000);

        pcntl_signal_dispatch();

        $detectRef = new ReflectionMethod($master, 'detectDeadWorkers');
        $deadWorkers = $detectRef->invoke($master);

        $master->uninstallSigchldHandler();

        $this->assertContains(1, $deadWorkers);
    }

    #[Test]
    public function detect_dead_workers_returns_empty_when_all_alive(): void
    {
        $master = $this->createTestableMaster(restartDelay: 1);

        $detectRef = new ReflectionMethod($master, 'detectDeadWorkers');
        $deadWorkers = $detectRef->invoke($master);

        $this->assertSame([], $deadWorkers);
    }

    #[Test]
    public function pending_restarts_is_empty_initially(): void
    {
        $master = $this->createTestableMaster(restartDelay: 1);

        $this->assertEmpty($this->getPendingRestarts($master));
    }

    private function createTestableMaster(int $restartDelay = 1, bool $autoRestart = true): TestableAbstractMaster
    {
        $callback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void {}
        };

        $config = new WorkerPoolConfig(
            serverConfig: $this->serverConfig,
            workerCount: 1,
            autoRestart: $autoRestart,
            restartDelay: $restartDelay,
        );

        return new TestableAbstractMaster($config, new ForkWrapper(), workerCallback: $callback);
    }

    private function setPendingRestarts(TestableAbstractMaster $master, array $pending): void
    {
        $ref = new ReflectionProperty(AbstractMaster::class, 'pendingRestarts');
        $ref->setValue($master, $pending);
    }

    private function getPendingRestarts(TestableAbstractMaster $master): array
    {
        $ref = new ReflectionProperty(AbstractMaster::class, 'pendingRestarts');
        return $ref->getValue($master);
    }

    private function callProcessPendingRestarts(TestableAbstractMaster $master): void
    {
        $ref = new ReflectionMethod(AbstractMaster::class, 'processPendingRestarts');
        $ref->invoke($master);
    }
}

final class TestableAbstractMaster extends AbstractMaster
{
    public array $spawnedWorkers = [];

    #[Override]
    public function start(): void {}

    #[Override]
    public function getMetrics(): array
    {
        return [];
    }

    #[Override]
    public function installSigchldHandler(): void
    {
        parent::installSigchldHandler();
    }

    #[Override]
    public function uninstallSigchldHandler(): void
    {
        parent::uninstallSigchldHandler();
    }

    #[Override]
    protected function run(): void {}

    #[Override]
    protected function spawnWorker(int $workerId): void
    {
        $this->spawnedWorkers[] = $workerId;
    }
}

<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\Master;

use PHPUnit\Framework\Attributes\CoversClass;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\WorkerPool\Config\WorkerPoolConfig;
use Duyler\WorkerPool\Master\WorkerManager;
use Duyler\WorkerPool\Process\ProcessInfo;
use Duyler\WorkerPool\Process\ProcessState;
use Override;
use PHPUnit\Framework\TestCase;
use Duyler\WorkerPool\Process\ForkWrapper;

#[CoversClass(WorkerManager::class)]
final class WorkerManagerTest extends TestCase
{
    private WorkerPoolConfig $config;
    private WorkerManager $manager;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $serverConfig = new ServerConfig(
            host: '127.0.0.1',
            port: 9999,
        );

        $this->config = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 2,
            autoRestart: false,
        );

        $this->manager = new WorkerManager(new ForkWrapper());
    }

    public function testStartsWithEmptyWorkers(): void
    {
        $workers = $this->manager->getWorkers();

        $this->assertIsArray($workers);
        $this->assertCount(0, $workers);
    }

    public function testCanGetWorkerById(): void
    {
        $worker = $this->manager->getWorker(1);

        $this->assertNull($worker);
    }

    public function testCanUpdateWorker(): void
    {
        $processInfo = new ProcessInfo(
            workerId: 1,
            pid: 12345,
            state: ProcessState::Ready,
            forkWrapper: new ForkWrapper(),
        );

        $this->manager->updateWorker(1, $processInfo);

        $worker = $this->manager->getWorker(1);

        $this->assertNotNull($worker);
        $this->assertSame(1, $worker->workerId);
        $this->assertSame(12345, $worker->pid);
    }

    public function testCanRemoveWorker(): void
    {
        $processInfo = new ProcessInfo(
            workerId: 1,
            pid: 12345,
            state: ProcessState::Ready,
            forkWrapper: new ForkWrapper(),
        );

        $this->manager->updateWorker(1, $processInfo);

        $this->assertNotNull($this->manager->getWorker(1));

        $this->manager->removeWorker(1);

        $this->assertNull($this->manager->getWorker(1));
    }

    public function testCountsAliveWorkers(): void
    {
        $this->assertSame(0, $this->manager->countAlive());
    }
}

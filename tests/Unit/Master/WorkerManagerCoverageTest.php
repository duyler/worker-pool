<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\Master;

use PHPUnit\Framework\Attributes\CoversClass;

use Duyler\WorkerPool\Process\ProcessInfo;
use Duyler\WorkerPool\Process\ProcessState;
use Duyler\WorkerPool\Master\WorkerManager;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Duyler\WorkerPool\Process\ForkWrapper;

#[CoversClass(WorkerManager::class)]
final class WorkerManagerCoverageTest extends TestCase
{
    private WorkerManager $manager;

    #[Override]
    protected function setUp(): void
    {
        $this->manager = new WorkerManager(new ForkWrapper());
    }

    #[Test]
    public function getWorkersReturnsEmptyArrayInitially(): void
    {
        $this->assertSame([], $this->manager->getWorkers());
    }

    #[Test]
    public function getWorkerReturnsNullForUnknownWorker(): void
    {
        $this->assertNull($this->manager->getWorker(999));
    }

    #[Test]
    public function removeWorkerRemovesFromList(): void
    {
        $info = new ProcessInfo(
            workerId: 1,
            pid: 12345,
            state: ProcessState::Ready,
            forkWrapper: new ForkWrapper(),
        );

        $this->manager->updateWorker(1, $info);
        $this->assertNotNull($this->manager->getWorker(1));

        $this->manager->removeWorker(1);
        $this->assertNull($this->manager->getWorker(1));
    }

    #[Test]
    public function updateWorkerStoresProcessInfo(): void
    {
        $info = new ProcessInfo(
            workerId: 5,
            pid: 54321,
            state: ProcessState::Busy,
            forkWrapper: new ForkWrapper(),
        );

        $this->manager->updateWorker(5, $info);
        $stored = $this->manager->getWorker(5);

        $this->assertNotNull($stored);
        $this->assertSame(5, $stored->workerId);
        $this->assertSame(54321, $stored->pid);
        $this->assertSame(ProcessState::Busy, $stored->state);
    }

    #[Test]
    public function countAliveReturnsZeroForNoWorkers(): void
    {
        $this->assertSame(0, $this->manager->countAlive());
    }

    #[Test]
    public function countAliveCountsAliveWorkers(): void
    {
        $alive = new ProcessInfo(1, getmypid(), ProcessState::Ready, new ForkWrapper());
        $dead = new ProcessInfo(2, 999999, ProcessState::Stopped, new ForkWrapper());

        $this->manager->updateWorker(1, $alive);
        $this->manager->updateWorker(2, $dead);

        $this->assertSame(1, $this->manager->countAlive());
    }

    #[Test]
    public function getWorkersReturnsAllWorkers(): void
    {
        $w1 = new ProcessInfo(1, 100, ProcessState::Ready, new ForkWrapper());
        $w2 = new ProcessInfo(2, 200, ProcessState::Ready, new ForkWrapper());

        $this->manager->updateWorker(1, $w1);
        $this->manager->updateWorker(2, $w2);

        $workers = $this->manager->getWorkers();
        $this->assertCount(2, $workers);
        $this->assertArrayHasKey(1, $workers);
        $this->assertArrayHasKey(2, $workers);
    }

    #[Test]
    public function updateWorkerOverwritesExisting(): void
    {
        $original = new ProcessInfo(1, 100, ProcessState::Ready, new ForkWrapper());
        $updated = new ProcessInfo(1, 200, ProcessState::Busy, new ForkWrapper());

        $this->manager->updateWorker(1, $original);
        $this->manager->updateWorker(1, $updated);

        $stored = $this->manager->getWorker(1);
        $this->assertSame(200, $stored->pid);
        $this->assertSame(ProcessState::Busy, $stored->state);
    }
}

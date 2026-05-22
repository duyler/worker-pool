<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\Master;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

use Duyler\WorkerPool\Exception\WorkerPoolException;
use Duyler\WorkerPool\Master\WorkerManager;
use Duyler\WorkerPool\Process\ForkWrapperInterface;
use Duyler\WorkerPool\Process\ProcessInfo;
use Duyler\WorkerPool\Process\ProcessState;
use Override;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Duyler\WorkerPool\Process\ForkWrapper;

use const SIGTERM;
use const WNOHANG;

#[CoversClass(WorkerManager::class)]
#[UsesClass(ProcessInfo::class)]
#[UsesClass(WorkerPoolException::class)]
#[UsesClass(ForkWrapper::class)]
#[AllowMockObjectsWithoutExpectations]
final class WorkerManagerUnitTest extends TestCase
{
    private ForkWrapperInterface&MockObject $forkWrapper;
    private LoggerInterface&MockObject $logger;
    private WorkerManager $manager;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->forkWrapper = $this->createMock(ForkWrapperInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->manager = new WorkerManager($this->forkWrapper, $this->logger);
    }

    #[Test]
    public function starts_with_empty_workers(): void
    {
        $this->assertEmpty($this->manager->getWorkers());
    }

    #[Test]
    public function get_worker_returns_null_for_unknown(): void
    {
        $this->assertNull($this->manager->getWorker(99));
    }

    #[Test]
    public function update_worker_stores_process_info(): void
    {
        $info = new ProcessInfo(workerId: 1, pid: 100, state: ProcessState::Ready, forkWrapper: $this->forkWrapper);
        $this->manager->updateWorker(1, $info);
        $this->assertSame($info, $this->manager->getWorker(1));
    }

    #[Test]
    public function remove_worker_deletes_entry(): void
    {
        $info = new ProcessInfo(workerId: 1, pid: 100, state: ProcessState::Ready, forkWrapper: $this->forkWrapper);
        $this->manager->updateWorker(1, $info);
        $this->assertNotNull($this->manager->getWorker(1));

        $this->manager->removeWorker(1);
        $this->assertNull($this->manager->getWorker(1));
    }

    #[Test]
    public function count_alive_returns_zero_initially(): void
    {
        $this->assertSame(0, $this->manager->countAlive());
    }

    #[Test]
    public function check_removes_dead_workers(): void
    {
        $this->forkWrapper->method('kill')->willReturn(true);
        $this->forkWrapper->method('waitpid')->willReturnCallback(fn(int $pid, int &$status, int $options): int => $options === WNOHANG ? 100 : 0);

        $info = new ProcessInfo(workerId: 1, pid: 100, state: ProcessState::Ready, forkWrapper: $this->forkWrapper);
        $this->manager->updateWorker(1, $info);

        $dead = $this->manager->check();
        $this->assertSame([1], $dead);
        $this->assertNull($this->manager->getWorker(1));
    }

    #[Test]
    public function check_returns_empty_when_all_alive(): void
    {
        $this->forkWrapper->method('waitpid')->willReturn(0);

        $info = new ProcessInfo(workerId: 1, pid: 100, state: ProcessState::Ready, forkWrapper: $this->forkWrapper);
        $this->manager->updateWorker(1, $info);

        $this->assertEmpty($this->manager->check());
    }

    #[Test]
    public function stop_all_sends_sigterm(): void
    {
        $this->forkWrapper->method('kill')->willReturn(true);
        $this->forkWrapper->method('waitpid')->willReturn(0);
        $this->forkWrapper->expects($this->once())->method('kill')->with(100, SIGTERM);

        $info = new ProcessInfo(workerId: 1, pid: 100, state: ProcessState::Ready, forkWrapper: $this->forkWrapper);
        $this->manager->updateWorker(1, $info);
        $this->manager->stopAll();
    }

    #[Test]
    public function wait_all_waits_for_each(): void
    {
        $this->forkWrapper->method('waitpid')->willReturn(100);
        $this->forkWrapper->expects($this->once())->method('waitpid')->with(100, $this->anything());

        $info = new ProcessInfo(workerId: 1, pid: 100, state: ProcessState::Ready, forkWrapper: $this->forkWrapper);
        $this->manager->updateWorker(1, $info);
        $this->manager->waitAll();
    }

    #[Test]
    public function spawn_throws_on_fork_failure(): void
    {
        $this->forkWrapper->method('fork')->willReturn(-1);

        $this->expectException(WorkerPoolException::class);
        $this->manager->spawn(1, fn(): bool => true);
    }

    #[Test]
    public function spawn_returns_process_info_on_success(): void
    {
        $this->forkWrapper->method('fork')->willReturn(12345);

        $result = $this->manager->spawn(1, fn(): bool => true);

        $this->assertSame(1, $result->workerId);
        $this->assertSame(12345, $result->pid);
        $this->assertSame(ProcessState::Ready, $result->state);
    }

    #[Test]
    public function spawn_registers_worker(): void
    {
        $this->forkWrapper->method('fork')->willReturn(12345);

        $this->manager->spawn(1, fn(): bool => true);

        $workers = $this->manager->getWorkers();
        $this->assertArrayHasKey(1, $workers);
        $this->assertSame(12345, $workers[1]->pid);
    }

    #[Test]
    public function spawn_multiple_workers(): void
    {
        $callCount = 0;
        $this->forkWrapper->method('fork')->willReturnCallback(function () use (&$callCount): int {
            $callCount++;
            return 10000 + $callCount;
        });

        $this->manager->spawn(1, fn(): bool => true);
        $this->manager->spawn(2, fn(): bool => true);
        $this->manager->spawn(3, fn(): bool => true);

        $this->assertCount(3, $this->manager->getWorkers());
        $this->assertSame(10001, $this->manager->getWorker(1)->pid);
        $this->assertSame(10002, $this->manager->getWorker(2)->pid);
        $this->assertSame(10003, $this->manager->getWorker(3)->pid);
    }

    #[Test]
    public function count_alive_counts_only_alive_workers(): void
    {
        $aliveInfo = new ProcessInfo(workerId: 1, pid: 100, state: ProcessState::Ready, forkWrapper: $this->forkWrapper);
        $deadInfo = new ProcessInfo(workerId: 2, pid: 200, state: ProcessState::Ready, forkWrapper: $this->forkWrapper);

        $this->forkWrapper->method('kill')->willReturnCallback(function (int $pid, int $sig): bool {
            if (0 === $sig) {
                return 100 === $pid;
            }
            return true;
        });

        $this->manager->updateWorker(1, $aliveInfo);
        $this->manager->updateWorker(2, $deadInfo);

        $this->assertSame(1, $this->manager->countAlive());
    }

    #[Test]
    public function stop_all_skips_zero_pid(): void
    {
        $info = new ProcessInfo(workerId: 1, pid: 0, state: ProcessState::Ready, forkWrapper: $this->forkWrapper);
        $this->manager->updateWorker(1, $info);

        $this->forkWrapper->expects($this->never())->method('kill');

        $this->manager->stopAll();
    }

    #[Test]
    public function spawn_child_path_executes_callback_and_exits(): void
    {
        $realFork = new ForkWrapper();
        $tempFile = sys_get_temp_dir() . '/wm_spawntest_' . uniqid();
        $manager = new WorkerManager($realFork, $this->logger);

        $result = $manager->spawn(1, function () use ($tempFile): void {
            file_put_contents($tempFile, 'callback_executed');
        });

        $this->assertSame(1, $result->workerId);
        $this->assertGreaterThan(0, $result->pid);

        pcntl_waitpid($result->pid, $status);

        $this->assertFileExists($tempFile);
        $this->assertSame('callback_executed', file_get_contents($tempFile));
        @unlink($tempFile);
    }
}

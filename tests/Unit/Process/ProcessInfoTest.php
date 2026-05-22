<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\Process;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\Test;

use Duyler\WorkerPool\Process\ProcessInfo;
use Duyler\WorkerPool\Process\ProcessState;
use PHPUnit\Framework\TestCase;
use Duyler\WorkerPool\Process\ForkWrapper;

#[CoversClass(ProcessInfo::class)]
#[UsesClass(ForkWrapper::class)]
class ProcessInfoTest extends TestCase
{
    #[Test]
    public function creates_process_info_with_defaults(): void
    {
        $info = new ProcessInfo(
            workerId: 1,
            pid: 12345,
            state: ProcessState::Ready,
            forkWrapper: new ForkWrapper(),
        );

        $this->assertSame(1, $info->workerId);
        $this->assertSame(12345, $info->pid);
        $this->assertSame(ProcessState::Ready, $info->state);
        $this->assertSame(0, $info->connections);
        $this->assertSame(0, $info->totalRequests);
        $this->assertGreaterThan(0, $info->startedAt);
        $this->assertGreaterThan(0, $info->lastActivityAt);
        $this->assertSame(0, $info->memoryUsage);
    }

    #[Test]
    public function creates_process_info_with_all_params(): void
    {
        $startedAt = microtime(true) - 100;
        $lastActivityAt = microtime(true) - 10;

        $info = new ProcessInfo(
            workerId: 5,
            pid: 99999,
            state: ProcessState::Busy,
            forkWrapper: new ForkWrapper(),
            connections: 10,
            totalRequests: 500,
            startedAt: $startedAt,
            lastActivityAt: $lastActivityAt,
            memoryUsage: 2048576,
        );

        $this->assertSame(5, $info->workerId);
        $this->assertSame(99999, $info->pid);
        $this->assertSame(ProcessState::Busy, $info->state);
        $this->assertSame(10, $info->connections);
        $this->assertSame(500, $info->totalRequests);
        $this->assertSame($startedAt, $info->startedAt);
        $this->assertSame($lastActivityAt, $info->lastActivityAt);
        $this->assertSame(2048576, $info->memoryUsage);
    }

    #[Test]
    public function returns_new_instance_with_state_change(): void
    {
        $info1 = new ProcessInfo(
            workerId: 1,
            pid: 123,
            state: ProcessState::Starting,
            forkWrapper: new ForkWrapper(),
        );

        $info2 = $info1->withState(ProcessState::Ready);

        $this->assertNotSame($info1, $info2);
        $this->assertSame(ProcessState::Starting, $info1->state);
        $this->assertSame(ProcessState::Ready, $info2->state);
        $this->assertSame($info1->workerId, $info2->workerId);
        $this->assertSame($info1->pid, $info2->pid);
    }

    #[Test]
    public function returns_new_instance_with_connections_change(): void
    {
        $info1 = new ProcessInfo(
            workerId: 1,
            pid: 123,
            state: ProcessState::Ready,
            forkWrapper: new ForkWrapper(),
            connections: 5,
        );

        $info2 = $info1->withConnections(10);

        $this->assertSame(5, $info1->connections);
        $this->assertSame(10, $info2->connections);
        $this->assertGreaterThan($info1->lastActivityAt, $info2->lastActivityAt);
    }

    #[Test]
    public function increments_requests_counter(): void
    {
        $info1 = new ProcessInfo(
            workerId: 1,
            pid: 123,
            state: ProcessState::Ready,
            forkWrapper: new ForkWrapper(),
            totalRequests: 100,
        );

        $info2 = $info1->withIncrementedRequests();
        $info3 = $info2->withIncrementedRequests();

        $this->assertSame(100, $info1->totalRequests);
        $this->assertSame(101, $info2->totalRequests);
        $this->assertSame(102, $info3->totalRequests);
    }

    #[Test]
    public function updates_last_activity_on_request_increment(): void
    {
        $info1 = new ProcessInfo(
            workerId: 1,
            pid: 123,
            state: ProcessState::Ready,
            forkWrapper: new ForkWrapper(),
        );

        usleep(10000);

        $info2 = $info1->withIncrementedRequests();

        $this->assertGreaterThan($info1->lastActivityAt, $info2->lastActivityAt);
    }

    #[Test]
    public function updates_memory_usage(): void
    {
        $info1 = new ProcessInfo(
            workerId: 1,
            pid: 123,
            state: ProcessState::Ready,
            forkWrapper: new ForkWrapper(),
            memoryUsage: 1024,
        );

        $info2 = $info1->withMemoryUsage(2048);

        $this->assertSame(1024, $info1->memoryUsage);
        $this->assertSame(2048, $info2->memoryUsage);
        $this->assertGreaterThan($info1->lastActivityAt, $info2->lastActivityAt);
    }

    #[Test]
    public function calculates_uptime(): void
    {
        $startedAt = microtime(true) - 60;

        $info = new ProcessInfo(
            workerId: 1,
            pid: 123,
            state: ProcessState::Ready,
            forkWrapper: new ForkWrapper(),
            startedAt: $startedAt,
        );

        $uptime = $info->getUptime();

        $this->assertGreaterThanOrEqual(59, $uptime);
        $this->assertLessThanOrEqual(61, $uptime);
    }

    #[Test]
    public function calculates_idle_time(): void
    {
        $lastActivityAt = microtime(true) - 30;

        $info = new ProcessInfo(
            workerId: 1,
            pid: 123,
            state: ProcessState::Ready,
            forkWrapper: new ForkWrapper(),
            lastActivityAt: $lastActivityAt,
        );

        $idleTime = $info->getIdleTime();

        $this->assertGreaterThanOrEqual(29, $idleTime);
        $this->assertLessThanOrEqual(31, $idleTime);
    }

    #[Test]
    public function checks_if_process_is_alive(): void
    {
        $currentPid = getmypid();

        $info = new ProcessInfo(
            workerId: 1,
            pid: $currentPid,
            state: ProcessState::Ready,
            forkWrapper: new ForkWrapper(),
        );

        $this->assertTrue($info->isAlive());
    }

    #[Test]
    public function returns_false_for_dead_process(): void
    {
        $info = new ProcessInfo(
            workerId: 1,
            pid: 999999,
            state: ProcessState::Ready,
            forkWrapper: new ForkWrapper(),
        );

        $this->assertFalse($info->isAlive());
    }

    #[Test]
    public function returns_false_for_zero_pid(): void
    {
        $info = new ProcessInfo(
            workerId: 1,
            pid: 0,
            state: ProcessState::Stopped,
            forkWrapper: new ForkWrapper(),
        );

        $this->assertFalse($info->isAlive());
    }

    #[Test]
    public function returns_false_for_negative_pid(): void
    {
        $info = new ProcessInfo(
            workerId: 1,
            pid: -1,
            state: ProcessState::Failed,
            forkWrapper: new ForkWrapper(),
        );

        $this->assertFalse($info->isAlive());
    }

    #[Test]
    public function converts_to_array(): void
    {
        $startedAt = microtime(true) - 100;
        $lastActivityAt = microtime(true) - 10;

        $info = new ProcessInfo(
            workerId: 3,
            pid: 55555,
            state: ProcessState::Busy,
            forkWrapper: new ForkWrapper(),
            connections: 7,
            totalRequests: 250,
            startedAt: $startedAt,
            lastActivityAt: $lastActivityAt,
            memoryUsage: 1048576,
        );

        $array = $info->toArray();

        $this->assertIsArray($array);
        $this->assertSame(3, $array['worker_id']);
        $this->assertSame(55555, $array['pid']);
        $this->assertSame('busy', $array['state']);
        $this->assertSame(7, $array['connections']);
        $this->assertSame(250, $array['total_requests']);
        $this->assertSame($startedAt, $array['started_at']);
        $this->assertSame($lastActivityAt, $array['last_activity_at']);
        $this->assertSame(1048576, $array['memory_usage']);
        $this->assertIsFloat($array['uptime']);
        $this->assertIsFloat($array['idle_time']);
        $this->assertIsBool($array['is_alive']);
    }

    #[Test]
    public function immutability_preserves_original(): void
    {
        $info = new ProcessInfo(
            workerId: 1,
            pid: 123,
            state: ProcessState::Ready,
            forkWrapper: new ForkWrapper(),
            connections: 5,
            totalRequests: 100,
        );

        $info->withState(ProcessState::Busy);
        $info->withConnections(10);
        $info->withIncrementedRequests();
        $info->withMemoryUsage(2048);

        $this->assertSame(ProcessState::Ready, $info->state);
        $this->assertSame(5, $info->connections);
        $this->assertSame(100, $info->totalRequests);
        $this->assertSame(0, $info->memoryUsage);
    }
}

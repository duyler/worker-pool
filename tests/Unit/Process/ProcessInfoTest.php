<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\Process;

use Duyler\WorkerPool\Process\ProcessInfo;
use Duyler\WorkerPool\Process\ProcessState;
use PHPUnit\Framework\TestCase;

class ProcessInfoTest extends TestCase
{
    public function testCreatesProcessInfoWithDefaults(): void
    {
        $info = new ProcessInfo(
            workerId: 1,
            pid: 12345,
            state: ProcessState::Ready,
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

    public function testCreatesProcessInfoWithAllParams(): void
    {
        $startedAt = microtime(true) - 100;
        $lastActivityAt = microtime(true) - 10;

        $info = new ProcessInfo(
            workerId: 5,
            pid: 99999,
            state: ProcessState::Busy,
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

    public function testReturnsNewInstanceWithStateChange(): void
    {
        $info1 = new ProcessInfo(
            workerId: 1,
            pid: 123,
            state: ProcessState::Starting,
        );

        $info2 = $info1->withState(ProcessState::Ready);

        $this->assertNotSame($info1, $info2);
        $this->assertSame(ProcessState::Starting, $info1->state);
        $this->assertSame(ProcessState::Ready, $info2->state);
        $this->assertSame($info1->workerId, $info2->workerId);
        $this->assertSame($info1->pid, $info2->pid);
    }

    public function testReturnsNewInstanceWithConnectionsChange(): void
    {
        $info1 = new ProcessInfo(
            workerId: 1,
            pid: 123,
            state: ProcessState::Ready,
            connections: 5,
        );

        $info2 = $info1->withConnections(10);

        $this->assertSame(5, $info1->connections);
        $this->assertSame(10, $info2->connections);
        $this->assertGreaterThan($info1->lastActivityAt, $info2->lastActivityAt);
    }

    public function testIncrementsRequestsCounter(): void
    {
        $info1 = new ProcessInfo(
            workerId: 1,
            pid: 123,
            state: ProcessState::Ready,
            totalRequests: 100,
        );

        $info2 = $info1->withIncrementedRequests();
        $info3 = $info2->withIncrementedRequests();

        $this->assertSame(100, $info1->totalRequests);
        $this->assertSame(101, $info2->totalRequests);
        $this->assertSame(102, $info3->totalRequests);
    }

    public function testUpdatesLastActivityOnRequestIncrement(): void
    {
        $info1 = new ProcessInfo(
            workerId: 1,
            pid: 123,
            state: ProcessState::Ready,
        );

        usleep(10000);

        $info2 = $info1->withIncrementedRequests();

        $this->assertGreaterThan($info1->lastActivityAt, $info2->lastActivityAt);
    }

    public function testUpdatesMemoryUsage(): void
    {
        $info1 = new ProcessInfo(
            workerId: 1,
            pid: 123,
            state: ProcessState::Ready,
            memoryUsage: 1024,
        );

        $info2 = $info1->withMemoryUsage(2048);

        $this->assertSame(1024, $info1->memoryUsage);
        $this->assertSame(2048, $info2->memoryUsage);
        $this->assertGreaterThan($info1->lastActivityAt, $info2->lastActivityAt);
    }

    public function testCalculatesUptime(): void
    {
        $startedAt = microtime(true) - 60;

        $info = new ProcessInfo(
            workerId: 1,
            pid: 123,
            state: ProcessState::Ready,
            startedAt: $startedAt,
        );

        $uptime = $info->getUptime();

        $this->assertGreaterThanOrEqual(59, $uptime);
        $this->assertLessThanOrEqual(61, $uptime);
    }

    public function testCalculatesIdleTime(): void
    {
        $lastActivityAt = microtime(true) - 30;

        $info = new ProcessInfo(
            workerId: 1,
            pid: 123,
            state: ProcessState::Ready,
            lastActivityAt: $lastActivityAt,
        );

        $idleTime = $info->getIdleTime();

        $this->assertGreaterThanOrEqual(29, $idleTime);
        $this->assertLessThanOrEqual(31, $idleTime);
    }

    public function testChecksIfProcessIsAlive(): void
    {
        $currentPid = getmypid();

        $info = new ProcessInfo(
            workerId: 1,
            pid: $currentPid,
            state: ProcessState::Ready,
        );

        $this->assertTrue($info->isAlive());
    }

    public function testReturnsFalseForDeadProcess(): void
    {
        $info = new ProcessInfo(
            workerId: 1,
            pid: 999999,
            state: ProcessState::Ready,
        );

        $this->assertFalse($info->isAlive());
    }

    public function testReturnsFalseForZeroPid(): void
    {
        $info = new ProcessInfo(
            workerId: 1,
            pid: 0,
            state: ProcessState::Stopped,
        );

        $this->assertFalse($info->isAlive());
    }

    public function testReturnsFalseForNegativePid(): void
    {
        $info = new ProcessInfo(
            workerId: 1,
            pid: -1,
            state: ProcessState::Failed,
        );

        $this->assertFalse($info->isAlive());
    }

    public function testConvertsToArray(): void
    {
        $startedAt = microtime(true) - 100;
        $lastActivityAt = microtime(true) - 10;

        $info = new ProcessInfo(
            workerId: 3,
            pid: 55555,
            state: ProcessState::Busy,
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

    public function testImmutabilityPreservesOriginal(): void
    {
        $info = new ProcessInfo(
            workerId: 1,
            pid: 123,
            state: ProcessState::Ready,
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

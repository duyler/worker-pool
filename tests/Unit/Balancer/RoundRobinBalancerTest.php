<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\Balancer;

use Duyler\WorkerPool\Balancer\RoundRobinBalancer;
use Override;
use PHPUnit\Framework\TestCase;

class RoundRobinBalancerTest extends TestCase
{
    private RoundRobinBalancer $balancer;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->balancer = new RoundRobinBalancer();
    }

    public function testReturnsNullWhenNoWorkersAvailable(): void
    {
        $result = $this->balancer->selectWorker([]);

        $this->assertNull($result);
    }

    public function testSelectsOnlyAvailableWorker(): void
    {
        $connections = [1 => 0];

        $result = $this->balancer->selectWorker($connections);

        $this->assertSame(1, $result);
    }

    public function testRotatesThroughWorkersInOrder(): void
    {
        $connections = [1 => 0, 2 => 0, 3 => 0];

        $result1 = $this->balancer->selectWorker($connections);
        $result2 = $this->balancer->selectWorker($connections);
        $result3 = $this->balancer->selectWorker($connections);

        $this->assertSame(1, $result1);
        $this->assertSame(2, $result2);
        $this->assertSame(3, $result3);
    }

    public function testWrapsAroundAfterLastWorker(): void
    {
        $connections = [1 => 0, 2 => 0, 3 => 0];

        $this->balancer->selectWorker($connections);
        $this->balancer->selectWorker($connections);
        $this->balancer->selectWorker($connections);
        $result = $this->balancer->selectWorker($connections);

        $this->assertSame(1, $result, 'Should wrap around to first worker');
    }

    public function testDistributesEvenlyAcrossWorkers(): void
    {
        $connections = [1 => 0, 2 => 0, 3 => 0, 4 => 0];

        $distribution = [1 => 0, 2 => 0, 3 => 0, 4 => 0];

        for ($i = 0; $i < 100; $i++) {
            $workerId = $this->balancer->selectWorker($connections);
            $distribution[$workerId]++;
        }

        $this->assertSame(25, $distribution[1]);
        $this->assertSame(25, $distribution[2]);
        $this->assertSame(25, $distribution[3]);
        $this->assertSame(25, $distribution[4]);
    }

    public function testIgnoresConnectionCount(): void
    {
        $connections = [1 => 100, 2 => 0, 3 => 50];

        $result1 = $this->balancer->selectWorker($connections);
        $result2 = $this->balancer->selectWorker($connections);
        $result3 = $this->balancer->selectWorker($connections);

        $this->assertSame(1, $result1);
        $this->assertSame(2, $result2);
        $this->assertSame(3, $result3);
    }

    public function testResetsToFirstWorker(): void
    {
        $connections = [1 => 0, 2 => 0, 3 => 0];

        $this->balancer->selectWorker($connections);
        $this->balancer->selectWorker($connections);

        $this->balancer->reset();

        $this->assertSame(0, $this->balancer->getCurrentIndex(), 'Index should be 0 after reset');

        $result = $this->balancer->selectWorker($connections);

        $this->assertSame(1, $result, 'Should start from first worker after reset');
    }

    public function testHandlesWorkerIdsNotSequential(): void
    {
        $connections = [5 => 0, 10 => 0, 15 => 0];

        $result1 = $this->balancer->selectWorker($connections);
        $result2 = $this->balancer->selectWorker($connections);
        $result3 = $this->balancer->selectWorker($connections);
        $result4 = $this->balancer->selectWorker($connections);

        $this->assertSame(5, $result1);
        $this->assertSame(10, $result2);
        $this->assertSame(15, $result3);
        $this->assertSame(5, $result4);
    }

    public function testMaintainsIndexAcrossMultipleCalls(): void
    {
        $connections = [1 => 0, 2 => 0];

        $this->balancer->selectWorker($connections);

        $this->assertSame(1, $this->balancer->getCurrentIndex());

        $this->balancer->selectWorker($connections);

        $this->assertSame(2, $this->balancer->getCurrentIndex());
    }

    public function testConnectionCallbacksDoNothing(): void
    {
        $connections = [1 => 0, 2 => 0];

        $this->balancer->onConnectionEstablished(1);
        $this->balancer->onConnectionClosed(1);

        $result = $this->balancer->selectWorker($connections);

        $this->assertSame(1, $result);
    }

    public function testHandlesSingleWorkerRepeatedly(): void
    {
        $connections = [42 => 0];

        $result1 = $this->balancer->selectWorker($connections);
        $result2 = $this->balancer->selectWorker($connections);
        $result3 = $this->balancer->selectWorker($connections);

        $this->assertSame(42, $result1);
        $this->assertSame(42, $result2);
        $this->assertSame(42, $result3);
    }

    public function testHandlesDynamicWorkerListChanges(): void
    {
        $connections1 = [1 => 0, 2 => 0, 3 => 0];

        $this->balancer->selectWorker($connections1);
        $this->balancer->selectWorker($connections1);

        $connections2 = [1 => 0, 2 => 0];

        $result = $this->balancer->selectWorker($connections2);

        $this->assertSame(1, $result, 'Should restart from beginning with new worker list');
    }

    public function testHandlesWorkerRemovalBeforeCurrentIndex(): void
    {
        $connections = [1 => 0, 2 => 0, 3 => 0, 4 => 0];

        $this->balancer->selectWorker($connections);
        $this->balancer->selectWorker($connections);
        $this->balancer->selectWorker($connections);

        $this->balancer->onWorkerRemoved(2);

        $updatedConnections = [1 => 0, 3 => 0, 4 => 0];
        $result = $this->balancer->selectWorker($updatedConnections);

        $this->assertSame(4, $result, 'Should select worker 4 after worker 2 removal');
    }

    public function testHandlesWorkerRemovalAfterCurrentIndex(): void
    {
        $connections = [1 => 0, 2 => 0, 3 => 0, 4 => 0];

        $this->balancer->selectWorker($connections);
        $this->balancer->selectWorker($connections);

        $this->balancer->onWorkerRemoved(4);

        $updatedConnections = [1 => 0, 2 => 0, 3 => 0];
        $result = $this->balancer->selectWorker($updatedConnections);

        $this->assertSame(3, $result, 'Should select worker 3 after worker 4 removal');
    }

    public function testHandlesWorkerRemovalAtCurrentIndex(): void
    {
        $connections = [1 => 0, 2 => 0, 3 => 0];

        $this->balancer->selectWorker($connections);
        $this->balancer->selectWorker($connections);

        $this->balancer->onWorkerRemoved(2);

        $updatedConnections = [1 => 0, 3 => 0];
        $result = $this->balancer->selectWorker($updatedConnections);

        $this->assertSame(3, $result, 'Should select worker 3 after worker 2 removal at current index');
    }

    public function testHandlesRemovalOfNonExistentWorker(): void
    {
        $connections = [1 => 0, 2 => 0, 3 => 0];

        $this->balancer->selectWorker($connections);
        $this->balancer->selectWorker($connections);

        $this->balancer->onWorkerRemoved(999);

        $result = $this->balancer->selectWorker($connections);

        $this->assertSame(3, $result, 'Should continue normally after non-existent worker removal');
    }

    public function testContinuesRotationAfterMultipleRemovals(): void
    {
        $connections = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];

        $this->balancer->selectWorker($connections);
        $this->balancer->selectWorker($connections);
        $this->balancer->selectWorker($connections);

        $this->balancer->onWorkerRemoved(1);
        $this->balancer->onWorkerRemoved(3);

        $updatedConnections = [2 => 0, 4 => 0, 5 => 0];
        $result1 = $this->balancer->selectWorker($updatedConnections);
        $result2 = $this->balancer->selectWorker($updatedConnections);

        $this->assertSame(5, $result1);
        $this->assertContains($result2, [2, 4, 5]);
    }
}

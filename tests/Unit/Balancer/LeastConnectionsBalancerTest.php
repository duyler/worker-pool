<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\Balancer;

use PHPUnit\Framework\Attributes\CoversClass;

use Duyler\WorkerPool\Balancer\LeastConnectionsBalancer;
use Override;
use PHPUnit\Framework\TestCase;

#[CoversClass(LeastConnectionsBalancer::class)]
class LeastConnectionsBalancerTest extends TestCase
{
    private LeastConnectionsBalancer $balancer;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->balancer = new LeastConnectionsBalancer();
    }

    public function testReturnsNullWhenNoWorkersAvailable(): void
    {
        $result = $this->balancer->selectWorker([]);

        $this->assertNull($result);
    }

    public function testSelectsOnlyAvailableWorker(): void
    {
        $connections = [1 => 5];

        $result = $this->balancer->selectWorker($connections);

        $this->assertSame(1, $result);
    }

    public function testSelectsWorkerWithLeastConnections(): void
    {
        $connections = [
            1 => 10,
            2 => 3,
            3 => 7,
        ];

        $result = $this->balancer->selectWorker($connections);

        $this->assertSame(2, $result);
    }

    public function testSelectsWorkerWithZeroConnections(): void
    {
        $connections = [
            1 => 5,
            2 => 0,
            3 => 3,
        ];

        $result = $this->balancer->selectWorker($connections);

        $this->assertSame(2, $result);
    }

    public function testRandomlySelectsWhenMultipleWorkersHaveSameMinConnections(): void
    {
        $connections = [
            1 => 5,
            2 => 5,
            3 => 10,
        ];

        $selected = [];
        for ($i = 0; $i < 20; $i++) {
            $result = $this->balancer->selectWorker($connections);
            $this->assertContains($result, [1, 2]);
            $selected[$result] = true;
        }

        $this->assertCount(2, $selected, 'Should select both workers with min connections');
    }

    public function testSelectsAllWorkersWithZeroConnectionsRandomly(): void
    {
        $connections = [
            1 => 0,
            2 => 0,
            3 => 0,
        ];

        $selected = [];
        for ($i = 0; $i < 30; $i++) {
            $result = $this->balancer->selectWorker($connections);
            $this->assertContains($result, [1, 2, 3]);
            $selected[$result] = true;
        }

        $this->assertCount(3, $selected, 'Should select all workers');
    }

    public function testTracksConnectionEstablished(): void
    {
        $this->balancer->selectWorker([1 => 0, 2 => 0]);

        $this->balancer->onConnectionEstablished(1);
        $this->balancer->onConnectionEstablished(1);
        $this->balancer->onConnectionEstablished(2);

        $connections = $this->balancer->getConnections();

        $this->assertSame(2, $connections[1]);
        $this->assertSame(1, $connections[2]);
    }

    public function testTracksConnectionClosed(): void
    {
        $this->balancer->selectWorker([1 => 5, 2 => 3]);

        $this->balancer->onConnectionClosed(1);
        $this->balancer->onConnectionClosed(1);

        $connections = $this->balancer->getConnections();

        $this->assertSame(3, $connections[1]);
        $this->assertSame(3, $connections[2]);
    }

    public function testDoesNotGoBelowZeroConnections(): void
    {
        $this->balancer->selectWorker([1 => 0]);

        $this->balancer->onConnectionClosed(1);
        $this->balancer->onConnectionClosed(1);

        $connections = $this->balancer->getConnections();

        $this->assertSame(0, $connections[1]);
    }

    public function testHandlesConnectionClosedForUnknownWorker(): void
    {
        $this->balancer->selectWorker([1 => 5]);

        $this->balancer->onConnectionClosed(999);

        $connections = $this->balancer->getConnections();

        $this->assertArrayNotHasKey(999, $connections);
    }

    public function testInitializesWorkerOnFirstConnectionEstablished(): void
    {
        $this->balancer->onConnectionEstablished(42);

        $connections = $this->balancer->getConnections();

        $this->assertSame(1, $connections[42]);
    }

    public function testResetsAllConnections(): void
    {
        $this->balancer->selectWorker([1 => 5, 2 => 3]);
        $this->balancer->onConnectionEstablished(1);

        $this->balancer->reset();

        $connections = $this->balancer->getConnections();

        $this->assertEmpty($connections);
    }

    public function testSelectsCorrectlyAfterMultipleOperations(): void
    {
        $this->balancer->selectWorker([1 => 0, 2 => 0, 3 => 0]);

        $this->balancer->onConnectionEstablished(1);
        $this->balancer->onConnectionEstablished(1);
        $this->balancer->onConnectionEstablished(2);
        $this->balancer->onConnectionEstablished(3);
        $this->balancer->onConnectionEstablished(3);
        $this->balancer->onConnectionEstablished(3);

        $connections = $this->balancer->getConnections();
        $this->assertSame(2, $connections[1]);
        $this->assertSame(1, $connections[2]);
        $this->assertSame(3, $connections[3]);

        $selected = $this->balancer->selectWorker($connections);

        $this->assertSame(2, $selected, 'Should select worker 2 with least connections (1)');
    }

    public function testHandlesLargeNumberOfWorkers(): void
    {
        $connections = [];
        for ($i = 1; $i <= 100; $i++) {
            $connections[$i] = $i * 10;
        }

        $result = $this->balancer->selectWorker($connections);

        $this->assertSame(1, $result, 'Should select worker 1 with 10 connections');
    }

    public function testSelectsNewWorkerAfterConnectionsChange(): void
    {
        $connections = [1 => 10, 2 => 5, 3 => 8];

        $result1 = $this->balancer->selectWorker($connections);
        $this->assertSame(2, $result1);

        $this->balancer->onConnectionEstablished(2);
        $this->balancer->onConnectionEstablished(2);
        $this->balancer->onConnectionEstablished(2);
        $this->balancer->onConnectionEstablished(2);
        $this->balancer->onConnectionEstablished(2);
        $this->balancer->onConnectionEstablished(2);

        $newConnections = $this->balancer->getConnections();
        $result2 = $this->balancer->selectWorker($newConnections);

        $this->assertSame(3, $result2, 'Should now select worker 3');
    }

    public function testRemovesWorkerFromConnections(): void
    {
        $this->balancer->selectWorker([1 => 10, 2 => 5, 3 => 7]);

        $this->balancer->onWorkerRemoved(2);

        $connections = $this->balancer->getConnections();

        $this->assertArrayNotHasKey(2, $connections);
        $this->assertSame(10, $connections[1]);
        $this->assertSame(7, $connections[3]);
    }

    public function testHandlesRemovalOfNonExistentWorker(): void
    {
        $this->balancer->selectWorker([1 => 5, 2 => 3]);

        $this->balancer->onWorkerRemoved(999);

        $connections = $this->balancer->getConnections();

        $this->assertCount(2, $connections);
        $this->assertSame(5, $connections[1]);
        $this->assertSame(3, $connections[2]);
    }

    public function testSelectsCorrectlyAfterWorkerRemoval(): void
    {
        $this->balancer->selectWorker([1 => 10, 2 => 5, 3 => 7]);

        $this->balancer->onWorkerRemoved(2);

        $connections = $this->balancer->getConnections();
        $result = $this->balancer->selectWorker($connections);

        $this->assertSame(3, $result, 'Should select worker 3 with least connections after worker 2 removal');
    }

    public function testHandlesRemovalOfAllWorkers(): void
    {
        $this->balancer->selectWorker([1 => 5, 2 => 3]);

        $this->balancer->onWorkerRemoved(1);
        $this->balancer->onWorkerRemoved(2);

        $connections = $this->balancer->getConnections();

        $this->assertEmpty($connections);
    }
}

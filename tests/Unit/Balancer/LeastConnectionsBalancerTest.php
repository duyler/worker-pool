<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\Balancer;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

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

    #[Test]
    public function returns_null_when_no_workers_available(): void
    {
        $result = $this->balancer->selectWorker([]);

        $this->assertNull($result);
    }

    #[Test]
    public function selects_only_available_worker(): void
    {
        $connections = [1 => 5];

        $result = $this->balancer->selectWorker($connections);

        $this->assertSame(1, $result);
    }

    #[Test]
    public function selects_worker_with_least_connections(): void
    {
        $connections = [
            1 => 10,
            2 => 3,
            3 => 7,
        ];

        $result = $this->balancer->selectWorker($connections);

        $this->assertSame(2, $result);
    }

    #[Test]
    public function selects_worker_with_zero_connections(): void
    {
        $connections = [
            1 => 5,
            2 => 0,
            3 => 3,
        ];

        $result = $this->balancer->selectWorker($connections);

        $this->assertSame(2, $result);
    }

    #[Test]
    public function randomly_selects_when_multiple_workers_have_same_min_connections(): void
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

    #[Test]
    public function selects_all_workers_with_zero_connections_randomly(): void
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

    #[Test]
    public function tracks_connection_established(): void
    {
        $this->balancer->selectWorker([1 => 0, 2 => 0]);

        $this->balancer->onConnectionEstablished(1);
        $this->balancer->onConnectionEstablished(1);
        $this->balancer->onConnectionEstablished(2);

        $connections = $this->balancer->getConnections();

        $this->assertSame(2, $connections[1]);
        $this->assertSame(1, $connections[2]);
    }

    #[Test]
    public function tracks_connection_closed(): void
    {
        $this->balancer->selectWorker([1 => 5, 2 => 3]);

        $this->balancer->onConnectionClosed(1);
        $this->balancer->onConnectionClosed(1);

        $connections = $this->balancer->getConnections();

        $this->assertSame(3, $connections[1]);
        $this->assertSame(3, $connections[2]);
    }

    #[Test]
    public function does_not_go_below_zero_connections(): void
    {
        $this->balancer->selectWorker([1 => 0]);

        $this->balancer->onConnectionClosed(1);
        $this->balancer->onConnectionClosed(1);

        $connections = $this->balancer->getConnections();

        $this->assertSame(0, $connections[1]);
    }

    #[Test]
    public function handles_connection_closed_for_unknown_worker(): void
    {
        $this->balancer->selectWorker([1 => 5]);

        $this->balancer->onConnectionClosed(999);

        $connections = $this->balancer->getConnections();

        $this->assertArrayNotHasKey(999, $connections);
    }

    #[Test]
    public function initializes_worker_on_first_connection_established(): void
    {
        $this->balancer->onConnectionEstablished(42);

        $connections = $this->balancer->getConnections();

        $this->assertSame(1, $connections[42]);
    }

    #[Test]
    public function resets_all_connections(): void
    {
        $this->balancer->selectWorker([1 => 5, 2 => 3]);
        $this->balancer->onConnectionEstablished(1);

        $this->balancer->reset();

        $connections = $this->balancer->getConnections();

        $this->assertEmpty($connections);
    }

    #[Test]
    public function selects_correctly_after_multiple_operations(): void
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

    #[Test]
    public function handles_large_number_of_workers(): void
    {
        $connections = [];
        for ($i = 1; $i <= 100; $i++) {
            $connections[$i] = $i * 10;
        }

        $result = $this->balancer->selectWorker($connections);

        $this->assertSame(1, $result, 'Should select worker 1 with 10 connections');
    }

    #[Test]
    public function selects_new_worker_after_connections_change(): void
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

    #[Test]
    public function removes_worker_from_connections(): void
    {
        $this->balancer->selectWorker([1 => 10, 2 => 5, 3 => 7]);

        $this->balancer->onWorkerRemoved(2);

        $connections = $this->balancer->getConnections();

        $this->assertArrayNotHasKey(2, $connections);
        $this->assertSame(10, $connections[1]);
        $this->assertSame(7, $connections[3]);
    }

    #[Test]
    public function handles_removal_of_non_existent_worker(): void
    {
        $this->balancer->selectWorker([1 => 5, 2 => 3]);

        $this->balancer->onWorkerRemoved(999);

        $connections = $this->balancer->getConnections();

        $this->assertCount(2, $connections);
        $this->assertSame(5, $connections[1]);
        $this->assertSame(3, $connections[2]);
    }

    #[Test]
    public function selects_correctly_after_worker_removal(): void
    {
        $this->balancer->selectWorker([1 => 10, 2 => 5, 3 => 7]);

        $this->balancer->onWorkerRemoved(2);

        $connections = $this->balancer->getConnections();
        $result = $this->balancer->selectWorker($connections);

        $this->assertSame(3, $result, 'Should select worker 3 with least connections after worker 2 removal');
    }

    #[Test]
    public function handles_removal_of_all_workers(): void
    {
        $this->balancer->selectWorker([1 => 5, 2 => 3]);

        $this->balancer->onWorkerRemoved(1);
        $this->balancer->onWorkerRemoved(2);

        $connections = $this->balancer->getConnections();

        $this->assertEmpty($connections);
    }
}

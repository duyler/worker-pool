<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\Balancer;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

use Duyler\WorkerPool\Balancer\RoundRobinBalancer;
use Override;
use PHPUnit\Framework\TestCase;

#[CoversClass(RoundRobinBalancer::class)]
class RoundRobinBalancerTest extends TestCase
{
    private RoundRobinBalancer $balancer;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->balancer = new RoundRobinBalancer();
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
        $connections = [1 => 0];

        $result = $this->balancer->selectWorker($connections);

        $this->assertSame(1, $result);
    }

    #[Test]
    public function rotates_through_workers_in_order(): void
    {
        $connections = [1 => 0, 2 => 0, 3 => 0];

        $result1 = $this->balancer->selectWorker($connections);
        $result2 = $this->balancer->selectWorker($connections);
        $result3 = $this->balancer->selectWorker($connections);

        $this->assertSame(1, $result1);
        $this->assertSame(2, $result2);
        $this->assertSame(3, $result3);
    }

    #[Test]
    public function wraps_around_after_last_worker(): void
    {
        $connections = [1 => 0, 2 => 0, 3 => 0];

        $this->balancer->selectWorker($connections);
        $this->balancer->selectWorker($connections);
        $this->balancer->selectWorker($connections);
        $result = $this->balancer->selectWorker($connections);

        $this->assertSame(1, $result, 'Should wrap around to first worker');
    }

    #[Test]
    public function distributes_evenly_across_workers(): void
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

    #[Test]
    public function ignores_connection_count(): void
    {
        $connections = [1 => 100, 2 => 0, 3 => 50];

        $result1 = $this->balancer->selectWorker($connections);
        $result2 = $this->balancer->selectWorker($connections);
        $result3 = $this->balancer->selectWorker($connections);

        $this->assertSame(1, $result1);
        $this->assertSame(2, $result2);
        $this->assertSame(3, $result3);
    }

    #[Test]
    public function resets_to_first_worker(): void
    {
        $connections = [1 => 0, 2 => 0, 3 => 0];

        $this->balancer->selectWorker($connections);
        $this->balancer->selectWorker($connections);

        $this->balancer->reset();

        $this->assertSame(0, $this->balancer->getCurrentIndex(), 'Index should be 0 after reset');

        $result = $this->balancer->selectWorker($connections);

        $this->assertSame(1, $result, 'Should start from first worker after reset');
    }

    #[Test]
    public function handles_worker_ids_not_sequential(): void
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

    #[Test]
    public function maintains_index_across_multiple_calls(): void
    {
        $connections = [1 => 0, 2 => 0];

        $this->balancer->selectWorker($connections);

        $this->assertSame(1, $this->balancer->getCurrentIndex());

        $this->balancer->selectWorker($connections);

        $this->assertSame(2, $this->balancer->getCurrentIndex());
    }

    #[Test]
    public function connection_callbacks_do_nothing(): void
    {
        $connections = [1 => 0, 2 => 0];

        $this->balancer->onConnectionEstablished(1);
        $this->balancer->onConnectionClosed(1);

        $result = $this->balancer->selectWorker($connections);

        $this->assertSame(1, $result);
    }

    #[Test]
    public function handles_single_worker_repeatedly(): void
    {
        $connections = [42 => 0];

        $result1 = $this->balancer->selectWorker($connections);
        $result2 = $this->balancer->selectWorker($connections);
        $result3 = $this->balancer->selectWorker($connections);

        $this->assertSame(42, $result1);
        $this->assertSame(42, $result2);
        $this->assertSame(42, $result3);
    }

    #[Test]
    public function handles_dynamic_worker_list_changes(): void
    {
        $connections1 = [1 => 0, 2 => 0, 3 => 0];

        $this->balancer->selectWorker($connections1);
        $this->balancer->selectWorker($connections1);

        $connections2 = [1 => 0, 2 => 0];

        $result = $this->balancer->selectWorker($connections2);

        $this->assertSame(1, $result, 'Should restart from beginning with new worker list');
    }

    #[Test]
    public function handles_worker_removal_before_current_index(): void
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

    #[Test]
    public function handles_worker_removal_after_current_index(): void
    {
        $connections = [1 => 0, 2 => 0, 3 => 0, 4 => 0];

        $this->balancer->selectWorker($connections);
        $this->balancer->selectWorker($connections);

        $this->balancer->onWorkerRemoved(4);

        $updatedConnections = [1 => 0, 2 => 0, 3 => 0];
        $result = $this->balancer->selectWorker($updatedConnections);

        $this->assertSame(3, $result, 'Should select worker 3 after worker 4 removal');
    }

    #[Test]
    public function handles_worker_removal_at_current_index(): void
    {
        $connections = [1 => 0, 2 => 0, 3 => 0];

        $this->balancer->selectWorker($connections);
        $this->balancer->selectWorker($connections);

        $this->balancer->onWorkerRemoved(2);

        $updatedConnections = [1 => 0, 3 => 0];
        $result = $this->balancer->selectWorker($updatedConnections);

        $this->assertSame(3, $result, 'Should select worker 3 after worker 2 removal at current index');
    }

    #[Test]
    public function handles_removal_of_non_existent_worker(): void
    {
        $connections = [1 => 0, 2 => 0, 3 => 0];

        $this->balancer->selectWorker($connections);
        $this->balancer->selectWorker($connections);

        $this->balancer->onWorkerRemoved(999);

        $result = $this->balancer->selectWorker($connections);

        $this->assertSame(3, $result, 'Should continue normally after non-existent worker removal');
    }

    #[Test]
    public function continues_rotation_after_multiple_removals(): void
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

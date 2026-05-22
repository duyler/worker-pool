<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Performance;

use Duyler\WorkerPool\Balancer\LeastConnectionsBalancer;
use Duyler\WorkerPool\Balancer\RoundRobinBalancer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('performance')]
#[CoversClass(LeastConnectionsBalancer::class)]
#[CoversClass(RoundRobinBalancer::class)]
final class BalancerPerformanceTest extends TestCase
{
    #[Test]
    public function least_connections_10000_selections_timing(): void
    {
        $balancer = new LeastConnectionsBalancer();

        $connections = [];
        for ($i = 1; $i <= 16; $i++) {
            $connections[$i] = 0;
        }

        $start = microtime(true);

        for ($i = 0; $i < 10000; $i++) {
            $workerId = $balancer->selectWorker($connections);
            if (null !== $workerId) {
                $balancer->onConnectionEstablished($workerId);
                $connections[$workerId]++;
            }
        }

        $elapsed = microtime(true) - $start;

        $this->assertLessThan(1.0, $elapsed);
    }

    #[Test]
    public function round_robin_10000_selections_timing(): void
    {
        $balancer = new RoundRobinBalancer();

        $connections = [];
        for ($i = 1; $i <= 16; $i++) {
            $connections[$i] = 0;
        }

        $start = microtime(true);

        for ($i = 0; $i < 10000; $i++) {
            $workerId = $balancer->selectWorker($connections);
            if (null !== $workerId) {
                $balancer->onConnectionEstablished($workerId);
                $connections[$workerId]++;
            }
        }

        $elapsed = microtime(true) - $start;

        $this->assertLessThan(1.0, $elapsed);
    }

    #[Test]
    public function least_connections_with_many_workers(): void
    {
        $balancer = new LeastConnectionsBalancer();

        $connections = [];
        for ($i = 1; $i <= 256; $i++) {
            $connections[$i] = 0;
        }

        $start = microtime(true);

        for ($i = 0; $i < 5000; $i++) {
            $balancer->selectWorker($connections);
        }

        $elapsed = microtime(true) - $start;

        $this->assertLessThan(2.0, $elapsed);
    }

    #[Test]
    public function round_robin_distributes_evenly(): void
    {
        $balancer = new RoundRobinBalancer();

        $connections = [
            1 => 0,
            2 => 0,
            3 => 0,
            4 => 0,
        ];

        $distribution = [1 => 0, 2 => 0, 3 => 0, 4 => 0];

        $start = microtime(true);

        for ($i = 0; $i < 1000; $i++) {
            $workerId = $balancer->selectWorker($connections);
            if (null !== $workerId) {
                ++$distribution[$workerId];
            }
        }

        $elapsed = microtime(true) - $start;

        $this->assertLessThan(0.5, $elapsed);

        foreach ($distribution as $count) {
            $this->assertSame(250, $count);
        }
    }

    #[Test]
    public function balancer_reset_timing(): void
    {
        $balancer = new LeastConnectionsBalancer();

        $connections = [];
        for ($i = 1; $i <= 64; $i++) {
            $connections[$i] = 0;
        }

        $start = microtime(true);

        for ($cycle = 0; $cycle < 1000; $cycle++) {
            $balancer->selectWorker($connections);
            $balancer->reset();
        }

        $elapsed = microtime(true) - $start;

        $this->assertLessThan(1.0, $elapsed);
    }
}

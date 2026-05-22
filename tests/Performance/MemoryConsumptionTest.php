<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Performance;

use Duyler\WorkerPool\Balancer\LeastConnectionsBalancer;
use Duyler\WorkerPool\IPC\Message;
use Duyler\WorkerPool\IPC\MessageType;
use Duyler\WorkerPool\Master\ConnectionQueue;
use Duyler\WorkerPool\Master\SocketManager;
use Duyler\WorkerPool\Socket\SocketWrapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function gc_collect_cycles;
use function memory_get_usage;

use const AF_INET;
use const SOCK_STREAM;
use const SOL_TCP;

#[Group('performance')]
#[CoversClass(Message::class)]
#[UsesClass(MessageType::class)]
#[UsesClass(LeastConnectionsBalancer::class)]
#[UsesClass(ConnectionQueue::class)]
#[UsesClass(SocketWrapper::class)]
#[UsesClass(SocketManager::class)]
final class MemoryConsumptionTest extends TestCase
{
    #[Test]
    public function message_creation_memory_stable(): void
    {
        gc_collect_cycles();
        $baseline = memory_get_usage(true);

        for ($i = 0; $i < 10000; $i++) {
            $message = Message::workerReady($i);
            $message->serialize();
        }

        gc_collect_cycles();
        $after = memory_get_usage(true);

        $growth = $after - $baseline;

        $this->assertLessThan(2097152, $growth);
    }

    #[Test]
    public function message_roundtrip_memory_stable(): void
    {
        gc_collect_cycles();
        $baseline = memory_get_usage(true);

        for ($i = 0; $i < 5000; $i++) {
            $original = Message::workerMetrics([
                'requests' => $i,
                'memory' => 65536,
                'uptime' => 3600.5,
            ]);

            $serialized = $original->serialize();
            Message::unserialize($serialized);
        }

        gc_collect_cycles();
        $after = memory_get_usage(true);

        $growth = $after - $baseline;

        $this->assertLessThan(2097152, $growth);
    }

    #[Test]
    public function balancer_operations_memory_stable(): void
    {
        $balancer = new LeastConnectionsBalancer();

        $connections = [];
        for ($i = 1; $i <= 32; $i++) {
            $connections[$i] = 0;
        }

        gc_collect_cycles();
        $baseline = memory_get_usage(true);

        for ($i = 0; $i < 50000; $i++) {
            $workerId = $balancer->selectWorker($connections);
            if (null !== $workerId) {
                $balancer->onConnectionEstablished($workerId);
                ++$connections[$workerId];
            }

            if (0 === $i % 100) {
                foreach (array_keys($connections) as $wid) {
                    $balancer->onConnectionClosed($wid);
                    $connections[$wid] = 0;
                }
            }
        }

        gc_collect_cycles();
        $after = memory_get_usage(true);

        $growth = $after - $baseline;

        $this->assertLessThan(1048576, $growth);
    }

    #[Test]
    public function queue_operations_memory_stable(): void
    {
        gc_collect_cycles();
        $baseline = memory_get_usage(true);

        for ($cycle = 0; $cycle < 100; $cycle++) {
            $queue = new ConnectionQueue(socketWrapper: new SocketWrapper(), maxSize: 50);

            for ($i = 0; $i < 50; $i++) {
                $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
                $queue->enqueue($socket);
            }

            while (null !== ($socket = $queue->dequeue())) {
                socket_close($socket);
            }

            unset($queue);
        }

        gc_collect_cycles();
        $after = memory_get_usage(true);

        $growth = $after - $baseline;

        $this->assertLessThan(2097152, $growth);
    }
}

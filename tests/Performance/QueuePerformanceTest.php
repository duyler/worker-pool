<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Performance;

use Duyler\WorkerPool\Master\ConnectionQueue;
use Duyler\WorkerPool\Socket\SocketWrapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function count;

use function assert;

use const AF_INET;
use const SOCK_STREAM;
use const SOL_TCP;

#[Group('performance')]
#[CoversClass(ConnectionQueue::class)]
#[UsesClass(SocketWrapper::class)]
final class QueuePerformanceTest extends TestCase
{
    #[Test]
    public function enqueue_dequeue_100_sockets_under_threshold(): void
    {
        $queue = new ConnectionQueue(socketWrapper: new SocketWrapper(), maxSize: 200);

        $sockets = $this->createSockets(100);

        $start = microtime(true);

        foreach ($sockets as $socket) {
            $queue->enqueue($socket);
        }

        $dequeued = $this->drainQueue($queue);

        $elapsed = microtime(true) - $start;

        $this->closeSockets($dequeued);
        $queue->clear();

        $this->assertLessThan(0.5, $elapsed);
        $this->assertSame(100, count($dequeued));
    }

    #[Test]
    public function enqueue_dequeue_1000_sockets_under_threshold(): void
    {
        $queue = new ConnectionQueue(socketWrapper: new SocketWrapper(), maxSize: 1100);

        $sockets = $this->createSockets(1000);

        $start = microtime(true);

        foreach ($sockets as $socket) {
            $queue->enqueue($socket);
        }

        $dequeued = $this->drainQueue($queue);

        $elapsed = microtime(true) - $start;

        $this->closeSockets($dequeued);
        $queue->clear();

        $this->assertLessThan(2.0, $elapsed);
        $this->assertSame(1000, count($dequeued));
    }

    #[Test]
    public function mixed_enqueue_dequeue_cycles_timing(): void
    {
        $queue = new ConnectionQueue(socketWrapper: new SocketWrapper(), maxSize: 20);

        $start = microtime(true);

        $openSockets = [];

        for ($cycle = 0; $cycle < 500; $cycle++) {
            $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            $queue->enqueue($socket);

            if (0 === $cycle % 2) {
                $dequeued = $queue->dequeue();
                if (null !== $dequeued) {
                    $openSockets[] = $dequeued;
                }
            }
        }

        $remaining = $this->drainQueue($queue);

        $elapsed = microtime(true) - $start;

        $this->closeSockets($openSockets);
        $this->closeSockets($remaining);
        $queue->clear();

        $this->assertLessThan(1.0, $elapsed);
    }

    #[Test]
    public function fifo_order_under_load(): void
    {
        $queue = new ConnectionQueue(socketWrapper: new SocketWrapper(), maxSize: 500);

        $sockets = $this->createSockets(500);

        foreach ($sockets as $socket) {
            $queue->enqueue($socket);
        }

        $dequeued = $this->drainQueue($queue);

        $this->assertSame(count($sockets), count($dequeued));

        foreach ($dequeued as $index => $socket) {
            $this->assertSame($sockets[$index], $socket);
        }

        $this->closeSockets($dequeued);
    }

    #[Test]
    public function full_queue_rejection_timing(): void
    {
        $queue = new ConnectionQueue(socketWrapper: new SocketWrapper(), maxSize: 50);

        $sockets = $this->createSockets(50);
        $extraSockets = $this->createSockets(50);

        foreach ($sockets as $socket) {
            $queue->enqueue($socket);
        }

        $start = microtime(true);

        $rejected = 0;
        foreach ($extraSockets as $socket) {
            if (false === $queue->enqueue($socket)) {
                ++$rejected;
            }
        }

        $elapsed = microtime(true) - $start;

        $this->assertSame(50, $rejected);
        $this->assertLessThan(0.1, $elapsed);

        $this->closeSockets($extraSockets);
        $queue->clear();
    }

    private function createSockets(int $count): array
    {
        $sockets = [];

        for ($i = 0; $i < $count; $i++) {
            $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            assert(false !== $socket);
            $sockets[] = $socket;
        }

        return $sockets;
    }

    private function drainQueue(ConnectionQueue $queue): array
    {
        $sockets = [];

        while (null !== ($socket = $queue->dequeue())) {
            $sockets[] = $socket;
        }

        return $sockets;
    }

    private function closeSockets(array $sockets): void
    {
        foreach ($sockets as $socket) {
            socket_close($socket);
        }
    }
}

<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\Master;

use Duyler\WorkerPool\Master\ConnectionQueue;
use PHPUnit\Framework\TestCase;

use const AF_INET;
use const SOCK_STREAM;
use const SOL_TCP;

class ConnectionQueueTest extends TestCase
{
    public function testCreatesEmptyQueue(): void
    {
        $queue = new ConnectionQueue(maxSize: 10);

        $this->assertTrue($queue->isEmpty());
        $this->assertFalse($queue->isFull());
        $this->assertSame(0, $queue->size());
    }

    public function testEnqueuesSocket(): void
    {
        $queue = new ConnectionQueue(maxSize: 10);

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertNotFalse($socket);

        $result = $queue->enqueue($socket);

        $this->assertTrue($result);
        $this->assertSame(1, $queue->size());
        $this->assertFalse($queue->isEmpty());

        $queue->clear();
    }

    public function testDequeuesSocket(): void
    {
        $queue = new ConnectionQueue(maxSize: 10);

        $socket1 = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $socket2 = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        $this->assertNotFalse($socket1);
        $this->assertNotFalse($socket2);

        $queue->enqueue($socket1);
        $queue->enqueue($socket2);

        $dequeued = $queue->dequeue();

        $this->assertSame($socket1, $dequeued);
        $this->assertSame(1, $queue->size());

        socket_close($socket1);
        $queue->clear();
    }

    public function testReturnsNullWhenEmpty(): void
    {
        $queue = new ConnectionQueue(maxSize: 10);

        $result = $queue->dequeue();

        $this->assertNull($result);
    }

    public function testRespectsMaxSize(): void
    {
        $queue = new ConnectionQueue(maxSize: 2);

        $socket1 = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $socket2 = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $socket3 = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        $this->assertNotFalse($socket1);
        $this->assertNotFalse($socket2);
        $this->assertNotFalse($socket3);

        $this->assertTrue($queue->enqueue($socket1));
        $this->assertTrue($queue->enqueue($socket2));
        $this->assertFalse($queue->enqueue($socket3));

        $this->assertTrue($queue->isFull());

        socket_close($socket3);
        $queue->clear();
    }

    public function testMaintainsFifoOrder(): void
    {
        $queue = new ConnectionQueue(maxSize: 10);

        $socket1 = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $socket2 = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $socket3 = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        $this->assertNotFalse($socket1);
        $this->assertNotFalse($socket2);
        $this->assertNotFalse($socket3);

        $queue->enqueue($socket1);
        $queue->enqueue($socket2);
        $queue->enqueue($socket3);

        $this->assertSame($socket1, $queue->dequeue());
        $this->assertSame($socket2, $queue->dequeue());
        $this->assertSame($socket3, $queue->dequeue());

        socket_close($socket1);
        socket_close($socket2);
        socket_close($socket3);
    }

    public function testClearsAllSockets(): void
    {
        $queue = new ConnectionQueue(maxSize: 10);

        $socket1 = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $socket2 = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        $this->assertNotFalse($socket1);
        $this->assertNotFalse($socket2);

        $queue->enqueue($socket1);
        $queue->enqueue($socket2);

        $this->assertSame(2, $queue->size());

        $queue->clear();

        $this->assertSame(0, $queue->size());
        $this->assertTrue($queue->isEmpty());
    }

    public function testChecksIfFull(): void
    {
        $queue = new ConnectionQueue(maxSize: 1);

        $this->assertFalse($queue->isFull());

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertNotFalse($socket);

        $queue->enqueue($socket);

        $this->assertTrue($queue->isFull());

        $queue->clear();
    }

    public function testHandlesMultipleEnqueueDequeueCycles(): void
    {
        $queue = new ConnectionQueue(maxSize: 3);

        $socket1 = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $socket2 = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        $this->assertNotFalse($socket1);
        $this->assertNotFalse($socket2);

        $queue->enqueue($socket1);
        $this->assertSame(1, $queue->size());

        $dequeued1 = $queue->dequeue();
        socket_close($dequeued1);
        $this->assertSame(0, $queue->size());

        $queue->enqueue($socket2);
        $this->assertSame(1, $queue->size());

        $dequeued2 = $queue->dequeue();
        socket_close($dequeued2);
        $this->assertTrue($queue->isEmpty());
    }
}

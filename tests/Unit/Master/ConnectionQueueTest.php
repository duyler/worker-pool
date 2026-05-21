<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\Master;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

use Duyler\WorkerPool\Master\ConnectionQueue;
use PHPUnit\Framework\TestCase;

use Duyler\WorkerPool\Socket\SocketWrapper;

use const AF_INET;
use const SOCK_STREAM;
use const SOL_TCP;

#[CoversClass(ConnectionQueue::class)]
class ConnectionQueueTest extends TestCase
{
    #[Test]
    public function creates_empty_queue(): void
    {
        $queue = new ConnectionQueue(socketWrapper: new SocketWrapper(), maxSize: 10);

        $this->assertTrue($queue->isEmpty());
        $this->assertFalse($queue->isFull());
        $this->assertSame(0, $queue->size());
    }

    #[Test]
    public function enqueues_socket(): void
    {
        $queue = new ConnectionQueue(socketWrapper: new SocketWrapper(), maxSize: 10);

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertNotFalse($socket);

        $result = $queue->enqueue($socket);

        $this->assertTrue($result);
        $this->assertSame(1, $queue->size());
        $this->assertFalse($queue->isEmpty());

        $queue->clear();
    }

    #[Test]
    public function dequeues_socket(): void
    {
        $queue = new ConnectionQueue(socketWrapper: new SocketWrapper(), maxSize: 10);

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

    #[Test]
    public function returns_null_when_empty(): void
    {
        $queue = new ConnectionQueue(socketWrapper: new SocketWrapper(), maxSize: 10);

        $result = $queue->dequeue();

        $this->assertNull($result);
    }

    #[Test]
    public function respects_max_size(): void
    {
        $queue = new ConnectionQueue(socketWrapper: new SocketWrapper(), maxSize: 2);

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

    #[Test]
    public function maintains_fifo_order(): void
    {
        $queue = new ConnectionQueue(socketWrapper: new SocketWrapper(), maxSize: 10);

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

    #[Test]
    public function clears_all_sockets(): void
    {
        $queue = new ConnectionQueue(socketWrapper: new SocketWrapper(), maxSize: 10);

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

    #[Test]
    public function checks_if_full(): void
    {
        $queue = new ConnectionQueue(socketWrapper: new SocketWrapper(), maxSize: 1);

        $this->assertFalse($queue->isFull());

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertNotFalse($socket);

        $queue->enqueue($socket);

        $this->assertTrue($queue->isFull());

        $queue->clear();
    }

    #[Test]
    public function handles_multiple_enqueue_dequeue_cycles(): void
    {
        $queue = new ConnectionQueue(socketWrapper: new SocketWrapper(), maxSize: 3);

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

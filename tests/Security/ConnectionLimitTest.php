<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Security;

use Duyler\WorkerPool\Master\ConnectionQueue;
use Duyler\WorkerPool\Socket\SocketWrapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use const AF_INET;
use const SOCK_STREAM;
use const SOL_TCP;

#[Group('security')]
#[CoversClass(ConnectionQueue::class)]
#[UsesClass(SocketWrapper::class)]
final class ConnectionLimitTest extends TestCase
{
    #[Test]
    public function rejects_connections_when_full(): void
    {
        $maxSize = 5;
        $queue = new ConnectionQueue(socketWrapper: new SocketWrapper(), maxSize: $maxSize);

        $accepted = 0;
        $rejected = 0;

        for ($i = 0; $i < 10; $i++) {
            $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            if ($queue->enqueue($socket)) {
                ++$accepted;
            } else {
                ++$rejected;
                socket_close($socket);
            }
        }

        $this->assertSame($maxSize, $accepted);
        $this->assertSame(10 - $maxSize, $rejected);
        $this->assertTrue($queue->isFull());

        $queue->clear();
    }

    #[Test]
    public function accepts_after_dequeue_from_full_queue(): void
    {
        $maxSize = 3;
        $queue = new ConnectionQueue(socketWrapper: new SocketWrapper(), maxSize: $maxSize);

        $sockets = [];
        for ($i = 0; $i < 3; $i++) {
            $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            $sockets[] = $socket;
            $this->assertTrue($queue->enqueue($socket));
        }

        $this->assertTrue($queue->isFull());

        $extraSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertFalse($queue->enqueue($extraSocket));

        $dequeued = $queue->dequeue();
        $this->assertNotNull($dequeued);
        socket_close($dequeued);

        $this->assertFalse($queue->isFull());

        $this->assertTrue($queue->enqueue($extraSocket));

        $queue->clear();
    }

    #[Test]
    public function continues_after_rejection(): void
    {
        $maxSize = 2;
        $queue = new ConnectionQueue(socketWrapper: new SocketWrapper(), maxSize: $maxSize);

        $socket1 = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $socket2 = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $socket3 = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $socket4 = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        $this->assertTrue($queue->enqueue($socket1));
        $this->assertTrue($queue->enqueue($socket2));
        $this->assertFalse($queue->enqueue($socket3));

        socket_close($socket3);

        $dequeued = $queue->dequeue();
        $this->assertNotNull($dequeued);
        socket_close($dequeued);

        $this->assertTrue($queue->enqueue($socket4));

        $this->assertSame(2, $queue->size());

        $queue->clear();
    }

    #[Test]
    public function alternating_enqueue_dequeue_at_limit(): void
    {
        $maxSize = 3;
        $queue = new ConnectionQueue(socketWrapper: new SocketWrapper(), maxSize: $maxSize);

        $allSockets = [];

        for ($cycle = 0; $cycle < 10; $cycle++) {
            for ($i = 0; $i < $maxSize; $i++) {
                $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
                $allSockets[] = $socket;
                $this->assertTrue($queue->enqueue($socket));
            }

            $this->assertTrue($queue->isFull());

            $overflowSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            $this->assertFalse($queue->enqueue($overflowSocket));
            socket_close($overflowSocket);

            while (null !== ($dequeued = $queue->dequeue())) {
                socket_close($dequeued);
            }

            $this->assertTrue($queue->isEmpty());
        }

        $this->assertSame(0, $queue->size());
    }

    #[Test]
    public function queue_size_never_exceeds_max(): void
    {
        $maxSize = 10;
        $queue = new ConnectionQueue(socketWrapper: new SocketWrapper(), maxSize: $maxSize);

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            $queue->enqueue($socket);

            $this->assertLessThanOrEqual($maxSize, $queue->size());

            if (0 === $attempt % 3) {
                $dequeued = $queue->dequeue();
                if (null !== $dequeued) {
                    socket_close($dequeued);
                }
            }
        }

        $queue->clear();
    }

    #[Test]
    public function clear_resets_full_queue(): void
    {
        $maxSize = 5;
        $queue = new ConnectionQueue(socketWrapper: new SocketWrapper(), maxSize: $maxSize);

        for ($i = 0; $i < $maxSize; $i++) {
            $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            $queue->enqueue($socket);
        }

        $this->assertTrue($queue->isFull());

        $queue->clear();

        $this->assertTrue($queue->isEmpty());
        $this->assertFalse($queue->isFull());
        $this->assertSame(0, $queue->size());

        $newSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertTrue($queue->enqueue($newSocket));

        $queue->clear();
    }
}

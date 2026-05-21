<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Master;

use Duyler\WorkerPool\Socket\SocketWrapperInterface;
use RuntimeException;
use Socket;
use SplQueue;

use function assert;

final readonly class ConnectionQueue
{
    private SplQueue $queue;

    public function __construct(
        private int $maxSize,
        private SocketWrapperInterface $socketWrapper,
    ) {
        $this->queue = new SplQueue();
    }

    public function __destruct()
    {
        $this->clear();
    }

    public function enqueue(Socket $socket): bool
    {
        if ($this->isFull()) {
            return false;
        }

        $this->queue->enqueue($socket);

        return true;
    }

    public function dequeue(): ?Socket
    {
        try {
            $socket = $this->queue->dequeue();
        } catch (RuntimeException) {
            return null;
        }

        assert($socket instanceof Socket);

        return $socket;
    }

    public function size(): int
    {
        return $this->queue->count();
    }

    public function isEmpty(): bool
    {
        return $this->queue->isEmpty();
    }

    public function isFull(): bool
    {
        return $this->queue->count() >= $this->maxSize;
    }

    public function clear(): void
    {
        while (false === $this->queue->isEmpty()) {
            $socket = $this->queue->dequeue();
            assert($socket instanceof Socket);
            $this->socketWrapper->close($socket);
        }
    }
}

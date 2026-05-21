<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Master;

use Duyler\WorkerPool\Socket\SocketWrapperInterface;
use Socket;

use function count;

final class ConnectionQueue
{
    /**
     * @var array<Socket>
     */
    private array $queue = [];

    public function __construct(
        private readonly int $maxSize,
        private readonly SocketWrapperInterface $socketWrapper,
    ) {}

    public function __destruct()
    {
        $this->clear();
    }

    public function enqueue(Socket $socket): bool
    {
        if ($this->isFull()) {
            return false;
        }

        $this->queue[] = $socket;

        return true;
    }

    public function dequeue(): ?Socket
    {
        if ($this->isEmpty()) {
            return null;
        }

        return array_shift($this->queue);
    }

    public function size(): int
    {
        return count($this->queue);
    }

    public function isEmpty(): bool
    {
        return [] === $this->queue;
    }

    public function isFull(): bool
    {
        return count($this->queue) >= $this->maxSize;
    }

    public function clear(): void
    {
        foreach ($this->queue as $socket) {
            $this->socketWrapper->close($socket);
        }

        $this->queue = [];
    }
}

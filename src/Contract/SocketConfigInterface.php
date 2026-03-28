<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Contract;

/**
 * Socket configuration for Worker Pool.
 *
 * Provides minimum information for creating listening socket.
 *
 * @example
 * ```php
 * class HttpSocketConfig implements SocketConfigInterface {
 *     public function getHost(): string { return '127.0.0.1'; }
 *     public function getPort(): int { return 8080; }
 *     public function getBacklog(): int { return 128; }
 *     public function getMaxConnections(): int { return 1000; }
 * }
 * ```
 */
interface SocketConfigInterface
{
    /**
     * Returns address for socket binding.
     */
    public function getHost(): string;

    /**
     * Returns port for socket binding.
     */
    public function getPort(): int;

    /**
     * Returns backlog queue size.
     */
    public function getBacklog(): int;

    /**
     * Returns maximum number of connections.
     */
    public function getMaxConnections(): int;
}

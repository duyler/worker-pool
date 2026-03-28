<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Contract;

use Socket;

/**
 * Factory for creating sockets.
 *
 * Allows delegating socket creation to HTTP package or other implementations.
 *
 * @example
 * ```php
 * class HttpSocketFactory implements SocketFactoryInterface {
 *     public function createListeningSocket(int $workerId): Socket {
 *         $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
 *         socket_bind($socket, $this->config->getHost(), $this->config->getPort());
 *         socket_listen($socket, $this->config->getBacklog());
 *         return $socket;
 *     }
 *     public function createSharedSocket(int $workerId): Socket {
 *         // Creates socket with SO_REUSEPORT for shared binding
 *     }
 *     public function getConfig(): SocketConfigInterface { return $this->config; }
 * }
 * ```
 */
interface SocketFactoryInterface
{
    /**
     * Creates listening socket for worker.
     *
     * @param int $workerId Worker process identifier
     */
    public function createListeningSocket(int $workerId): Socket;

    /**
     * Creates shared socket with SO_REUSEPORT.
     *
     * @param int $workerId Worker process identifier
     */
    public function createSharedSocket(int $workerId): Socket;

    /**
     * Returns socket configuration.
     */
    public function getConfig(): SocketConfigInterface;
}

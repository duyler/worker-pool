<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Socket;

use Socket;

use const PHP_BINARY_READ;

interface SocketWrapperInterface
{
    /**
     * Create a socket resource endpoint
     */
    public function create(int $domain, int $type, int $protocol): Socket|false;

    /**
     * Bind a name to a socket
     */
    public function bind(Socket $socket, string $address, int $port = 0): bool;

    /**
     * Listen for connections on a socket
     */
    public function listen(Socket $socket, int $backlog = 0): bool;

    /**
     * Accept a connection on a socket
     */
    public function accept(Socket $socket): Socket|false;

    /**
     * Read data from a socket
     */
    public function read(Socket $socket, int $length, int $type = PHP_BINARY_READ): string|false;

    /**
     * Write data to a socket
     */
    public function write(Socket $socket, string $data, ?int $length = null): int|false;

    /**
     * Close a socket resource
     */
    public function close(Socket $socket): void;

    /**
     * Set nonblocking mode for file descriptor
     */
    public function setNonBlock(Socket $socket): void;

    /**
     * Set socket options for the socket
     */
    public function setOption(Socket $socket, int $level, int $name, int|array $value): bool;

    /**
     * Get the remote side of a socket connection
     */
    public function getPeerName(Socket $socket, string &$address, ?int &$port = null): bool;

    /**
     * Return the last socket error code
     */
    public function lastError(?Socket $socket = null): int;

    /**
     * Return a string describing a socket error
     */
    public function strerror(int $errorCode): string;

    /**
     * Run the select() system call on the given arrays of sockets with a specified timeout
     *
     * @param list<Socket>|null $read
     * @param list<Socket>|null $write
     * @param list<Socket>|null $except
     */
    public function select(?array &$read, ?array &$write, ?array &$except, int $timeout, int $usec = 0): int|false;

    /**
     * Create a pair of connected, indistinguishable sockets
     *
     * @param list<Socket> $pair
     */
    public function createPair(int $domain, int $type, int $protocol, array &$pair): bool;

    /**
     * Initiate a connection on a socket
     */
    public function connect(Socket $socket, string $address, ?int $port = null): bool;
}

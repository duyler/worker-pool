<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Socket;

use Override;
use Socket;

use function socket_accept;
use function socket_bind;
use function socket_close;
use function socket_connect;
use function socket_create;
use function socket_create_pair;
use function socket_getpeername;
use function socket_last_error;
use function socket_listen;
use function socket_read;
use function socket_select;
use function socket_set_nonblock;
use function socket_set_option;
use function socket_strerror;
use function socket_write;

use const PHP_BINARY_READ;
use const E_WARNING;

final class SocketWrapper implements SocketWrapperInterface
{
    #[Override]
    public function create(int $domain, int $type, int $protocol): Socket|false
    {
        return socket_create($domain, $type, $protocol);
    }

    #[Override]
    public function bind(Socket $socket, string $address, int $port = 0): bool
    {
        set_error_handler(static fn(int $errno, string $errstr): bool => true, E_WARNING);

        try {
            return socket_bind($socket, $address, $port);
        } finally {
            restore_error_handler();
        }
    }

    #[Override]
    public function listen(Socket $socket, int $backlog = 0): bool
    {
        return socket_listen($socket, $backlog);
    }

    #[Override]
    public function accept(Socket $socket): Socket|false
    {
        return socket_accept($socket);
    }

    #[Override]
    public function read(Socket $socket, int $length, int $type = PHP_BINARY_READ): string|false
    {
        return socket_read($socket, $length, $type);
    }

    #[Override]
    public function write(Socket $socket, string $data, ?int $length = null): int|false
    {
        return socket_write($socket, $data, $length);
    }

    #[Override]
    public function close(Socket $socket): void
    {
        socket_close($socket);
    }

    #[Override]
    public function setNonBlock(Socket $socket): void
    {
        socket_set_nonblock($socket);
    }

    #[Override]
    public function setOption(Socket $socket, int $level, int $name, int|array $value): bool
    {
        return socket_set_option($socket, $level, $name, $value);
    }

    #[Override]
    public function getPeerName(Socket $socket, string &$address, ?int &$port = null): bool
    {
        return socket_getpeername($socket, $address, $port);
    }

    #[Override]
    public function lastError(?Socket $socket = null): int
    {
        return socket_last_error($socket);
    }

    #[Override]
    public function strerror(int $errorCode): string
    {
        return socket_strerror($errorCode);
    }

    #[Override]
    public function select(?array &$read, ?array &$write, ?array &$except, int $timeout, int $usec = 0): int|false
    {
        return socket_select($read, $write, $except, $timeout, $usec);
    }

    #[Override]
    public function createPair(int $domain, int $type, int $protocol, array &$pair): bool
    {
        return socket_create_pair($domain, $type, $protocol, $pair);
    }

    #[Override]
    public function connect(Socket $socket, string $address, ?int $port = null): bool
    {
        return socket_connect($socket, $address, $port);
    }
}

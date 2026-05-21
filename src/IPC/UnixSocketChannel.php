<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\IPC;

use Duyler\WorkerPool\Exception\IPCException;
use Duyler\WorkerPool\Socket\SocketWrapperInterface;
use Socket;

use function assert;
use function is_int;
use function strlen;

use const AF_UNIX;
use const PHP_BINARY_READ;
use const SOCK_STREAM;

final class UnixSocketChannel
{
    private ?Socket $socket = null;
    private bool $isConnected = false;

    public function __construct(
        private readonly string $socketPath,
        private readonly SocketWrapperInterface $socketWrapper,
        private readonly bool $isServer = false,
        private readonly int $maxIpcMessageSize = 1048576,
    ) {}

    public function __destruct()
    {
        $this->close();
    }

    public function connect(): bool
    {
        $socket = $this->socketWrapper->create(AF_UNIX, SOCK_STREAM, 0);
        if (false === $socket) {
            throw new IPCException('Failed to create Unix socket: ' . $this->socketWrapper->strerror($this->socketWrapper->lastError()));
        }
        $this->socket = $socket;

        if ($this->isServer) {
            if (file_exists($this->socketPath)) {
                unlink($this->socketPath);
            }

            if (!$this->socketWrapper->bind($this->socket, $this->socketPath)) {
                throw new IPCException('Failed to bind Unix socket: ' . $this->socketWrapper->strerror($this->socketWrapper->lastError($this->socket)));
            }

            if (!$this->socketWrapper->listen($this->socket)) {
                throw new IPCException('Failed to listen on Unix socket: ' . $this->socketWrapper->strerror($this->socketWrapper->lastError($this->socket)));
            }
        } else {
            if (!$this->socketWrapper->connect($this->socket, $this->socketPath)) {
                throw new IPCException('Failed to connect to Unix socket: ' . $this->socketWrapper->strerror($this->socketWrapper->lastError($this->socket)));
            }
        }

        $this->socketWrapper->setNonBlock($this->socket);
        $this->isConnected = true;

        return true;
    }

    public function accept(): ?Socket
    {
        if (false === $this->isServer || null === $this->socket) {
            throw new IPCException('Cannot accept on non-server socket');
        }

        $clientSocket = $this->socketWrapper->accept($this->socket);

        if (false === $clientSocket) {
            return null;
        }

        return $clientSocket;
    }

    public function send(Message $message): bool
    {
        if (null === $this->socket || false === $this->isConnected) {
            throw new IPCException('Socket is not connected');
        }

        $data = $message->serialize();
        $length = strlen($data);

        $header = pack('N', $length);
        $packet = $header . $data;

        $written = $this->socketWrapper->write($this->socket, $packet, strlen($packet));

        return false !== $written && 0 < $written;
    }

    public function receive(): ?Message
    {
        if (null === $this->socket || false === $this->isConnected) {
            throw new IPCException('Socket is not connected');
        }

        $lengthData = $this->socketWrapper->read($this->socket, 4, PHP_BINARY_READ);

        if (false === $lengthData || '' === $lengthData) {
            return null;
        }

        if (4 > strlen($lengthData)) {
            return null;
        }

        $unpacked = unpack('N', $lengthData);
        if (false === $unpacked || !isset($unpacked[1])) {
            return null;
        }

        $length = $unpacked[1];
        assert(is_int($length));

        if (0 === $length || $this->maxIpcMessageSize < $length) {
            throw new IPCException('Invalid message length: ' . $length);
        }

        $data = '';
        $remaining = $length;

        while ($remaining > 0) {
            $chunk = $this->socketWrapper->read($this->socket, $remaining, PHP_BINARY_READ);

            if (false === $chunk || '' === $chunk) {
                return null;
            }

            $data .= $chunk;
            $remaining -= strlen($chunk);
        }

        return Message::unserialize($data);
    }

    public function getSocket(): ?Socket
    {
        return $this->socket;
    }

    public function isConnected(): bool
    {
        return $this->isConnected;
    }

    public function close(): void
    {
        if (null !== $this->socket) {
            $this->socketWrapper->close($this->socket);
            $this->socket = null;
            $this->isConnected = false;
        }

        if ($this->isServer && file_exists($this->socketPath)) {
            unlink($this->socketPath);
        }
    }
}

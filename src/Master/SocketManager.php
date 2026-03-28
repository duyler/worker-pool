<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Master;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Socket\SocketErrorSuppressor;
use Duyler\WorkerPool\Exception\WorkerPoolException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Socket;

use function sprintf;

use const AF_INET;
use const SOCK_STREAM;
use const SOL_SOCKET;
use const SOL_TCP;
use const SO_REUSEADDR;

final class SocketManager
{
    use SocketErrorSuppressor;

    private ?Socket $masterSocket = null;
    private bool $isListening = false;
    private bool $shouldCloseOnDestruct = true;

    public function __construct(
        private readonly ServerConfig $config,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function __destruct()
    {
        if ($this->shouldCloseOnDestruct) {
            $this->close();
        } else {
            $this->logger->debug('Skipping close in destructor (disabled)');
        }
    }

    public function listen(): void
    {
        if ($this->isListening) {
            $this->logger->debug('Already listening, skipping');
            return;
        }

        $this->logger->info('Creating socket');
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        if (false === $socket) {
            throw new WorkerPoolException('Failed to create master socket: ' . socket_strerror(socket_last_error()));
        }

        $this->masterSocket = $socket;

        $this->logger->debug('Setting SO_REUSEADDR');
        if (false === socket_set_option($this->masterSocket, SOL_SOCKET, SO_REUSEADDR, 1)) {
            throw new WorkerPoolException('Failed to set SO_REUSEADDR: ' . socket_strerror(socket_last_error($this->masterSocket)));
        }

        $this->logger->info('Binding socket', [
            'host' => $this->config->host,
            'port' => $this->config->port,
        ]);

        $socket = $this->masterSocket;
        $result = $this->suppressSocketWarnings(
            fn(): bool => socket_bind($socket, $this->config->host, $this->config->port),
        );

        if (false === $result) {
            throw new WorkerPoolException(
                sprintf(
                    'Failed to bind to %s:%d: %s',
                    $this->config->host,
                    $this->config->port,
                    socket_strerror(socket_last_error($this->masterSocket)),
                ),
            );
        }

        $this->logger->debug('Starting to listen', ['backlog' => $this->config->socketBacklog]);
        if (false === socket_listen($this->masterSocket, $this->config->socketBacklog)) {
            throw new WorkerPoolException(
                sprintf(
                    'Failed to listen on socket: %s',
                    socket_strerror(socket_last_error($this->masterSocket)),
                ),
            );
        }

        $this->logger->debug('Setting non-blocking mode');
        socket_set_nonblock($this->masterSocket);

        $this->isListening = true;
        $this->logger->info('Successfully listening', [
            'host' => $this->config->host,
            'port' => $this->config->port,
        ]);
    }

    public function accept(): ?Socket
    {
        /** @var int $acceptCalls */
        static $acceptCalls = 0;
        $acceptCalls++;

        if (false === $this->isListening) {
            if ($acceptCalls % 1000 === 0) {
                $this->logger->warning('accept() called but not listening', ['calls' => $acceptCalls]);
            }
            return null;
        }

        if (null === $this->masterSocket) {
            $this->logger->error('masterSocket is null');
            return null;
        }

        $clientSocket = socket_accept($this->masterSocket);

        if (false === $clientSocket) {
            $errno = socket_last_error($this->masterSocket);
            if ($errno !== 11 && $errno !== 0) {
                $this->logger->debug('accept() error', [
                    'errno' => $errno,
                    'error' => socket_strerror($errno),
                ]);
            }
            return null;
        }

        $this->logger->debug('Accepted new connection, setting non-blocking');
        socket_set_nonblock($clientSocket);
        $this->logger->debug('Connection ready to be processed');

        return $clientSocket;
    }

    public function getSocket(): ?Socket
    {
        return $this->masterSocket;
    }

    public function detachFromWorker(): void
    {
        $this->logger->debug('Detaching socket in worker process', ['pid' => getmypid()]);

        $this->masterSocket = null;
        $this->isListening = false;
        $this->shouldCloseOnDestruct = false;

        $this->logger->debug('Socket detached (not closed, just forgotten)');
    }

    public function isListening(): bool
    {
        return $this->isListening;
    }

    public function close(): void
    {
        $this->logger->debug('Closing socket');
        if (null !== $this->masterSocket) {
            socket_close($this->masterSocket);
            $this->masterSocket = null;
        }

        $this->isListening = false;
        $this->logger->debug('Socket closed');
    }

    public function disableAutoClose(): void
    {
        $this->shouldCloseOnDestruct = false;
    }
}

<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\IPC;

use Duyler\WorkerPool\Exception\IPCException;
use Duyler\WorkerPool\Socket\SocketMsgWrapperInterface;
use Duyler\WorkerPool\Socket\SocketWrapperInterface;
use JsonException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Socket;

use function array_key_exists;
use function defined;
use function function_exists;
use function gettype;
use function is_array;
use function is_resource;
use function strlen;

use function assert;
use function is_string;

use const JSON_THROW_ON_ERROR;
use const MSG_DONTWAIT;
use const PHP_OS_FAMILY;
use const SCM_RIGHTS;
use const SOL_SOCKET;

final readonly class FdPasser
{
    public function __construct(
        private SocketWrapperInterface $socketWrapper,
        private SocketMsgWrapperInterface $socketMsgWrapper,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    public function isSupported(): bool
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return false;
        }

        return function_exists('socket_sendmsg') && function_exists('socket_recvmsg');
    }

    public function sendFd(Socket $controlSocket, Socket $fdToSend, array $metadata = []): bool
    {
        if (!function_exists('socket_sendmsg')) {
            throw new IPCException('socket_sendmsg() is not available');
        }

        if (!defined('SCM_RIGHTS')) {
            throw new IPCException('SCM_RIGHTS is not defined');
        }

        $this->logger->debug('Sending FD with metadata', ['metadata' => $metadata]);

        $metadataJson = json_encode($metadata, JSON_THROW_ON_ERROR);
        if ('[]' === $metadataJson) {
            $metadataJson = '{}';
        }

        $message = [
            'iov' => [$metadataJson],
            'control' => [
                [
                    'level' => SOL_SOCKET,
                    'type' => SCM_RIGHTS,
                    'data' => [$fdToSend],
                ],
            ],
        ];

        $result = $this->socketMsgWrapper->sendmsg($controlSocket, $message, 0);

        if (false === $result) {
            $this->logger->error('sendmsg failed', [
                'error' => $this->socketWrapper->strerror($this->socketWrapper->lastError($controlSocket)),
            ]);
        } else {
            $this->logger->debug('FD sent', ['bytes' => $result]);
        }

        return false !== $result;
    }

    /**
     * @return array{fd: Socket|resource, metadata: array<string, mixed>}|null
     */
    public function receiveFd(Socket $controlSocket): ?array
    {
        if (!function_exists('socket_recvmsg')) {
            throw new IPCException('socket_recvmsg() is not available');
        }

        if (!defined('SCM_RIGHTS')) {
            throw new IPCException('SCM_RIGHTS is not defined');
        }

        $cmsgSpace = $this->socketMsgWrapper->cmsgSpace(SOL_SOCKET, SCM_RIGHTS, 1);

        $message = [
            'iov' => [''],
            'control' => [],
            'controllen' => (null !== $cmsgSpace && $cmsgSpace > 0)
                ? $cmsgSpace
                : 256,
        ];

        $result = $this->socketMsgWrapper->recvmsg($controlSocket, $message, MSG_DONTWAIT);

        if (false === $result || 0 === $result) {
            $errno = $this->socketWrapper->lastError($controlSocket);
            if ($errno !== 11 && $errno !== 0) {
                $this->logger->debug('recvmsg error', [
                    'errno' => $errno,
                    'error' => $this->socketWrapper->strerror($errno),
                ]);
            }
            return null;
        }

        $this->logger->debug('recvmsg returned bytes', ['bytes' => $result]);
        $this->logger->debug('Message type', ['type' => gettype($message)]);

        $this->logger->debug('Message keys', ['keys' => array_keys($message)]);

        assert(is_array($message['control']));

        if (!array_key_exists(0, $message['control'])) {
            $this->logger->error('No control data at index 0');
            return null;
        }

        $controlData = $message['control'][0];

        if (false === is_array($controlData)) {
            $this->logger->error('Invalid control array structure');
            return null;
        }

        if (!isset($controlData['data']) || !is_array($controlData['data'])) {
            $this->logger->error('No control data array');
            return null;
        }

        if (!isset($controlData['data'][0])) {
            $this->logger->error('No file descriptor in control data');
            return null;
        }

        $receivedFd = $controlData['data'][0];

        if (false === ($receivedFd instanceof Socket) && false === is_resource($receivedFd)) {
            $this->logger->error('Received FD is not a Socket or resource', ['type' => gettype($receivedFd)]);
            return null;
        }

        $metadataJson = $message['iov'][0] ?? '{}';
        assert(is_string($metadataJson));
        $metadataJson = rtrim($metadataJson, "\0");

        if ('' === $metadataJson) {
            $metadataJson = '{}';
        }

        try {
            /** @var array<string, mixed> $metadata */
            $metadata = json_decode($metadataJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->logger->error('Failed to decode IPC metadata JSON', [
                'error' => $e->getMessage(),
                'json_length' => strlen($metadataJson),
            ]);
            $metadata = [];
        }

        $this->logger->debug('FD received successfully', ['metadata' => $metadata]);

        return [
            'fd' => $receivedFd,
            'metadata' => $metadata,
        ];
    }
}

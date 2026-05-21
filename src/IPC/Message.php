<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\IPC;

use InvalidArgumentException;
use JsonException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function assert;
use function gettype;
use function is_array;
use function is_float;
use function is_int;
use function is_null;
use function is_string;
use function strlen;

use const JSON_THROW_ON_ERROR;

final readonly class Message
{
    public float $timestamp;

    public function __construct(
        public MessageType $type,
        public array $data = [],
        ?float $timestamp = null,
    ) {
        $this->timestamp = $timestamp ?? microtime(true);
    }

    public function serialize(): string
    {
        return json_encode([
            'type' => $this->type->value,
            'data' => $this->data,
            'timestamp' => $this->timestamp,
        ], JSON_THROW_ON_ERROR);
    }

    public static function unserialize(string $data, ?LoggerInterface $logger = null): self
    {
        $logger ??= new NullLogger();

        try {
            $decoded = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $logger->warning('Failed to unserialize IPC message: JSON parse error', [
                'error' => $e->getMessage(),
                'data_length' => strlen($data),
            ]);
            throw new InvalidArgumentException('Invalid message format: ' . $e->getMessage(), 0, $e);
        }

        if (false === is_array($decoded)) {
            $logger->warning('Failed to unserialize IPC message: decoded data is not an array', [
                'type' => gettype($decoded),
            ]);
            throw new InvalidArgumentException('Invalid message format');
        }

        if (!isset($decoded['type'])) {
            $logger->warning('Failed to unserialize IPC message: missing type field');
            throw new InvalidArgumentException('Message type is required');
        }
        assert(is_int($decoded['type']) || is_string($decoded['type']));
        assert(!isset($decoded['data']) || is_array($decoded['data']));
        assert(!isset($decoded['timestamp']) || is_float($decoded['timestamp']) || is_int($decoded['timestamp']) || is_null($decoded['timestamp']));

        $data = $decoded['data'] ?? [];

        $timestamp = null;
        if (isset($decoded['timestamp'])) {
            $timestamp = is_int($decoded['timestamp']) ? (float) $decoded['timestamp'] : $decoded['timestamp'];
        }

        return new self(
            type: MessageType::from($decoded['type']),
            data: $data,
            timestamp: $timestamp,
        );
    }

    public static function connectionClosed(int $connectionId): self
    {
        return new self(
            type: MessageType::ConnectionClosed,
            data: ['connection_id' => $connectionId],
        );
    }

    public static function workerReady(int $workerId): self
    {
        return new self(
            type: MessageType::WorkerReady,
            data: ['worker_id' => $workerId],
        );
    }

    public static function workerMetrics(array $metrics): self
    {
        return new self(
            type: MessageType::WorkerMetrics,
            data: $metrics,
        );
    }

    public static function shutdown(): self
    {
        return new self(type: MessageType::Shutdown);
    }

    public static function reload(): self
    {
        return new self(type: MessageType::Reload);
    }
}

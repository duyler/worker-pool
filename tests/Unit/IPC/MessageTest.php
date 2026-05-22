<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\IPC;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

use Duyler\WorkerPool\IPC\Message;
use Duyler\WorkerPool\IPC\MessageType;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ValueError;

#[CoversClass(Message::class)]
class MessageTest extends TestCase
{
    #[Test]
    public function creates_message_with_type_and_data(): void
    {
        $message = new Message(
            type: MessageType::WorkerReady,
            data: ['worker_id' => 1],
        );

        $this->assertSame(MessageType::WorkerReady, $message->type);
        $this->assertSame(['worker_id' => 1], $message->data);
        $this->assertIsFloat($message->timestamp);
        $this->assertGreaterThan(0, $message->timestamp);
    }

    #[Test]
    public function creates_message_with_custom_timestamp(): void
    {
        $timestamp = microtime(true);

        $message = new Message(
            type: MessageType::Shutdown,
            timestamp: $timestamp,
        );

        $this->assertSame($timestamp, $message->timestamp);
    }

    #[Test]
    public function serializes_to_json(): void
    {
        $message = new Message(
            type: MessageType::ConnectionClosed,
            data: ['connection_id' => 42],
            timestamp: 1234567890.123,
        );

        $serialized = $message->serialize();

        $this->assertJson($serialized);

        $decoded = json_decode($serialized, true);
        $this->assertSame('connection_closed', $decoded['type']);
        $this->assertSame(['connection_id' => 42], $decoded['data']);
        $this->assertSame(1234567890.123, $decoded['timestamp']);
    }

    #[Test]
    public function unserializes_from_json(): void
    {
        $json = json_encode([
            'type' => 'worker_ready',
            'data' => ['worker_id' => 5],
            'timestamp' => 1234567890.123,
        ]);

        $message = Message::unserialize($json);

        $this->assertSame(MessageType::WorkerReady, $message->type);
        $this->assertSame(['worker_id' => 5], $message->data);
        $this->assertSame(1234567890.123, $message->timestamp);
    }

    #[Test]
    public function unserialize_handles_missing_data(): void
    {
        $json = json_encode([
            'type' => 'shutdown',
            'timestamp' => 1234567890.123,
        ]);

        $message = Message::unserialize($json);

        $this->assertSame(MessageType::Shutdown, $message->type);
        $this->assertSame([], $message->data);
    }

    #[Test]
    public function unserialize_throws_on_invalid_json(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Message::unserialize('invalid json');
    }

    #[Test]
    public function unserialize_throws_on_missing_type(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Message type is required');

        Message::unserialize(json_encode(['data' => []]));
    }

    #[Test]
    public function unserialize_throws_on_invalid_type(): void
    {
        $this->expectException(ValueError::class);

        Message::unserialize(json_encode(['type' => 'invalid_type']));
    }

    #[Test]
    public function creates_connection_closed_message(): void
    {
        $message = Message::connectionClosed(123);

        $this->assertSame(MessageType::ConnectionClosed, $message->type);
        $this->assertSame(['connection_id' => 123], $message->data);
    }

    #[Test]
    public function creates_worker_ready_message(): void
    {
        $message = Message::workerReady(7);

        $this->assertSame(MessageType::WorkerReady, $message->type);
        $this->assertSame(['worker_id' => 7], $message->data);
    }

    #[Test]
    public function creates_worker_metrics_message(): void
    {
        $metrics = [
            'requests' => 100,
            'memory' => 1024,
        ];

        $message = Message::workerMetrics($metrics);

        $this->assertSame(MessageType::WorkerMetrics, $message->type);
        $this->assertSame($metrics, $message->data);
    }

    #[Test]
    public function creates_shutdown_message(): void
    {
        $message = Message::shutdown();

        $this->assertSame(MessageType::Shutdown, $message->type);
        $this->assertSame([], $message->data);
    }

    #[Test]
    public function creates_reload_message(): void
    {
        $message = Message::reload();

        $this->assertSame(MessageType::Reload, $message->type);
        $this->assertSame([], $message->data);
    }

    #[Test]
    public function serialization_roundtrip_preserves_data(): void
    {
        $original = Message::workerMetrics([
            'requests' => 500,
            'uptime' => 3600.5,
            'memory' => 2048,
        ]);

        $serialized = $original->serialize();
        $restored = Message::unserialize($serialized);

        $this->assertSame($original->type, $restored->type);
        $this->assertSame($original->data, $restored->data);
        $this->assertSame($original->timestamp, $restored->timestamp);
    }

    #[Test]
    public function logs_warning_on_invalid_json(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects($this->once())
            ->method('warning')
            ->with(
                'Failed to unserialize IPC message: JSON parse error',
                $this->callback(fn(array $context): bool => isset($context['error'])
                    && isset($context['data_length'])
                    && $context['data_length'] === 12),
            );

        $this->expectException(InvalidArgumentException::class);
        Message::unserialize('invalid json', $logger);
    }

    #[Test]
    public function logs_warning_on_non_array_json(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects($this->once())
            ->method('warning')
            ->with(
                'Failed to unserialize IPC message: decoded data is not an array',
                $this->callback(fn(array $context): bool => isset($context['type']) && $context['type'] === 'string'),
            );

        $this->expectException(InvalidArgumentException::class);
        Message::unserialize('"just a string"', $logger);
    }

    #[Test]
    public function logs_warning_on_missing_type(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects($this->once())
            ->method('warning')
            ->with('Failed to unserialize IPC message: missing type field');

        $this->expectException(InvalidArgumentException::class);
        Message::unserialize(json_encode(['data' => []]), $logger);
    }

    #[Test]
    public function does_not_log_on_valid_unserialize(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects($this->never())
            ->method('warning');

        $json = json_encode([
            'type' => 'shutdown',
            'data' => [],
        ]);

        Message::unserialize($json, $logger);
    }
}

<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\IPC;

use Duyler\WorkerPool\IPC\Message;
use Duyler\WorkerPool\IPC\MessageType;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ValueError;

class MessageTest extends TestCase
{
    public function testCreatesMessageWithTypeAndData(): void
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

    public function testCreatesMessageWithCustomTimestamp(): void
    {
        $timestamp = microtime(true);

        $message = new Message(
            type: MessageType::Shutdown,
            timestamp: $timestamp,
        );

        $this->assertSame($timestamp, $message->timestamp);
    }

    public function testSerializesToJson(): void
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

    public function testUnserializesFromJson(): void
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

    public function testUnserializeHandlesMissingData(): void
    {
        $json = json_encode([
            'type' => 'shutdown',
            'timestamp' => 1234567890.123,
        ]);

        $message = Message::unserialize($json);

        $this->assertSame(MessageType::Shutdown, $message->type);
        $this->assertSame([], $message->data);
    }

    public function testUnserializeThrowsOnInvalidJson(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Message::unserialize('invalid json');
    }

    public function testUnserializeThrowsOnMissingType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Message type is required');

        Message::unserialize(json_encode(['data' => []]));
    }

    public function testUnserializeThrowsOnInvalidType(): void
    {
        $this->expectException(ValueError::class);

        Message::unserialize(json_encode(['type' => 'invalid_type']));
    }

    public function testCreatesConnectionClosedMessage(): void
    {
        $message = Message::connectionClosed(123);

        $this->assertSame(MessageType::ConnectionClosed, $message->type);
        $this->assertSame(['connection_id' => 123], $message->data);
    }

    public function testCreatesWorkerReadyMessage(): void
    {
        $message = Message::workerReady(7);

        $this->assertSame(MessageType::WorkerReady, $message->type);
        $this->assertSame(['worker_id' => 7], $message->data);
    }

    public function testCreatesWorkerMetricsMessage(): void
    {
        $metrics = [
            'requests' => 100,
            'memory' => 1024,
        ];

        $message = Message::workerMetrics($metrics);

        $this->assertSame(MessageType::WorkerMetrics, $message->type);
        $this->assertSame($metrics, $message->data);
    }

    public function testCreatesShutdownMessage(): void
    {
        $message = Message::shutdown();

        $this->assertSame(MessageType::Shutdown, $message->type);
        $this->assertSame([], $message->data);
    }

    public function testCreatesReloadMessage(): void
    {
        $message = Message::reload();

        $this->assertSame(MessageType::Reload, $message->type);
        $this->assertSame([], $message->data);
    }

    public function testSerializationRoundtripPreservesData(): void
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

    public function testLogsWarningOnInvalidJson(): void
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

    public function testLogsWarningOnNonArrayJson(): void
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

    public function testLogsWarningOnMissingType(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects($this->once())
            ->method('warning')
            ->with('Failed to unserialize IPC message: missing type field');

        $this->expectException(InvalidArgumentException::class);
        Message::unserialize(json_encode(['data' => []]), $logger);
    }

    public function testDoesNotLogOnValidUnserialize(): void
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

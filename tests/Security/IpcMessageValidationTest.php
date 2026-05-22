<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Security;

use Duyler\WorkerPool\IPC\Message;
use Duyler\WorkerPool\IPC\MessageType;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ValueError;

use function json_encode;
use function str_repeat;

#[Group('security')]
#[CoversClass(Message::class)]
#[UsesClass(MessageType::class)]
final class IpcMessageValidationTest extends TestCase
{
    #[Test]
    public function handles_corrupt_json(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Message::unserialize('{not valid json}');
    }

    #[Test]
    public function handles_missing_type_field(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Message type is required');

        Message::unserialize(json_encode(['data' => ['key' => 'value']]));
    }

    #[Test]
    public function handles_invalid_type_value(): void
    {
        $this->expectException(ValueError::class);

        Message::unserialize(json_encode(['type' => 'nonexistent_type']));
    }

    #[Test]
    public function handles_empty_string(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Message::unserialize('');
    }

    #[Test]
    public function handles_null_byte_in_json(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Message::unserialize("{\"type\":\"shutdown\",\"data\":\x00}");
    }

    #[Test]
    public function handles_non_object_json(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Message::unserialize('42');
    }

    #[Test]
    public function handles_json_array_instead_of_object(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Message::unserialize('[1,2,3]');
    }

    #[Test]
    public function handles_boolean_json(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Message::unserialize('true');
    }

    #[Test]
    public function handles_xss_payload_in_data(): void
    {
        $json = json_encode([
            'type' => 'worker_ready',
            'data' => ['payload' => '<script>alert("xss")</script>'],
        ]);

        $message = Message::unserialize($json);

        $this->assertSame(MessageType::WorkerReady, $message->type);
        $this->assertSame('<script>alert("xss")</script>', $message->data['payload']);
    }

    #[Test]
    public function handles_sql_injection_in_data(): void
    {
        $json = json_encode([
            'type' => 'worker_metrics',
            'data' => ['query' => "'; DROP TABLE users; --"],
        ]);

        $message = Message::unserialize($json);

        $this->assertSame(MessageType::WorkerMetrics, $message->type);
        $this->assertSame("'; DROP TABLE users; --", $message->data['query']);
    }

    #[Test]
    public function handles_deeply_nested_data(): void
    {
        $nested = ['level' => 0];
        $current = &$nested;
        for ($i = 0; $i < 50; $i++) {
            $current['child'] = ['level' => $i + 1];
            $current = &$current['child'];
        }

        $json = json_encode([
            'type' => 'worker_metrics',
            'data' => $nested,
        ]);

        $message = Message::unserialize($json);

        $this->assertSame(MessageType::WorkerMetrics, $message->type);
    }

    #[Test]
    public function handles_large_data_payload(): void
    {
        $largeData = str_repeat('A', 100000);

        $json = json_encode([
            'type' => 'worker_metrics',
            'data' => ['payload' => $largeData],
        ]);

        $message = Message::unserialize($json);

        $this->assertSame(MessageType::WorkerMetrics, $message->type);
        $this->assertSame($largeData, $message->data['payload']);
    }

    #[Test]
    public function handles_unicode_in_data(): void
    {
        $json = json_encode([
            'type' => 'connection_closed',
            'data' => ['message' => 'Привет мир 你好世界 🌍'],
        ]);

        $message = Message::unserialize($json);

        $this->assertSame(MessageType::ConnectionClosed, $message->type);
        $this->assertSame('Привет мир 你好世界 🌍', $message->data['message']);
    }

    #[Test]
    public function valid_message_roundtrip_survives_validation(): void
    {
        $original = Message::workerReady(42);

        $serialized = $original->serialize();
        $restored = Message::unserialize($serialized);

        $this->assertSame($original->type, $restored->type);
        $this->assertSame($original->data, $restored->data);
    }
}

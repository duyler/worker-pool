<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\Config;

use Duyler\WorkerPool\Config\BalancerType;
use Duyler\WorkerPool\Config\WorkerPoolConfig;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkerPoolConfigTest extends TestCase
{
    #[Test]
    public function default_max_ipc_message_size(): void
    {
        $config = new WorkerPoolConfig();

        $this->assertSame(1048576, $config->maxIpcMessageSize);
    }

    #[Test]
    public function custom_max_ipc_message_size(): void
    {
        $config = new WorkerPoolConfig(
            maxIpcMessageSize: 2097152,
        );

        $this->assertSame(2097152, $config->maxIpcMessageSize);
    }

    #[Test]
    public function rejects_max_ipc_message_size_below_minimum(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Max IPC message size must be at least 1024 bytes');

        new WorkerPoolConfig(
            maxIpcMessageSize: 1023,
        );
    }

    #[Test]
    public function accepts_minimum_max_ipc_message_size(): void
    {
        $config = new WorkerPoolConfig(
            maxIpcMessageSize: 1024,
        );

        $this->assertSame(1024, $config->maxIpcMessageSize);
    }

    #[Test]
    public function accepts_large_max_ipc_message_size(): void
    {
        $config = new WorkerPoolConfig(
            maxIpcMessageSize: 10485760,
        );

        $this->assertSame(10485760, $config->maxIpcMessageSize);
    }

    #[Test]
    public function accepts_zero_worker_count_as_auto_detect(): void
    {
        $config = new WorkerPoolConfig(
            workerCount: 0,
        );

        $this->assertGreaterThanOrEqual(1, $config->workerCount);
    }

    #[Test]
    public function rejects_negative_worker_count(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Worker count must be positive');

        new WorkerPoolConfig(
            workerCount: -1,
        );
    }

    #[Test]
    public function rejects_too_large_worker_count(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Worker count too large');

        new WorkerPoolConfig(
            workerCount: 1025,
        );
    }

    #[Test]
    public function accepts_maximum_worker_count(): void
    {
        $config = new WorkerPoolConfig(
            workerCount: 1024,
        );

        $this->assertSame(1024, $config->workerCount);
    }

    #[Test]
    public function accepts_minimum_worker_count(): void
    {
        $config = new WorkerPoolConfig(
            workerCount: 1,
        );

        $this->assertSame(1, $config->workerCount);
    }

    #[Test]
    public function rejects_zero_backlog(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Backlog must be positive');

        new WorkerPoolConfig(
            backlog: 0,
        );
    }

    #[Test]
    public function rejects_negative_backlog(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Backlog must be positive');

        new WorkerPoolConfig(
            backlog: -1,
        );
    }

    #[Test]
    public function rejects_zero_max_queue_size(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Max queue size must be positive');

        new WorkerPoolConfig(
            maxQueueSize: 0,
        );
    }

    #[Test]
    public function rejects_negative_max_queue_size(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Max queue size must be positive');

        new WorkerPoolConfig(
            maxQueueSize: -1,
        );
    }

    #[Test]
    public function rejects_negative_restart_delay(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Restart delay must be non-negative');

        new WorkerPoolConfig(
            restartDelay: -1,
        );
    }

    #[Test]
    public function accepts_zero_restart_delay(): void
    {
        $config = new WorkerPoolConfig(
            restartDelay: 0,
        );

        $this->assertSame(0, $config->restartDelay);
    }

    #[Test]
    public function rejects_zero_fallback_cpu_cores(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Fallback CPU cores must be positive');

        new WorkerPoolConfig(
            fallbackCpuCores: 0,
        );
    }

    #[Test]
    public function rejects_too_small_poll_interval(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Poll interval must be at least 100 microseconds');

        new WorkerPoolConfig(
            pollInterval: 99,
        );
    }

    #[Test]
    public function accepts_minimum_poll_interval(): void
    {
        $config = new WorkerPoolConfig(
            pollInterval: 100,
        );

        $this->assertSame(100, $config->pollInterval);
    }

    #[Test]
    public function accepts_all_default_values(): void
    {
        $config = new WorkerPoolConfig();

        $this->assertInstanceOf(WorkerPoolConfig::class, $config);
        $this->assertSame(BalancerType::LeastConnections, $config->balancer);
        $this->assertSame(128, $config->backlog);
        $this->assertSame(1000, $config->maxQueueSize);
        $this->assertSame(1048576, $config->maxIpcMessageSize);
        $this->assertFalse($config->enableStickySession);
        $this->assertFalse($config->enableGracefulReload);
        $this->assertTrue($config->autoRestart);
        $this->assertSame(1, $config->restartDelay);
        $this->assertSame(4, $config->fallbackCpuCores);
        $this->assertSame(1000, $config->pollInterval);
        $this->assertNull($config->socketPath);
        $this->assertNull($config->port);
        $this->assertNull($config->host);
    }

    #[Test]
    public function auto_method_creates_config_with_auto_worker_count(): void
    {
        $config = WorkerPoolConfig::auto();

        $this->assertGreaterThanOrEqual(1, $config->workerCount);
    }

    #[Test]
    public function auto_method_accepts_balancer(): void
    {
        $config = WorkerPoolConfig::auto(BalancerType::RoundRobin);

        $this->assertSame(BalancerType::RoundRobin, $config->balancer);
    }

    #[Test]
    public function accepts_custom_values(): void
    {
        $config = new WorkerPoolConfig(
            workerCount: 8,
            balancer: BalancerType::RoundRobin,
            backlog: 256,
            maxQueueSize: 500,
            maxIpcMessageSize: 2097152,
            enableStickySession: true,
            enableGracefulReload: true,
            autoRestart: false,
            restartDelay: 5,
            fallbackCpuCores: 8,
            pollInterval: 500,
            socketPath: '/var/run/socket.sock',
            port: 8080,
            host: '127.0.0.1',
        );

        $this->assertSame(8, $config->workerCount);
        $this->assertSame(BalancerType::RoundRobin, $config->balancer);
        $this->assertSame(256, $config->backlog);
        $this->assertSame(500, $config->maxQueueSize);
        $this->assertSame(2097152, $config->maxIpcMessageSize);
        $this->assertTrue($config->enableStickySession);
        $this->assertTrue($config->enableGracefulReload);
        $this->assertFalse($config->autoRestart);
        $this->assertSame(5, $config->restartDelay);
        $this->assertSame(8, $config->fallbackCpuCores);
        $this->assertSame(500, $config->pollInterval);
        $this->assertSame('/var/run/socket.sock', $config->socketPath);
        $this->assertSame(8080, $config->port);
        $this->assertSame('127.0.0.1', $config->host);
    }

    #[Test]
    public function auto_detects_cpu_cores(): void
    {
        $config = new WorkerPoolConfig(
            workerCount: 0,
        );

        $this->assertGreaterThanOrEqual(1, $config->workerCount);
    }

    #[Test]
    public function uses_custom_fallback_cpu_cores(): void
    {
        $config = new WorkerPoolConfig(
            workerCount: 0,
            fallbackCpuCores: 16,
        );

        $this->assertGreaterThanOrEqual(1, $config->workerCount);
    }
}

<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\Test;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\WorkerPool\Config\BalancerType;
use Duyler\WorkerPool\Config\WorkerPoolConfig;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Duyler\WorkerPool\Util\SystemInfo;

#[CoversClass(WorkerPoolConfig::class)]
#[UsesClass(SystemInfo::class)]
class WorkerPoolConfigValidationTest extends TestCase
{
    #[Test]
    public function accepts_zero_worker_count_as_auto_detect(): void
    {
        $serverConfig = new ServerConfig();
        $config = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 0,
        );

        $this->assertGreaterThanOrEqual(1, $config->workerCount);
    }

    #[Test]
    public function rejects_negative_worker_count(): void
    {
        $serverConfig = new ServerConfig();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Worker count must be positive');

        new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: -1,
        );
    }

    #[Test]
    public function rejects_too_large_worker_count(): void
    {
        $serverConfig = new ServerConfig();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Worker count too large');

        new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 1025,
        );
    }

    #[Test]
    public function accepts_maximum_worker_count(): void
    {
        $serverConfig = new ServerConfig();
        $config = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 1024,
        );

        $this->assertSame(1024, $config->workerCount);
    }

    #[Test]
    public function accepts_minimum_worker_count(): void
    {
        $serverConfig = new ServerConfig();
        $config = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 1,
        );

        $this->assertSame(1, $config->workerCount);
    }

    #[Test]
    public function rejects_zero_backlog(): void
    {
        $serverConfig = new ServerConfig();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Backlog must be positive');

        new WorkerPoolConfig(
            serverConfig: $serverConfig,
            backlog: 0,
        );
    }

    #[Test]
    public function rejects_negative_backlog(): void
    {
        $serverConfig = new ServerConfig();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Backlog must be positive');

        new WorkerPoolConfig(
            serverConfig: $serverConfig,
            backlog: -1,
        );
    }

    #[Test]
    public function rejects_zero_max_queue_size(): void
    {
        $serverConfig = new ServerConfig();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Max queue size must be positive');

        new WorkerPoolConfig(
            serverConfig: $serverConfig,
            maxQueueSize: 0,
        );
    }

    #[Test]
    public function rejects_negative_max_queue_size(): void
    {
        $serverConfig = new ServerConfig();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Max queue size must be positive');

        new WorkerPoolConfig(
            serverConfig: $serverConfig,
            maxQueueSize: -1,
        );
    }

    #[Test]
    public function rejects_negative_restart_delay(): void
    {
        $serverConfig = new ServerConfig();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Restart delay must be non-negative');

        new WorkerPoolConfig(
            serverConfig: $serverConfig,
            restartDelay: -1,
        );
    }

    #[Test]
    public function accepts_zero_restart_delay(): void
    {
        $serverConfig = new ServerConfig();
        $config = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            restartDelay: 0,
        );

        $this->assertSame(0, $config->restartDelay);
    }

    #[Test]
    public function rejects_too_small_max_ipc_message_size(): void
    {
        $serverConfig = new ServerConfig();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Max IPC message size must be at least 1024 bytes');

        new WorkerPoolConfig(
            serverConfig: $serverConfig,
            maxIpcMessageSize: 1023,
        );
    }

    #[Test]
    public function rejects_zero_fallback_cpu_cores(): void
    {
        $serverConfig = new ServerConfig();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Fallback CPU cores must be positive');

        new WorkerPoolConfig(
            serverConfig: $serverConfig,
            fallbackCpuCores: 0,
        );
    }

    #[Test]
    public function rejects_too_small_poll_interval(): void
    {
        $serverConfig = new ServerConfig();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Poll interval must be at least 100 microseconds');

        new WorkerPoolConfig(
            serverConfig: $serverConfig,
            pollInterval: 99,
        );
    }

    #[Test]
    public function accepts_minimum_poll_interval(): void
    {
        $serverConfig = new ServerConfig();
        $config = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            pollInterval: 100,
        );

        $this->assertSame(100, $config->pollInterval);
    }

    #[Test]
    public function accepts_all_default_values(): void
    {
        $serverConfig = new ServerConfig();
        $config = new WorkerPoolConfig(serverConfig: $serverConfig);

        $this->assertInstanceOf(WorkerPoolConfig::class, $config);
        $this->assertSame($serverConfig, $config->serverConfig);
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
    }

    #[Test]
    public function auto_method_creates_config_with_auto_worker_count(): void
    {
        $serverConfig = new ServerConfig();
        $config = WorkerPoolConfig::auto($serverConfig);

        $this->assertGreaterThanOrEqual(1, $config->workerCount);
    }

    #[Test]
    public function auto_method_accepts_balancer(): void
    {
        $serverConfig = new ServerConfig();
        $config = WorkerPoolConfig::auto($serverConfig, BalancerType::RoundRobin);

        $this->assertSame(BalancerType::RoundRobin, $config->balancer);
    }

    #[Test]
    public function accepts_custom_values(): void
    {
        $serverConfig = new ServerConfig();
        $config = new WorkerPoolConfig(
            serverConfig: $serverConfig,
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
    }

    #[Test]
    public function auto_detects_cpu_cores(): void
    {
        $serverConfig = new ServerConfig();
        $config = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 0,
        );

        $this->assertGreaterThanOrEqual(1, $config->workerCount);
    }

    #[Test]
    public function single_creates_config_with_one_worker(): void
    {
        $serverConfig = new ServerConfig();
        $config = WorkerPoolConfig::single($serverConfig);

        $this->assertSame(1, $config->workerCount);
    }

    #[Test]
    public function single_accepts_balancer(): void
    {
        $serverConfig = new ServerConfig();
        $config = WorkerPoolConfig::single($serverConfig, BalancerType::RoundRobin);

        $this->assertSame(1, $config->workerCount);
        $this->assertSame(BalancerType::RoundRobin, $config->balancer);
    }
}

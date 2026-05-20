<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\WorkerPool\Config\BalancerType;
use Duyler\WorkerPool\Config\WorkerPoolConfig;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

#[CoversClass(WorkerPoolConfig::class)]
class WorkerPoolConfigValidationTest extends TestCase
{
    public function testAcceptsZeroWorkerCountAsAutoDetect(): void
    {
        $serverConfig = new ServerConfig();
        $config = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 0,
        );

        $this->assertGreaterThanOrEqual(1, $config->workerCount);
    }

    public function testRejectsNegativeWorkerCount(): void
    {
        $serverConfig = new ServerConfig();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Worker count must be positive');

        new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: -1,
        );
    }

    public function testRejectsTooLargeWorkerCount(): void
    {
        $serverConfig = new ServerConfig();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Worker count too large');

        new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 1025,
        );
    }

    public function testAcceptsMaximumWorkerCount(): void
    {
        $serverConfig = new ServerConfig();
        $config = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 1024,
        );

        $this->assertSame(1024, $config->workerCount);
    }

    public function testAcceptsMinimumWorkerCount(): void
    {
        $serverConfig = new ServerConfig();
        $config = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 1,
        );

        $this->assertSame(1, $config->workerCount);
    }

    public function testRejectsZeroBacklog(): void
    {
        $serverConfig = new ServerConfig();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Backlog must be positive');

        new WorkerPoolConfig(
            serverConfig: $serverConfig,
            backlog: 0,
        );
    }

    public function testRejectsNegativeBacklog(): void
    {
        $serverConfig = new ServerConfig();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Backlog must be positive');

        new WorkerPoolConfig(
            serverConfig: $serverConfig,
            backlog: -1,
        );
    }

    public function testRejectsZeroMaxQueueSize(): void
    {
        $serverConfig = new ServerConfig();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Max queue size must be positive');

        new WorkerPoolConfig(
            serverConfig: $serverConfig,
            maxQueueSize: 0,
        );
    }

    public function testRejectsNegativeMaxQueueSize(): void
    {
        $serverConfig = new ServerConfig();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Max queue size must be positive');

        new WorkerPoolConfig(
            serverConfig: $serverConfig,
            maxQueueSize: -1,
        );
    }

    public function testRejectsNegativeRestartDelay(): void
    {
        $serverConfig = new ServerConfig();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Restart delay must be non-negative');

        new WorkerPoolConfig(
            serverConfig: $serverConfig,
            restartDelay: -1,
        );
    }

    public function testAcceptsZeroRestartDelay(): void
    {
        $serverConfig = new ServerConfig();
        $config = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            restartDelay: 0,
        );

        $this->assertSame(0, $config->restartDelay);
    }

    public function testRejectsTooSmallMaxIpcMessageSize(): void
    {
        $serverConfig = new ServerConfig();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Max IPC message size must be at least 1024 bytes');

        new WorkerPoolConfig(
            serverConfig: $serverConfig,
            maxIpcMessageSize: 1023,
        );
    }

    public function testRejectsZeroFallbackCpuCores(): void
    {
        $serverConfig = new ServerConfig();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Fallback CPU cores must be positive');

        new WorkerPoolConfig(
            serverConfig: $serverConfig,
            fallbackCpuCores: 0,
        );
    }

    public function testRejectsTooSmallPollInterval(): void
    {
        $serverConfig = new ServerConfig();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Poll interval must be at least 100 microseconds');

        new WorkerPoolConfig(
            serverConfig: $serverConfig,
            pollInterval: 99,
        );
    }

    public function testAcceptsMinimumPollInterval(): void
    {
        $serverConfig = new ServerConfig();
        $config = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            pollInterval: 100,
        );

        $this->assertSame(100, $config->pollInterval);
    }

    public function testAcceptsAllDefaultValues(): void
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

    public function testAutoMethodCreatesConfigWithAutoWorkerCount(): void
    {
        $serverConfig = new ServerConfig();
        $config = WorkerPoolConfig::auto($serverConfig);

        $this->assertGreaterThanOrEqual(1, $config->workerCount);
    }

    public function testAutoMethodAcceptsBalancer(): void
    {
        $serverConfig = new ServerConfig();
        $config = WorkerPoolConfig::auto($serverConfig, BalancerType::RoundRobin);

        $this->assertSame(BalancerType::RoundRobin, $config->balancer);
    }

    public function testAcceptsCustomValues(): void
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

    public function testAutoDetectsCpuCores(): void
    {
        $serverConfig = new ServerConfig();
        $config = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 0,
        );

        $this->assertGreaterThanOrEqual(1, $config->workerCount);
    }
}

<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\WorkerPool\Config\WorkerPoolConfig;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

#[CoversClass(WorkerPoolConfig::class)]
class WorkerPoolConfigTest extends TestCase
{
    public function testDefaultMaxIpcMessageSize(): void
    {
        $serverConfig = new ServerConfig();
        $config = new WorkerPoolConfig(serverConfig: $serverConfig);

        $this->assertSame(1048576, $config->maxIpcMessageSize);
    }

    public function testCustomMaxIpcMessageSize(): void
    {
        $serverConfig = new ServerConfig();
        $config = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            maxIpcMessageSize: 2097152,
        );

        $this->assertSame(2097152, $config->maxIpcMessageSize);
    }

    public function testRejectsMaxIpcMessageSizeBelowMinimum(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Max IPC message size must be at least 1024 bytes');

        $serverConfig = new ServerConfig();
        new WorkerPoolConfig(
            serverConfig: $serverConfig,
            maxIpcMessageSize: 1023,
        );
    }

    public function testAcceptsMinimumMaxIpcMessageSize(): void
    {
        $serverConfig = new ServerConfig();
        $config = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            maxIpcMessageSize: 1024,
        );

        $this->assertSame(1024, $config->maxIpcMessageSize);
    }

    public function testAcceptsLargeMaxIpcMessageSize(): void
    {
        $serverConfig = new ServerConfig();
        $config = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            maxIpcMessageSize: 10485760,
        );

        $this->assertSame(10485760, $config->maxIpcMessageSize);
    }
}

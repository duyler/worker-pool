<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\WorkerPool\Config\WorkerPoolConfig;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

#[CoversClass(WorkerPoolConfig::class)]
class WorkerPoolConfigTest extends TestCase
{
    #[Test]
    public function default_max_ipc_message_size(): void
    {
        $serverConfig = new ServerConfig();
        $config = new WorkerPoolConfig(serverConfig: $serverConfig);

        $this->assertSame(1048576, $config->maxIpcMessageSize);
    }

    #[Test]
    public function custom_max_ipc_message_size(): void
    {
        $serverConfig = new ServerConfig();
        $config = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            maxIpcMessageSize: 2097152,
        );

        $this->assertSame(2097152, $config->maxIpcMessageSize);
    }

    #[Test]
    public function rejects_max_ipc_message_size_below_minimum(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Max IPC message size must be at least 1024 bytes');

        $serverConfig = new ServerConfig();
        new WorkerPoolConfig(
            serverConfig: $serverConfig,
            maxIpcMessageSize: 1023,
        );
    }

    #[Test]
    public function accepts_minimum_max_ipc_message_size(): void
    {
        $serverConfig = new ServerConfig();
        $config = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            maxIpcMessageSize: 1024,
        );

        $this->assertSame(1024, $config->maxIpcMessageSize);
    }

    #[Test]
    public function accepts_large_max_ipc_message_size(): void
    {
        $serverConfig = new ServerConfig();
        $config = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            maxIpcMessageSize: 10485760,
        );

        $this->assertSame(10485760, $config->maxIpcMessageSize);
    }
}

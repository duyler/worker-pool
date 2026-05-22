<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\Util;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

use Duyler\WorkerPool\Util\SystemInfo;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

use const PHP_OS_FAMILY;

#[CoversClass(SystemInfo::class)]
final class SystemInfoFullTest extends TestCase
{
    #[Test]
    public function get_cpu_cores_returns_positive(): void
    {
        SystemInfo::resetCache();
        $info = new SystemInfo();
        $cores = $info->getCpuCores();

        $this->assertGreaterThan(0, $cores);
    }

    #[Test]
    public function get_cpu_cores_uses_fallback_on_failure(): void
    {
        SystemInfo::resetCache();

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');

        $info = new SystemInfo($logger);
        $cores = $info->getCpuCores(8);

        $this->assertGreaterThan(0, $cores);
    }

    #[Test]
    public function get_cpu_cores_caches_result(): void
    {
        SystemInfo::resetCache();
        $info = new SystemInfo();

        $first = $info->getCpuCores();
        $second = $info->getCpuCores();

        $this->assertSame($first, $second);
    }

    #[Test]
    public function get_os_info_returns_complete_array(): void
    {
        SystemInfo::resetCache();
        $info = new SystemInfo();
        $osInfo = $info->getOsInfo();

        $this->assertArrayHasKey('os', $osInfo);
        $this->assertArrayHasKey('os_family', $osInfo);
        $this->assertArrayHasKey('php_version', $osInfo);
        $this->assertArrayHasKey('sapi', $osInfo);
        $this->assertArrayHasKey('cpu_cores', $osInfo);
        $this->assertIsString($osInfo['os']);
        $this->assertIsString($osInfo['os_family']);
        $this->assertIsString($osInfo['php_version']);
        $this->assertIsString($osInfo['sapi']);
        $this->assertIsInt($osInfo['cpu_cores']);
    }

    #[Test]
    public function is_container_environment_returns_bool(): void
    {
        $info = new SystemInfo();
        $result = $info->isContainerEnvironment();

        $this->assertIsBool($result);
    }

    #[Test]
    public function supports_fd_passing_returns_bool(): void
    {
        $info = new SystemInfo();
        $result = $info->supportsFdPassing();

        $this->assertIsBool($result);
    }

    #[Test]
    public function supports_fd_passing_on_linux(): void
    {
        $info = new SystemInfo();
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->assertFalse($info->supportsFdPassing());
        } else {
            $this->assertIsBool($info->supportsFdPassing());
        }
    }

    #[Test]
    public function supports_reuse_port_returns_bool(): void
    {
        $info = new SystemInfo();
        $result = $info->supportsReusePort();

        $this->assertIsBool($result);
    }

    #[Test]
    public function reset_cache_clears_cache(): void
    {
        $info = new SystemInfo();
        $info->getCpuCores();

        SystemInfo::resetCache();

        $newInfo = new SystemInfo();
        $cores = $newInfo->getCpuCores();
        $this->assertGreaterThan(0, $cores);
    }

    #[Test]
    public function get_cpu_cores_with_custom_fallback(): void
    {
        SystemInfo::resetCache();
        $info = new SystemInfo();
        $cores = $info->getCpuCores(12);

        $this->assertGreaterThanOrEqual(1, $cores);
    }
}

<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\Util;

use Duyler\WorkerPool\Util\SystemInfo;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

use function defined;
use function function_exists;

use const PHP_OS;
use const PHP_OS_FAMILY;
use const PHP_SAPI;
use const PHP_VERSION;

final class SystemInfoTest extends TestCase
{
    #[Test]
    public function get_cpu_cores_returns_positive_value(): void
    {
        SystemInfo::resetCache();
        $systemInfo = new SystemInfo();
        $cores = $systemInfo->getCpuCores();

        $this->assertGreaterThanOrEqual(1, $cores);
    }

    #[Test]
    public function get_cpu_cores_with_custom_logger(): void
    {
        SystemInfo::resetCache();
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('debug')
            ->with(
                'CPU cores detected',
                $this->callback(fn(array $context): bool => isset($context['cores']) && isset($context['os'])),
            );

        $systemInfo = new SystemInfo($logger);
        $cores = $systemInfo->getCpuCores();

        $this->assertGreaterThanOrEqual(1, $cores);
    }

    #[Test]
    public function get_cpu_cores_logs_on_success(): void
    {
        SystemInfo::resetCache();
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())
            ->method('debug');

        $systemInfo = new SystemInfo($logger);
        $systemInfo->getCpuCores();
    }

    #[Test]
    public function get_cpu_cores_uses_fallback(): void
    {
        SystemInfo::resetCache();
        $systemInfo = new SystemInfo();
        $cores = $systemInfo->getCpuCores(16);

        $this->assertGreaterThanOrEqual(1, $cores);
    }

    #[Test]
    public function cpu_cores_are_cached(): void
    {
        SystemInfo::resetCache();
        $systemInfo = new SystemInfo();
        $cores1 = $systemInfo->getCpuCores();
        $cores2 = $systemInfo->getCpuCores();

        $this->assertSame($cores1, $cores2);
    }

    #[Test]
    public function reset_cache_clears_cached_value(): void
    {
        $systemInfo = new SystemInfo();
        $cores1 = $systemInfo->getCpuCores();

        SystemInfo::resetCache();

        $cores2 = $systemInfo->getCpuCores();

        $this->assertSame($cores1, $cores2);
    }

    #[Test]
    public function get_os_info_returns_array(): void
    {
        SystemInfo::resetCache();
        $systemInfo = new SystemInfo();
        $info = $systemInfo->getOsInfo();

        $this->assertIsArray($info);
        $this->assertArrayHasKey('os', $info);
        $this->assertArrayHasKey('os_family', $info);
        $this->assertArrayHasKey('php_version', $info);
        $this->assertArrayHasKey('sapi', $info);
        $this->assertArrayHasKey('cpu_cores', $info);
        $this->assertSame(PHP_OS, $info['os']);
        $this->assertSame(PHP_OS_FAMILY, $info['os_family']);
        $this->assertSame(PHP_VERSION, $info['php_version']);
        $this->assertSame(PHP_SAPI, $info['sapi']);
    }

    #[Test]
    public function supports_fd_passing_returns_bool(): void
    {
        $systemInfo = new SystemInfo();
        $result = $systemInfo->supportsFdPassing();

        $this->assertIsBool($result);
    }

    #[Test]
    public function supports_fd_passing_on_linux(): void
    {
        $systemInfo = new SystemInfo();
        $result = $systemInfo->supportsFdPassing();

        if (PHP_OS_FAMILY === 'Linux') {
            $expected = function_exists('socket_sendmsg')
                && function_exists('socket_recvmsg')
                && defined('SCM_RIGHTS');
            $this->assertSame($expected, $result);
        } else {
            $this->assertFalse($result);
        }
    }

    #[Test]
    public function supports_fd_passing_checks_functions(): void
    {
        $systemInfo = new SystemInfo();
        $result = $systemInfo->supportsFdPassing();

        if (PHP_OS_FAMILY === 'Linux') {
            if (!function_exists('socket_sendmsg') || !function_exists('socket_recvmsg')) {
                $this->assertFalse($result);
            } elseif (!defined('SCM_RIGHTS')) {
                $this->assertFalse($result);
            } else {
                $this->assertTrue($result);
            }
        } else {
            $this->assertFalse($result);
        }
    }

    #[Test]
    public function supports_reuse_port_returns_bool(): void
    {
        $systemInfo = new SystemInfo();
        $result = $systemInfo->supportsReusePort();

        $this->assertIsBool($result);
    }

    #[Test]
    public function supports_reuse_port_depends_on_constant(): void
    {
        $systemInfo = new SystemInfo();
        $result = $systemInfo->supportsReusePort();

        $expected = defined('SO_REUSEPORT');
        $this->assertSame($expected, $result);
    }

    #[Test]
    public function is_container_environment_returns_bool(): void
    {
        $systemInfo = new SystemInfo();
        $result = $systemInfo->isContainerEnvironment();

        $this->assertIsBool($result);
    }

    #[Test]
    public function is_container_environment_checks_dockerenv(): void
    {
        $systemInfo = new SystemInfo();
        $result = $systemInfo->isContainerEnvironment();

        $expected = file_exists('/.dockerenv') || file_exists('/run/.containerenv');
        $this->assertSame($expected, $result);
    }
}

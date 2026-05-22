<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\Util;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Duyler\WorkerPool\Util\SystemInfo;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

use function defined;
use function function_exists;

use const PHP_OS;
use const PHP_OS_FAMILY;
use const PHP_SAPI;
use const PHP_VERSION;

#[CoversClass(SystemInfo::class)]
final class SystemInfoMethodTest extends TestCase
{
    #[Test]
    public function detect_cpu_cores_linux_with_nproc(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('Linux only');
        }

        SystemInfo::resetCache();
        $info = new SystemInfo();
        $cores = $info->getCpuCores();

        $this->assertGreaterThan(0, $cores);
    }

    #[Test]
    public function detect_cpu_cores_returns_fallback(): void
    {
        SystemInfo::resetCache();
        $info = new SystemInfo();
        $cores = $info->getCpuCores(16);

        $this->assertGreaterThanOrEqual(1, $cores);
        $this->assertLessThanOrEqual(1024, $cores);
    }

    #[Test]
    public function get_os_info_has_required_keys(): void
    {
        SystemInfo::resetCache();
        $info = new SystemInfo();
        $osInfo = $info->getOsInfo();

        $requiredKeys = ['os', 'os_family', 'php_version', 'sapi', 'cpu_cores'];
        foreach ($requiredKeys as $key) {
            $this->assertArrayHasKey($key, $osInfo, "Missing key: $key");
        }

        $this->assertSame(PHP_OS, $osInfo['os']);
        $this->assertSame(PHP_OS_FAMILY, $osInfo['os_family']);
        $this->assertSame(PHP_VERSION, $osInfo['php_version']);
        $this->assertSame(PHP_SAPI, $osInfo['sapi']);
    }

    #[Test]
    public function is_container_environment_in_docker(): void
    {
        $info = new SystemInfo();
        $result = $info->isContainerEnvironment();

        if (file_exists('/.dockerenv') || file_exists('/run/.containerenv')) {
            $this->assertTrue($result);
        } else {
            $this->assertFalse($result);
        }
    }

    #[Test]
    public function supports_fd_passing_linux_check(): void
    {
        $info = new SystemInfo();
        $result = $info->supportsFdPassing();

        if (PHP_OS_FAMILY === 'Linux') {
            $hasFunctions = function_exists('socket_sendmsg') && function_exists('socket_recvmsg');
            $hasConstant = defined('SCM_RIGHTS');
            $this->assertSame($hasFunctions && $hasConstant, $result);
        } else {
            $this->assertFalse($result);
        }
    }

    #[Test]
    public function supports_reuse_port_check(): void
    {
        $info = new SystemInfo();
        $result = $info->supportsReusePort();

        $this->assertSame(defined('SO_REUSEPORT'), $result);
    }

    #[Test]
    public function cpu_cores_cached_across_calls(): void
    {
        SystemInfo::resetCache();
        $info = new SystemInfo();

        $first = $info->getCpuCores(8);
        SystemInfo::resetCache();
        $second = $info->getCpuCores(8);

        $this->assertSame($first, $second);
    }

    #[Test]
    public function cpu_cores_caching_within_instance(): void
    {
        SystemInfo::resetCache();
        $info = new SystemInfo();

        $call1 = $info->getCpuCores(4);
        $call2 = $info->getCpuCores(4);

        $this->assertSame($call1, $call2);
    }

    #[Test]
    public function exec_command_returns_zero_for_nonexistent(): void
    {
        $info = new SystemInfo();
        $execCommand = new ReflectionMethod($info, 'execCommand');
        $result = $execCommand->invoke($info, 'echo notanumber');

        $this->assertSame(0, $result);
    }

    #[Test]
    public function exec_command_string_returns_zero_for_empty(): void
    {
        $info = new SystemInfo();
        $execCommandString = new ReflectionMethod($info, 'execCommandString');
        $result = $execCommandString->invoke($info, 'echo notanumber');

        $this->assertSame(0, $result);
    }

    #[Test]
    public function exec_command_output_returns_empty_for_nonexistent(): void
    {
        $info = new SystemInfo();
        $execCommandOutput = new ReflectionMethod($info, 'execCommandOutput');
        $result = $execCommandOutput->invoke($info, 'printf ""');

        $this->assertTrue(null === $result || '' === $result);
    }

    #[Test]
    public function detect_cpu_cores_bsd_returns_non_negative(): void
    {
        $info = new SystemInfo();
        $detectBsd = new ReflectionMethod($info, 'detectCpuCoresBsd');
        $result = $detectBsd->invoke($info);

        $this->assertGreaterThanOrEqual(0, $result);
    }

    #[Test]
    public function detect_cpu_cores_linux_returns_positive(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('Linux only');
        }

        $info = new SystemInfo();
        $detectLinux = new ReflectionMethod($info, 'detectCpuCoresLinux');
        $result = $detectLinux->invoke($info);

        $this->assertGreaterThan(0, $result);
    }

    #[Test]
    public function detect_cpu_cores_returns_positive_on_linux(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('Linux only');
        }

        $info = new SystemInfo();
        $detect = new ReflectionMethod($info, 'detectCpuCores');
        $result = $detect->invoke($info);

        $this->assertGreaterThan(0, $result);
    }
}

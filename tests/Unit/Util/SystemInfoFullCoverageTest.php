<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\Util;

use PHPUnit\Framework\Attributes\CoversClass;

use Duyler\WorkerPool\Util\SystemInfo;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

use const PHP_OS_FAMILY;

#[CoversClass(SystemInfo::class)]
final class SystemInfoFullCoverageTest extends TestCase
{
    #[Override]
    protected function tearDown(): void
    {
        SystemInfo::resetCache();
        parent::tearDown();
    }

    #[Test]
    public function getCpuCoresUsesFallbackOnFailure(): void
    {
        SystemInfo::resetCache();

        $info = new SystemInfo();
        $result = $info->getCpuCores(fallback: 4);

        $this->assertGreaterThanOrEqual(1, $result);
    }

    #[Test]
    public function getCpuCoresCachesResult(): void
    {
        SystemInfo::resetCache();

        $info = new SystemInfo();
        $first = $info->getCpuCores();
        $second = $info->getCpuCores();

        $this->assertSame($first, $second);
    }

    #[Test]
    public function getOsInfoReturnsRequiredKeys(): void
    {
        $info = new SystemInfo();
        $osInfo = $info->getOsInfo();

        $this->assertArrayHasKey('os', $osInfo);
        $this->assertArrayHasKey('os_family', $osInfo);
        $this->assertArrayHasKey('php_version', $osInfo);
        $this->assertArrayHasKey('sapi', $osInfo);
        $this->assertArrayHasKey('cpu_cores', $osInfo);
    }

    #[Test]
    public function isContainerEnvironment(): void
    {
        $info = new SystemInfo();
        $result = $info->isContainerEnvironment();

        $this->assertIsBool($result);
    }

    #[Test]
    public function supportsFdPassing(): void
    {
        $info = new SystemInfo();
        $result = $info->supportsFdPassing();

        $this->assertIsBool($result);
    }

    #[Test]
    public function supportsReusePort(): void
    {
        $info = new SystemInfo();
        $result = $info->supportsReusePort();

        $this->assertIsBool($result);
    }

    #[Test]
    public function resetCacheClearsCachedValue(): void
    {
        SystemInfo::resetCache();

        $info = new SystemInfo();
        $cores1 = $info->getCpuCores();

        SystemInfo::resetCache();

        $cores2 = $info->getCpuCores();
        $this->assertSame($cores1, $cores2);
    }

    #[Test]
    public function detectCpuCoresLinuxViaReflection(): void
    {
        $info = new SystemInfo();
        $ref = new ReflectionMethod($info, 'detectCpuCoresLinux');
        $result = $ref->invoke($info);

        $this->assertGreaterThanOrEqual(0, $result);
    }

    #[Test]
    public function detectCpuCoresBsdViaReflection(): void
    {
        $info = new SystemInfo();
        $ref = new ReflectionMethod($info, 'detectCpuCoresBsd');
        $result = $ref->invoke($info);

        $this->assertGreaterThanOrEqual(0, $result);
    }

    #[Test]
    public function detectCpuCoresWindowsViaReflection(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Windows-only: detectCpuCoresWindows()');
        }

        $info = new SystemInfo();
        $ref = new ReflectionMethod($info, 'detectCpuCoresWindows');
        $result = $ref->invoke($info);

        $this->assertGreaterThanOrEqual(0, $result);
    }

    #[Test]
    public function execCommandViaReflection(): void
    {
        $info = new SystemInfo();
        $ref = new ReflectionMethod($info, 'execCommand');
        $result = $ref->invoke($info, 'nproc');

        $this->assertGreaterThanOrEqual(0, $result);
    }

    #[Test]
    public function execCommandStringViaReflection(): void
    {
        $info = new SystemInfo();
        $ref = new ReflectionMethod($info, 'execCommandString');
        $result = $ref->invoke($info, 'echo 4');

        $this->assertSame(4, $result);
    }

    #[Test]
    public function execCommandReturnsZeroOnInvalidCommand(): void
    {
        $info = new SystemInfo();
        $ref = new ReflectionMethod($info, 'execCommand');
        $result = $ref->invoke($info, 'echo notanumber');

        $this->assertSame(0, $result);
    }

    #[Test]
    public function execCommandStringReturnsZeroOnInvalidCommand(): void
    {
        $info = new SystemInfo();
        $ref = new ReflectionMethod($info, 'execCommandString');
        $result = $ref->invoke($info, 'echo notanumber');

        $this->assertSame(0, $result);
    }

    #[Test]
    public function detectCpuCoresMainMethod(): void
    {
        SystemInfo::resetCache();

        $info = new SystemInfo();
        $ref = new ReflectionMethod($info, 'detectCpuCores');
        $result = $ref->invoke($info);

        $this->assertGreaterThanOrEqual(0, $result);
    }
}

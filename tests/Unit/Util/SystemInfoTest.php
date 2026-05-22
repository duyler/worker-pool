<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\Util;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

use Duyler\WorkerPool\Util\SystemInfo;
use Override;
use PHPUnit\Framework\TestCase;

#[CoversClass(SystemInfo::class)]
class SystemInfoTest extends TestCase
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        SystemInfo::resetCache();
    }

    #[Test]
    public function returns_positive_number(): void
    {
        $systemInfo = new SystemInfo();
        $cores = $systemInfo->getCpuCores();

        $this->assertGreaterThan(0, $cores);
        $this->assertIsInt($cores);
    }

    #[Test]
    public function uses_cache(): void
    {
        $systemInfo = new SystemInfo();

        $cores1 = $systemInfo->getCpuCores();
        $cores2 = $systemInfo->getCpuCores();

        $this->assertSame($cores1, $cores2);
    }

    #[Test]
    public function uses_fallback_when_detection_fails(): void
    {
        $systemInfo = new SystemInfo();

        $cores = $systemInfo->getCpuCores(fallback: 8);

        $this->assertGreaterThanOrEqual(1, $cores);
    }

    #[Test]
    public function returns_os_info(): void
    {
        $systemInfo = new SystemInfo();
        $info = $systemInfo->getOsInfo();

        $this->assertIsArray($info);
        $this->assertArrayHasKey('os', $info);
        $this->assertArrayHasKey('os_family', $info);
        $this->assertArrayHasKey('php_version', $info);
        $this->assertArrayHasKey('sapi', $info);
        $this->assertArrayHasKey('cpu_cores', $info);

        $this->assertGreaterThan(0, $info['cpu_cores']);
    }

    #[Test]
    public function resets_cache(): void
    {
        $systemInfo = new SystemInfo();

        $cores1 = $systemInfo->getCpuCores();

        SystemInfo::resetCache();

        $cores2 = $systemInfo->getCpuCores();

        $this->assertSame($cores1, $cores2);
    }
}

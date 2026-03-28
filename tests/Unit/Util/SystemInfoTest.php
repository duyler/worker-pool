<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\Util;

use Duyler\WorkerPool\Util\SystemInfo;
use Override;
use PHPUnit\Framework\TestCase;

class SystemInfoTest extends TestCase
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        SystemInfo::resetCache();
    }

    public function testReturnsPositiveNumber(): void
    {
        $systemInfo = new SystemInfo();
        $cores = $systemInfo->getCpuCores();

        $this->assertGreaterThan(0, $cores);
        $this->assertIsInt($cores);
    }

    public function testUsesCache(): void
    {
        $systemInfo = new SystemInfo();

        $cores1 = $systemInfo->getCpuCores();
        $cores2 = $systemInfo->getCpuCores();

        $this->assertSame($cores1, $cores2);
    }

    public function testUsesFallbackWhenDetectionFails(): void
    {
        $systemInfo = new SystemInfo();

        $cores = $systemInfo->getCpuCores(fallback: 8);

        $this->assertGreaterThanOrEqual(1, $cores);
    }

    public function testReturnsOsInfo(): void
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

    public function testResetsCache(): void
    {
        $systemInfo = new SystemInfo();

        $cores1 = $systemInfo->getCpuCores();

        SystemInfo::resetCache();

        $cores2 = $systemInfo->getCpuCores();

        $this->assertSame($cores1, $cores2);
    }
}

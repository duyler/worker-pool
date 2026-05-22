<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\Util;

use PHPUnit\Framework\Attributes\CoversClass;

use Duyler\WorkerPool\Util\SystemInfo;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

use const PHP_OS_FAMILY;
use const PHP_VERSION;

#[CoversClass(SystemInfo::class)]
#[AllowMockObjectsWithoutExpectations]
final class SystemInfoCoverageTest extends TestCase
{
    private LoggerInterface&MockObject $logger;

    #[Override]
    protected function setUp(): void
    {
        SystemInfo::resetCache();
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    #[Override]
    protected function tearDown(): void
    {
        SystemInfo::resetCache();
        parent::tearDown();
    }

    #[Test]
    public function getOsInfoReturnsAllFields(): void
    {
        $info = new SystemInfo();
        $result = $info->getOsInfo();

        $this->assertArrayHasKey('os', $result);
        $this->assertArrayHasKey('os_family', $result);
        $this->assertArrayHasKey('php_version', $result);
        $this->assertArrayHasKey('sapi', $result);
        $this->assertArrayHasKey('cpu_cores', $result);
        $this->assertSame(PHP_OS_FAMILY, $result['os_family']);
        $this->assertSame(PHP_VERSION, $result['php_version']);
    }

    #[Test]
    public function getCpuCoresUsesFallbackValue(): void
    {
        $info = new SystemInfo($this->logger);
        $cores = $info->getCpuCores(8);
        $this->assertGreaterThanOrEqual(1, $cores);
    }

    #[Test]
    public function getCpuCoresCachesResult(): void
    {
        $info = new SystemInfo();
        $first = $info->getCpuCores(4);
        $second = $info->getCpuCores(2);

        $this->assertSame($first, $second);
    }

    #[Test]
    public function resetCacheClearsCachedCores(): void
    {
        $info = new SystemInfo();
        $cores = $info->getCpuCores(4);
        $this->assertGreaterThanOrEqual(1, $cores);

        SystemInfo::resetCache();

        $cores2 = $info->getCpuCores(4);
        $this->assertGreaterThanOrEqual(1, $cores2);
    }

    #[Test]
    public function supportsFdPassingReturnsBool(): void
    {
        $info = new SystemInfo();
        $result = $info->supportsFdPassing();
        $this->assertIsBool($result);
    }

    #[Test]
    public function supportsReusePortReturnsBool(): void
    {
        $info = new SystemInfo();
        $result = $info->supportsReusePort();
        $this->assertIsBool($result);
    }

    #[Test]
    public function isContainerEnvironmentReturnsBool(): void
    {
        $info = new SystemInfo();
        $result = $info->isContainerEnvironment();
        $this->assertIsBool($result);
    }

    #[Test]
    public function constructorWithDefaultLogger(): void
    {
        $info = new SystemInfo();
        $cores = $info->getCpuCores();
        $this->assertGreaterThanOrEqual(1, $cores);
    }

    #[Test]
    public function constructorWithCustomLogger(): void
    {
        $info = new SystemInfo($this->logger);
        $cores = $info->getCpuCores();
        $this->assertGreaterThanOrEqual(1, $cores);
    }

    #[Test]
    public function getCpuCoresLogsDebugOnSuccess(): void
    {
        $this->logger->expects($this->atLeastOnce())
            ->method('debug');

        $info = new SystemInfo($this->logger);
        $info->getCpuCores(4);
    }
}

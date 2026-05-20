<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\Util;

use PHPUnit\Framework\Attributes\CoversClass;

use Duyler\WorkerPool\Util\SystemInfo;
use Override;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

use function defined;
use function function_exists;

use const PHP_OS_FAMILY;

#[CoversClass(SystemInfo::class)]
class SystemInfoExtendedTest extends TestCase
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        SystemInfo::resetCache();
    }

    #[Override]
    protected function tearDown(): void
    {
        SystemInfo::resetCache();
        parent::tearDown();
    }

    public function testGetCpuCoresCachesResult(): void
    {
        $systemInfo = new SystemInfo();

        $cores1 = $systemInfo->getCpuCores();
        $cores2 = $systemInfo->getCpuCores();

        $this->assertSame($cores1, $cores2);
        $this->assertGreaterThan(0, $cores1);
    }

    public function testGetCpuCoresLogsDebugOnSuccess(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('debug')
            ->with(
                'CPU cores detected',
                $this->callback(fn(array $context): bool => isset($context['cores']) && isset($context['os'])),
            );

        $systemInfo = new SystemInfo($logger);
        $systemInfo->getCpuCores();
    }

    public function testGetOsInfoReturnsCorrectStructure(): void
    {
        $systemInfo = new SystemInfo();

        $info = $systemInfo->getOsInfo();

        $this->assertIsArray($info);
        $this->assertArrayHasKey('os', $info);
        $this->assertArrayHasKey('os_family', $info);
        $this->assertArrayHasKey('php_version', $info);
        $this->assertArrayHasKey('sapi', $info);
        $this->assertArrayHasKey('cpu_cores', $info);

        $this->assertIsString($info['os']);
        $this->assertIsString($info['os_family']);
        $this->assertIsString($info['php_version']);
        $this->assertIsString($info['sapi']);
        $this->assertIsInt($info['cpu_cores']);
    }

    public function testIsContainerEnvironmentReturnsBool(): void
    {
        $systemInfo = new SystemInfo();

        $isContainer = $systemInfo->isContainerEnvironment();

        $this->assertIsBool($isContainer);
    }

    public function testSupportsFdPassingReturnsBool(): void
    {
        $systemInfo = new SystemInfo();

        $supportsFdPassing = $systemInfo->supportsFdPassing();

        $this->assertIsBool($supportsFdPassing);

        if (PHP_OS_FAMILY === 'Linux' && function_exists('socket_sendmsg') && defined('SCM_RIGHTS')) {
            $this->assertTrue($supportsFdPassing);
        }
    }

    public function testSupportsReusePortReturnsBool(): void
    {
        $systemInfo = new SystemInfo();

        $supportsReusePort = $systemInfo->supportsReusePort();

        $this->assertIsBool($supportsReusePort);

        if (defined('SO_REUSEPORT')) {
            $this->assertTrue($supportsReusePort);
        }
    }

    public function testResetCacheClearsCachedValue(): void
    {
        $systemInfo = new SystemInfo();

        $cores1 = $systemInfo->getCpuCores();
        SystemInfo::resetCache();
        $cores2 = $systemInfo->getCpuCores();

        $this->assertSame($cores1, $cores2);
        $this->assertGreaterThan(0, $cores1);
    }

    public function testGetCpuCoresWithDifferentFallbackValues(): void
    {
        $systemInfo = new SystemInfo();

        SystemInfo::resetCache();
        $cores1 = $systemInfo->getCpuCores(2);

        SystemInfo::resetCache();
        $cores2 = $systemInfo->getCpuCores(16);

        $this->assertSame($cores1, $cores2);
    }
}

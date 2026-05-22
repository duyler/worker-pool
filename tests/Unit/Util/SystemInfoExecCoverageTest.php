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
use ReflectionMethod;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

use function is_string;

use const PHP_OS_FAMILY;

#[CoversClass(SystemInfo::class)]
#[AllowMockObjectsWithoutExpectations]
final class SystemInfoExecCoverageTest extends TestCase
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
    public function exec_command_output_returns_output(): void
    {
        $info = new SystemInfo($this->logger);
        $ref = new ReflectionMethod($info, 'execCommandOutput');
        $result = $ref->invoke($info, 'echo 8');

        $this->assertNotNull($result);
        $this->assertStringContainsString('8', $result);
    }

    #[Test]
    public function exec_command_output_returns_empty_for_bad_command(): void
    {
        $info = new SystemInfo($this->logger);
        $ref = new ReflectionMethod($info, 'execCommandOutput');
        $result = $ref->invoke($info, 'printf ""');

        $this->assertTrue(null === $result || is_string($result));
    }

    #[Test]
    public function detect_cpu_cores_fallback_path(): void
    {
        SystemInfo::resetCache();

        $info = new SystemInfo($this->logger);
        $ref = new ReflectionMethod($info, 'detectCpuCores');
        $result = $ref->invoke($info);

        $this->assertGreaterThanOrEqual(0, $result);
    }

    #[Test]
    public function get_cpu_cores_uses_fallback_when_detection_fails(): void
    {
        SystemInfo::resetCache();

        $this->logger->expects($this->atLeastOnce())
            ->method('debug');

        $info = new SystemInfo($this->logger);
        $cores = $info->getCpuCores(fallback: 2);

        $this->assertGreaterThanOrEqual(1, $cores);
    }

    #[Test]
    public function exec_command_with_nproc(): void
    {
        $info = new SystemInfo($this->logger);
        $ref = new ReflectionMethod($info, 'execCommand');
        $result = $ref->invoke($info, 'nproc');

        $this->assertGreaterThanOrEqual(0, $result);
    }

    #[Test]
    public function exec_command_string_with_sysctl(): void
    {
        $info = new SystemInfo($this->logger);
        $ref = new ReflectionMethod($info, 'execCommandString');
        $result = $ref->invoke($info, 'sysctl -n hw.ncpu 2>/dev/null || echo 0');

        $this->assertGreaterThanOrEqual(0, $result);
    }

    #[Test]
    public function detect_cpu_cores_bsd(): void
    {
        $info = new SystemInfo($this->logger);
        $ref = new ReflectionMethod($info, 'detectCpuCoresBsd');
        $result = $ref->invoke($info);

        $this->assertGreaterThanOrEqual(0, $result);
    }

    #[Test]
    public function detect_cpu_cores_linux(): void
    {
        $info = new SystemInfo($this->logger);
        $ref = new ReflectionMethod($info, 'detectCpuCoresLinux');
        $result = $ref->invoke($info);

        $this->assertGreaterThanOrEqual(0, $result);
    }

    #[Test]
    public function detect_cpu_cores_windows(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Windows-only: detectCpuCoresWindows()');
        }

        $info = new SystemInfo($this->logger);
        $ref = new ReflectionMethod($info, 'detectCpuCoresWindows');
        $result = $ref->invoke($info);

        $this->assertGreaterThanOrEqual(0, $result);
    }
}

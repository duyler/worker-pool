<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\Util;

use Duyler\WorkerPool\Util\SystemInfo;
use Override;
use PHPUnit\Framework\TestCase;

use function defined;
use function function_exists;

use const PHP_OS_FAMILY;

final class SystemInfoFdPassingTest extends TestCase
{
    private SystemInfo $systemInfo;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->systemInfo = new SystemInfo();
    }

    public function testSupportsFdPassingOnLinuxWithRequiredFunctions(): void
    {
        $result = $this->systemInfo->supportsFdPassing();

        if (PHP_OS_FAMILY === 'Linux' && function_exists('socket_sendmsg') && defined('SCM_RIGHTS')) {
            $this->assertTrue($result);
        } else {
            $this->assertFalse($result);
        }
    }

    public function testDoesNotSupportFdPassingOnNonLinux(): void
    {
        if (PHP_OS_FAMILY === 'Linux') {
            $this->markTestSkipped('Test only for non-Linux platforms');
        }

        $result = $this->systemInfo->supportsFdPassing();

        $this->assertFalse($result);
    }

    public function testChecksReusePortSupport(): void
    {
        $result = $this->systemInfo->supportsReusePort();

        $this->assertIsBool($result);

        if (defined('SO_REUSEPORT')) {
            $this->assertTrue($result);
        } else {
            $this->assertFalse($result);
        }
    }
}

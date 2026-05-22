<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\Process;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

use Duyler\WorkerPool\Process\ForkWrapper;
use Duyler\WorkerPool\Process\ForkWrapperInterface;
use Override;
use PHPUnit\Framework\TestCase;

use const SIGKILL;
use const SIGTERM;
use const WNOHANG;

#[CoversClass(ForkWrapper::class)]
class ForkWrapperTest extends TestCase
{
    private ForkWrapperInterface $wrapper;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->wrapper = new ForkWrapper();
    }

    #[Test]
    public function fork_returns_positive_pid_in_parent(): void
    {
        $pid = $this->wrapper->fork();

        if (0 === $pid) {
            posix_kill(posix_getpid(), SIGKILL);
        }

        $this->assertGreaterThan(0, $pid);

        pcntl_waitpid($pid, $status);
    }

    #[Test]
    public function waitpid_returns_child_pid_after_exit(): void
    {
        $pid = $this->wrapper->fork();

        if (0 === $pid) {
            posix_kill(posix_getpid(), SIGKILL);
        }

        $status = 0;
        $result = $this->wrapper->waitpid($pid, $status);

        $this->assertSame($pid, $result);
    }

    #[Test]
    public function waitpid_with_wnohang_returns_zero_for_running_child(): void
    {
        $pid = $this->wrapper->fork();

        if (0 === $pid) {
            usleep(200000);
            posix_kill(posix_getpid(), SIGKILL);
        }

        usleep(10000);

        $status = 0;
        $result = $this->wrapper->waitpid($pid, $status, WNOHANG);

        $this->assertSame(0, $result);

        pcntl_waitpid($pid, $blockStatus);
    }

    #[Test]
    public function kill_checks_process_existence_with_signal_zero(): void
    {
        $result = $this->wrapper->kill(posix_getpid(), 0);

        $this->assertTrue($result);
    }

    #[Test]
    public function kill_returns_false_for_nonexistent_pid(): void
    {
        $result = $this->wrapper->kill(9999999, 0);

        $this->assertFalse($result);
    }

    #[Test]
    public function kill_terminates_child_process(): void
    {
        $pid = $this->wrapper->fork();

        if (0 === $pid) {
            usleep(500000);
            posix_kill(posix_getpid(), SIGKILL);
        }

        usleep(10000);

        $terminated = $this->wrapper->kill($pid, SIGTERM);

        $this->assertTrue($terminated);

        $status = 0;
        $result = $this->wrapper->waitpid($pid, $status);

        $this->assertSame($pid, $result);
    }

    #[Test]
    public function waitpid_reaps_exited_child(): void
    {
        $pid = $this->wrapper->fork();

        if (0 === $pid) {
            posix_kill(posix_getpid(), SIGKILL);
        }

        usleep(50000);

        $status = 0;
        $result = $this->wrapper->waitpid($pid, $status, WNOHANG);

        if (0 === $result) {
            pcntl_waitpid($pid, $status);
        } else {
            $this->assertSame($pid, $result);
        }
    }
}

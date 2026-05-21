<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\Signal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

use Duyler\WorkerPool\Signal\SignalHandler;
use Override;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

use function defined;
use function function_exists;

use const SIGINT;
use const SIGTERM;
use const SIGUSR1;
use const SIGUSR2;

#[Group('pcntl')]
#[CoversClass(SignalHandler::class)]
class SignalHandlerTest extends TestCase
{
    private SignalHandler $handler;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        if (!function_exists('pcntl_signal') || !function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl extension not available');
        }

        $this->handler = new SignalHandler();
    }

    #[Override]
    protected function tearDown(): void
    {
        parent::tearDown();
        $this->handler->reset();
    }

    #[Test]
    public function registers_signal_handler(): void
    {
        $called = false;

        $this->handler->register(SIGUSR1, function () use (&$called): void {
            $called = true;
        });

        $signals = $this->handler->getRegisteredSignals();

        $this->assertArrayHasKey(SIGUSR1, $signals);
        $this->assertSame(1, $signals[SIGUSR1]);
    }

    #[Test]
    public function registers_multiple_handlers_for_same_signal(): void
    {
        $this->handler->register(SIGUSR1, function (): void {});
        $this->handler->register(SIGUSR1, function (): void {});
        $this->handler->register(SIGUSR1, function (): void {});

        $signals = $this->handler->getRegisteredSignals();

        $this->assertSame(3, $signals[SIGUSR1]);
    }

    #[Test]
    public function registers_different_signals(): void
    {
        $this->handler->register(SIGUSR1, function (): void {});
        $this->handler->register(SIGUSR2, function (): void {});
        $this->handler->register(SIGTERM, function (): void {});

        $signals = $this->handler->getRegisteredSignals();

        $this->assertCount(3, $signals);
        $this->assertArrayHasKey(SIGUSR1, $signals);
        $this->assertArrayHasKey(SIGUSR2, $signals);
        $this->assertArrayHasKey(SIGTERM, $signals);
    }

    #[Test]
    public function unregisters_signal_handler(): void
    {
        $this->handler->register(SIGUSR1, function (): void {});

        $this->handler->unregister(SIGUSR1);

        $signals = $this->handler->getRegisteredSignals();

        $this->assertArrayNotHasKey(SIGUSR1, $signals);
    }

    #[Test]
    public function handles_signal_dispatch(): void
    {
        $called = false;

        $this->handler->register(SIGUSR1, function () use (&$called): void {
            $called = true;
        });

        posix_kill(getmypid(), SIGUSR1);
        $this->handler->dispatch();

        $this->assertTrue($called);
    }

    #[Test]
    public function calls_multiple_handlers_for_signal(): void
    {
        $counter = 0;

        $this->handler->register(SIGUSR1, function () use (&$counter): void {
            $counter++;
        });
        $this->handler->register(SIGUSR1, function () use (&$counter): void {
            $counter++;
        });

        posix_kill(getmypid(), SIGUSR1);
        $this->handler->dispatch();

        $this->assertSame(2, $counter);
    }

    #[Test]
    public function resets_all_handlers(): void
    {
        $this->handler->register(SIGUSR1, function (): void {});
        $this->handler->register(SIGUSR2, function (): void {});

        $this->handler->reset();

        $signals = $this->handler->getRegisteredSignals();

        $this->assertEmpty($signals);
    }

    #[Test]
    public function creates_default_handler(): void
    {
        $handler = SignalHandler::createDefault();

        $signals = $handler->getRegisteredSignals();

        $this->assertArrayHasKey(SIGTERM, $signals);
        $this->assertArrayHasKey(SIGINT, $signals);

        if (defined('SIGUSR1')) {
            $this->assertArrayHasKey(SIGUSR1, $signals);
        }

        if (defined('SIGUSR2')) {
            $this->assertArrayHasKey(SIGUSR2, $signals);
        }

        $handler->reset();
    }

    #[Test]
    public function handler_receives_signal_number(): void
    {
        $receivedSignal = null;

        $this->handler->register(SIGUSR1, function (int $signo) use (&$receivedSignal): void {
            $receivedSignal = $signo;
        });

        posix_kill(getmypid(), SIGUSR1);
        $this->handler->dispatch();

        $this->assertSame(SIGUSR1, $receivedSignal);
    }

    #[Test]
    public function does_not_call_handler_after_unregister(): void
    {
        $called = false;

        $this->handler->register(SIGUSR1, function () use (&$called): void {
            $called = true;
        });

        $this->handler->unregister(SIGUSR1);

        posix_kill(getmypid(), SIGUSR1);
        $this->handler->dispatch();

        $this->assertFalse($called);
    }

    #[Test]
    public function handles_multiple_signals_independently(): void
    {
        $usr1Called = false;
        $usr2Called = false;

        $this->handler->register(SIGUSR1, function () use (&$usr1Called): void {
            $usr1Called = true;
        });

        $this->handler->register(SIGUSR2, function () use (&$usr2Called): void {
            $usr2Called = true;
        });

        posix_kill(getmypid(), SIGUSR1);
        $this->handler->dispatch();

        $this->assertTrue($usr1Called);
        $this->assertFalse($usr2Called);

        posix_kill(getmypid(), SIGUSR2);
        $this->handler->dispatch();

        $this->assertTrue($usr2Called);
    }
}

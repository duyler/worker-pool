<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\Signal;

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

    public function testRegistersSignalHandler(): void
    {
        $called = false;

        $this->handler->register(SIGUSR1, function () use (&$called): void {
            $called = true;
        });

        $signals = $this->handler->getRegisteredSignals();

        $this->assertArrayHasKey(SIGUSR1, $signals);
        $this->assertSame(1, $signals[SIGUSR1]);
    }

    public function testRegistersMultipleHandlersForSameSignal(): void
    {
        $this->handler->register(SIGUSR1, function (): void {});
        $this->handler->register(SIGUSR1, function (): void {});
        $this->handler->register(SIGUSR1, function (): void {});

        $signals = $this->handler->getRegisteredSignals();

        $this->assertSame(3, $signals[SIGUSR1]);
    }

    public function testRegistersDifferentSignals(): void
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

    public function testUnregistersSignalHandler(): void
    {
        $this->handler->register(SIGUSR1, function (): void {});

        $this->handler->unregister(SIGUSR1);

        $signals = $this->handler->getRegisteredSignals();

        $this->assertArrayNotHasKey(SIGUSR1, $signals);
    }

    public function testHandlesSignalDispatch(): void
    {
        $called = false;

        $this->handler->register(SIGUSR1, function () use (&$called): void {
            $called = true;
        });

        posix_kill(getmypid(), SIGUSR1);
        $this->handler->dispatch();

        $this->assertTrue($called);
    }

    public function testCallsMultipleHandlersForSignal(): void
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

    public function testResetsAllHandlers(): void
    {
        $this->handler->register(SIGUSR1, function (): void {});
        $this->handler->register(SIGUSR2, function (): void {});

        $this->handler->reset();

        $signals = $this->handler->getRegisteredSignals();

        $this->assertEmpty($signals);
    }

    public function testCreatesDefaultHandler(): void
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

    public function testHandlerReceivesSignalNumber(): void
    {
        $receivedSignal = null;

        $this->handler->register(SIGUSR1, function (int $signo) use (&$receivedSignal): void {
            $receivedSignal = $signo;
        });

        posix_kill(getmypid(), SIGUSR1);
        $this->handler->dispatch();

        $this->assertSame(SIGUSR1, $receivedSignal);
    }

    public function testDoesNotCallHandlerAfterUnregister(): void
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

    public function testHandlesMultipleSignalsIndependently(): void
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

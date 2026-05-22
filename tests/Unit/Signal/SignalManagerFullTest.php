<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\Signal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

use Duyler\WorkerPool\Signal\SignalHandler;
use Duyler\WorkerPool\Signal\SignalManager;
use PHPUnit\Framework\TestCase;

use const SIGINT;
use const SIGTERM;
use const SIGUSR1;

#[CoversClass(SignalManager::class)]
#[UsesClass(SignalHandler::class)]
final class SignalManagerFullTest extends TestCase
{
    #[Test]
    public function setup_worker_signals_registers_handlers(): void
    {
        $handler = new SignalHandler();
        $manager = new SignalManager($handler);

        $shutdownReceived = false;
        $manager->setupWorkerSignals(
            onShutdown: function (int $signal) use (&$shutdownReceived): void {
                $shutdownReceived = true;
            },
        );

        posix_kill(getmypid(), SIGTERM);
        pcntl_signal_dispatch();

        $this->assertTrue($shutdownReceived);
        $this->assertTrue($manager->isShutdownRequested());

        $handler->reset();
    }

    #[Test]
    public function setup_worker_signals_handles_sigint(): void
    {
        $handler = new SignalHandler();
        $manager = new SignalManager($handler);

        $shutdownSignal = 0;
        $manager->setupWorkerSignals(
            onShutdown: function (int $signal) use (&$shutdownSignal): void {
                $shutdownSignal = $signal;
            },
        );

        posix_kill(getmypid(), SIGINT);
        pcntl_signal_dispatch();

        $this->assertSame(SIGINT, $shutdownSignal);
        $this->assertTrue($manager->isShutdownRequested());

        $handler->reset();
    }

    #[Test]
    public function reset_clears_all_flags(): void
    {
        $handler = new SignalHandler();
        $manager = new SignalManager($handler);

        $manager->setupMasterSignals(
            onShutdown: function (): void {},
            onReload: function (): void {},
        );

        posix_kill(getmypid(), SIGUSR1);
        pcntl_signal_dispatch();

        $this->assertTrue($manager->isReloadRequested());

        $manager->reset();

        $this->assertFalse($manager->isShutdownRequested());
        $this->assertFalse($manager->isReloadRequested());
    }

    #[Test]
    public function reset_flags_clears_only_flags(): void
    {
        $handler = new SignalHandler();
        $manager = new SignalManager($handler);

        $manager->requestShutdown();
        $this->assertTrue($manager->isShutdownRequested());

        $manager->resetFlags();

        $this->assertFalse($manager->isShutdownRequested());
        $this->assertFalse($manager->isReloadRequested());
    }

    #[Test]
    public function dispatch_calls_handler_dispatch(): void
    {
        $handler = new SignalHandler();
        $manager = new SignalManager($handler);

        $manager->setupMasterSignals(
            onShutdown: function (): void {},
            onReload: function (): void {},
        );

        $manager->dispatch();

        $handler->reset();
        $this->assertFalse($manager->isShutdownRequested());
    }

    #[Test]
    public function is_reload_requested_returns_false_initially(): void
    {
        $handler = new SignalHandler();
        $manager = new SignalManager($handler);

        $this->assertFalse($manager->isReloadRequested());
    }

    #[Test]
    public function setup_master_signals_sigusr1_triggers_reload(): void
    {
        $handler = new SignalHandler();
        $manager = new SignalManager($handler);

        $reloadSignal = 0;
        $manager->setupMasterSignals(
            onShutdown: function (): void {},
            onReload: function (int $signal) use (&$reloadSignal): void {
                $reloadSignal = $signal;
            },
        );

        posix_kill(getmypid(), SIGUSR1);
        pcntl_signal_dispatch();

        $this->assertSame(SIGUSR1, $reloadSignal);
        $this->assertTrue($manager->isReloadRequested());
        $this->assertFalse($manager->isShutdownRequested());

        $handler->reset();
    }
}

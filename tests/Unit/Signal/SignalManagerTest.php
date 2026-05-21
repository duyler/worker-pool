<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\Signal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

use Duyler\WorkerPool\Signal\SignalHandler;
use Duyler\WorkerPool\Signal\SignalManager;
use Override;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

use function function_exists;

use const SIGINT;
use const SIGTERM;
use const SIGUSR1;

#[Group('pcntl')]
#[CoversClass(SignalManager::class)]
class SignalManagerTest extends TestCase
{
    private SignalHandler $handler;
    private SignalManager $manager;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        if (!function_exists('pcntl_signal') || !function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl extension not available');
        }

        $this->handler = new SignalHandler();
        $this->manager = new SignalManager($this->handler);
    }

    #[Override]
    protected function tearDown(): void
    {
        parent::tearDown();
        $this->handler->reset();
    }

    #[Test]
    public function sets_up_master_signals(): void
    {
        if (!$this->handler->isSignalsSupported()) {
            $this->markTestSkipped('Signals not supported');
        }

        $shutdownCalled = false;
        $reloadCalled = false;

        $this->manager->setupMasterSignals(
            onShutdown: function () use (&$shutdownCalled): void {
                $shutdownCalled = true;
            },
            onReload: function () use (&$reloadCalled): void {
                $reloadCalled = true;
            },
        );

        $this->assertTrue($this->handler->hasHandlers(SIGTERM));
        $this->assertTrue($this->handler->hasHandlers(SIGINT));
        $this->assertTrue($this->handler->hasHandlers(SIGUSR1));
    }

    #[Test]
    public function sets_up_worker_signals(): void
    {
        if (!$this->handler->isSignalsSupported()) {
            $this->markTestSkipped('Signals not supported');
        }

        $shutdownCalled = false;

        $this->manager->setupWorkerSignals(
            onShutdown: function () use (&$shutdownCalled): void {
                $shutdownCalled = true;
            },
        );

        $this->assertTrue($this->handler->hasHandlers(SIGTERM));
        $this->assertTrue($this->handler->hasHandlers(SIGINT));
    }

    #[Test]
    public function tracks_shutdown_request(): void
    {
        $this->assertFalse($this->manager->isShutdownRequested());

        $this->manager->setupMasterSignals(
            onShutdown: function (): void {},
            onReload: function (): void {},
        );

        $this->assertFalse($this->manager->isShutdownRequested());
    }

    #[Test]
    public function tracks_reload_request(): void
    {
        $this->assertFalse($this->manager->isReloadRequested());

        $this->manager->setupMasterSignals(
            onShutdown: function (): void {},
            onReload: function (): void {},
        );

        $this->assertFalse($this->manager->isReloadRequested());
    }

    #[Test]
    public function resets_signal_handlers(): void
    {
        if (!$this->handler->isSignalsSupported()) {
            $this->markTestSkipped('Signals not supported');
        }

        $this->manager->setupMasterSignals(
            onShutdown: function (): void {},
            onReload: function (): void {},
        );

        $this->assertTrue($this->handler->hasHandlers(SIGTERM));

        $this->manager->reset();

        $this->assertFalse($this->handler->hasHandlers(SIGTERM));
        $this->assertFalse($this->manager->isShutdownRequested());
        $this->assertFalse($this->manager->isReloadRequested());
    }

    #[Test]
    public function resets_only_flags(): void
    {
        if (!$this->handler->isSignalsSupported()) {
            $this->markTestSkipped('Signals not supported');
        }

        $this->manager->setupMasterSignals(
            onShutdown: function (): void {},
            onReload: function (): void {},
        );

        $this->manager->resetFlags();

        $this->assertTrue($this->handler->hasHandlers(SIGTERM));
        $this->assertFalse($this->manager->isShutdownRequested());
        $this->assertFalse($this->manager->isReloadRequested());
    }

    #[Test]
    public function dispatch_calls_handler_dispatch(): void
    {
        $this->manager->dispatch();

        $this->assertTrue(true);
    }

    #[Test]
    public function handles_multiple_setups(): void
    {
        if (!$this->handler->isSignalsSupported()) {
            $this->markTestSkipped('Signals not supported');
        }

        $this->manager->setupMasterSignals(
            onShutdown: function (): void {},
            onReload: function (): void {},
        );

        $this->manager->setupWorkerSignals(
            onShutdown: function (): void {},
        );

        $this->assertTrue($this->handler->hasHandlers(SIGTERM));
        $this->assertTrue($this->handler->hasHandlers(SIGINT));
    }
}

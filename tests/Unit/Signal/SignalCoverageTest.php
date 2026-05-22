<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\Signal;

use PHPUnit\Framework\Attributes\CoversClass;

use Duyler\WorkerPool\Signal\SignalHandler;
use Duyler\WorkerPool\Signal\SignalManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use const SIGTERM;
use const SIGUSR1;

#[CoversClass(SignalHandler::class)]
#[CoversClass(SignalManager::class)]
final class SignalCoverageTest extends TestCase
{
    #[Test]
    public function signalHandlerRegistersAndTracks(): void
    {
        $handler = new SignalHandler();
        $handler->register(SIGUSR1, function (): void {});

        $this->assertTrue($handler->hasHandlers(SIGUSR1));
    }

    #[Test]
    public function signalHandlerUnregisterStopsTracking(): void
    {
        $handler = new SignalHandler();

        $id = $handler->register(SIGUSR1, function (): void {});
        $handler->unregister(SIGUSR1);

        $this->assertFalse($handler->hasHandlers(SIGUSR1));
    }

    #[Test]
    public function signalHandlerHasHandlersReturnsCorrectly(): void
    {
        $handler = new SignalHandler();

        $this->assertFalse($handler->hasHandlers(SIGUSR1));

        $handler->register(SIGUSR1, function (): void {});

        $this->assertTrue($handler->hasHandlers(SIGUSR1));
    }

    #[Test]
    public function signalHandlerResetClearsAll(): void
    {
        $handler = new SignalHandler();
        $handler->register(SIGUSR1, function (): void {});
        $handler->register(SIGTERM, function (): void {});

        $this->assertTrue($handler->hasHandlers(SIGUSR1));
        $this->assertTrue($handler->hasHandlers(SIGTERM));

        $handler->reset();

        $this->assertFalse($handler->hasHandlers(SIGUSR1));
        $this->assertFalse($handler->hasHandlers(SIGTERM));
    }

    #[Test]
    public function signalHandlerCreateDefaultReturnsInstance(): void
    {
        $handler = SignalHandler::createDefault();
        $this->assertInstanceOf(SignalHandler::class, $handler);
    }

    #[Test]
    public function signalManagerTracksShutdownRequest(): void
    {
        $innerHandler = new SignalHandler();
        $manager = new SignalManager($innerHandler);

        $this->assertFalse($manager->isShutdownRequested());
    }

    #[Test]
    public function signalManagerSetupMasterSignals(): void
    {
        $innerHandler = new SignalHandler();
        $manager = new SignalManager($innerHandler);

        $shutdownCalled = false;
        $reloadCalled = false;

        $manager->setupMasterSignals(
            onShutdown: function () use (&$shutdownCalled): void {
                $shutdownCalled = true;
            },
            onReload: function () use (&$reloadCalled): void {
                $reloadCalled = true;
            },
        );

        $this->assertFalse($manager->isShutdownRequested());
    }

    #[Test]
    public function signalManagerSetupWorkerSignals(): void
    {
        $innerHandler = new SignalHandler();
        $manager = new SignalManager($innerHandler);
        $manager->setupWorkerSignals(function (): void {});

        $this->assertFalse($manager->isShutdownRequested());
    }

    #[Test]
    public function signalManagerResetSignals(): void
    {
        $innerHandler = new SignalHandler();
        $manager = new SignalManager($innerHandler);

        $manager->setupMasterSignals(
            onShutdown: function (): void {},
            onReload: function (): void {},
        );

        $manager->reset();

        $this->assertFalse($manager->isShutdownRequested());
    }

    #[Test]
    public function signalManagerDispatchCallsHandler(): void
    {
        $innerHandler = new SignalHandler();
        $manager = new SignalManager($innerHandler);

        $manager->setupMasterSignals(
            onShutdown: function (): void {},
            onReload: function (): void {},
        );

        $manager->dispatch();

        $this->assertFalse($manager->isShutdownRequested());
    }
}

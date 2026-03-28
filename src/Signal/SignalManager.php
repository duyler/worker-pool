<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Signal;

use Closure;

use const SIGINT;
use const SIGTERM;
use const SIGUSR1;

final class SignalManager
{
    private bool $shutdownRequested = false;
    private bool $reloadRequested = false;

    public function __construct(
        private readonly SignalHandler $handler,
    ) {}

    public function setupMasterSignals(
        Closure $onShutdown,
        Closure $onReload,
    ): void {
        $this->handler->register(SIGTERM, function () use ($onShutdown): void {
            $this->shutdownRequested = true;
            $onShutdown(SIGTERM);
        });

        $this->handler->register(SIGINT, function () use ($onShutdown): void {
            $this->shutdownRequested = true;
            $onShutdown(SIGINT);
        });

        $this->handler->register(SIGUSR1, function () use ($onReload): void {
            $this->reloadRequested = true;
            $onReload(SIGUSR1);
        });
    }

    public function setupWorkerSignals(
        Closure $onShutdown,
    ): void {
        $this->handler->register(SIGTERM, function () use ($onShutdown): void {
            $this->shutdownRequested = true;
            $onShutdown(SIGTERM);
        });

        $this->handler->register(SIGINT, function () use ($onShutdown): void {
            $this->shutdownRequested = true;
            $onShutdown(SIGINT);
        });
    }

    public function dispatch(): void
    {
        $this->handler->dispatch();
    }

    public function isShutdownRequested(): bool
    {
        return $this->shutdownRequested;
    }

    public function isReloadRequested(): bool
    {
        return $this->reloadRequested;
    }

    public function reset(): void
    {
        $this->shutdownRequested = false;
        $this->reloadRequested = false;
        $this->handler->reset();
    }

    public function resetFlags(): void
    {
        $this->shutdownRequested = false;
        $this->reloadRequested = false;
    }
}

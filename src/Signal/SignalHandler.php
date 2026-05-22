<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Signal;

use Closure;

use function count;
use function defined;
use function function_exists;

use const SIGINT;
use const SIGTERM;
use const SIGUSR1;
use const SIGUSR2;
use const SIG_DFL;

final class SignalHandler
{
    /** @var array<int, array<Closure>> */
    private array $handlers = [];

    public function register(int $signal, Closure $handler): void
    {
        $needsInstall = false === isset($this->handlers[$signal]);

        if ($needsInstall) {
            $this->handlers[$signal] = [];
        }

        $this->handlers[$signal][] = $handler;

        if ($needsInstall) {
            $this->installSignal($signal);
        }
    }

    public function unregister(int $signal): void
    {
        unset($this->handlers[$signal]);
        pcntl_signal($signal, SIG_DFL);
    }

    public function dispatch(): void
    {
        pcntl_signal_dispatch();
    }

    public function reset(): void
    {
        foreach (array_keys($this->handlers) as $signal) {
            pcntl_signal($signal, SIG_DFL);
        }

        $this->handlers = [];
    }

    public function getRegisteredSignals(): array
    {
        $signals = [];
        foreach (array_keys($this->handlers) as $signal) {
            $signals[$signal] = count($this->handlers[$signal]);
        }
        return $signals;
    }

    public static function createDefault(): self
    {
        $handler = new self();

        $handler->register(SIGTERM, function (): void {});

        $handler->register(SIGINT, function (): void {});

        if (defined('SIGUSR1')) {
            $handler->register(SIGUSR1, function (): void {});
        }

        if (defined('SIGUSR2')) {
            $handler->register(SIGUSR2, function (): void {});
        }

        return $handler;
    }

    public function isSignalsSupported(): bool
    {
        return function_exists('pcntl_signal');
    }

    public function hasHandlers(int $signal): bool
    {
        return isset($this->handlers[$signal]) && $this->handlers[$signal] !== [];
    }

    private function installSignal(int $signal): void
    {
        pcntl_signal($signal, function (int $signo): void {
            if (false === isset($this->handlers[$signo])) {
                return;
            }

            foreach ($this->handlers[$signo] as $handler) {
                $handler($signo);
            }
        });
    }
}

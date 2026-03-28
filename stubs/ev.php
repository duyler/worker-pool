<?php

declare(strict_types=1);

/**
 * Stub for the ev extension (ext-ev) EvIo watcher class.
 *
 * @see https://www.php.net/manual/en/class.evio.php
 */
final class EvIo extends EvWatcher
{
    public function __construct(mixed $fd, int $events, callable $callback, mixed $data = null, int $priority = 0) {}

    public function set(mixed $fd, int $events): void {}

    public static function createStopped(mixed $fd, int $events, callable $callback, mixed $data = null, int $priority = 0): EvIo {}
}

/**
 * Stub for the ev extension (ext-ev) EvWatcher base class.
 *
 * @see https://www.php.net/manual/en/class.evwatcher.php
 */
class EvWatcher
{
    public function start(): void {}

    public function stop(): void {}

    public function feed(int $revents): void {}

    public function getLoop(): EvLoop {}

    public function invoke(int $revents): void {}

    public function clear(): void {}

    public function isPending(): bool {}

    public function isActive(): bool {}
}

/**
 * Stub for the ev extension (ext-ev) Ev class.
 *
 * @see https://www.php.net/manual/en/class.ev.php
 */
final class Ev
{
    public const READ = 1;
    public const WRITE = 2;
    public const TIMER = 4;
    public const SIGNAL = 8;
    public const STAT = 16;
    public const IDLE = 32;
    public const PREPARE = 64;
    public const CHECK = 128;
    public const FORK = 256;
    public const EMBED = 512;
    public const CHILD = 1024;
    public const RUN_NOWAIT = 1;
    public const RUN_ONCE = 2;
    public const BREAK_CANCEL = 1;
    public const BREAK_ALL = 2;
    public const MINPRI = 0;
    public const MAXPRI = -1;
    public const BACKEND_SELECT = 1;
    public const BACKEND_POLL = 2;
    public const BACKEND_EPOLL = 4;
    public const BACKEND_KQUEUE = 8;
    public const BACKEND_DEVPOLL = 16;
    public const BACKEND_PORT = 32;
    public const BACKEND_ALL = 0x3F;
    public const BACKEND_MASK = 0x3FFFFFFFFFF;

    public static function run(int $flags = 0): void {}

    public static function stop(int $how = self::BREAK_ALL): void {}

    public static function now(): float {}

    public static function sleep(float $seconds): void {}

    public static function supportedBackends(): int {}

    public static function recommendedBackends(): int {}

    public static function embeddableBackends(): int {}

    public static function time(): float {}

    public static function feedSignal(int $signum): void {}

    public static function clear(): void {}
}

/**
 * Stub for the ev extension (ext-ev) EvLoop class.
 *
 * @see https://www.php.net/manual/en/class.evloop.php
 */
final class EvLoop
{
    public const READ = 1;
    public const WRITE = 2;
    public const TIMER = 4;
    public const SIGNAL = 8;
    public const STAT = 16;
    public const IDLE = 32;
    public const PREPARE = 64;
    public const CHECK = 128;
    public const FORK = 256;
    public const EMBED = 512;
    public const CHILD = 1024;
    public const RUN_NOWAIT = 1;
    public const RUN_ONCE = 2;
    public const BREAK_CANCEL = 1;
    public const BREAK_ALL = 2;
    public const MINPRI = 0;
    public const MAXPRI = -1;
    public const BACKEND_SELECT = 1;
    public const BACKEND_POLL = 2;
    public const BACKEND_EPOLL = 4;
    public const BACKEND_KQUEUE = 8;
    public const BACKEND_DEVPOLL = 16;
    public const BACKEND_PORT = 32;
    public const BACKEND_ALL = 0x3F;
    public const BACKEND_MASK = 0x3FFFFFFFFFF;

    public function __construct(int $flags = 0, mixed $data = null, float $ioInterval = 0.0, float $timeoutInterval = 0.0) {}

    public function run(int $flags = 0): void {}

    public function stop(int $how = self::BREAK_ALL): void {}

    public function now(): float {}

    public function feedSignalEvent(int $signum): void {}

    public function io(mixed $fd, int $events, callable $callback, mixed $data = null, int $priority = 0): EvIo {}

    public static function defaultLoop(int $flags = 0, mixed $data = null, float $ioInterval = 0.0, float $timeoutInterval = 0.0): EvLoop {}
}

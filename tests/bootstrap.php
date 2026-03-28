<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

if (\function_exists('pcntl_signal')) {
    pcntl_signal(\SIGTERM, static function (): void {});
    pcntl_signal(\SIGINT, static function (): void {});
    pcntl_signal(\SIGHUP, static function (): void {});
    pcntl_async_signals(true);
}

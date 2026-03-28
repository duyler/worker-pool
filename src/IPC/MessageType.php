<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\IPC;

enum MessageType: string
{
    case ConnectionClosed = 'connection_closed';
    case WorkerReady = 'worker_ready';
    case WorkerMetrics = 'worker_metrics';
    case Shutdown = 'shutdown';
    case Reload = 'reload';
}

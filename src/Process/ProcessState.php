<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Process;

enum ProcessState: string
{
    case Starting = 'starting';
    case Ready = 'ready';
    case Busy = 'busy';
    case Stopping = 'stopping';
    case Stopped = 'stopped';
    case Failed = 'failed';
}

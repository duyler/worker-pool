<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Socket;

use Socket;

interface SocketMsgWrapperInterface
{
    /**
     * Send a message to a socket regardless of whether it is connection-oriented or not
     */
    public function sendmsg(Socket $socket, array $message, int $flags = 0): int|false;

    /**
     * Receive a message from a socket regardless of whether it is connection-oriented or not
     */
    public function recvmsg(Socket $socket, array &$message, int $flags = 0): int|false;

    /**
     * Calculate message buffer size for socket_sendmsg and socket_recvmsg
     */
    public function cmsgSpace(int $level, int $type, int $n = 0): ?int;
}

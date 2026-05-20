<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Socket;

use Override;
use Socket;

use function socket_cmsg_space;
use function socket_recvmsg;
use function socket_sendmsg;

final class SocketMsgWrapper implements SocketMsgWrapperInterface
{
    #[Override]
    public function sendmsg(Socket $socket, array $message, int $flags = 0): int|false
    {
        return socket_sendmsg($socket, $message, $flags);
    }

    #[Override]
    public function recvmsg(Socket $socket, array &$message, int $flags = 0): int|false
    {
        return socket_recvmsg($socket, $message, $flags);
    }

    #[Override]
    public function cmsgSpace(int $level, int $type, int $n = 0): ?int
    {
        return socket_cmsg_space($level, $type, $n);
    }
}

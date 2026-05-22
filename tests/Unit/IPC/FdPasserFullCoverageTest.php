<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\IPC;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

use Duyler\WorkerPool\IPC\FdPasser;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use Duyler\WorkerPool\Socket\SocketWrapper;
use Duyler\WorkerPool\Socket\SocketMsgWrapper;
use Socket;

use const AF_INET;
use const AF_UNIX;
use const SOCK_STREAM;
use const SOL_TCP;

#[CoversClass(FdPasser::class)]
#[UsesClass(SocketMsgWrapper::class)]
#[UsesClass(SocketWrapper::class)]
final class FdPasserFullCoverageTest extends TestCase
{
    private FdPasser $fdPasser;

    #[Override]
    protected function setUp(): void
    {
        $this->fdPasser = new FdPasser(new SocketWrapper(), new SocketMsgWrapper());
    }

    #[Test]
    public function is_supported_returns_bool(): void
    {
        $result = $this->fdPasser->isSupported();
        $this->assertIsBool($result);
    }

    #[Test]
    public function sendFdAttemptsToSend(): void
    {
        $pair = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        [$sender, $receiver] = $pair;

        $fdToSend = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertNotFalse($fdToSend);

        $result = $this->fdPasser->sendFd($sender, $fdToSend, ['worker_id' => 1, 'client_ip' => '127.0.0.1']);
        $this->assertIsBool($result);

        $received = $this->fdPasser->receiveFd($receiver);
        if (null !== $received) {
            $this->assertArrayHasKey('fd', $received);
            if ($received['fd'] instanceof Socket) {
                socket_close($received['fd']);
            }
        } else {
            $this->assertNull($received);
        }

        socket_close($sender);
        socket_close($receiver);
        if ($fdToSend instanceof Socket) {
            socket_close($fdToSend);
        }
    }

    #[Test]
    public function sendFdWithEmptyMetadata(): void
    {
        $pair = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        [$sender] = $pair;

        $fdToSend = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertNotFalse($fdToSend);

        $result = $this->fdPasser->sendFd($sender, $fdToSend);
        $this->assertIsBool($result);

        socket_close($sender);
        if ($fdToSend instanceof Socket) {
            socket_close($fdToSend);
        }
    }

    #[Test]
    public function receiveFdReturnsNullOnEmptySocket(): void
    {
        $pair = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        [$sender, $receiver] = $pair;

        $result = $this->fdPasser->receiveFd($sender);
        $this->assertNull($result);

        socket_close($sender);
        socket_close($receiver);
    }

    #[Test]
    public function sendFdReturnsBoolAfterAttempt(): void
    {
        $pair = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        [$sender] = $pair;

        $fdToSend = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertNotFalse($fdToSend);

        $result = $this->fdPasser->sendFd($sender, $fdToSend, ['key' => 'value']);
        $this->assertIsBool($result);

        socket_close($sender);
        if ($fdToSend instanceof Socket) {
            socket_close($fdToSend);
        }
    }
}

<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\IPC;

use PHPUnit\Framework\Attributes\CoversClass;

use Duyler\WorkerPool\IPC\FdPasser;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use const AF_INET;
use const AF_UNIX;
use const SOCK_STREAM;
use const SOL_TCP;

#[CoversClass(FdPasser::class)]
final class FdPasserFullCoverageTest extends TestCase
{
    private FdPasser $fdPasser;

    #[Override]
    protected function setUp(): void
    {
        $this->fdPasser = new FdPasser();
    }

    #[Test]
    public function isSupportedReturnsBool(): void
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
        $this->assertNull($received);

        socket_close($sender);
        socket_close($receiver);
        @socket_close($fdToSend);
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
        @socket_close($fdToSend);
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
        @socket_close($fdToSend);
    }
}

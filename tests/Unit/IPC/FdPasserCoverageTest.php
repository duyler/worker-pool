<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\IPC;

use PHPUnit\Framework\Attributes\CoversClass;

use Duyler\WorkerPool\IPC\FdPasser;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Socket;

use const AF_INET;
use const AF_UNIX;
use const SOCK_STREAM;
use const SOL_TCP;

#[CoversClass(FdPasser::class)]
final class FdPasserCoverageTest extends TestCase
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
    public function sendFdWithUnixSocketPair(): void
    {
        $sockets = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $sockets);
        [$sock1, $sock2] = $sockets;

        $fdToSend = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertNotFalse($fdToSend);

        $result = $this->fdPasser->sendFd($sock1, $fdToSend, ['test' => true]);

        $this->assertIsBool($result);

        if ($result) {
            $received = $this->fdPasser->receiveFd($sock2);
            if (null !== $received) {
                $this->assertArrayHasKey('fd', $received);
                $this->assertArrayHasKey('metadata', $received);
                $this->assertArrayHasKey('test', $received['metadata']);
            }
        }

        socket_close($sock1);
        socket_close($sock2);
        if ($fdToSend instanceof Socket) {
            @socket_close($fdToSend);
        }
    }

    #[Test]
    public function receiveFdReturnsNullOnEmptySocket(): void
    {
        $sockets = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $sockets);
        [$sock1, $sock2] = $sockets;

        $result = $this->fdPasser->receiveFd($sock1);
        $this->assertNull($result);

        socket_close($sock1);
        socket_close($sock2);
    }
}

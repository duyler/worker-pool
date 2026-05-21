<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\IPC;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

use Duyler\WorkerPool\Tests\Support\PlatformHelper;
use Duyler\WorkerPool\IPC\FdPasser;
use Exception;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Socket;

use Duyler\WorkerPool\Socket\SocketWrapper;
use Duyler\WorkerPool\Socket\SocketMsgWrapper;

use const AF_INET;
use const AF_UNIX;
use const SOCK_STREAM;
use const SOL_TCP;

#[CoversClass(FdPasser::class)]
class FdPasserTest extends TestCase
{
    #[Test]
    public function checks_scm_rights_support(): void
    {
        $passer = new FdPasser(new SocketWrapper(), new SocketMsgWrapper());

        $isSupported = $passer->isSupported();

        $this->assertIsBool($isSupported);
    }

    #[Test]
    public function sends_and_receives_fd(): void
    {
        $passer = new FdPasser(new SocketWrapper(), new SocketMsgWrapper());

        if (!PlatformHelper::supportsSCMRights()) {
            $this->markTestSkipped(
                'SCM_RIGHTS not supported on this platform. '
                . 'Requires Linux with socket_sendmsg/recvmsg and proper seccomp configuration.',
            );
        }

        $sockets = socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        $this->assertTrue($sockets);

        [$socket1, $socket2] = $pair;

        $testSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertInstanceOf(Socket::class, $testSocket);

        $metadata = [
            'connection_id' => 42,
            'client_ip' => '127.0.0.1',
        ];

        try {
            $sent = $passer->sendFd($socket1, $testSocket, $metadata);
        } catch (Exception $e) {
            $this->fail("Exception during sendFd: " . $e->getMessage());
        }

        if (!$sent) {
            $error = socket_strerror(socket_last_error($socket1));
            $this->fail("Failed to send FD: $error (sent result: " . var_export($sent, true) . ")");
        }
        $this->assertTrue($sent);

        usleep(10000);

        $received = $passer->receiveFd($socket2);
        $this->assertNotNull($received);
        $this->assertArrayHasKey('fd', $received);
        $this->assertArrayHasKey('metadata', $received);
        $this->assertInstanceOf(Socket::class, $received['fd']);
        $this->assertSame($metadata, $received['metadata']);

        socket_close($received['fd']);
        socket_close($testSocket);
        socket_close($socket1);
        socket_close($socket2);
    }

    #[Test]
    public function returns_null_when_no_fd_to_receive(): void
    {
        $passer = new FdPasser(new SocketWrapper(), new SocketMsgWrapper());

        if (!$passer->isSupported()) {
            $this->markTestSkipped(
                'SCM_RIGHTS not supported on this platform. '
                . 'Requires Linux with socket_sendmsg/recvmsg and proper seccomp configuration.',
            );
        }

        $sockets = socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        $this->assertTrue($sockets);

        [$socket1, $socket2] = $pair;

        socket_set_nonblock($socket2);

        $received = $passer->receiveFd($socket2);
        $this->assertNull($received);

        socket_close($socket1);
        socket_close($socket2);
    }

    #[Test]
    public function sends_fd_with_empty_metadata(): void
    {
        $passer = new FdPasser(new SocketWrapper(), new SocketMsgWrapper());

        if (!PlatformHelper::supportsSCMRights()) {
            $this->markTestSkipped(
                'SCM_RIGHTS not supported on this platform. '
                . 'Requires Linux with socket_sendmsg/recvmsg and proper seccomp configuration.',
            );
        }

        $sockets = socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        $this->assertTrue($sockets);

        [$socket1, $socket2] = $pair;

        $testSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertInstanceOf(Socket::class, $testSocket);

        $sent = $passer->sendFd($socket1, $testSocket);
        $this->assertTrue($sent);

        usleep(10000);

        $received = $passer->receiveFd($socket2);
        $this->assertNotNull($received);
        $this->assertSame([], $received['metadata']);

        socket_close($received['fd']);
        socket_close($testSocket);
        socket_close($socket1);
        socket_close($socket2);
    }

    #[Test]
    public function accepts_logger_via_constructor(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $passer = new FdPasser(new SocketWrapper(), new SocketMsgWrapper(), $logger);

        $this->assertIsBool($passer->isSupported());
    }
}

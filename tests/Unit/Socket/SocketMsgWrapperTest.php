<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\Socket;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

use Duyler\WorkerPool\Socket\SocketMsgWrapper;
use Duyler\WorkerPool\Socket\SocketMsgWrapperInterface;
use Duyler\WorkerPool\Tests\Support\PlatformHelper;
use Override;
use PHPUnit\Framework\TestCase;
use Socket;

use Error;
use ValueError;

use function function_exists;

use const AF_INET;
use const AF_UNIX;
use const E_WARNING;
use const SOCK_STREAM;

use const SOL_SOCKET;
use const SOL_TCP;
use const SCM_RIGHTS;

#[CoversClass(SocketMsgWrapper::class)]
class SocketMsgWrapperTest extends TestCase
{
    private SocketMsgWrapperInterface $wrapper;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->wrapper = new SocketMsgWrapper();
    }

    #[Test]
    public function sendmsg_sends_data_payload(): void
    {
        if (!function_exists('socket_sendmsg')) {
            $this->markTestSkipped('socket_sendmsg not available');
        }

        $pair = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        [$sock1, $sock2] = $pair;

        $message = [
            'iov' => ['hello world'],
        ];

        $sent = $this->wrapper->sendmsg($sock1, $message, 0);

        $this->assertNotFalse($sent);
        $this->assertGreaterThan(0, $sent);

        $data = socket_read($sock2, 1024);
        $this->assertSame('hello world', $data);

        socket_close($sock1);
        socket_close($sock2);
    }

    #[Test]
    public function recvmsg_receives_data_payload(): void
    {
        if (!function_exists('socket_recvmsg')) {
            $this->markTestSkipped('socket_recvmsg not available');
        }

        $pair = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        [$sock1, $sock2] = $pair;

        socket_write($sock1, 'test data');

        $message = [
            'iov' => [''],
            'controllen' => socket_cmsg_space(SOL_SOCKET, SCM_RIGHTS, 0) ?? 256,
        ];

        $result = $this->wrapper->recvmsg($sock2, $message, 0);

        $this->assertNotFalse($result);
        $this->assertGreaterThan(0, $result);
        $this->assertSame('test data', $message['iov'][0]);

        socket_close($sock1);
        socket_close($sock2);
    }

    #[Test]
    public function sendmsg_and_recvmsg_with_fd_passing(): void
    {
        if (!PlatformHelper::supportsSCMRights()) {
            $this->markTestSkipped('SCM_RIGHTS not supported on this platform');
        }

        $pair = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        [$sock1, $sock2] = $pair;

        $testSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertInstanceOf(Socket::class, $testSocket);

        $message = [
            'iov' => ['test_payload'],
            'control' => [
                [
                    'level' => SOL_SOCKET,
                    'type' => SCM_RIGHTS,
                    'data' => [$testSocket],
                ],
            ],
        ];

        $sent = $this->wrapper->sendmsg($sock1, $message, 0);
        $this->assertNotFalse($sent);

        usleep(10000);

        $recvMessage = [
            'iov' => [''],
            'control' => [],
            'controllen' => 256,
        ];

        $received = $this->wrapper->recvmsg($sock2, $recvMessage, 0);
        $this->assertNotFalse($received);

        socket_close($sock1);
        socket_close($sock2);
        socket_close($testSocket);

        if (isset($recvMessage['control'][0]['data'][0])) {
            $fd = $recvMessage['control'][0]['data'][0];
            if ($fd instanceof Socket) {
                socket_close($fd);
            }
        }
    }

    #[Test]
    public function sendmsg_throws_on_closed_socket(): void
    {
        if (!function_exists('socket_sendmsg')) {
            $this->markTestSkipped('socket_sendmsg not available');
        }

        $socket = socket_create(AF_UNIX, SOCK_STREAM, 0);
        socket_close($socket);

        $message = [
            'iov' => ['test'],
            'control' => [],
        ];

        $this->expectException(Error::class);

        $this->wrapper->sendmsg($socket, $message, 0);
    }

    #[Test]
    public function recvmsg_returns_false_when_no_data(): void
    {
        if (!function_exists('socket_recvmsg')) {
            $this->markTestSkipped('socket_recvmsg not available');
        }

        $pair = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        [$sock1, $sock2] = $pair;

        socket_set_nonblock($sock2);

        $message = [
            'iov' => [''],
            'control' => [],
            'controllen' => 256,
        ];

        set_error_handler(static fn(): bool => true, E_WARNING);
        try {
            $result = $this->wrapper->recvmsg($sock2, $message, 0);
        } finally {
            restore_error_handler();
        }

        $this->assertFalse($result);

        socket_close($sock1);
        socket_close($sock2);
    }

    #[Test]
    public function cmsg_space_returns_int_or_null(): void
    {
        if (!PlatformHelper::isLinux()) {
            $result = $this->wrapper->cmsgSpace(SOL_SOCKET, SCM_RIGHTS, 1);
            $this->assertNull($result);

            return;
        }

        $result = $this->wrapper->cmsgSpace(SOL_SOCKET, SCM_RIGHTS, 1);

        if (null === $result) {
            $this->assertNull($result);
        } else {
            $this->assertGreaterThan(0, $result);
        }
    }

    #[Test]
    public function cmsg_space_throws_on_invalid_args(): void
    {
        $this->expectException(ValueError::class);

        $this->wrapper->cmsgSpace(-1, -1);
    }
}

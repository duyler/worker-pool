<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\IPC;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

use Duyler\WorkerPool\Exception\IPCException;
use Duyler\WorkerPool\IPC\FdPasser;
use Duyler\WorkerPool\Socket\SocketMsgWrapperInterface;
use Duyler\WorkerPool\Socket\SocketWrapperInterface;
use Override;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

use Socket;

use function defined;
use function function_exists;

use const AF_INET;
use const SOL_SOCKET;
use const SOCK_STREAM;
use const SOL_TCP;
use const PHP_OS_FAMILY;

#[CoversClass(FdPasser::class)]
#[UsesClass(IPCException::class)]
final class FdPasserUnitTest extends TestCase
{
    private SocketWrapperInterface $socketWrapper;
    private SocketMsgWrapperInterface $socketMsgWrapper;
    private LoggerInterface $logger;
    private FdPasser $fdPasser;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->socketWrapper = $this->createStub(SocketWrapperInterface::class);
        $this->socketMsgWrapper = $this->createStub(SocketMsgWrapperInterface::class);
        $this->logger = $this->createStub(LoggerInterface::class);
        $this->fdPasser = new FdPasser($this->socketWrapper, $this->socketMsgWrapper, $this->logger);
    }

    #[Test]
    public function is_supported_returns_bool(): void
    {
        $result = $this->fdPasser->isSupported();

        if (PHP_OS_FAMILY === 'Linux') {
            $this->assertTrue($result);
        } else {
            $this->assertFalse($result);
        }
    }

    #[Test]
    public function send_fd_throws_when_sendmsg_not_available(): void
    {
        if (function_exists('socket_sendmsg')) {
            $this->markTestSkipped('socket_sendmsg is available');
        }

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $fdToSend = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        $this->expectException(IPCException::class);
        $this->expectExceptionMessage('socket_sendmsg() is not available');

        $this->fdPasser->sendFd($socket, $fdToSend);
    }

    #[Test]
    public function receive_fd_throws_when_recvmsg_not_available(): void
    {
        if (function_exists('socket_recvmsg')) {
            $this->markTestSkipped('socket_recvmsg is available');
        }

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        $this->expectException(IPCException::class);
        $this->expectExceptionMessage('socket_recvmsg() is not available');

        $this->fdPasser->receiveFd($socket);
    }

    #[Test]
    public function receive_fd_returns_null_when_recvmsg_returns_false(): void
    {
        if (!function_exists('socket_recvmsg') || !defined('SCM_RIGHTS')) {
            $this->markTestSkipped('SCM_RIGHTS not available');
        }

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        $this->socketMsgWrapper->method('cmsgSpace')->willReturn(256);
        $this->socketMsgWrapper->method('recvmsg')->willReturn(false);
        $this->socketWrapper->method('lastError')->willReturn(11);

        $this->assertNull($this->fdPasser->receiveFd($socket));
    }

    #[Test]
    public function receive_fd_returns_null_when_recvmsg_returns_zero(): void
    {
        if (!function_exists('socket_recvmsg') || !defined('SCM_RIGHTS')) {
            $this->markTestSkipped('SCM_RIGHTS not available');
        }

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        $this->socketMsgWrapper->method('cmsgSpace')->willReturn(256);
        $this->socketMsgWrapper->method('recvmsg')->willReturn(0);
        $this->socketWrapper->method('lastError')->willReturn(0);

        $this->assertNull($this->fdPasser->receiveFd($socket));
    }

    #[Test]
    public function receive_fd_returns_null_when_no_control_data(): void
    {
        if (!function_exists('socket_recvmsg') || !defined('SCM_RIGHTS')) {
            $this->markTestSkipped('SCM_RIGHTS not available');
        }

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        $this->socketMsgWrapper->method('cmsgSpace')->willReturn(256);
        $this->socketMsgWrapper->method('recvmsg')->willReturnCallback(function (Socket $s, array &$msg, int $flags): int {
            $msg = ['iov' => ['{}'], 'control' => []];
            return 10;
        });
        $this->socketWrapper->method('lastError')->willReturn(0);

        $this->assertNull($this->fdPasser->receiveFd($socket));
    }

    #[Test]
    public function receive_fd_returns_null_when_control_data_is_not_array(): void
    {
        if (!function_exists('socket_recvmsg') || !defined('SCM_RIGHTS')) {
            $this->markTestSkipped('SCM_RIGHTS not available');
        }

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        $this->socketMsgWrapper->method('cmsgSpace')->willReturn(256);
        $this->socketMsgWrapper->method('recvmsg')->willReturnCallback(function (Socket $s, array &$msg, int $flags): int {
            $msg = ['iov' => ['{}'], 'control' => ['not_array']];
            return 10;
        });
        $this->socketWrapper->method('lastError')->willReturn(0);

        $this->assertNull($this->fdPasser->receiveFd($socket));
    }

    #[Test]
    public function receive_fd_returns_null_when_no_data_key_in_control(): void
    {
        if (!function_exists('socket_recvmsg') || !defined('SCM_RIGHTS')) {
            $this->markTestSkipped('SCM_RIGHTS not available');
        }

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        $this->socketMsgWrapper->method('cmsgSpace')->willReturn(256);
        $this->socketMsgWrapper->method('recvmsg')->willReturnCallback(function (Socket $s, array &$msg, int $flags): int {
            $msg = ['iov' => ['{}'], 'control' => [['level' => SOL_SOCKET]]];
            return 10;
        });
        $this->socketWrapper->method('lastError')->willReturn(0);

        $this->assertNull($this->fdPasser->receiveFd($socket));
    }

    #[Test]
    public function receive_fd_returns_null_when_no_fd_in_control_data(): void
    {
        if (!function_exists('socket_recvmsg') || !defined('SCM_RIGHTS')) {
            $this->markTestSkipped('SCM_RIGHTS not available');
        }

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        $this->socketMsgWrapper->method('cmsgSpace')->willReturn(256);
        $this->socketMsgWrapper->method('recvmsg')->willReturnCallback(function (Socket $s, array &$msg, int $flags): int {
            $msg = ['iov' => ['{}'], 'control' => [['data' => []]]];
            return 10;
        });
        $this->socketWrapper->method('lastError')->willReturn(0);

        $this->assertNull($this->fdPasser->receiveFd($socket));
    }

    #[Test]
    public function receive_fd_returns_null_when_received_fd_is_invalid(): void
    {
        if (!function_exists('socket_recvmsg') || !defined('SCM_RIGHTS')) {
            $this->markTestSkipped('SCM_RIGHTS not available');
        }

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        $this->socketMsgWrapper->method('cmsgSpace')->willReturn(256);
        $this->socketMsgWrapper->method('recvmsg')->willReturnCallback(function (Socket $s, array &$msg, int $flags): int {
            $msg = ['iov' => ['{}'], 'control' => [['data' => ['not_a_socket']]]];
            return 10;
        });
        $this->socketWrapper->method('lastError')->willReturn(0);

        $this->assertNull($this->fdPasser->receiveFd($socket));
    }

    #[Test]
    public function receive_fd_returns_metadata_with_empty_json(): void
    {
        if (!function_exists('socket_recvmsg') || !defined('SCM_RIGHTS')) {
            $this->markTestSkipped('SCM_RIGHTS not available');
        }

        $controlSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $receivedSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        $this->socketMsgWrapper->method('cmsgSpace')->willReturn(256);
        $this->socketMsgWrapper->method('recvmsg')->willReturnCallback(function (Socket $s, array &$msg, int $flags) use ($receivedSocket): int {
            $msg = ['iov' => [''], 'control' => [['data' => [$receivedSocket]]]];
            return 10;
        });
        $this->socketWrapper->method('lastError')->willReturn(0);

        $result = $this->fdPasser->receiveFd($controlSocket);
        $this->assertNotNull($result);
        $this->assertSame($receivedSocket, $result['fd']);
        $this->assertSame([], $result['metadata']);
    }

    #[Test]
    public function receive_fd_parses_json_metadata(): void
    {
        if (!function_exists('socket_recvmsg') || !defined('SCM_RIGHTS')) {
            $this->markTestSkipped('SCM_RIGHTS not available');
        }

        $controlSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $receivedSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        $this->socketMsgWrapper->method('cmsgSpace')->willReturn(256);
        $this->socketMsgWrapper->method('recvmsg')->willReturnCallback(function (Socket $s, array &$msg, int $flags) use ($receivedSocket): int {
            $msg = ['iov' => ['{"worker_id":1}'], 'control' => [['data' => [$receivedSocket]]]];
            return 50;
        });
        $this->socketWrapper->method('lastError')->willReturn(0);

        $result = $this->fdPasser->receiveFd($controlSocket);
        $this->assertNotNull($result);
        $this->assertSame(['worker_id' => 1], $result['metadata']);
    }

    #[Test]
    public function receive_fd_handles_invalid_json(): void
    {
        if (!function_exists('socket_recvmsg') || !defined('SCM_RIGHTS')) {
            $this->markTestSkipped('SCM_RIGHTS not available');
        }

        $controlSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $receivedSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        $this->socketMsgWrapper->method('cmsgSpace')->willReturn(256);
        $this->socketMsgWrapper->method('recvmsg')->willReturnCallback(function (Socket $s, array &$msg, int $flags) use ($receivedSocket): int {
            $msg = ['iov' => ['{invalid}'], 'control' => [['data' => [$receivedSocket]]]];
            return 20;
        });
        $this->socketWrapper->method('lastError')->willReturn(0);

        $result = $this->fdPasser->receiveFd($controlSocket);
        $this->assertNotNull($result);
        $this->assertSame([], $result['metadata']);
    }

    #[Test]
    public function send_fd_throws_when_scm_rights_not_defined(): void
    {
        if (defined('SCM_RIGHTS')) {
            $this->markTestSkipped('SCM_RIGHTS is defined');
        }

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $fdToSend = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        $this->expectException(IPCException::class);
        $this->expectExceptionMessage('SCM_RIGHTS is not defined');

        $this->fdPasser->sendFd($socket, $fdToSend);
    }
}

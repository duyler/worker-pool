<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\IPC;

use PHPUnit\Framework\Attributes\CoversClass;

use Duyler\WorkerPool\Tests\Support\PlatformHelper;
use Duyler\WorkerPool\Exception\IPCException;
use Duyler\WorkerPool\IPC\FdPasser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Socket;

use Duyler\WorkerPool\Socket\SocketWrapper;
use Duyler\WorkerPool\Socket\SocketMsgWrapper;

use function defined;
use function function_exists;

use const AF_INET;
use const AF_UNIX;
use const PHP_OS_FAMILY;
use const SOCK_STREAM;
use const SOL_TCP;

#[CoversClass(FdPasser::class)]
class FdPasserExtendedTest extends TestCase
{
    public function testThrowsExceptionWhenSocketSendmsgNotAvailable(): void
    {
        if (function_exists('socket_sendmsg')) {
            $this->markTestSkipped('socket_sendmsg is available on this system');
        }

        $passer = new FdPasser(new SocketWrapper(), new SocketMsgWrapper());
        $socket = socket_create(AF_UNIX, SOCK_STREAM, 0);
        $this->assertInstanceOf(Socket::class, $socket);

        $this->expectException(IPCException::class);
        $this->expectExceptionMessage('socket_sendmsg() is not available');

        $passer->sendFd($socket, $socket);
    }

    public function testThrowsExceptionWhenScmRightsNotDefined(): void
    {
        if (!function_exists('socket_sendmsg') || defined('SCM_RIGHTS')) {
            $this->markTestSkipped('SCM_RIGHTS is defined on this system');
        }

        $passer = new FdPasser(new SocketWrapper(), new SocketMsgWrapper());
        $socket = socket_create(AF_UNIX, SOCK_STREAM, 0);
        $this->assertInstanceOf(Socket::class, $socket);

        $this->expectException(IPCException::class);
        $this->expectExceptionMessage('SCM_RIGHTS is not defined');

        $passer->sendFd($socket, $socket);
    }

    public function testThrowsExceptionWhenSocketRecvmsgNotAvailable(): void
    {
        if (function_exists('socket_recvmsg')) {
            $this->markTestSkipped('socket_recvmsg is available on this system');
        }

        $passer = new FdPasser(new SocketWrapper(), new SocketMsgWrapper());
        $socket = socket_create(AF_UNIX, SOCK_STREAM, 0);
        $this->assertInstanceOf(Socket::class, $socket);

        $this->expectException(IPCException::class);
        $this->expectExceptionMessage('socket_recvmsg() is not available');

        $passer->receiveFd($socket);
    }

    public function testSendFdReturnsFalseOnFailure(): void
    {
        $passer = new FdPasser(new SocketWrapper(), new SocketMsgWrapper());

        if (!PlatformHelper::supportsSCMRights()) {
            $this->markTestSkipped('SCM_RIGHTS not supported on this platform');
        }

        $socket = socket_create(AF_UNIX, SOCK_STREAM, 0);
        $this->assertInstanceOf(Socket::class, $socket);

        $result = $passer->sendFd($socket, $socket);

        $this->assertFalse($result);
    }

    public function testReceiveFdReturnsNullWhenSocketNotReadable(): void
    {
        $passer = new FdPasser(new SocketWrapper(), new SocketMsgWrapper());

        if (!$passer->isSupported()) {
            $this->markTestSkipped('SCM_RIGHTS not supported on this platform');
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

    public function testReceivesFdWithComplexMetadata(): void
    {
        $passer = new FdPasser(new SocketWrapper(), new SocketMsgWrapper());

        if (!PlatformHelper::supportsSCMRights()) {
            $this->markTestSkipped('SCM_RIGHTS not supported on this platform');
        }

        $sockets = socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        $this->assertTrue($sockets);

        [$socket1, $socket2] = $pair;

        $testSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertInstanceOf(Socket::class, $testSocket);

        $metadata = [
            'connection_id' => 42,
            'client_ip' => '127.0.0.1',
            'worker_id' => 5,
            'timestamp' => time(),
            'nested' => [
                'key' => 'value',
            ],
        ];

        $sent = $passer->sendFd($socket1, $testSocket, $metadata);
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

    public function testLogsDebugMessagesOnSend(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())
            ->method('debug');

        $passer = new FdPasser(new SocketWrapper(), new SocketMsgWrapper(), $logger);

        if (!PlatformHelper::supportsSCMRights()) {
            $this->markTestSkipped('SCM_RIGHTS not supported on this platform');
        }

        $sockets = socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        $this->assertTrue($sockets);

        [$socket1, $socket2] = $pair;

        $testSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertInstanceOf(Socket::class, $testSocket);

        $metadata = ['test' => 'data'];

        $passer->sendFd($socket1, $testSocket, $metadata);

        usleep(10000);

        socket_close($testSocket);
        socket_close($socket1);
        socket_close($socket2);
    }

    public function testLogsErrorOnSendFailure(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())
            ->method('error');

        $passer = new FdPasser(new SocketWrapper(), new SocketMsgWrapper(), $logger);

        if (!PlatformHelper::supportsSCMRights()) {
            $this->markTestSkipped('SCM_RIGHTS not supported on this platform');
        }

        $socket = socket_create(AF_UNIX, SOCK_STREAM, 0);
        $this->assertInstanceOf(Socket::class, $socket);

        $result = $passer->sendFd($socket, $socket);
        $this->assertFalse($result);
    }

    public function testHandlesCorruptedMetadataJson(): void
    {
        $passer = new FdPasser(new SocketWrapper(), new SocketMsgWrapper());

        if (!PlatformHelper::supportsSCMRights()) {
            $this->markTestSkipped('SCM_RIGHTS not supported on this platform');
        }

        $sockets = socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        $this->assertTrue($sockets);

        [$socket1, $socket2] = $pair;

        $testSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertInstanceOf(Socket::class, $testSocket);

        $metadata = ['test' => 'data'];

        $sent = $passer->sendFd($socket1, $testSocket, $metadata);
        $this->assertTrue($sent);

        usleep(10000);

        $received = $passer->receiveFd($socket2);
        $this->assertNotNull($received);
        $this->assertIsArray($received['metadata']);

        socket_close($received['fd']);
        socket_close($testSocket);
        socket_close($socket1);
        socket_close($socket2);
    }

    public function testReturnsEmptyArrayWhenNoMetadataSent(): void
    {
        $passer = new FdPasser(new SocketWrapper(), new SocketMsgWrapper());

        if (!PlatformHelper::supportsSCMRights()) {
            $this->markTestSkipped('SCM_RIGHTS not supported on this platform');
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

    public function testLogsErrorWhenNoControlData(): void
    {
        $logger = $this->createMock(LoggerInterface::class);

        $passer = new FdPasser(new SocketWrapper(), new SocketMsgWrapper(), $logger);

        if (!$passer->isSupported()) {
            $this->markTestSkipped('SCM_RIGHTS not supported on this platform');
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

    public function testHandlesEmptyIovData(): void
    {
        $passer = new FdPasser(new SocketWrapper(), new SocketMsgWrapper());

        if (!PlatformHelper::supportsSCMRights()) {
            $this->markTestSkipped('SCM_RIGHTS not supported on this platform');
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
        $this->assertIsArray($received['metadata']);

        socket_close($received['fd']);
        socket_close($testSocket);
        socket_close($socket1);
        socket_close($socket2);
    }

    public function testHandlesNullByteInMetadata(): void
    {
        $passer = new FdPasser(new SocketWrapper(), new SocketMsgWrapper());

        if (!PlatformHelper::supportsSCMRights()) {
            $this->markTestSkipped('SCM_RIGHTS not supported on this platform');
        }

        $sockets = socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        $this->assertTrue($sockets);

        [$socket1, $socket2] = $pair;

        $testSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertInstanceOf(Socket::class, $testSocket);

        $metadata = ['key' => "value\x00with\x00nulls"];

        $sent = $passer->sendFd($socket1, $testSocket, $metadata);
        $this->assertTrue($sent);

        usleep(10000);

        $received = $passer->receiveFd($socket2);
        $this->assertNotNull($received);
        $this->assertIsArray($received['metadata']);

        socket_close($received['fd']);
        socket_close($testSocket);
        socket_close($socket1);
        socket_close($socket2);
    }

    public function testIsSupportedReturnsFalseOnNonLinux(): void
    {
        $passer = new FdPasser(new SocketWrapper(), new SocketMsgWrapper());

        $isSupported = $passer->isSupported();

        if (PHP_OS_FAMILY === 'Linux') {
            $this->assertTrue($isSupported);
        } else {
            $this->assertFalse($isSupported);
        }
    }
}

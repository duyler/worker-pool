<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\IPC;

use Duyler\WorkerPool\Exception\IPCException;
use Duyler\WorkerPool\IPC\FdPasser;
use Duyler\WorkerPool\Socket\SocketMsgWrapper;
use Duyler\WorkerPool\Socket\SocketWrapper;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Duyler\WorkerPool\Socket\SocketMsgWrapperInterface;
use Duyler\WorkerPool\Socket\SocketWrapperInterface;

use function function_exists;

use const AF_INET;
use const AF_UNIX;
use const SOCK_STREAM;
use const SOL_TCP;
use const PHP_OS_FAMILY;

#[CoversClass(FdPasser::class)]
#[UsesClass(IPCException::class)]
#[UsesClass(SocketWrapper::class)]
#[UsesClass(SocketMsgWrapper::class)]
final class FdPasserRealTest extends TestCase
{
    private LoggerInterface $logger;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = $this->createStub(LoggerInterface::class);
    }

    #[Test]
    public function send_fd_success_with_real_sockets(): void
    {
        if (!function_exists('socket_sendmsg')) {
            $this->markTestSkipped('socket_sendmsg not available');
        }

        $pair = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        [$s1, $s2] = $pair;

        $fdToSend = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        $fdPasser = new FdPasser(new SocketWrapper(), new SocketMsgWrapper(), $this->logger);

        $result = $fdPasser->sendFd($s1, $fdToSend, ['worker_id' => 1, 'client_ip' => '127.0.0.1']);

        $this->assertTrue($result);
    }

    #[Test]
    public function send_fd_returns_false_on_failure(): void
    {
        if (!function_exists('socket_sendmsg')) {
            $this->markTestSkipped('socket_sendmsg not available');
        }

        $socketWrapper = $this->createStub(SocketWrapperInterface::class);
        $socketMsgWrapper = $this->createStub(SocketMsgWrapperInterface::class);
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $fdToSend = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        $socketMsgWrapper->method('sendmsg')->willReturn(false);
        $socketWrapper->method('lastError')->willReturn(9);
        $socketWrapper->method('strerror')->willReturn('Bad file descriptor');

        $fdPasser = new FdPasser($socketWrapper, $socketMsgWrapper, $this->logger);

        $result = $fdPasser->sendFd($socket, $fdToSend);

        $this->assertFalse($result);
    }

    #[Test]
    public function receive_fd_success_with_real_sockets(): void
    {
        if (!function_exists('socket_sendmsg')) {
            $this->markTestSkipped('socket_sendmsg not available');
        }

        $pair = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        [$s1, $s2] = $pair;

        $fdToSend = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        $fdPasser = new FdPasser(new SocketWrapper(), new SocketMsgWrapper(), $this->logger);

        $sent = $fdPasser->sendFd($s1, $fdToSend, ['worker_id' => 1]);
        $this->assertTrue($sent);

        $result = $fdPasser->receiveFd($s2);

        $this->assertNotNull($result);
        $this->assertArrayHasKey('fd', $result);
        $this->assertArrayHasKey('metadata', $result);
        $this->assertSame(['worker_id' => 1], $result['metadata']);
    }

    #[Test]
    public function send_fd_with_empty_metadata(): void
    {
        if (!function_exists('socket_sendmsg')) {
            $this->markTestSkipped('socket_sendmsg not available');
        }

        $pair = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        [$s1, $s2] = $pair;

        $fdToSend = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        $fdPasser = new FdPasser(new SocketWrapper(), new SocketMsgWrapper(), $this->logger);

        $result = $fdPasser->sendFd($s1, $fdToSend);

        $this->assertTrue($result);
    }

    #[Test]
    public function is_supported_returns_expected(): void
    {
        $fdPasser = new FdPasser(new SocketWrapper(), new SocketMsgWrapper(), $this->logger);

        if (PHP_OS_FAMILY === 'Linux') {
            $this->assertTrue($fdPasser->isSupported());
        } else {
            $this->assertFalse($fdPasser->isSupported());
        }
    }
}

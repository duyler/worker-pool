<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\Master;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

use Duyler\WorkerPool\Balancer\LeastConnectionsBalancer;
use Duyler\WorkerPool\IPC\FdPasser;
use Duyler\WorkerPool\Master\ConnectionRouter;
use Duyler\WorkerPool\Process\ForkWrapperInterface;
use Duyler\WorkerPool\Process\ProcessInfo;
use Duyler\WorkerPool\Process\ProcessState;
use Duyler\WorkerPool\Socket\SocketMsgWrapperInterface;
use Duyler\WorkerPool\Socket\SocketWrapperInterface;
use Override;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

use RuntimeException;

use function defined;
use function function_exists;

use const AF_INET;
use const SOCK_STREAM;
use const SOL_TCP;

#[CoversClass(ConnectionRouter::class)]
#[UsesClass(FdPasser::class)]
#[UsesClass(LeastConnectionsBalancer::class)]
#[UsesClass(ProcessInfo::class)]
#[AllowMockObjectsWithoutExpectations]
final class ConnectionRouterUnitTest extends TestCase
{
    private SocketWrapperInterface&MockObject $socketWrapper;
    private SocketMsgWrapperInterface&MockObject $socketMsgWrapper;
    private ForkWrapperInterface&MockObject $forkWrapper;
    private LeastConnectionsBalancer $balancer;
    private ConnectionRouter $router;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->socketWrapper = $this->createMock(SocketWrapperInterface::class);
        $this->socketMsgWrapper = $this->createMock(SocketMsgWrapperInterface::class);
        $this->forkWrapper = $this->createMock(ForkWrapperInterface::class);
        $this->balancer = new LeastConnectionsBalancer();
        $this->router = new ConnectionRouter(
            $this->socketWrapper,
            $this->balancer,
            new FdPasser($this->socketWrapper, $this->socketMsgWrapper, $this->createMock(LoggerInterface::class)),
            $this->createMock(LoggerInterface::class),
        );
    }

    #[Test]
    public function route_returns_false_when_no_workers(): void
    {
        $client = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->socketWrapper->expects($this->once())->method('close');

        $this->assertFalse($this->router->route($client, [], []));
    }

    #[Test]
    public function route_returns_false_when_worker_socket_missing(): void
    {
        $client = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->forkWrapper->method('kill')->willReturn(true);
        $this->socketWrapper->expects($this->once())->method('close');

        $worker = new ProcessInfo(workerId: 1, pid: 100, state: ProcessState::Ready, forkWrapper: $this->forkWrapper);

        $this->assertFalse($this->router->route($client, [1 => $worker], []));
    }

    #[Test]
    public function route_returns_false_for_dead_worker(): void
    {
        $client = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->forkWrapper->method('kill')->willReturn(false);
        $this->socketWrapper->expects($this->once())->method('close');

        $worker = new ProcessInfo(workerId: 1, pid: 100, state: ProcessState::Ready, forkWrapper: $this->forkWrapper);

        $this->assertFalse($this->router->route($client, [1 => $worker], []));
    }

    #[Test]
    public function route_returns_false_for_non_ready_worker(): void
    {
        $client = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->forkWrapper->method('kill')->willReturn(true);
        $this->socketWrapper->expects($this->once())->method('close');

        $worker = new ProcessInfo(workerId: 1, pid: 100, state: ProcessState::Stopped, forkWrapper: $this->forkWrapper);

        $this->assertFalse($this->router->route($client, [1 => $worker], []));
    }

    #[Test]
    public function get_balancer_returns_correct_instance(): void
    {
        $this->assertSame($this->balancer, $this->router->getBalancer());
    }

    #[Test]
    public function route_passes_fd_to_alive_worker(): void
    {
        if (!function_exists('socket_sendmsg') || !defined('SCM_RIGHTS')) {
            $this->markTestSkipped('SCM_RIGHTS not available');
        }

        $client = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $workerSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        $this->forkWrapper->method('kill')->willReturn(true);
        $this->socketWrapper->method('getPeerName')->willReturn(true);
        $this->socketMsgWrapper->method('sendmsg')->willReturn(10);

        $worker = new ProcessInfo(workerId: 1, pid: 100, state: ProcessState::Ready, forkWrapper: $this->forkWrapper);

        $this->assertTrue($this->router->route($client, [1 => $worker], [1 => $workerSocket]));
    }

    #[Test]
    public function route_returns_false_on_fd_pass_error(): void
    {
        if (!function_exists('socket_sendmsg') || !defined('SCM_RIGHTS')) {
            $this->markTestSkipped('SCM_RIGHTS not available');
        }

        $client = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $workerSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        $this->forkWrapper->method('kill')->willReturn(true);
        $this->socketWrapper->method('getPeerName')->willReturn(true);
        $this->socketMsgWrapper->method('sendmsg')->willThrowException(new RuntimeException('FD pass failed'));
        $this->socketWrapper->expects($this->once())->method('close');

        $worker = new ProcessInfo(workerId: 1, pid: 100, state: ProcessState::Ready, forkWrapper: $this->forkWrapper);

        $this->assertFalse($this->router->route($client, [1 => $worker], [1 => $workerSocket]));
    }
}

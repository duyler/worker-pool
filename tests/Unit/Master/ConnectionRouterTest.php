<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\Master;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\Test;

use Duyler\WorkerPool\Balancer\LeastConnectionsBalancer;
use Duyler\WorkerPool\Master\ConnectionRouter;
use Duyler\WorkerPool\Process\ProcessInfo;
use Duyler\WorkerPool\Process\ProcessState;
use Override;
use PHPUnit\Framework\TestCase;

use Duyler\WorkerPool\Process\ForkWrapper;
use Duyler\WorkerPool\Socket\SocketWrapper;
use Duyler\WorkerPool\Socket\SocketMsgWrapper;
use Duyler\WorkerPool\IPC\FdPasser;

use const AF_INET;
use const SOCK_STREAM;
use const SOL_TCP;

#[CoversClass(ConnectionRouter::class)]
#[UsesClass(LeastConnectionsBalancer::class)]
#[UsesClass(FdPasser::class)]
#[UsesClass(ForkWrapper::class)]
#[UsesClass(ProcessInfo::class)]
#[UsesClass(SocketWrapper::class)]
final class ConnectionRouterTest extends TestCase
{
    private ConnectionRouter $router;
    private LeastConnectionsBalancer $balancer;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->balancer = new LeastConnectionsBalancer();
        $this->router = new ConnectionRouter(new SocketWrapper(), $this->balancer, new FdPasser(new SocketWrapper(), new SocketMsgWrapper()));
    }

    #[Test]
    public function can_get_balancer(): void
    {
        $balancer = $this->router->getBalancer();

        $this->assertSame($this->balancer, $balancer);
    }

    #[Test]
    public function route_returns_false_when_no_workers_available(): void
    {
        $clientSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        if (false === $clientSocket) {
            $this->markTestSkipped('Cannot create socket');
        }

        $result = $this->router->route(
            clientSocket: $clientSocket,
            workers: [],
            workerSockets: [],
        );

        $this->assertFalse($result);
    }

    #[Test]
    public function route_returns_false_when_worker_socket_not_found(): void
    {
        $clientSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        if (false === $clientSocket) {
            $this->markTestSkipped('Cannot create socket');
        }

        $workers = [
            1 => new ProcessInfo(
                workerId: 1,
                pid: 12345,
                state: ProcessState::Ready,
                forkWrapper: new ForkWrapper(),
            ),
        ];

        $result = $this->router->route(
            clientSocket: $clientSocket,
            workers: $workers,
            workerSockets: [],
        );

        $this->assertFalse($result);
    }
}

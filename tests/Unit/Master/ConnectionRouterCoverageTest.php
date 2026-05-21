<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\Master;

use PHPUnit\Framework\Attributes\CoversClass;

use Duyler\WorkerPool\Balancer\LeastConnectionsBalancer;
use Duyler\WorkerPool\Master\ConnectionRouter;
use Duyler\WorkerPool\Process\ProcessInfo;
use Duyler\WorkerPool\Process\ProcessState;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Socket;

use Duyler\WorkerPool\Process\ForkWrapper;

use Duyler\WorkerPool\Socket\SocketWrapper;
use Duyler\WorkerPool\Socket\SocketMsgWrapper;
use Duyler\WorkerPool\IPC\FdPasser;

use function assert;

use const AF_INET;
use const SOCK_STREAM;
use const SOL_TCP;

#[CoversClass(ConnectionRouter::class)]
final class ConnectionRouterCoverageTest extends TestCase
{
    private ConnectionRouter $router;
    private LeastConnectionsBalancer $balancer;

    #[Override]
    protected function setUp(): void
    {
        $this->balancer = new LeastConnectionsBalancer();
        $this->router = new ConnectionRouter(new SocketWrapper(), $this->balancer, new FdPasser(new SocketWrapper(), new SocketMsgWrapper()));
    }

    #[Test]
    public function getBalancerReturnsCorrectInstance(): void
    {
        $this->assertSame($this->balancer, $this->router->getBalancer());
    }

    #[Test]
    public function routeReturnsFalseWhenNoWorkersAvailable(): void
    {
        $clientSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        assert($clientSocket instanceof Socket);

        $result = $this->router->route($clientSocket, [], []);

        $this->assertFalse($result);
    }

    #[Test]
    public function routeReturnsFalseWhenWorkerSocketMissing(): void
    {
        $clientSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        assert($clientSocket instanceof Socket);

        $workers = [
            1 => new ProcessInfo(1, 100, ProcessState::Ready, new ForkWrapper()),
        ];

        $result = $this->router->route($clientSocket, $workers, []);

        $this->assertFalse($result);
    }

    #[Test]
    public function routeReturnsFalseForDeadWorker(): void
    {
        $clientSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        assert($clientSocket instanceof Socket);

        $workers = [
            1 => new ProcessInfo(1, 100, ProcessState::Stopped, new ForkWrapper()),
        ];

        $workerSockets = [
            1 => socket_create(AF_INET, SOCK_STREAM, SOL_TCP),
        ];

        $result = $this->router->route($clientSocket, $workers, $workerSockets);

        $this->assertFalse($result);
    }
}

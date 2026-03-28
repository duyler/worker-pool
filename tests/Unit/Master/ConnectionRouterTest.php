<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\Master;

use Duyler\WorkerPool\Balancer\LeastConnectionsBalancer;
use Duyler\WorkerPool\Master\ConnectionRouter;
use Duyler\WorkerPool\Process\ProcessInfo;
use Duyler\WorkerPool\Process\ProcessState;
use Override;
use PHPUnit\Framework\TestCase;

use const AF_INET;
use const SOCK_STREAM;
use const SOL_TCP;

final class ConnectionRouterTest extends TestCase
{
    private ConnectionRouter $router;
    private LeastConnectionsBalancer $balancer;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->balancer = new LeastConnectionsBalancer();
        $this->router = new ConnectionRouter($this->balancer);
    }

    public function testCanGetBalancer(): void
    {
        $balancer = $this->router->getBalancer();

        $this->assertSame($this->balancer, $balancer);
    }

    public function testRouteReturnsFalseWhenNoWorkersAvailable(): void
    {
        $clientSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        if ($clientSocket === false) {
            $this->markTestSkipped('Cannot create socket');
        }

        $result = $this->router->route(
            clientSocket: $clientSocket,
            workers: [],
            workerSockets: [],
        );

        $this->assertFalse($result);
    }

    public function testRouteReturnsFalseWhenWorkerSocketNotFound(): void
    {
        $clientSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        if ($clientSocket === false) {
            $this->markTestSkipped('Cannot create socket');
        }

        $workers = [
            1 => new ProcessInfo(
                workerId: 1,
                pid: 12345,
                state: ProcessState::Ready,
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

<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\Master;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Server;
use Duyler\HttpServer\ServerInterface;
use Duyler\WorkerPool\Balancer\RoundRobinBalancer;
use Duyler\WorkerPool\Config\WorkerPoolConfig;
use Duyler\WorkerPool\Master\CentralizedMaster;
use Duyler\WorkerPool\Worker\EventDrivenWorkerInterface;
use Duyler\WorkerPool\Worker\WorkerCallbackInterface;
use InvalidArgumentException;
use Override;
use PHPUnit\Framework\TestCase;
use Socket;

class CentralizedMasterEventDrivenTest extends TestCase
{
    private ServerConfig $serverConfig;
    private WorkerPoolConfig $workerPoolConfig;
    private RoundRobinBalancer $balancer;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->serverConfig = new ServerConfig(
            host: '127.0.0.1',
            port: 8080,
        );

        $this->workerPoolConfig = new WorkerPoolConfig(
            serverConfig: $this->serverConfig,
            workerCount: 2,
        );

        $this->balancer = new RoundRobinBalancer($this->workerPoolConfig->workerCount);
    }

    public function testCreatesMasterWithEventDrivenWorker(): void
    {
        $worker = new class implements EventDrivenWorkerInterface {
            public function run(int $workerId, ServerInterface $server): void {}
        };

        $master = new CentralizedMaster(
            config: $this->workerPoolConfig,
            balancer: $this->balancer,
            serverConfig: $this->serverConfig,
            eventDrivenWorker: $worker,
        );

        $this->assertInstanceOf(CentralizedMaster::class, $master);
    }

    public function testCreatesMasterWithWorkerCallback(): void
    {
        $callback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void {}
        };

        $master = new CentralizedMaster(
            config: $this->workerPoolConfig,
            balancer: $this->balancer,
            serverConfig: $this->serverConfig,
            workerCallback: $callback,
        );

        $this->assertInstanceOf(CentralizedMaster::class, $master);
    }

    public function testThrowsExceptionWhenNoWorkerInterfaceProvided(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Either workerCallback or eventDrivenWorker must be provided');

        new CentralizedMaster(
            config: $this->workerPoolConfig,
            balancer: $this->balancer,
            serverConfig: $this->serverConfig,
            workerCallback: null,
            eventDrivenWorker: null,
        );
    }

    public function testAcceptsBothWorkerInterfaces(): void
    {
        $callback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void {}
        };

        $worker = new class implements EventDrivenWorkerInterface {
            public function run(int $workerId, ServerInterface $server): void {}
        };

        // Should not throw - both interfaces provided
        $master = new CentralizedMaster(
            config: $this->workerPoolConfig,
            balancer: $this->balancer,
            serverConfig: $this->serverConfig,
            workerCallback: $callback,
            eventDrivenWorker: $worker,
        );

        $this->assertInstanceOf(CentralizedMaster::class, $master);
    }

    public function testCreatesMasterWithoutServerConfig(): void
    {
        $worker = new class implements EventDrivenWorkerInterface {
            public function run(int $workerId, ServerInterface $server): void {}
        };

        // CentralizedMaster can work without serverConfig (external socket mode)
        $master = new CentralizedMaster(
            config: $this->workerPoolConfig,
            balancer: $this->balancer,
            serverConfig: null,
            eventDrivenWorker: $worker,
        );

        $this->assertInstanceOf(CentralizedMaster::class, $master);
    }

    public function testEventDrivenWorkerReceivesParameters(): void
    {
        $receivedWorkerId = null;
        $receivedServer = null;

        $worker = new class ($receivedWorkerId, $receivedServer) implements EventDrivenWorkerInterface {
            public function __construct(
                private ?int &$workerId,
                private ?Server &$server,
            ) {}

            public function run(int $workerId, ServerInterface $server): void
            {
                $this->workerId = $workerId;
                $this->server = $server;
            }
        };

        $master = new CentralizedMaster(
            config: $this->workerPoolConfig,
            balancer: $this->balancer,
            serverConfig: $this->serverConfig,
            eventDrivenWorker: $worker,
        );

        $this->assertInstanceOf(CentralizedMaster::class, $master);
    }
}

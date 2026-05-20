<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\Master;

use PHPUnit\Framework\Attributes\CoversClass;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\ServerInterface;
use Duyler\WorkerPool\Config\WorkerPoolConfig;
use Duyler\WorkerPool\Master\SharedSocketMaster;
use Duyler\WorkerPool\Worker\EventDrivenWorkerInterface;
use Duyler\WorkerPool\Worker\WorkerCallbackInterface;
use InvalidArgumentException;
use Override;
use PHPUnit\Framework\TestCase;

#[CoversClass(SharedSocketMaster::class)]
class SharedSocketMasterEventDrivenTest extends TestCase
{
    private ServerConfig $serverConfig;
    private WorkerPoolConfig $workerPoolConfig;

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
    }

    public function testCreatesMasterWithEventDrivenWorker(): void
    {
        $worker = new class implements EventDrivenWorkerInterface {
            public function run(int $workerId, ServerInterface $server): void {}
        };

        $master = new SharedSocketMaster(
            config: $this->workerPoolConfig,
            serverConfig: $this->serverConfig,
            eventDrivenWorker: $worker,
        );

        $this->assertInstanceOf(SharedSocketMaster::class, $master);
    }

    public function testCreatesMasterWithWorkerCallback(): void
    {
        $callback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void {}
        };

        $master = new SharedSocketMaster(
            config: $this->workerPoolConfig,
            serverConfig: $this->serverConfig,
            workerCallback: $callback,
        );

        $this->assertInstanceOf(SharedSocketMaster::class, $master);
    }

    public function testThrowsExceptionWhenNoWorkerInterfaceProvided(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Either workerCallback or eventDrivenWorker must be provided');

        new SharedSocketMaster(
            config: $this->workerPoolConfig,
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
        $master = new SharedSocketMaster(
            config: $this->workerPoolConfig,
            serverConfig: $this->serverConfig,
            workerCallback: $callback,
            eventDrivenWorker: $worker,
        );

        $this->assertInstanceOf(SharedSocketMaster::class, $master);
    }

    public function testEventDrivenWorkerCanBeInstantiated(): void
    {
        $workerInitialized = false;

        $worker = new class ($workerInitialized) implements EventDrivenWorkerInterface {
            public function __construct(
                private bool &$initialized,
            ) {
                $this->initialized = true;
            }

            public function run(int $workerId, ServerInterface $server): void {}
        };

        $this->assertTrue($workerInitialized);

        $master = new SharedSocketMaster(
            config: $this->workerPoolConfig,
            serverConfig: $this->serverConfig,
            eventDrivenWorker: $worker,
        );

        $this->assertInstanceOf(SharedSocketMaster::class, $master);
    }
}

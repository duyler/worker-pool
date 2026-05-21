<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\Master;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\ServerInterface;
use Duyler\WorkerPool\Config\WorkerPoolConfig;
use Duyler\WorkerPool\Master\SharedSocketMaster;
use Duyler\WorkerPool\Process\ForkWrapper;
use Duyler\WorkerPool\Socket\SocketWrapper;
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
    private SocketWrapper $socketWrapper;
    private ForkWrapper $forkWrapper;

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

        $this->socketWrapper = new SocketWrapper();
        $this->forkWrapper = new ForkWrapper();
    }

    #[Test]
    public function creates_master_with_event_driven_worker(): void
    {
        $worker = new class implements EventDrivenWorkerInterface {
            public function run(int $workerId, ServerInterface $server): void {}
        };

        $master = new SharedSocketMaster(
            config: $this->workerPoolConfig,
            serverConfig: $this->serverConfig,
            socketWrapper: $this->socketWrapper,
            forkWrapper: $this->forkWrapper,
            eventDrivenWorker: $worker,
        );

        $this->assertInstanceOf(SharedSocketMaster::class, $master);
    }

    #[Test]
    public function creates_master_with_worker_callback(): void
    {
        $callback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void {}
        };

        $master = new SharedSocketMaster(
            config: $this->workerPoolConfig,
            serverConfig: $this->serverConfig,
            socketWrapper: $this->socketWrapper,
            forkWrapper: $this->forkWrapper,
            workerCallback: $callback,
        );

        $this->assertInstanceOf(SharedSocketMaster::class, $master);
    }

    #[Test]
    public function throws_exception_when_no_worker_interface_provided(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Either workerCallback or eventDrivenWorker must be provided');

        new SharedSocketMaster(
            config: $this->workerPoolConfig,
            serverConfig: $this->serverConfig,
            socketWrapper: $this->socketWrapper,
            forkWrapper: $this->forkWrapper,
            workerCallback: null,
            eventDrivenWorker: null,
        );
    }

    #[Test]
    public function accepts_both_worker_interfaces(): void
    {
        $callback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void {}
        };

        $worker = new class implements EventDrivenWorkerInterface {
            public function run(int $workerId, ServerInterface $server): void {}
        };

        $master = new SharedSocketMaster(
            config: $this->workerPoolConfig,
            serverConfig: $this->serverConfig,
            socketWrapper: $this->socketWrapper,
            forkWrapper: $this->forkWrapper,
            workerCallback: $callback,
            eventDrivenWorker: $worker,
        );

        $this->assertInstanceOf(SharedSocketMaster::class, $master);
    }

    #[Test]
    public function event_driven_worker_can_be_instantiated(): void
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
            socketWrapper: $this->socketWrapper,
            forkWrapper: $this->forkWrapper,
            eventDrivenWorker: $worker,
        );

        $this->assertInstanceOf(SharedSocketMaster::class, $master);
    }
}

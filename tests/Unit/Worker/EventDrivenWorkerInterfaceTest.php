<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\Worker;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\ErrorHandler\ErrorHandler;
use Duyler\HttpServer\Server;
use Duyler\HttpServer\ServerInterface;
use Duyler\WorkerPool\Worker\EventDrivenWorkerInterface;
use Nyholm\Psr7\Response;
use Override;
use PHPUnit\Framework\TestCase;
use Throwable;
use Psr\Log\NullLogger;

#[CoversNothing]
class EventDrivenWorkerInterfaceTest extends TestCase
{
    private ?Server $server = null;

    #[Override]
    protected function tearDown(): void
    {
        if (null !== $this->server) {
            try {
                $this->server->stop();
                $this->server->reset();
            } catch (Throwable) {
            }
        }
        (new ErrorHandler(new NullLogger()))->reset();
        parent::tearDown();
    }

    #[Test]
    public function implements_event_driven_worker_interface(): void
    {
        $worker = new class implements EventDrivenWorkerInterface {
            public bool $runCalled = false;
            public ?int $receivedWorkerId = null;
            public ?Server $receivedServer = null;

            public function run(int $workerId, ServerInterface $server): void
            {
                $this->runCalled = true;
                $this->receivedWorkerId = $workerId;
                $this->receivedServer = $server;
            }
        };

        $config = new ServerConfig(host: '127.0.0.1', port: 8080);
        $this->server = new Server($config);

        $worker->run(42, $this->server);

        $this->assertTrue($worker->runCalled);
        $this->assertSame(42, $worker->receivedWorkerId);
        $this->assertSame($this->server, $worker->receivedServer);
    }

    #[Test]
    public function worker_can_check_has_request(): void
    {
        $hasRequestCalled = false;

        $worker = new class ($hasRequestCalled) implements EventDrivenWorkerInterface {
            public function __construct(
                private bool &$hasRequestCalled,
            ) {}

            public function run(int $workerId, ServerInterface $server): void
            {
                // NOTE: Do NOT call $server->start() in Worker Pool mode!
                // Server is marked as running when setWorkerId() is called.
                $this->hasRequestCalled = $server->hasRequest();
            }
        };

        $config = new ServerConfig(host: '127.0.0.1', port: 8081);
        $this->server = new Server($config);
        $this->server->setWorkerId(1); // This marks server as running

        $worker->run(1, $this->server);

        $this->assertFalse($hasRequestCalled);
    }

    #[Test]
    public function worker_receives_correct_worker_id(): void
    {
        $receivedIds = [];

        $worker = new class ($receivedIds) implements EventDrivenWorkerInterface {
            public function __construct(
                private array &$receivedIds,
            ) {}

            public function run(int $workerId, ServerInterface $server): void
            {
                $this->receivedIds[] = $workerId;
            }
        };

        $config = new ServerConfig(host: '127.0.0.1', port: 8082);

        // Simulate multiple workers
        for ($i = 1; $i <= 5; $i++) {
            if (null !== $this->server) {
                $this->server->reset();
            }
            $this->server = new Server($config);
            $worker->run($i, $this->server);
        }

        $this->assertSame([1, 2, 3, 4, 5], $receivedIds);
    }

    #[Test]
    public function worker_can_interact_with_server(): void
    {
        $serverMode = null;
        $workerId = null;

        $worker = new class ($serverMode, $workerId) implements EventDrivenWorkerInterface {
            public function __construct(
                private mixed &$serverMode,
                private mixed &$workerId,
            ) {}

            public function run(int $workerId, ServerInterface $server): void
            {
                $this->serverMode = $server->getMode()->value;
                $this->workerId = $server->getWorkerId();
            }
        };

        $config = new ServerConfig(host: '127.0.0.1', port: 8083);
        $this->server = new Server($config);
        $this->server->setWorkerId(1);

        $worker->run(1, $this->server);

        $this->assertSame('worker_pool', $serverMode);
        $this->assertSame(1, $workerId);
    }

    #[Test]
    public function worker_can_handle_request_response_cycle(): void
    {
        $requestHandled = false;

        $worker = new class ($requestHandled) implements EventDrivenWorkerInterface {
            public function __construct(
                private bool &$requestHandled,
            ) {}

            public function run(int $workerId, ServerInterface $server): void
            {
                // Just verify we can call server methods without errors
                // NOTE: Do NOT call $server->start() in Worker Pool mode!

                // Check for requests (none expected in this test)
                $hasRequest = $server->hasRequest();

                if ($hasRequest) {
                    $request = $server->getRequest();
                    if ($request !== null) {
                        $response = new Response(200, [], 'OK');
                        $server->respond($response);
                        $this->requestHandled = true;
                    }
                }
            }
        };

        $config = new ServerConfig(host: '127.0.0.1', port: 8084);
        $this->server = new Server($config);
        $this->server->setWorkerId(1); // Mark as running in Worker Pool mode

        $worker->run(1, $this->server);

        // Request was not handled because we didn't send any
        // This is just testing the interface works correctly
        $this->assertFalse($requestHandled);
    }

    #[Test]
    public function multiple_workers_can_be_created(): void
    {
        $workerCount = 4;
        $workers = [];

        for ($i = 0; $i < $workerCount; $i++) {
            $workers[] = new class implements EventDrivenWorkerInterface {
                public bool $initialized = false;

                public function run(int $workerId, ServerInterface $server): void
                {
                    $this->initialized = true;
                }
            };
        }

        $config = new ServerConfig(host: '127.0.0.1', port: 8085);

        foreach ($workers as $index => $worker) {
            if (null !== $this->server) {
                $this->server->reset();
            }
            $this->server = new Server($config);
            $worker->run($index + 1, $this->server);
        }

        foreach ($workers as $worker) {
            $this->assertTrue($worker->initialized);
        }
    }
}

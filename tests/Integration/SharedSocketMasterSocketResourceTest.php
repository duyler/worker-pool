<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Integration;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\ErrorHandler\ErrorHandler;
use Duyler\HttpServer\ServerInterface;
use Duyler\WorkerPool\Config\WorkerPoolConfig;
use Duyler\WorkerPool\Master\SharedSocketMaster;
use Duyler\WorkerPool\Process\ForkWrapper;
use Duyler\WorkerPool\Socket\SocketWrapper;
use Duyler\WorkerPool\Worker\EventDrivenWorkerInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Duyler\WorkerPool\Master\WorkerManager;
use Duyler\WorkerPool\Signal\SignalHandler;
use Duyler\WorkerPool\Signal\SignalManager;

#[CoversClass(SharedSocketMaster::class)]
#[UsesClass(WorkerPoolConfig::class)]
#[UsesClass(WorkerManager::class)]
#[UsesClass(SignalHandler::class)]
#[UsesClass(SignalManager::class)]
class SharedSocketMasterSocketResourceTest extends TestCase
{
    private SocketWrapper $socketWrapper;
    private ForkWrapper $forkWrapper;

    #[Override]
    protected function setUp(): void
    {
        $this->socketWrapper = new SocketWrapper();
        $this->forkWrapper = new ForkWrapper();
    }

    #[Override]
    protected function tearDown(): void
    {
        new ErrorHandler(new NullLogger())->reset();
        parent::tearDown();
    }

    #[Test]
    public function server_receives_socket_resource_from_master(): void
    {
        $socketResource = null;

        $testWorker = new class ($socketResource) implements EventDrivenWorkerInterface {
            private static mixed $resource = null;

            public function __construct(mixed &$resource)
            {
                self::$resource = &$resource;
            }

            public function run(int $workerId, ServerInterface $server): void
            {
                self::$resource = $server->getSocketResource();
            }

            public static function getResource(): mixed
            {
                return self::$resource;
            }
        };

        $serverConfig = new ServerConfig(
            host: '127.0.0.1',
            port: 19080,
        );

        $workerPoolConfig = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 1,
        );

        $master = new SharedSocketMaster(
            config: $workerPoolConfig,
            serverConfig: $serverConfig,
            socketWrapper: $this->socketWrapper,
            forkWrapper: $this->forkWrapper,
            eventDrivenWorker: $testWorker,
        );

        $this->assertInstanceOf(SharedSocketMaster::class, $master);
    }
}

<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Integration;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\ErrorHandler;
use Duyler\HttpServer\ServerInterface;
use Duyler\WorkerPool\Config\WorkerPoolConfig;
use Duyler\WorkerPool\Master\SharedSocketMaster;
use Duyler\WorkerPool\Worker\EventDrivenWorkerInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SharedSocketMaster::class)]
class SharedSocketMasterSocketResourceTest extends TestCase
{
    #[Override]
    protected function tearDown(): void
    {
        ErrorHandler::reset();
        parent::tearDown();
    }

    public function testServerReceivesSocketResourceFromMaster(): void
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
            eventDrivenWorker: $testWorker,
        );

        $this->assertInstanceOf(SharedSocketMaster::class, $master);
    }
}

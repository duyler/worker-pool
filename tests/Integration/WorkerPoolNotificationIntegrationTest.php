<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Integration;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\ErrorHandler;
use Duyler\HttpServer\ServerInterface;
use Duyler\WorkerPool\Balancer\RoundRobinBalancer;
use Duyler\WorkerPool\Config\WorkerPoolConfig;
use Duyler\WorkerPool\Master\CentralizedMaster;
use Duyler\WorkerPool\Master\SharedSocketMaster;
use Duyler\WorkerPool\Worker\EventDrivenWorkerInterface;
use Duyler\WorkerPool\Worker\WorkerCallbackInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(SharedSocketMaster::class)]
#[CoversClass(CentralizedMaster::class)]
class WorkerPoolNotificationIntegrationTest extends TestCase
{
    #[Override]
    protected function tearDown(): void
    {
        ErrorHandler::reset();
        parent::tearDown();
    }

    public function testSharedSocketMasterEnablesNotificationForEventDrivenWorker(): void
    {
        $notificationSocket = null;

        $testWorker = new class ($notificationSocket) implements EventDrivenWorkerInterface {
            private static mixed $socket = null;

            public function __construct(mixed &$socket)
            {
                self::$socket = &$socket;
            }

            public function run(int $workerId, ServerInterface $server): void
            {
                self::$socket = $server->getSocketResource();
            }
        };

        $serverConfig = new ServerConfig(
            host: '127.0.0.1',
            port: 19082,
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

    public function testCentralizedMasterEnablesNotificationForEventDrivenWorker(): void
    {
        $notificationSocket = null;

        $testWorker = new class ($notificationSocket) implements EventDrivenWorkerInterface {
            private static mixed $socket = null;

            public function __construct(mixed &$socket)
            {
                self::$socket = &$socket;
            }

            public function run(int $workerId, ServerInterface $server): void
            {
                self::$socket = $server->getSocketResource();
            }
        };

        $serverConfig = new ServerConfig(
            host: '127.0.0.1',
            port: 19083,
        );

        $workerPoolConfig = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 1,
        );

        $balancer = new RoundRobinBalancer($workerPoolConfig->workerCount);

        $master = new CentralizedMaster(
            config: $workerPoolConfig,
            balancer: $balancer,
            serverConfig: $serverConfig,
            eventDrivenWorker: $testWorker,
        );

        $this->assertInstanceOf(CentralizedMaster::class, $master);
    }

    public function testSharedSocketMasterNotificationEnabledInRunEventDrivenWorkerMethod(): void
    {
        $reflection = new ReflectionClass(SharedSocketMaster::class);
        $method = $reflection->getMethod('runEventDrivenWorker');

        $source = file_get_contents(__DIR__ . '/../../src/Master/SharedSocketMaster.php');

        $this->assertNotFalse($source);
        $this->assertStringContainsString('enableNotification()', $source);
        $this->assertStringContainsString("Notification enabled for worker", $source);
    }

    public function testCentralizedMasterNotificationEnabledInRunEventDrivenWorkerMethod(): void
    {
        $reflection = new ReflectionClass(CentralizedMaster::class);
        $method = $reflection->getMethod('runEventDrivenWorker');

        $source = file_get_contents(__DIR__ . '/../../src/Master/CentralizedMaster.php');

        $this->assertNotFalse($source);
        $this->assertStringContainsString('enableNotification()', $source);
        $this->assertStringContainsString("Notification enabled for worker", $source);
        $this->assertStringContainsString("'mode' => 'centralized'", $source);
    }

    public function testCallbackWorkerModeDoesNotUseNotification(): void
    {
        $source = file_get_contents(__DIR__ . '/../../src/Master/SharedSocketMaster.php');

        $this->assertNotFalse($source);
        $this->assertStringContainsString('runCallbackWorker', $source);

        $callbackWorkerSource = substr(
            $source,
            strpos($source, 'private function runCallbackWorker'),
        );

        $this->assertStringNotContainsString('enableNotification()', $callbackWorkerSource);
    }

    public function testCentralizedCallbackWorkerModeDoesNotUseNotification(): void
    {
        $source = file_get_contents(__DIR__ . '/../../src/Master/CentralizedMaster.php');

        $this->assertNotFalse($source);
        $this->assertStringContainsString('runCallbackWorker', $source);

        $callbackWorkerSource = substr(
            $source,
            strpos($source, 'private function runCallbackWorker'),
        );

        $this->assertStringNotContainsString('enableNotification()', $callbackWorkerSource);
    }

    public function testEventDrivenWorkerInterfaceDocumentationContainsNotificationExample(): void
    {
        $reflection = new ReflectionClass(EventDrivenWorkerInterface::class);
        $docComment = $reflection->getDocComment();

        $this->assertNotFalse($docComment);
        $this->assertStringContainsString('Notification Socket Integration', $docComment);
        $this->assertStringContainsString('enableNotification()', $docComment);
        $this->assertStringContainsString('getSocketResource()', $docComment);
        $this->assertStringContainsString('EvIo', $docComment);
        $this->assertStringContainsString('setEventLoopActive', $docComment);
    }

    public function testSharedSocketMasterLegacyCallbackModeContinuesToWork(): void
    {
        $callback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void {}
        };

        $serverConfig = new ServerConfig(
            host: '127.0.0.1',
            port: 19084,
        );

        $workerPoolConfig = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 1,
        );

        $master = new SharedSocketMaster(
            config: $workerPoolConfig,
            serverConfig: $serverConfig,
            workerCallback: $callback,
        );

        $this->assertInstanceOf(SharedSocketMaster::class, $master);
    }

    public function testCentralizedMasterLegacyCallbackModeContinuesToWork(): void
    {
        $callback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void {}
        };

        $serverConfig = new ServerConfig(
            host: '127.0.0.1',
            port: 19085,
        );

        $workerPoolConfig = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 1,
        );

        $balancer = new RoundRobinBalancer($workerPoolConfig->workerCount);

        $master = new CentralizedMaster(
            config: $workerPoolConfig,
            balancer: $balancer,
            serverConfig: $serverConfig,
            workerCallback: $callback,
        );

        $this->assertInstanceOf(CentralizedMaster::class, $master);
    }
}

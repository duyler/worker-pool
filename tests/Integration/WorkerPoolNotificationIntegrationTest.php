<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Integration;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\ErrorHandler\ErrorHandler;
use Duyler\HttpServer\ServerInterface;
use Duyler\WorkerPool\Balancer\RoundRobinBalancer;
use Duyler\WorkerPool\Config\WorkerPoolConfig;
use Duyler\WorkerPool\Master\CentralizedMaster;
use Duyler\WorkerPool\Master\SharedSocketMaster;
use Duyler\WorkerPool\Process\ForkWrapper;
use Duyler\WorkerPool\Socket\SocketMsgWrapper;
use Duyler\WorkerPool\Socket\SocketWrapper;
use Duyler\WorkerPool\Worker\EventDrivenWorkerInterface;
use Duyler\WorkerPool\Worker\WorkerCallbackInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Psr\Log\NullLogger;
use Duyler\WorkerPool\IPC\FdPasser;
use Duyler\WorkerPool\Master\ConnectionQueue;
use Duyler\WorkerPool\Master\ConnectionRouter;
use Duyler\WorkerPool\Master\SocketManager;
use Duyler\WorkerPool\Master\WorkerManager;
use Duyler\WorkerPool\Signal\SignalHandler;
use Duyler\WorkerPool\Signal\SignalManager;

#[CoversClass(SharedSocketMaster::class)]
#[CoversClass(CentralizedMaster::class)]
#[UsesClass(WorkerPoolConfig::class)]
#[UsesClass(FdPasser::class)]
#[UsesClass(ConnectionQueue::class)]
#[UsesClass(ConnectionRouter::class)]
#[UsesClass(SocketManager::class)]
#[UsesClass(WorkerManager::class)]
#[UsesClass(SignalHandler::class)]
#[UsesClass(SignalManager::class)]
class WorkerPoolNotificationIntegrationTest extends TestCase
{
    private SocketWrapper $socketWrapper;
    private SocketMsgWrapper $socketMsgWrapper;
    private ForkWrapper $forkWrapper;

    #[Override]
    protected function setUp(): void
    {
        $this->socketWrapper = new SocketWrapper();
        $this->socketMsgWrapper = new SocketMsgWrapper();
        $this->forkWrapper = new ForkWrapper();
    }

    #[Override]
    protected function tearDown(): void
    {
        (new ErrorHandler(new NullLogger()))->reset();
        parent::tearDown();
    }

    #[Test]
    public function shared_socket_master_enables_notification_for_event_driven_worker(): void
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
            socketWrapper: $this->socketWrapper,
            forkWrapper: $this->forkWrapper,
            eventDrivenWorker: $testWorker,
        );

        $this->assertInstanceOf(SharedSocketMaster::class, $master);
    }

    #[Test]
    public function centralized_master_enables_notification_for_event_driven_worker(): void
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
            socketWrapper: $this->socketWrapper,
            socketMsgWrapper: $this->socketMsgWrapper,
            forkWrapper: $this->forkWrapper,
            serverConfig: $serverConfig,
            eventDrivenWorker: $testWorker,
        );

        $this->assertInstanceOf(CentralizedMaster::class, $master);
    }

    #[Test]
    public function shared_socket_master_notification_enabled_in_run_event_driven_worker_method(): void
    {
        $reflection = new ReflectionClass(SharedSocketMaster::class);
        $reflection->getMethod('runEventDrivenWorker');

        $source = file_get_contents(__DIR__ . '/../../src/Master/SharedSocketMaster.php');

        $this->assertNotFalse($source);
        $this->assertStringContainsString('enableNotification()', $source);
        $this->assertStringContainsString("Notification enabled for worker", $source);
    }

    #[Test]
    public function centralized_master_notification_enabled_in_run_event_driven_worker_method(): void
    {
        $reflection = new ReflectionClass(CentralizedMaster::class);
        $reflection->getMethod('runEventDrivenWorker');

        $source = file_get_contents(__DIR__ . '/../../src/Master/CentralizedMaster.php');

        $this->assertNotFalse($source);
        $this->assertStringContainsString('enableNotification()', $source);
        $this->assertStringContainsString("Notification enabled for worker", $source);
        $this->assertStringContainsString("'mode' => 'centralized'", $source);
    }

    #[Test]
    public function callback_worker_mode_does_not_use_notification(): void
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

    #[Test]
    public function centralized_callback_worker_mode_does_not_use_notification(): void
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

    #[Test]
    public function event_driven_worker_interface_documentation_contains_notification_example(): void
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

    #[Test]
    public function shared_socket_master_legacy_callback_mode_continues_to_work(): void
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
            socketWrapper: $this->socketWrapper,
            forkWrapper: $this->forkWrapper,
            workerCallback: $callback,
        );

        $this->assertInstanceOf(SharedSocketMaster::class, $master);
    }

    #[Test]
    public function centralized_master_legacy_callback_mode_continues_to_work(): void
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
            socketWrapper: $this->socketWrapper,
            socketMsgWrapper: $this->socketMsgWrapper,
            forkWrapper: $this->forkWrapper,
            serverConfig: $serverConfig,
            workerCallback: $callback,
        );

        $this->assertInstanceOf(CentralizedMaster::class, $master);
    }
}

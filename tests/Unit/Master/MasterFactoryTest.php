<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\Master;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\Test;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\ServerInterface;
use Duyler\WorkerPool\Balancer\LeastConnectionsBalancer;
use Duyler\WorkerPool\Config\WorkerPoolConfig;
use Duyler\WorkerPool\Master\CentralizedMaster;
use Duyler\WorkerPool\Master\MasterFactory;
use Duyler\WorkerPool\Master\MasterInterface;
use Duyler\WorkerPool\Master\SharedSocketMaster;
use Duyler\WorkerPool\Worker\EventDrivenWorkerInterface;
use Duyler\WorkerPool\Worker\WorkerCallbackInterface;
use InvalidArgumentException;
use Override;
use PHPUnit\Framework\TestCase;

use Duyler\WorkerPool\IPC\FdPasser;
use Duyler\WorkerPool\Master\AbstractMaster;

use Duyler\WorkerPool\Master\ConnectionQueue;
use Duyler\WorkerPool\Master\ConnectionRouter;
use Duyler\WorkerPool\Master\SocketManager;
use Duyler\WorkerPool\Master\WorkerManager;
use Duyler\WorkerPool\Signal\SignalHandler;
use Duyler\WorkerPool\Signal\SignalManager;
use Duyler\WorkerPool\Util\SystemInfo;

use function defined;
use function function_exists;

use const PHP_OS_FAMILY;

#[CoversClass(MasterFactory::class)]
#[UsesClass(WorkerPoolConfig::class)]
#[UsesClass(FdPasser::class)]
#[UsesClass(AbstractMaster::class)]
#[UsesClass(CentralizedMaster::class)]
#[UsesClass(ConnectionQueue::class)]
#[UsesClass(ConnectionRouter::class)]
#[UsesClass(SharedSocketMaster::class)]
#[UsesClass(SocketManager::class)]
#[UsesClass(WorkerManager::class)]
#[UsesClass(SignalHandler::class)]
#[UsesClass(SignalManager::class)]
#[UsesClass(SystemInfo::class)]
final class MasterFactoryTest extends TestCase
{
    private WorkerPoolConfig $config;
    private ServerConfig $serverConfig;
    private WorkerCallbackInterface $callback;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->serverConfig = new ServerConfig(
            host: '127.0.0.1',
            port: 9999,
        );

        $this->config = new WorkerPoolConfig(
            serverConfig: $this->serverConfig,
            workerCount: 2,
            autoRestart: false,
        );

        $this->callback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void
            {
                socket_close($clientSocket);
            }
        };
    }

    #[Test]
    public function creates_master_instance(): void
    {
        $master = MasterFactory::create(
            config: $this->config,
            serverConfig: $this->serverConfig,
            workerCallback: $this->callback,
        );

        $this->assertInstanceOf(MasterInterface::class, $master);
    }

    #[Test]
    public function creates_shared_socket_master_when_fd_passing_not_supported(): void
    {
        $master = MasterFactory::create(
            config: $this->config,
            serverConfig: $this->serverConfig,
            workerCallback: $this->callback,
            balancer: null,
        );

        $this->assertInstanceOf(SharedSocketMaster::class, $master);
    }

    #[Test]
    public function creates_centralized_master_when_fd_passing_supported_and_balancer_provided(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('FD Passing only supported on Linux');
        }

        if (!function_exists('socket_sendmsg') || !defined('SCM_RIGHTS')) {
            $this->markTestSkipped('FD Passing not available');
        }

        $balancer = new LeastConnectionsBalancer();

        $master = MasterFactory::create(
            config: $this->config,
            serverConfig: $this->serverConfig,
            workerCallback: $this->callback,
            balancer: $balancer,
        );

        $this->assertInstanceOf(CentralizedMaster::class, $master);
    }

    #[Test]
    public function creates_recommended_master(): void
    {
        $master = MasterFactory::createRecommended(
            config: $this->config,
            serverConfig: $this->serverConfig,
            workerCallback: $this->callback,
        );

        $this->assertInstanceOf(MasterInterface::class, $master);
    }

    #[Test]
    public function returns_recommended_master_name(): void
    {
        $recommendation = MasterFactory::recommendedMaster();

        $this->assertIsString($recommendation);
        $this->assertNotEmpty($recommendation);
        $this->assertStringContainsString('Master', $recommendation);
    }

    #[Test]
    public function returns_comparison_array(): void
    {
        $comparison = MasterFactory::getComparison();

        $this->assertIsArray($comparison);
        $this->assertArrayHasKey('SharedSocketMaster', $comparison);
        $this->assertArrayHasKey('CentralizedMaster', $comparison);

        $this->assertArrayHasKey('architecture', $comparison['SharedSocketMaster']);
        $this->assertArrayHasKey('load_balancing', $comparison['SharedSocketMaster']);
        $this->assertArrayHasKey('requirements', $comparison['SharedSocketMaster']);
        $this->assertArrayHasKey('platforms', $comparison['SharedSocketMaster']);
        $this->assertArrayHasKey('complexity', $comparison['SharedSocketMaster']);
        $this->assertArrayHasKey('use_case', $comparison['SharedSocketMaster']);

        $this->assertArrayHasKey('architecture', $comparison['CentralizedMaster']);
        $this->assertArrayHasKey('load_balancing', $comparison['CentralizedMaster']);
    }

    #[Test]
    public function comparison_provides_useful_information(): void
    {
        $comparison = MasterFactory::getComparison();

        $sharedSocket = $comparison['SharedSocketMaster'];
        $centralized = $comparison['CentralizedMaster'];

        $this->assertStringContainsString('Distributed', $sharedSocket['architecture']);
        $this->assertStringContainsString('Kernel', $sharedSocket['load_balancing']);

        $this->assertStringContainsString('Centralized', $centralized['architecture']);
        $this->assertStringContainsString('Custom', $centralized['load_balancing']);
    }

    #[Test]
    public function creates_master_with_event_driven_worker(): void
    {
        $worker = new class implements EventDrivenWorkerInterface {
            public function run(int $workerId, ServerInterface $server): void {}
        };

        $master = MasterFactory::create(
            config: $this->config,
            serverConfig: $this->serverConfig,
            eventDrivenWorker: $worker,
        );

        $this->assertInstanceOf(MasterInterface::class, $master);
    }

    #[Test]
    public function creates_recommended_master_with_event_driven_worker(): void
    {
        $worker = new class implements EventDrivenWorkerInterface {
            public function run(int $workerId, ServerInterface $server): void {}
        };

        $master = MasterFactory::createRecommended(
            config: $this->config,
            serverConfig: $this->serverConfig,
            eventDrivenWorker: $worker,
        );

        $this->assertInstanceOf(MasterInterface::class, $master);
    }

    #[Test]
    public function throws_exception_when_no_worker_interface_provided_in_create(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Either workerCallback or eventDrivenWorker must be provided');

        MasterFactory::create(
            config: $this->config,
            serverConfig: $this->serverConfig,
        );
    }

    #[Test]
    public function throws_exception_when_no_worker_interface_provided_in_create_recommended(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Either workerCallback or eventDrivenWorker must be provided');

        MasterFactory::createRecommended(
            config: $this->config,
            serverConfig: $this->serverConfig,
        );
    }

    #[Test]
    public function accepts_both_worker_callback_and_event_driven_worker(): void
    {
        $worker = new class implements EventDrivenWorkerInterface {
            public function run(int $workerId, ServerInterface $server): void {}
        };

        $master = MasterFactory::create(
            config: $this->config,
            serverConfig: $this->serverConfig,
            workerCallback: $this->callback,
            eventDrivenWorker: $worker,
        );

        $this->assertInstanceOf(MasterInterface::class, $master);
    }

    #[Test]
    public function creates_shared_socket_master_with_event_driven_worker_when_no_balancer(): void
    {
        $worker = new class implements EventDrivenWorkerInterface {
            public function run(int $workerId, ServerInterface $server): void {}
        };

        $master = MasterFactory::create(
            config: $this->config,
            serverConfig: $this->serverConfig,
            eventDrivenWorker: $worker,
            balancer: null,
        );

        $this->assertInstanceOf(SharedSocketMaster::class, $master);
    }

    #[Test]
    public function creates_centralized_master_with_event_driven_worker_when_fd_passing_supported(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('FD Passing only supported on Linux');
        }

        if (!function_exists('socket_sendmsg') || !defined('SCM_RIGHTS')) {
            $this->markTestSkipped('FD Passing not available');
        }

        $worker = new class implements EventDrivenWorkerInterface {
            public function run(int $workerId, ServerInterface $server): void {}
        };

        $balancer = new LeastConnectionsBalancer();

        $master = MasterFactory::create(
            config: $this->config,
            serverConfig: $this->serverConfig,
            eventDrivenWorker: $worker,
            balancer: $balancer,
        );

        $this->assertInstanceOf(CentralizedMaster::class, $master);
    }
}

<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\Master;

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

use function defined;
use function function_exists;

use const PHP_OS_FAMILY;

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

    public function testCreatesMasterInstance(): void
    {
        $master = MasterFactory::create(
            config: $this->config,
            serverConfig: $this->serverConfig,
            workerCallback: $this->callback,
        );

        $this->assertInstanceOf(MasterInterface::class, $master);
    }

    public function testCreatesSharedSocketMasterWhenFdPassingNotSupported(): void
    {
        $master = MasterFactory::create(
            config: $this->config,
            serverConfig: $this->serverConfig,
            workerCallback: $this->callback,
            balancer: null,
        );

        $this->assertInstanceOf(SharedSocketMaster::class, $master);
    }

    public function testCreatesCentralizedMasterWhenFdPassingSupportedAndBalancerProvided(): void
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

    public function testCreatesRecommendedMaster(): void
    {
        $master = MasterFactory::createRecommended(
            config: $this->config,
            serverConfig: $this->serverConfig,
            workerCallback: $this->callback,
        );

        $this->assertInstanceOf(MasterInterface::class, $master);
    }

    public function testReturnsRecommendedMasterName(): void
    {
        $recommendation = MasterFactory::recommendedMaster();

        $this->assertIsString($recommendation);
        $this->assertNotEmpty($recommendation);
        $this->assertStringContainsString('Master', $recommendation);
    }

    public function testReturnsComparisonArray(): void
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

    public function testComparisonProvidesUsefulInformation(): void
    {
        $comparison = MasterFactory::getComparison();

        $sharedSocket = $comparison['SharedSocketMaster'];
        $centralized = $comparison['CentralizedMaster'];

        $this->assertStringContainsString('Distributed', $sharedSocket['architecture']);
        $this->assertStringContainsString('Kernel', $sharedSocket['load_balancing']);

        $this->assertStringContainsString('Centralized', $centralized['architecture']);
        $this->assertStringContainsString('Custom', $centralized['load_balancing']);
    }

    public function testCreatesMasterWithEventDrivenWorker(): void
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

    public function testCreatesRecommendedMasterWithEventDrivenWorker(): void
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

    public function testThrowsExceptionWhenNoWorkerInterfaceProvidedInCreate(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Either workerCallback or eventDrivenWorker must be provided');

        MasterFactory::create(
            config: $this->config,
            serverConfig: $this->serverConfig,
        );
    }

    public function testThrowsExceptionWhenNoWorkerInterfaceProvidedInCreateRecommended(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Either workerCallback or eventDrivenWorker must be provided');

        MasterFactory::createRecommended(
            config: $this->config,
            serverConfig: $this->serverConfig,
        );
    }

    public function testAcceptsBothWorkerCallbackAndEventDrivenWorker(): void
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

    public function testCreatesSharedSocketMasterWithEventDrivenWorkerWhenNoBalancer(): void
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

    public function testCreatesCentralizedMasterWithEventDrivenWorkerWhenFdPassingSupported(): void
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

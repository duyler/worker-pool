<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\Master;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\WorkerPool\Balancer\LeastConnectionsBalancer;
use Duyler\WorkerPool\Balancer\RoundRobinBalancer;
use Duyler\WorkerPool\Config\WorkerPoolConfig;
use Duyler\WorkerPool\IPC\FdPasser;
use Duyler\WorkerPool\Master\CentralizedMaster;
use Duyler\WorkerPool\Master\ConnectionQueue;
use Duyler\WorkerPool\Master\ConnectionRouter;
use Duyler\WorkerPool\Master\MasterFactory;
use Duyler\WorkerPool\Master\SharedSocketMaster;
use Duyler\WorkerPool\Master\SocketManager;
use Duyler\WorkerPool\Socket\SocketWrapperInterface;
use Duyler\WorkerPool\Socket\SocketMsgWrapperInterface;
use Duyler\WorkerPool\Process\ForkWrapperInterface;
use Duyler\WorkerPool\Worker\WorkerCallbackInterface;
use Duyler\WorkerPool\Util\SystemInfo;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Duyler\WorkerPool\Exception\WorkerPoolException;
use Duyler\WorkerPool\Master\AbstractMaster;
use Duyler\WorkerPool\Master\WorkerManager;
use Duyler\WorkerPool\Process\ForkWrapper;
use Duyler\WorkerPool\Process\ProcessInfo;
use Duyler\WorkerPool\Signal\SignalHandler;
use Duyler\WorkerPool\Signal\SignalManager;
use Duyler\WorkerPool\Socket\SocketMsgWrapper;
use Duyler\WorkerPool\Socket\SocketWrapper;

#[CoversClass(MasterFactory::class)]
#[UsesClass(WorkerPoolConfig::class)]
#[UsesClass(SharedSocketMaster::class)]
#[UsesClass(CentralizedMaster::class)]
#[UsesClass(AbstractMaster::class)]
#[UsesClass(SignalHandler::class)]
#[UsesClass(SignalManager::class)]
#[UsesClass(WorkerManager::class)]
#[UsesClass(ProcessInfo::class)]
#[UsesClass(WorkerPoolException::class)]
#[UsesClass(ForkWrapper::class)]
#[UsesClass(SocketWrapper::class)]
#[UsesClass(SocketMsgWrapper::class)]
#[UsesClass(LeastConnectionsBalancer::class)]
#[UsesClass(RoundRobinBalancer::class)]
#[UsesClass(SystemInfo::class)]
#[UsesClass(FdPasser::class)]
#[UsesClass(ConnectionQueue::class)]
#[UsesClass(ConnectionRouter::class)]
#[UsesClass(SocketManager::class)]
final class MasterFactoryFullTest extends TestCase
{
    private SocketWrapperInterface $socketWrapper;
    private SocketMsgWrapperInterface $socketMsgWrapper;
    private ForkWrapperInterface $forkWrapper;
    private WorkerCallbackInterface $callback;
    private LoggerInterface $logger;

    public function setUp(): void
    {
        parent::setUp();
        $this->socketWrapper = $this->createStub(SocketWrapperInterface::class);
        $this->socketMsgWrapper = $this->createStub(SocketMsgWrapperInterface::class);
        $this->forkWrapper = $this->createStub(ForkWrapperInterface::class);
        $this->callback = $this->createStub(WorkerCallbackInterface::class);
        $this->logger = $this->createStub(LoggerInterface::class);
    }

    #[Test]
    public function create_returns_shared_socket_without_balancer(): void
    {
        $serverConfig = new ServerConfig(host: '127.0.0.1', port: 9999);
        $config = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 1,
            autoRestart: false,
        );

        $master = MasterFactory::create(
            config: $config,
            serverConfig: $serverConfig,
            workerCallback: $this->callback,
            logger: $this->logger,
            socketWrapper: $this->socketWrapper,
            socketMsgWrapper: $this->socketMsgWrapper,
            forkWrapper: $this->forkWrapper,
        );

        $this->assertInstanceOf(SharedSocketMaster::class, $master);
    }

    #[Test]
    public function create_returns_centralized_with_balancer_on_linux(): void
    {
        $systemInfo = new SystemInfo();
        if (false === $systemInfo->supportsFdPassing()) {
            $this->markTestSkipped('FD passing not supported');
        }

        $serverConfig = new ServerConfig(host: '127.0.0.1', port: 9999);
        $config = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 1,
            autoRestart: false,
        );

        $master = MasterFactory::create(
            config: $config,
            serverConfig: $serverConfig,
            workerCallback: $this->callback,
            balancer: new RoundRobinBalancer(),
            logger: $this->logger,
            socketWrapper: $this->socketWrapper,
            socketMsgWrapper: $this->socketMsgWrapper,
            forkWrapper: $this->forkWrapper,
        );

        $this->assertInstanceOf(CentralizedMaster::class, $master);
    }

    #[Test]
    public function create_recommended_returns_centralized_on_linux(): void
    {
        $systemInfo = new SystemInfo();
        if (false === $systemInfo->supportsFdPassing()) {
            $this->markTestSkipped('FD passing not supported');
        }

        $serverConfig = new ServerConfig(host: '127.0.0.1', port: 9999);
        $config = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 1,
            autoRestart: false,
        );

        $master = MasterFactory::createRecommended(
            config: $config,
            serverConfig: $serverConfig,
            workerCallback: $this->callback,
            logger: $this->logger,
            socketWrapper: $this->socketWrapper,
            socketMsgWrapper: $this->socketMsgWrapper,
            forkWrapper: $this->forkWrapper,
        );

        $this->assertInstanceOf(CentralizedMaster::class, $master);
    }

    #[Test]
    public function create_recommended_returns_shared_without_fd_passing(): void
    {
        $serverConfig = new ServerConfig(host: '127.0.0.1', port: 9999);
        $config = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 1,
            autoRestart: false,
        );

        SystemInfo::resetCache();

        $master = MasterFactory::createRecommended(
            config: $config,
            serverConfig: $serverConfig,
            workerCallback: $this->callback,
            logger: $this->logger,
            socketWrapper: $this->socketWrapper,
            socketMsgWrapper: $this->socketMsgWrapper,
            forkWrapper: $this->forkWrapper,
        );

        $systemInfo = new SystemInfo();
        if ($systemInfo->supportsFdPassing()) {
            $this->assertInstanceOf(CentralizedMaster::class, $master);
        } else {
            $this->assertInstanceOf(SharedSocketMaster::class, $master);
        }
    }

    #[Test]
    public function recommended_master_returns_string(): void
    {
        SystemInfo::resetCache();
        $result = MasterFactory::recommendedMaster();

        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    #[Test]
    public function get_comparison_returns_both_architectures(): void
    {
        $comparison = MasterFactory::getComparison();

        $this->assertArrayHasKey('SharedSocketMaster', $comparison);
        $this->assertArrayHasKey('CentralizedMaster', $comparison);
        $this->assertArrayHasKey('architecture', $comparison['SharedSocketMaster']);
        $this->assertArrayHasKey('architecture', $comparison['CentralizedMaster']);
    }
}

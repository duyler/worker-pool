<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Integration;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\ErrorHandler\ErrorHandler;
use Duyler\HttpServer\ServerInterface;
use Duyler\WorkerPool\Balancer\RoundRobinBalancer;
use Duyler\WorkerPool\Config\WorkerPoolConfig;
use Duyler\WorkerPool\Master\CentralizedMaster;
use Duyler\WorkerPool\Process\ForkWrapper;
use Duyler\WorkerPool\Socket\SocketMsgWrapper;
use Duyler\WorkerPool\Socket\SocketWrapper;
use Duyler\WorkerPool\Worker\EventDrivenWorkerInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Psr\Log\NullLogger;

#[CoversClass(CentralizedMaster::class)]
class CentralizedMasterSocketResourceTest extends TestCase
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

    public function testServerReceivesUnixSocketResourceInWorker(): void
    {
        $serverConfig = new ServerConfig(
            host: '127.0.0.1',
            port: 19081,
        );

        $workerPoolConfig = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 1,
        );

        $balancer = new RoundRobinBalancer();

        $testWorker = new class implements EventDrivenWorkerInterface {
            public function run(int $workerId, ServerInterface $server): void {}
        };

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

    public function testPhpdocContainsEvioLimitationNote(): void
    {
        $reflection = new ReflectionClass(CentralizedMaster::class);
        $docComment = $reflection->getDocComment();

        $this->assertNotFalse($docComment);
        $this->assertStringContainsString(
            'EvIo',
            $docComment,
            'PHPDoc должен содержать note о EvIo limitation',
        );
        $this->assertStringContainsString(
            'EvTimer fallback is recommended',
            $docComment,
            'PHPDoc должен содержать рекомендацию использовать EvTimer fallback',
        );
    }

    public function testMasterInstantiatesWithBalancer(): void
    {
        $serverConfig = new ServerConfig(
            host: '127.0.0.1',
            port: 19081,
        );

        $workerPoolConfig = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 1,
        );

        $balancer = new RoundRobinBalancer();

        $testWorker = new class implements EventDrivenWorkerInterface {
            public function run(int $workerId, ServerInterface $server): void {}
        };

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
}

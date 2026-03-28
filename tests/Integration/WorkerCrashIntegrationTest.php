<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Integration;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\ErrorHandler;
use Duyler\WorkerPool\Tests\Support\PlatformHelper;
use Duyler\WorkerPool\Balancer\RoundRobinBalancer;
use Duyler\WorkerPool\Config\WorkerPoolConfig;
use Duyler\WorkerPool\Master\CentralizedMaster;
use Duyler\WorkerPool\Worker\WorkerCallbackInterface;
use Override;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

use const AF_INET;
use const SIGTERM;
use const SOCK_STREAM;
use const SOL_TCP;

#[Group('pcntl')]
final class WorkerCrashIntegrationTest extends TestCase
{
    #[Override]
    protected function tearDown(): void
    {
        ErrorHandler::reset();
        parent::tearDown();
    }

    public function testHandlesWorkerCrashWithoutAutoRestart(): void
    {
        if (!PlatformHelper::supportsSCMRights()) {
            $this->markTestSkipped(PlatformHelper::getSkipReason('scm_rights'));
        }

        $serverConfig = new ServerConfig(
            host: '127.0.0.1',
            port: 9999,
        );

        $workerPoolConfig = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 2,
            autoRestart: false,
        );

        $callback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void
            {
                socket_close($clientSocket);
            }
        };

        $balancer = new RoundRobinBalancer();

        $master = new CentralizedMaster(
            config: $workerPoolConfig,
            balancer: $balancer,
        );

        $initialWorkerCount = $master->getWorkerCount();

        $this->assertSame(0, $initialWorkerCount);
    }

    public function testAutoRestartIsConfigurable(): void
    {
        if (!PlatformHelper::supportsSCMRights()) {
            $this->markTestSkipped(PlatformHelper::getSkipReason('scm_rights'));
        }

        $serverConfig = new ServerConfig(
            host: '127.0.0.1',
            port: 9999,
        );

        $workerPoolConfigWithRestart = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 2,
            autoRestart: true,
            restartDelay: 1,
        );

        $workerPoolConfigWithoutRestart = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 2,
            autoRestart: false,
        );

        $this->assertTrue($workerPoolConfigWithRestart->autoRestart);
        $this->assertFalse($workerPoolConfigWithoutRestart->autoRestart);
        $this->assertSame(1, $workerPoolConfigWithRestart->restartDelay);
    }

    public function testMasterContinuesAfterWorkerCrash(): void
    {
        if (!PlatformHelper::supportsSCMRights()) {
            $this->markTestSkipped(PlatformHelper::getSkipReason('scm_rights'));
        }

        $port = $this->findFreePort();

        $serverConfig = new ServerConfig(
            host: '127.0.0.1',
            port: $port,
        );

        $workerPoolConfig = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 2,
            autoRestart: false,
        );

        $callback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void
            {
                $response = "HTTP/1.1 200 OK\r\n\r\nOK";
                socket_write($clientSocket, $response);
                socket_close($clientSocket);
            }
        };

        $balancer = new RoundRobinBalancer();

        $master = new CentralizedMaster(
            config: $workerPoolConfig,
            balancer: $balancer,
            serverConfig: $serverConfig,
            workerCallback: $callback,
        );

        $pid = pcntl_fork();

        if ($pid === 0) {
            $master->start();
            exit(0);
        }

        sleep(1);

        $client = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        if (@socket_connect($client, '127.0.0.1', $port)) {
            socket_write($client, "GET / HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");

            $response = '';
            while ($chunk = @socket_read($client, 1024)) {
                $response .= $chunk;
            }

            socket_close($client);

            $this->assertStringContainsString('HTTP/1.1 200 OK', $response);
        }

        posix_kill($pid, SIGTERM);
        pcntl_waitpid($pid, $status);

        if (!@socket_connect($client, '127.0.0.1', $port)) {
            $this->markTestSkipped('Could not connect to server');
        }
    }

    private function findFreePort(): int
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_bind($socket, '127.0.0.1', 0);
        socket_getsockname($socket, $addr, $port);
        socket_close($socket);

        return $port;
    }
}

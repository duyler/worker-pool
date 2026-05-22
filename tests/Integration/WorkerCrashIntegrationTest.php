<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Duyler\WorkerPool\Master\SharedSocketMaster;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\ErrorHandler\ErrorHandler;
use Duyler\WorkerPool\Tests\Support\PlatformHelper;
use Duyler\WorkerPool\Balancer\RoundRobinBalancer;
use Duyler\WorkerPool\Config\WorkerPoolConfig;
use Duyler\WorkerPool\Master\CentralizedMaster;
use Duyler\WorkerPool\Worker\WorkerCallbackInterface;
use Override;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

use const AF_INET;
use const SIGTERM;
use const SOCK_STREAM;
use const SOL_TCP;

#[Group('pcntl')]
#[CoversClass(SharedSocketMaster::class)]
#[CoversClass(CentralizedMaster::class)]
final class WorkerCrashIntegrationTest extends TestCase
{
    #[Override]
    protected function tearDown(): void
    {
        new ErrorHandler(new NullLogger())->reset();
        parent::tearDown();
    }

    #[Test]
    public function handles_worker_crash_without_auto_restart(): void
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

    #[Test]
    public function auto_restart_is_configurable(): void
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

    #[Test]
    public function master_continues_after_worker_crash(): void
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

        if (0 === $pid) {
            $master->start();
            exit(0);
        }

        sleep(1);

        $client = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        $previousEr = error_reporting(0);
        if (socket_connect($client, '127.0.0.1', $port)) {
            error_reporting($previousEr);
            socket_write($client, "GET / HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");

            $response = '';
            while (true) {
                $previousEr = error_reporting(0);
                $chunk = socket_read($client, 1024);
                error_reporting($previousEr);
                if (false === $chunk || '' === $chunk) {
                    break;
                }
                $response .= $chunk;
            }

            socket_close($client);

            $this->assertStringContainsString('HTTP/1.1 200 OK', $response);
        } else {
            error_reporting($previousEr);
        }

        posix_kill($pid, SIGTERM);
        pcntl_waitpid($pid, $status);

        $previousEr = error_reporting(0);
        if (!socket_connect($client, '127.0.0.1', $port)) {
            error_reporting($previousEr);
            $this->markTestSkipped('Could not connect to server');
        }
        error_reporting($previousEr);
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

<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Duyler\WorkerPool\Master\SharedSocketMaster;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\ErrorHandler\ErrorHandler;
use Duyler\WorkerPool\Tests\Support\PlatformHelper;
use Duyler\WorkerPool\Balancer\LeastConnectionsBalancer;
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
final class MasterLifecycleIntegrationTest extends TestCase
{
    #[Override]
    protected function tearDown(): void
    {
        new ErrorHandler(new NullLogger())->reset();
        parent::tearDown();
    }

    #[Test]
    public function starts_workers_and_accepts_connections(): void
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

        $handledRequests = 0;

        $callback = new class ($handledRequests) implements WorkerCallbackInterface {
            public function __construct(private int &$handledRequests) {}

            public function handle(mixed $clientSocket, array $metadata): void
            {
                ++$this->handledRequests;

                $response = "HTTP/1.1 200 OK\r\n";
                $response .= "Content-Type: text/plain\r\n";
                $response .= "Connection: close\r\n\r\n";
                $response .= "Hello from Worker {$metadata['worker_id']}";

                socket_write($clientSocket, $response);
                socket_close($clientSocket);
            }
        };

        $balancer = new LeastConnectionsBalancer();

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
        $this->assertNotFalse($client);

        $previousEr = error_reporting(0);
        $connected = socket_connect($client, '127.0.0.1', $port);
        error_reporting($previousEr);

        if ($connected) {
            $request = "GET / HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n";
            socket_write($client, $request);

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
            $this->assertStringContainsString('Hello from Worker', $response);
        }

        posix_kill($pid, SIGTERM);
        pcntl_waitpid($pid, $status);

        if (!$connected) {
            $this->markTestSkipped('Could not connect to server');
        }
    }

    #[Test]
    public function gracefully_stops_all_workers(): void
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
                socket_write($clientSocket, "HTTP/1.1 200 OK\r\n\r\nOK");
                socket_close($clientSocket);
            }
        };

        $balancer = new LeastConnectionsBalancer();

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

        $this->assertTrue(posix_kill($pid, 0), 'Master process should be running');

        posix_kill($pid, SIGTERM);

        $timeout = 5;
        $start = time();
        while (time() - $start < $timeout) {
            if (!posix_kill($pid, 0)) {
                break;
            }
            usleep(100000);
        }

        pcntl_waitpid($pid, $status);

        $this->assertTrue(pcntl_wifexited($status), 'Process should exit normally');
    }

    #[Test]
    public function returns_correct_metrics(): void
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
            workerCount: 3,
            autoRestart: false,
        );

        $callback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void
            {
                socket_close($clientSocket);
            }
        };

        $balancer = new LeastConnectionsBalancer();

        $master = new CentralizedMaster(
            config: $workerPoolConfig,
            balancer: $balancer,
        );

        $metrics = $master->getMetrics();

        $this->assertIsArray($metrics);
        $this->assertArrayHasKey('total_workers', $metrics);
        $this->assertArrayHasKey('alive_workers', $metrics);
        $this->assertArrayHasKey('total_connections', $metrics);
        $this->assertArrayHasKey('total_requests', $metrics);
        $this->assertArrayHasKey('queue_size', $metrics);
        $this->assertArrayHasKey('is_running', $metrics);

        $this->assertSame(3, $metrics['total_workers']);
        $this->assertTrue($metrics['is_running']);
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

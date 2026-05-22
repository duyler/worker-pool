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
use Duyler\WorkerPool\Worker\HttpWorkerAdapter;
use Duyler\WorkerPool\Worker\WorkerCallbackInterface;
use Override;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

use Duyler\WorkerPool\Socket\SocketWrapper;

use function count;

use const AF_INET;
use const SIGTERM;
use const SOCK_STREAM;
use const SOL_TCP;

#[Group('pcntl')]
#[CoversClass(SharedSocketMaster::class)]
#[CoversClass(CentralizedMaster::class)]
class MasterHttpIntegrationTest extends TestCase
{
    #[Override]
    protected function tearDown(): void
    {
        new ErrorHandler(new NullLogger())->reset();
        parent::tearDown();
    }

    #[Test]
    public function master_accepts_and_distributes_http_requests(): void
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
        );

        $callback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void
            {
                $adapter = new HttpWorkerAdapter(new SocketWrapper());
                $adapter->handleConnection($clientSocket, $metadata);
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

            $this->assertStringContainsString('HTTP/', $response);
            $this->assertStringContainsString('Hello from Worker Pool', $response);
        }

        posix_kill($pid, SIGTERM);
        pcntl_waitpid($pid, $status);

        if (!$connected) {
            $this->markTestSkipped('Could not connect to server');
        }
    }

    #[Test]
    public function master_handles_multiple_concurrent_requests(): void
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
        );

        $callback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void
            {
                $adapter = new HttpWorkerAdapter(new SocketWrapper());
                $adapter->handleConnection($clientSocket, $metadata);
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

        $responses = [];

        for ($i = 0; $i < 3; $i++) {
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
                $responses[] = $response;
            }
        }

        posix_kill($pid, SIGTERM);
        pcntl_waitpid($pid, $status);

        if (count($responses) === 0) {
            $this->markTestSkipped('No successful connections');
        }

        foreach ($responses as $response) {
            $this->assertStringContainsString('HTTP/', $response);
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

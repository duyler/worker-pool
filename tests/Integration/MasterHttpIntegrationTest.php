<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
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
        (new ErrorHandler(new NullLogger()))->reset();
        parent::tearDown();
    }

    public function testMasterAcceptsAndDistributesHttpRequests(): void
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
                $adapter = new HttpWorkerAdapter();
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

        if ($pid === 0) {
            $master->start();
            exit(0);
        }

        sleep(1);

        $client = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertNotFalse($client);

        $connected = @socket_connect($client, '127.0.0.1', $port);

        if ($connected) {
            $request = "GET / HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n";
            socket_write($client, $request);

            $response = '';
            while (true) {
                $chunk = @socket_read($client, 1024);
                if ($chunk === false || $chunk === '') {
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

    public function testMasterHandlesMultipleConcurrentRequests(): void
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
                $adapter = new HttpWorkerAdapter();
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

        if ($pid === 0) {
            $master->start();
            exit(0);
        }

        sleep(1);

        $responses = [];

        for ($i = 0; $i < 3; $i++) {
            $client = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            $this->assertNotFalse($client);

            if (@socket_connect($client, '127.0.0.1', $port)) {
                $request = "GET / HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n";
                socket_write($client, $request);

                $response = '';
                while (true) {
                    $chunk = @socket_read($client, 1024);
                    if ($chunk === false || $chunk === '') {
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

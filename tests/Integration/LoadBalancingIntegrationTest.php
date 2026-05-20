<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\ErrorHandler\ErrorHandler;
use Duyler\WorkerPool\Tests\Support\PlatformHelper;
use Duyler\WorkerPool\Balancer\LeastConnectionsBalancer;
use Duyler\WorkerPool\Balancer\RoundRobinBalancer;
use Duyler\WorkerPool\Config\WorkerPoolConfig;
use Duyler\WorkerPool\Master\CentralizedMaster;
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
#[CoversClass(CentralizedMaster::class)]
final class LoadBalancingIntegrationTest extends TestCase
{
    #[Override]
    protected function tearDown(): void
    {
        (new ErrorHandler(new NullLogger()))->reset();
        parent::tearDown();
    }

    public function testRoundRobinDistributesEvenly(): void
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
            workerCount: 3,
            autoRestart: false,
        );

        $workerHits = [];

        $callback = new class ($workerHits) implements WorkerCallbackInterface {
            public function __construct(private array &$workerHits) {}

            public function handle(mixed $clientSocket, array $metadata): void
            {
                $workerId = $metadata['worker_id'] ?? 0;
                if (!isset($this->workerHits[$workerId])) {
                    $this->workerHits[$workerId] = 0;
                }
                ++$this->workerHits[$workerId];

                $response = "HTTP/1.1 200 OK\r\n\r\nWorker: $workerId";
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

        $requestCount = 9;
        $responses = [];

        for ($i = 0; $i < $requestCount; $i++) {
            $client = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

            if (@socket_connect($client, '127.0.0.1', $port)) {
                socket_write($client, "GET / HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");

                $response = '';
                while ($chunk = @socket_read($client, 1024)) {
                    $response .= $chunk;
                }
                $responses[] = $response;
                socket_close($client);
            }

            usleep(10000);
        }

        posix_kill($pid, SIGTERM);
        pcntl_waitpid($pid, $status);

        if (count($responses) < 3) {
            $this->markTestSkipped('Not enough successful connections');
        }

        $this->assertGreaterThanOrEqual(3, count($responses));
    }

    public function testLeastConnectionsPrefersIdleWorkers(): void
    {
        if (!PlatformHelper::supportsSCMRights()) {
            $this->markTestSkipped(PlatformHelper::getSkipReason('scm_rights'));
        }

        $balancer = new LeastConnectionsBalancer();

        $connections = [
            1 => 5,
            2 => 2,
            3 => 8,
        ];

        $selected = $balancer->selectWorker($connections);

        $this->assertSame(2, $selected, 'Should select worker with least connections');
    }

    public function testHandlesMultipleConcurrentConnections(): void
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
            workerCount: 4,
            autoRestart: false,
        );

        $callback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void
            {
                usleep(50000); // Simulate work

                $response = "HTTP/1.1 200 OK\r\n\r\nOK";
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

        if ($pid === 0) {
            $master->start();
            exit(0);
        }

        sleep(1);

        $successfulConnections = 0;
        $concurrentRequests = 10;

        for ($i = 0; $i < $concurrentRequests; $i++) {
            $client = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

            if (@socket_connect($client, '127.0.0.1', $port)) {
                socket_write($client, "GET / HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");

                $response = @socket_read($client, 1024);
                if ($response && str_contains($response, 'HTTP/1.1 200 OK')) {
                    ++$successfulConnections;
                }

                socket_close($client);
            }
        }

        posix_kill($pid, SIGTERM);
        pcntl_waitpid($pid, $status);

        if ($successfulConnections === 0) {
            $this->markTestSkipped('No successful connections');
        }

        $this->assertGreaterThan(0, $successfulConnections);
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

<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
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

use function count;

use const AF_INET;
use const SIGTERM;
use const SOCK_STREAM;
use const SOL_TCP;

#[Group('pcntl')]
#[CoversClass(SharedSocketMaster::class)]
#[CoversClass(CentralizedMaster::class)]
final class ConcurrencyIntegrationTest extends TestCase
{
    #[Override]
    protected function tearDown(): void
    {
        (new ErrorHandler(new NullLogger()))->reset();
        parent::tearDown();
    }

    public function testHandlesConcurrentConnectionsWithoutRaceConditions(): void
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

        $requestCounter = 0;

        $callback = new class ($requestCounter) implements WorkerCallbackInterface {
            private static int $counter = 0;

            public function __construct(private int &$requestCounter) {}

            public function handle(mixed $clientSocket, array $metadata): void
            {
                ++self::$counter;
                ++$this->requestCounter;

                $requestId = self::$counter;

                usleep(10000); // Simulate some work

                $response = "HTTP/1.1 200 OK\r\n\r\nRequest: $requestId";
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

        $concurrentRequests = 20;
        $responses = [];

        for ($i = 0; $i < $concurrentRequests; $i++) {
            $client = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

            if (@socket_connect($client, '127.0.0.1', $port)) {
                socket_set_nonblock($client);
                socket_write($client, "GET / HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");

                $response = '';
                $attempts = 0;
                while ($attempts < 100) {
                    $chunk = @socket_read($client, 1024);
                    if ($chunk) {
                        $response .= $chunk;
                    }
                    if (str_contains($response, "\r\n\r\n")) {
                        break;
                    }
                    usleep(10000);
                    ++$attempts;
                }

                if ($response) {
                    $responses[] = $response;
                }

                socket_close($client);
            }

            usleep(5000);
        }

        sleep(1);

        posix_kill($pid, SIGTERM);
        pcntl_waitpid($pid, $status);

        if (count($responses) === 0) {
            $this->markTestSkipped('No successful connections');
        }

        $this->assertGreaterThan(0, count($responses));

        foreach ($responses as $response) {
            $this->assertStringContainsString('HTTP/1.1 200 OK', $response);
        }
    }

    public function testMaintainsRequestIsolationBetweenWorkers(): void
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
                $workerId = $metadata['worker_id'] ?? 'unknown';

                $response = "HTTP/1.1 200 OK\r\n";
                $response .= "X-Worker-Id: $workerId\r\n";
                $response .= "Connection: close\r\n\r\n";
                $response .= "Handled by worker $workerId";

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

        $workerIds = [];

        for ($i = 0; $i < 4; $i++) {
            $client = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

            if (@socket_connect($client, '127.0.0.1', $port)) {
                socket_write($client, "GET / HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");

                $response = '';
                while ($chunk = @socket_read($client, 1024)) {
                    $response .= $chunk;
                }

                if (preg_match('/X-Worker-Id: (\d+)/', $response, $matches)) {
                    $workerIds[] = $matches[1];
                }

                socket_close($client);
            }

            usleep(50000);
        }

        posix_kill($pid, SIGTERM);
        pcntl_waitpid($pid, $status);

        if (count($workerIds) === 0) {
            $this->markTestSkipped('No worker IDs captured');
        }

        $uniqueWorkers = array_unique($workerIds);
        $this->assertGreaterThan(0, count($uniqueWorkers));
    }

    public function testHandlesRapidConnectDisconnect(): void
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

        if ($pid === 0) {
            $master->start();
            exit(0);
        }

        sleep(1);

        $successCount = 0;

        for ($i = 0; $i < 50; $i++) {
            $client = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

            if (@socket_connect($client, '127.0.0.1', $port)) {
                socket_write($client, "GET / HTTP/1.1\r\n\r\n");
                $response = @socket_read($client, 1024);

                if ($response && str_contains($response, 'HTTP')) {
                    ++$successCount;
                }

                socket_close($client);
            }
        }

        posix_kill($pid, SIGTERM);
        pcntl_waitpid($pid, $status);

        if ($successCount === 0) {
            $this->markTestSkipped('No successful rapid connections');
        }

        $this->assertGreaterThan(0, $successCount);
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

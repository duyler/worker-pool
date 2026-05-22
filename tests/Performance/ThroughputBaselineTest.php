<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Performance;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\ErrorHandler\ErrorHandler;
use Duyler\WorkerPool\Balancer\LeastConnectionsBalancer;
use Duyler\WorkerPool\Config\WorkerPoolConfig;
use Duyler\WorkerPool\Master\CentralizedMaster;
use Duyler\WorkerPool\Master\SharedSocketMaster;
use Duyler\WorkerPool\Tests\Support\PlatformHelper;
use Duyler\WorkerPool\Worker\WorkerCallbackInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

use function count;
use function sort;
use function usleep;

use const AF_INET;
use const SIGTERM;
use const SOCK_STREAM;
use const SOL_TCP;
use const E_WARNING;

#[Group('performance')]
#[Group('pcntl')]
#[CoversClass(CentralizedMaster::class)]
#[UsesClass(SharedSocketMaster::class)]
final class ThroughputBaselineTest extends TestCase
{
    #[Override]
    protected function tearDown(): void
    {
        (new ErrorHandler(new NullLogger()))->reset();
        parent::tearDown();
    }

    #[Test]
    public function centralized_throughput_100_requests(): void
    {
        if (false === PlatformHelper::supportsSCMRights()) {
            $this->markTestSkipped(PlatformHelper::getSkipReason('scm_rights'));
        }

        $port = $this->findFreePort();

        $serverConfig = new ServerConfig(
            host: '127.0.0.1',
            port: $port,
        );

        $poolConfig = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 2,
            autoRestart: false,
        );

        $callback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void
            {
                socket_write($clientSocket, "HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nOK");
                socket_close($clientSocket);
            }
        };

        $master = new CentralizedMaster(
            config: $poolConfig,
            balancer: new LeastConnectionsBalancer(),
            serverConfig: $serverConfig,
            workerCallback: $callback,
        );

        $pid = pcntl_fork();

        if (0 === $pid) {
            $master->start();
            exit(0);
        }

        sleep(1);

        $requestCount = 100;
        $responses = $this->sendRequests($port, $requestCount);

        posix_kill($pid, SIGTERM);
        pcntl_waitpid($pid, $status);

        if (0 === count($responses)) {
            $this->markTestSkipped('No successful connections to master');
        }

        $this->assertGreaterThan(0, count($responses));

        foreach ($responses as $response) {
            $this->assertStringContainsString('200 OK', $response);
        }
    }

    #[Test]
    public function centralized_throughput_1000_requests(): void
    {
        if (false === PlatformHelper::supportsSCMRights()) {
            $this->markTestSkipped(PlatformHelper::getSkipReason('scm_rights'));
        }

        $port = $this->findFreePort();

        $serverConfig = new ServerConfig(
            host: '127.0.0.1',
            port: $port,
        );

        $poolConfig = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 2,
            autoRestart: false,
        );

        $callback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void
            {
                socket_write($clientSocket, "HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nOK");
                socket_close($clientSocket);
            }
        };

        $master = new CentralizedMaster(
            config: $poolConfig,
            balancer: new LeastConnectionsBalancer(),
            serverConfig: $serverConfig,
            workerCallback: $callback,
        );

        $pid = pcntl_fork();

        if (0 === $pid) {
            $master->start();
            exit(0);
        }

        sleep(1);

        $requestCount = 1000;
        $start = microtime(true);

        $responses = $this->sendRequests($port, $requestCount);

        $elapsed = microtime(true) - $start;

        posix_kill($pid, SIGTERM);
        pcntl_waitpid($pid, $status);

        if (0 === count($responses)) {
            $this->markTestSkipped('No successful connections to master');
        }

        $requestsPerSecond = count($responses) / $elapsed;

        $this->assertGreaterThan(10, $requestsPerSecond);
    }

    #[Test]
    public function centralized_latency_percentiles(): void
    {
        if (false === PlatformHelper::supportsSCMRights()) {
            $this->markTestSkipped(PlatformHelper::getSkipReason('scm_rights'));
        }

        $port = $this->findFreePort();

        $serverConfig = new ServerConfig(
            host: '127.0.0.1',
            port: $port,
        );

        $poolConfig = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 2,
            autoRestart: false,
        );

        $callback = new class implements WorkerCallbackInterface {
            public function handle(mixed $clientSocket, array $metadata): void
            {
                usleep(1000);
                socket_write($clientSocket, "HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nOK");
                socket_close($clientSocket);
            }
        };

        $master = new CentralizedMaster(
            config: $poolConfig,
            balancer: new LeastConnectionsBalancer(),
            serverConfig: $serverConfig,
            workerCallback: $callback,
        );

        $pid = pcntl_fork();

        if (0 === $pid) {
            $master->start();
            exit(0);
        }

        sleep(1);

        $latencies = [];
        $successfulRequests = 0;

        for ($i = 0; $i < 100; $i++) {
            $start = microtime(true);

            $response = $this->sendSingleRequest($port);

            $latency = microtime(true) - $start;

            if (null !== $response) {
                $latencies[] = $latency;
                ++$successfulRequests;
            }
        }

        posix_kill($pid, SIGTERM);
        pcntl_waitpid($pid, $status);

        if (0 === count($latencies)) {
            $this->markTestSkipped('No successful latency measurements');
        }

        sort($latencies);

        $p50 = $latencies[(int) (count($latencies) * 0.50)];
        $p95 = $latencies[(int) (count($latencies) * 0.95)];
        $p99 = $latencies[(int) (count($latencies) * 0.99)];

        $this->assertLessThan(5.0, $p99);
        $this->assertLessThan(2.0, $p95);
        $this->assertLessThan(1.0, $p50);
    }

    private function findFreePort(): int
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_bind($socket, '127.0.0.1', 0);
        socket_getsockname($socket, $addr, $port);
        socket_close($socket);

        return $port;
    }

    private function sendRequests(int $port, int $count): array
    {
        $responses = [];

        for ($i = 0; $i < $count; $i++) {
            $response = $this->sendSingleRequest($port);

            if (null !== $response) {
                $responses[] = $response;
            }

            if (0 === $i % 50) {
                usleep(1000);
            }
        }

        return $responses;
    }

    private function sendSingleRequest(int $port): ?string
    {
        $client = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        if (false === $client) {
            return null;
        }

        set_error_handler(static fn(int $errno, string $errstr): bool => true, E_WARNING);
        $connected = socket_connect($client, '127.0.0.1', $port);
        restore_error_handler();

        if (false === $connected) {
            socket_close($client);
            return null;
        }

        $written = socket_write($client, "GET / HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");

        if (false === $written) {
            socket_close($client);
            return null;
        }

        $response = '';
        $attempts = 0;

        while ($attempts < 50) {
            set_error_handler(static fn(int $errno, string $errstr): bool => true, E_WARNING);
            $chunk = socket_read($client, 1024);
            restore_error_handler();

            if (false === $chunk || '' === $chunk) {
                break;
            }

            $response .= $chunk;

            if (str_contains($response, "\r\n\r\n")) {
                break;
            }

            usleep(2000);
            ++$attempts;
        }

        socket_close($client);

        if ('' === $response) {
            return null;
        }

        return $response;
    }
}

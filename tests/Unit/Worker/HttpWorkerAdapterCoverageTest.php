<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\Worker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

use Duyler\WorkerPool\Worker\HttpWorkerAdapter;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use Duyler\WorkerPool\Socket\SocketWrapper;

use function strlen;

use const AF_UNIX;
use const SOCK_STREAM;

#[CoversClass(HttpWorkerAdapter::class)]
#[UsesClass(SocketWrapper::class)]
final class HttpWorkerAdapterCoverageTest extends TestCase
{
    private HttpWorkerAdapter $adapter;

    #[Override]
    protected function setUp(): void
    {
        $this->adapter = new HttpWorkerAdapter(new SocketWrapper());
    }

    #[Test]
    public function createsAdapterWithHttpParser(): void
    {
        $adapter = new HttpWorkerAdapter(new SocketWrapper());
        $this->assertInstanceOf(HttpWorkerAdapter::class, $adapter);
    }

    #[Test]
    public function handleConnectionWithValidGetRequest(): void
    {
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        [$serverSocket, $clientSocket] = $pair;

        $request = "GET /test HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n";
        socket_write($clientSocket, $request);
        socket_shutdown($clientSocket, 1);

        $this->adapter->handleConnection($serverSocket, ['worker_id' => 1]);

        $response = '';
        socket_set_nonblock($clientSocket);
        $start = microtime(true);
        while (microtime(true) - $start < 1.0) {
            $chunk = socket_read($clientSocket, 4096);
            if (false === $chunk || '' === $chunk) {
                break;
            }
            $response .= $chunk;
        }

        socket_close($clientSocket);

        $this->assertStringContainsString('HTTP/', $response);
        $this->assertStringContainsString('200', $response);
        $this->assertStringContainsString('Hello from Worker Pool!', $response);
    }

    #[Test]
    public function handleConnectionWithPostRequest(): void
    {
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        [$serverSocket, $clientSocket] = $pair;

        $body = '{"test": "data"}';
        $request = "POST /api HTTP/1.1\r\nHost: localhost\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body;
        socket_write($clientSocket, $request);
        socket_shutdown($clientSocket, 1);

        $this->adapter->handleConnection($serverSocket, []);

        $response = '';
        socket_set_nonblock($clientSocket);
        $start = microtime(true);
        while (microtime(true) - $start < 1.0) {
            $chunk = socket_read($clientSocket, 4096);
            if (false === $chunk || '' === $chunk) {
                break;
            }
            $response .= $chunk;
        }

        socket_close($clientSocket);

        $this->assertStringContainsString('200', $response);
    }

    #[Test]
    public function handleConnectionWithMetadata(): void
    {
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        [$serverSocket, $clientSocket] = $pair;

        $request = "GET / HTTP/1.1\r\nHost: example.com\r\nConnection: close\r\n\r\n";
        socket_write($clientSocket, $request);
        socket_shutdown($clientSocket, 1);

        $this->adapter->handleConnection($serverSocket, ['client_ip' => '127.0.0.1', 'worker_id' => 2]);

        $response = '';
        socket_set_nonblock($clientSocket);
        $start = microtime(true);
        while (microtime(true) - $start < 1.0) {
            $chunk = socket_read($clientSocket, 4096);
            if (false === $chunk || '' === $chunk) {
                break;
            }
            $response .= $chunk;
        }

        socket_close($clientSocket);

        $this->assertStringContainsString('200', $response);
    }

    #[Test]
    public function handleConnectionWithMultipleHeaders(): void
    {
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        [$serverSocket, $clientSocket] = $pair;

        $request = "GET /path?q=test HTTP/1.1\r\nHost: localhost\r\nAccept: application/json\r\nUser-Agent: TestClient/1.0\r\nX-Custom: value\r\nConnection: close\r\n\r\n";
        socket_write($clientSocket, $request);
        socket_shutdown($clientSocket, 1);

        $this->adapter->handleConnection($serverSocket, []);

        $response = '';
        socket_set_nonblock($clientSocket);
        $start = microtime(true);
        while (microtime(true) - $start < 1.0) {
            $chunk = socket_read($clientSocket, 4096);
            if (false === $chunk || '' === $chunk) {
                break;
            }
            $response .= $chunk;
        }

        socket_close($clientSocket);

        $this->assertStringContainsString('200', $response);
    }

    #[Test]
    public function handleConnectionWithIncompleteHeaders(): void
    {
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        [$serverSocket, $clientSocket] = $pair;

        socket_write($clientSocket, "INVALID\r\n");
        socket_shutdown($clientSocket, 1);

        $this->adapter->handleConnection($serverSocket, []);

        $response = '';
        socket_set_nonblock($clientSocket);
        $start = microtime(true);
        while (microtime(true) - $start < 1.0) {
            $chunk = socket_read($clientSocket, 4096);
            if (false === $chunk || '' === $chunk) {
                break;
            }
            $response .= $chunk;
        }

        socket_close($clientSocket);

        $this->assertStringContainsString('400', $response);
    }
}

<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\Worker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

use Duyler\HttpServer\ErrorHandler\ErrorHandler;
use Duyler\WorkerPool\Worker\HttpWorkerAdapter;
use Override;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Throwable;
use Psr\Log\NullLogger;

use Duyler\WorkerPool\Socket\SocketWrapper;

use function strlen;

use const AF_UNIX;
use const SOCK_STREAM;
use const STDERR;

#[Group('pcntl')]
#[CoversClass(HttpWorkerAdapter::class)]
class HttpWorkerAdapterTest extends TestCase
{
    private HttpWorkerAdapter $adapter;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->adapter = new HttpWorkerAdapter(new SocketWrapper());
    }

    #[Override]
    protected function tearDown(): void
    {
        (new ErrorHandler(new NullLogger()))->reset();
        parent::tearDown();
    }

    #[Test]
    public function creates_adapter(): void
    {
        $this->assertInstanceOf(HttpWorkerAdapter::class, $this->adapter);
    }

    #[Test]
    public function handles_simple_http_request(): void
    {
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        [$serverSocket, $clientSocket] = $pair;

        $request = "GET / HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n";
        socket_write($clientSocket, $request);

        $pid = pcntl_fork();

        if (0 === $pid) {
            socket_close($clientSocket);

            try {
                $this->adapter->handleConnection($serverSocket, []);
            } catch (Throwable $e) {
                fwrite(STDERR, $e->getMessage());
            }

            exit(0);
        }

        socket_close($serverSocket);

        $response = '';
        $previousEr = error_reporting(0);
        while (true) {
            $chunk = socket_read($clientSocket, 1024);
            if (false === $chunk || '' === $chunk) {
                break;
            }
            $response .= $chunk;
        }
        error_reporting($previousEr);

        socket_close($clientSocket);

        pcntl_waitpid($pid, $status);

        $this->assertStringContainsString('HTTP/', $response);
        $this->assertStringContainsString('200', $response);
        $this->assertStringContainsString('Hello from Worker Pool!', $response);
    }

    #[Test]
    public function reads_request_with_body(): void
    {
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        [$serverSocket, $clientSocket] = $pair;

        $body = '{"test": "data"}';
        $request = "POST /api HTTP/1.1\r\n";
        $request .= "Host: localhost\r\n";
        $request .= "Content-Type: application/json\r\n";
        $request .= "Content-Length: " . strlen($body) . "\r\n";
        $request .= "Connection: close\r\n";
        $request .= "\r\n";
        $request .= $body;

        socket_write($clientSocket, $request);

        $pid = pcntl_fork();

        if (0 === $pid) {
            socket_close($clientSocket);
            $this->adapter->handleConnection($serverSocket, []);
            exit(0);
        }

        socket_close($serverSocket);

        $response = '';
        $previousEr = error_reporting(0);
        while (true) {
            $chunk = socket_read($clientSocket, 1024);
            if (false === $chunk || '' === $chunk) {
                break;
            }
            $response .= $chunk;
        }
        error_reporting($previousEr);

        socket_close($clientSocket);
        pcntl_waitpid($pid, $status);

        $this->assertStringContainsString('HTTP/', $response);
        $this->assertStringContainsString('200', $response);
    }

    #[Test]
    public function closes_socket_after_handling(): void
    {
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        [$serverSocket, $clientSocket] = $pair;

        socket_write($clientSocket, "GET / HTTP/1.1\r\nHost: localhost\r\n\r\n");

        $pid = pcntl_fork();

        if (0 === $pid) {
            socket_close($clientSocket);
            $this->adapter->handleConnection($serverSocket, []);
            exit(0);
        }

        socket_close($serverSocket);

        sleep(1);

        $previousEr = error_reporting(0);
        socket_read($clientSocket, 1);
        error_reporting($previousEr);

        socket_close($clientSocket);
        pcntl_waitpid($pid, $status);

        $this->assertTrue(pcntl_wifexited($status));
    }

    #[Test]
    public function handles_empty_request(): void
    {
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        [$serverSocket, $clientSocket] = $pair;

        socket_write($clientSocket, '');

        $pid = pcntl_fork();

        if (0 === $pid) {
            socket_close($clientSocket);
            $this->adapter->handleConnection($serverSocket, []);
            exit(0);
        }

        socket_close($serverSocket);

        $response = '';
        $previousEr = error_reporting(0);
        while (true) {
            $chunk = socket_read($clientSocket, 1024);
            if (false === $chunk || '' === $chunk) {
                break;
            }
            $response .= $chunk;
        }
        error_reporting($previousEr);

        socket_close($clientSocket);
        pcntl_waitpid($pid, $status);

        $this->assertStringContainsString('400', $response);
        $this->assertStringContainsString('Bad Request', $response);
    }

    #[Test]
    public function handles_invalid_http_request(): void
    {
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        [$serverSocket, $clientSocket] = $pair;

        socket_write($clientSocket, "INVALID REQUEST\r\n\r\n");

        $pid = pcntl_fork();

        if (0 === $pid) {
            socket_close($clientSocket);
            $this->adapter->handleConnection($serverSocket, []);
            exit(0);
        }

        socket_close($serverSocket);

        $response = '';
        $previousEr = error_reporting(0);
        while (true) {
            $chunk = socket_read($clientSocket, 1024);
            if (false === $chunk || '' === $chunk) {
                break;
            }
            $response .= $chunk;
        }
        error_reporting($previousEr);

        socket_close($clientSocket);
        pcntl_waitpid($pid, $status);

        $this->assertStringContainsString('400', $response);
        $this->assertStringContainsString('Invalid HTTP Request', $response);
    }

    #[Test]
    public function handles_request_with_metadata(): void
    {
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        [$serverSocket, $clientSocket] = $pair;

        $request = "GET / HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n";
        socket_write($clientSocket, $request);

        $metadata = [
            'worker_id' => 1,
            'client_ip' => '127.0.0.1',
        ];

        $pid = pcntl_fork();

        if (0 === $pid) {
            socket_close($clientSocket);
            $this->adapter->handleConnection($serverSocket, $metadata);
            exit(0);
        }

        socket_close($serverSocket);

        $response = '';
        $previousEr = error_reporting(0);
        while (true) {
            $chunk = socket_read($clientSocket, 1024);
            if (false === $chunk || '' === $chunk) {
                break;
            }
            $response .= $chunk;
        }
        error_reporting($previousEr);

        socket_close($clientSocket);
        pcntl_waitpid($pid, $status);

        $this->assertStringContainsString('HTTP/', $response);
        $this->assertStringContainsString('200', $response);
    }

    #[Test]
    public function handles_request_with_multiple_headers(): void
    {
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        [$serverSocket, $clientSocket] = $pair;

        $request = "GET /api/users HTTP/1.1\r\n";
        $request .= "Host: localhost\r\n";
        $request .= "Accept: application/json\r\n";
        $request .= "Authorization: Bearer token123\r\n";
        $request .= "X-Custom-Header: value\r\n";
        $request .= "Connection: close\r\n";
        $request .= "\r\n";

        socket_write($clientSocket, $request);

        $pid = pcntl_fork();

        if (0 === $pid) {
            socket_close($clientSocket);
            $this->adapter->handleConnection($serverSocket, []);
            exit(0);
        }

        socket_close($serverSocket);

        $response = '';
        $previousEr = error_reporting(0);
        while (true) {
            $chunk = socket_read($clientSocket, 1024);
            if (false === $chunk || '' === $chunk) {
                break;
            }
            $response .= $chunk;
        }
        error_reporting($previousEr);

        socket_close($clientSocket);
        pcntl_waitpid($pid, $status);

        $this->assertStringContainsString('HTTP/', $response);
        $this->assertStringContainsString('200', $response);
    }

    #[Test]
    public function handles_chunked_request_body(): void
    {
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        [$serverSocket, $clientSocket] = $pair;

        $body = '{"name": "test", "value": 123}';
        $request = "POST /api/data HTTP/1.1\r\n";
        $request .= "Host: localhost\r\n";
        $request .= "Content-Type: application/json\r\n";
        $request .= "Content-Length: " . strlen($body) . "\r\n";
        $request .= "Connection: close\r\n";
        $request .= "\r\n";
        $request .= $body;

        socket_write($clientSocket, $request);

        $pid = pcntl_fork();

        if (0 === $pid) {
            socket_close($clientSocket);
            $this->adapter->handleConnection($serverSocket, []);
            exit(0);
        }

        socket_close($serverSocket);

        $response = '';
        $previousEr = error_reporting(0);
        while (true) {
            $chunk = socket_read($clientSocket, 1024);
            if (false === $chunk || '' === $chunk) {
                break;
            }
            $response .= $chunk;
        }
        error_reporting($previousEr);

        socket_close($clientSocket);
        pcntl_waitpid($pid, $status);

        $this->assertStringContainsString('HTTP/', $response);
        $this->assertStringContainsString('200', $response);
    }

    #[Test]
    public function handles_partial_headers(): void
    {
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        [$serverSocket, $clientSocket] = $pair;

        socket_write($clientSocket, "GET / HTTP/1.1\r\nHost: localhost");

        $pid = pcntl_fork();

        if (0 === $pid) {
            socket_close($clientSocket);
            $this->adapter->handleConnection($serverSocket, []);
            exit(0);
        }

        socket_close($serverSocket);

        $response = '';
        $previousEr = error_reporting(0);
        while (true) {
            $chunk = socket_read($clientSocket, 1024);
            if (false === $chunk || '' === $chunk) {
                break;
            }
            $response .= $chunk;
        }
        error_reporting($previousEr);

        socket_close($clientSocket);
        pcntl_waitpid($pid, $status);

        $this->assertStringContainsString('400', $response);
    }

    #[Test]
    public function handles_malformed_request_line(): void
    {
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        [$serverSocket, $clientSocket] = $pair;

        socket_write($clientSocket, "\r\n\r\n");

        $pid = pcntl_fork();

        if (0 === $pid) {
            socket_close($clientSocket);
            $this->adapter->handleConnection($serverSocket, []);
            exit(0);
        }

        socket_close($serverSocket);

        $response = '';
        $previousEr = error_reporting(0);
        while (true) {
            $chunk = socket_read($clientSocket, 1024);
            if (false === $chunk || '' === $chunk) {
                break;
            }
            $response .= $chunk;
        }
        error_reporting($previousEr);

        socket_close($clientSocket);
        pcntl_waitpid($pid, $status);

        $this->assertStringContainsString('400', $response);
        $this->assertStringContainsString('Invalid HTTP Request', $response);
    }
}

<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\Socket;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

use Duyler\WorkerPool\Socket\SocketWrapper;
use Duyler\WorkerPool\Socket\SocketWrapperInterface;
use Override;
use PHPUnit\Framework\TestCase;
use Socket;

use ValueError;

use function strlen;

use const AF_INET;
use const AF_UNIX;
use const PHP_BINARY_READ;
use const SOCK_STREAM;
use const SOL_SOCKET;
use const SOL_TCP;
use const SO_RCVTIMEO;
use const SO_REUSEADDR;

#[CoversClass(SocketWrapper::class)]
class SocketWrapperTest extends TestCase
{
    private SocketWrapperInterface $wrapper;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->wrapper = new SocketWrapper();
    }

    #[Test]
    public function creates_tcp_socket(): void
    {
        $socket = $this->wrapper->create(AF_INET, SOCK_STREAM, SOL_TCP);

        $this->assertInstanceOf(Socket::class, $socket);

        socket_close($socket);
    }

    #[Test]
    public function creates_unix_socket(): void
    {
        $socket = $this->wrapper->create(AF_UNIX, SOCK_STREAM, 0);

        $this->assertInstanceOf(Socket::class, $socket);

        socket_close($socket);
    }

    #[Test]
    public function create_throws_on_invalid_domain(): void
    {
        $this->expectException(ValueError::class);

        $this->wrapper->create(-1, -1, -1);
    }

    #[Test]
    public function binds_and_listens_tcp_socket(): void
    {
        $socket = $this->wrapper->create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertInstanceOf(Socket::class, $socket);

        $this->wrapper->setOption($socket, SOL_SOCKET, SO_REUSEADDR, 1);

        $bound = $this->wrapper->bind($socket, '127.0.0.1', 0);
        $this->assertTrue($bound);

        $listened = $this->wrapper->listen($socket, 5);
        $this->assertTrue($listened);

        socket_close($socket);
    }

    #[Test]
    public function bind_returns_false_on_used_address(): void
    {
        $socket1 = $this->wrapper->create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertInstanceOf(Socket::class, $socket1);

        $this->wrapper->setOption($socket1, SOL_SOCKET, SO_REUSEADDR, 1);
        $this->wrapper->bind($socket1, '127.0.0.1', 0);
        $this->wrapper->listen($socket1);

        socket_getsockname($socket1, $addr, $port);

        $socket2 = $this->wrapper->create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertInstanceOf(Socket::class, $socket2);

        $previousErrorReporting = error_reporting(0);
        $result = $this->wrapper->bind($socket2, '127.0.0.1', $port);
        error_reporting($previousErrorReporting);

        $this->assertFalse($result);

        socket_close($socket1);
        socket_close($socket2);
    }

    #[Test]
    public function accepts_client_connection(): void
    {
        $server = $this->wrapper->create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertInstanceOf(Socket::class, $server);

        $this->wrapper->setOption($server, SOL_SOCKET, SO_REUSEADDR, 1);
        $this->wrapper->bind($server, '127.0.0.1', 0);
        $this->wrapper->listen($server, 5);

        socket_getsockname($server, $addr, $port);

        $client = $this->wrapper->create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertInstanceOf(Socket::class, $client);

        $connected = $this->wrapper->connect($client, '127.0.0.1', $port);
        $this->assertTrue($connected);

        $accepted = $this->wrapper->accept($server);
        $this->assertInstanceOf(Socket::class, $accepted);

        socket_close($accepted);
        socket_close($client);
        socket_close($server);
    }

    #[Test]
    public function accept_returns_false_when_no_pending(): void
    {
        $server = $this->wrapper->create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertInstanceOf(Socket::class, $server);

        $this->wrapper->setOption($server, SOL_SOCKET, SO_REUSEADDR, 1);
        $this->wrapper->bind($server, '127.0.0.1', 0);
        $this->wrapper->listen($server, 5);
        $this->wrapper->setNonBlock($server);

        $result = $this->wrapper->accept($server);

        $this->assertFalse($result);

        socket_close($server);
    }

    #[Test]
    public function reads_and_writes_through_socket_pair(): void
    {
        $pair = [];
        $created = $this->wrapper->createPair(AF_UNIX, SOCK_STREAM, 0, $pair);

        $this->assertTrue($created);
        $this->assertCount(2, $pair);

        [$sock1, $sock2] = $pair;

        $written = $this->wrapper->write($sock1, 'hello');
        $this->assertSame(5, $written);

        $data = $this->wrapper->read($sock2, 1024);
        $this->assertSame('hello', $data);

        socket_close($sock1);
        socket_close($sock2);
    }

    #[Test]
    public function write_with_explicit_length(): void
    {
        $pair = [];
        $this->wrapper->createPair(AF_UNIX, SOCK_STREAM, 0, $pair);
        [$sock1, $sock2] = $pair;

        $written = $this->wrapper->write($sock1, 'hello world', 5);
        $this->assertSame(5, $written);

        $data = $this->wrapper->read($sock2, 1024);
        $this->assertSame('hello', $data);

        socket_close($sock1);
        socket_close($sock2);
    }

    #[Test]
    public function read_returns_empty_string_on_closed_pair(): void
    {
        $pair = [];
        $this->wrapper->createPair(AF_UNIX, SOCK_STREAM, 0, $pair);
        [$sock1, $sock2] = $pair;

        $this->wrapper->close($sock1);

        $data = $this->wrapper->read($sock2, 1024);

        $this->assertSame('', $data);

        socket_close($sock2);
    }

    #[Test]
    public function read_returns_false_on_nonblocking_no_data(): void
    {
        $pair = [];
        $this->wrapper->createPair(AF_UNIX, SOCK_STREAM, 0, $pair);
        [$sock1, $sock2] = $pair;

        $this->wrapper->setNonBlock($sock2);

        $data = $this->wrapper->read($sock2, 1024);

        $this->assertFalse($data);

        socket_close($sock1);
        socket_close($sock2);
    }

    #[Test]
    public function closes_socket_without_error(): void
    {
        $socket = $this->wrapper->create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertInstanceOf(Socket::class, $socket);

        $this->wrapper->close($socket);

        $this->assertTrue(true);
    }

    #[Test]
    public function sets_nonblocking_mode(): void
    {
        $socket = $this->wrapper->create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertInstanceOf(Socket::class, $socket);

        $this->wrapper->setNonBlock($socket);

        $this->assertTrue(true);

        socket_close($socket);
    }

    #[Test]
    public function sets_socket_option(): void
    {
        $socket = $this->wrapper->create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertInstanceOf(Socket::class, $socket);

        $result = $this->wrapper->setOption($socket, SOL_SOCKET, SO_REUSEADDR, 1);

        $this->assertTrue($result);

        socket_close($socket);
    }

    #[Test]
    public function sets_socket_option_with_array_value(): void
    {
        $socket = $this->wrapper->create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertInstanceOf(Socket::class, $socket);

        $result = $this->wrapper->setOption($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 5, 'usec' => 0]);

        $this->assertTrue($result);

        socket_close($socket);
    }

    #[Test]
    public function getPeerName_returns_peer_address(): void
    {
        $server = $this->wrapper->create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertInstanceOf(Socket::class, $server);

        $this->wrapper->setOption($server, SOL_SOCKET, SO_REUSEADDR, 1);
        $this->wrapper->bind($server, '127.0.0.1', 0);
        $this->wrapper->listen($server, 5);

        socket_getsockname($server, $addr, $port);

        $client = $this->wrapper->create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertInstanceOf(Socket::class, $client);

        $this->wrapper->connect($client, '127.0.0.1', $port);

        $accepted = $this->wrapper->accept($server);
        $this->assertInstanceOf(Socket::class, $accepted);

        $peerAddress = '';
        $peerPort = 0;

        $result = $this->wrapper->getPeerName($accepted, $peerAddress, $peerPort);

        $this->assertTrue($result);
        $this->assertSame('127.0.0.1', $peerAddress);
        $this->assertGreaterThan(0, $peerPort);

        socket_close($accepted);
        socket_close($client);
        socket_close($server);
    }

    #[Test]
    public function lastError_returns_int(): void
    {
        $socket = $this->wrapper->create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertInstanceOf(Socket::class, $socket);

        $error = $this->wrapper->lastError($socket);

        $this->assertIsInt($error);

        socket_close($socket);
    }

    #[Test]
    public function lastError_returns_int_without_socket(): void
    {
        $error = $this->wrapper->lastError();

        $this->assertIsInt($error);
    }

    #[Test]
    public function strerror_returns_string(): void
    {
        $message = $this->wrapper->strerror(0);

        $this->assertIsString($message);
    }

    #[Test]
    public function strerror_returns_description_for_known_error(): void
    {
        $message = $this->wrapper->strerror(111);

        $this->assertIsString($message);
        $this->assertNotEmpty($message);
    }

    #[Test]
    public function select_detects_readable_socket(): void
    {
        $pair = [];
        $this->wrapper->createPair(AF_UNIX, SOCK_STREAM, 0, $pair);
        [$sock1, $sock2] = $pair;

        $this->wrapper->write($sock1, 'data');

        $read = [$sock2];
        $write = null;
        $except = null;

        $result = $this->wrapper->select($read, $write, $except, 1, 0);

        $this->assertGreaterThanOrEqual(1, $result);
        $this->assertContains($sock2, $read);

        socket_close($sock1);
        socket_close($sock2);
    }

    #[Test]
    public function select_returns_zero_when_no_readable_data(): void
    {
        $pair = [];
        $this->wrapper->createPair(AF_UNIX, SOCK_STREAM, 0, $pair);
        [$sock1, $sock2] = $pair;

        $read = [$sock2];
        $write = null;
        $except = null;

        $result = $this->wrapper->select($read, $write, $except, 0, 0);

        $this->assertSame(0, $result);

        socket_close($sock1);
        socket_close($sock2);
    }

    #[Test]
    public function createPair_creates_two_connected_sockets(): void
    {
        $pair = [];
        $result = $this->wrapper->createPair(AF_UNIX, SOCK_STREAM, 0, $pair);

        $this->assertTrue($result);
        $this->assertCount(2, $pair);
        $this->assertInstanceOf(Socket::class, $pair[0]);
        $this->assertInstanceOf(Socket::class, $pair[1]);

        $written = socket_write($pair[0], 'pair test');
        $this->assertGreaterThan(0, $written);

        $data = socket_read($pair[1], 1024);
        $this->assertSame('pair test', $data);

        socket_close($pair[0]);
        socket_close($pair[1]);
    }

    #[Test]
    public function connect_to_listening_server(): void
    {
        $server = $this->wrapper->create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertInstanceOf(Socket::class, $server);

        $this->wrapper->setOption($server, SOL_SOCKET, SO_REUSEADDR, 1);
        $this->wrapper->bind($server, '127.0.0.1', 0);
        $this->wrapper->listen($server, 5);

        socket_getsockname($server, $addr, $port);

        $client = $this->wrapper->create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertInstanceOf(Socket::class, $client);

        $result = $this->wrapper->connect($client, '127.0.0.1', $port);

        $this->assertTrue($result);

        socket_close($client);
        socket_close($server);
    }

    #[Test]
    public function connect_returns_false_on_refused(): void
    {
        $client = $this->wrapper->create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertInstanceOf(Socket::class, $client);

        $previousErrorReporting = error_reporting(0);
        $result = $this->wrapper->connect($client, '127.0.0.1', 1);
        error_reporting($previousErrorReporting);

        $this->assertFalse($result);

        socket_close($client);
    }

    #[Test]
    public function read_uses_binary_mode_by_default(): void
    {
        $pair = [];
        $this->wrapper->createPair(AF_UNIX, SOCK_STREAM, 0, $pair);
        [$sock1, $sock2] = $pair;

        $binaryData = "hello\x00world";
        $this->wrapper->write($sock1, $binaryData);

        $data = $this->wrapper->read($sock2, 1024, PHP_BINARY_READ);

        $this->assertSame($binaryData, $data);

        socket_close($sock1);
        socket_close($sock2);
    }

    #[Test]
    public function full_client_server_roundtrip(): void
    {
        $server = $this->wrapper->create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertInstanceOf(Socket::class, $server);

        $this->wrapper->setOption($server, SOL_SOCKET, SO_REUSEADDR, 1);
        $this->wrapper->bind($server, '127.0.0.1', 0);
        $this->wrapper->listen($server, 5);

        socket_getsockname($server, $addr, $port);

        $client = $this->wrapper->create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertInstanceOf(Socket::class, $client);

        $this->wrapper->connect($client, '127.0.0.1', $port);

        $accepted = $this->wrapper->accept($server);
        $this->assertInstanceOf(Socket::class, $accepted);

        $request = 'GET / HTTP/1.1';
        $written = $this->wrapper->write($client, $request);
        $this->assertSame(strlen($request), $written);

        $received = $this->wrapper->read($accepted, 4096);
        $this->assertSame($request, $received);

        $response = 'HTTP/1.1 200 OK';
        $this->wrapper->write($accepted, $response);

        $responseData = $this->wrapper->read($client, 4096);
        $this->assertSame($response, $responseData);

        socket_close($accepted);
        socket_close($client);
        socket_close($server);
    }
}

# Duyler Worker Pool

[![Quality Gate Status](https://sonarcloud.io/api/project_badges/measure?project=duyler_worker-pool&metric=alert_status)](https://sonarcloud.io/summary/new_code?id=duyler_worker-pool)
[![Coverage](https://sonarcloud.io/api/project_badges/measure?project=duyler_worker-pool&metric=coverage)](https://sonarcloud.io/summary/new_code?id=duyler_worker-pool)
[![type-coverage](https://shepherd.dev/github/duyler/worker-pool/coverage.svg)](https://shepherd.dev/github/duyler/worker-pool)
[![psalm-level](https://shepherd.dev/github/duyler/worker-pool/level.svg)](https://shepherd.dev/github/duyler/worker-pool)
![PHP Version](https://img.shields.io/packagist/dependency-v/duyler/worker-pool/php?version=dev-main)

Process manager with load balancing for Duyler Framework.

## Features

- Multi-process worker pool with fork-based workers
- Two architecture modes: Shared Socket (SO_REUSEPORT) and Centralized (FD Passing)
- Load balancing: Least Connections, Round Robin
- IPC via Unix domain sockets with JSON-serialized messages
- File descriptor passing (SCM_RIGHTS) for connection distribution
- Signal handling: SIGTERM, SIGINT, SIGCHLD, SIGUSR1, SIGUSR2
- Auto CPU core detection for optimal worker count
- Event-driven workers with Fiber-based event loop integration
- Callback workers for simple connection handling
- HTTP worker adapter with PSR-7 request parsing
- Process monitoring with uptime, idle time, memory tracking
- Auto-restart on worker failure with configurable delay
- Graceful shutdown

## Requirements

- PHP 8.4+
- ext-sockets
- ext-pcntl
- ext-posix
- duyler/http-server
- nyholm/psr7
- psr/log (optional, for logging)

## Installation

```bash
composer require duyler/worker-pool
```

## Quick Start

### Event-Driven Worker Mode

This is the recommended mode for production HTTP servers. Each worker runs its own event loop with a full `Server` instance. The kernel distributes connections across workers via `SO_REUSEPORT`.

```php
use Duyler\HttpServer\Config\ServerConfig;
use Duyler\WorkerPool\Config\WorkerPoolConfig;
use Duyler\WorkerPool\Master\MasterFactory;
use Duyler\WorkerPool\Worker\EventDrivenWorkerInterface;

// Implement EventDrivenWorkerInterface (see Worker Types section for full example)
$worker = new MyApp(); // implements EventDrivenWorkerInterface

$serverConfig = new ServerConfig(host: '0.0.0.0', port: 8080);
$poolConfig = WorkerPoolConfig::auto($serverConfig);

$master = MasterFactory::createRecommended(
    config: $poolConfig,
    serverConfig: $serverConfig,
    eventDrivenWorker: $worker,
);

$master->start(); // blocks until shutdown
```

### Callback Worker Mode

For simpler use cases where you handle raw sockets directly.

```php
use Duyler\HttpServer\Config\ServerConfig;
use Duyler\WorkerPool\Config\WorkerPoolConfig;
use Duyler\WorkerPool\Master\MasterFactory;
use Duyler\WorkerPool\Worker\WorkerCallbackInterface;

$callback = new class implements WorkerCallbackInterface
{
    public function handle(mixed $clientSocket, array $metadata): void
    {
        $response = "HTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\nHello";
        socket_write($clientSocket, $response);
        socket_close($clientSocket);
    }
};

$serverConfig = new ServerConfig(host: '0.0.0.0', port: 8080);
$poolConfig = new WorkerPoolConfig(serverConfig: $serverConfig, workerCount: 4);

$master = MasterFactory::createRecommended(
    config: $poolConfig,
    serverConfig: $serverConfig,
    workerCallback: $callback,
);

$master->start();
```

## Configuration

### WorkerPoolConfig

```php
use Duyler\HttpServer\Config\ServerConfig;
use Duyler\WorkerPool\Config\BalancerType;
use Duyler\WorkerPool\Config\WorkerPoolConfig;

$config = new WorkerPoolConfig(
    serverConfig: new ServerConfig(),  // Required. HTTP server configuration.
    workerCount: 0,                    // Number of workers. 0 = auto-detect CPU cores.
    balancer: BalancerType::LeastConnections,  // Balancer algorithm (for CentralizedMaster).
    backlog: 128,                      // Socket listen backlog.
    maxQueueSize: 1000,                // Max connections in centralized queue.
    maxIpcMessageSize: 1048576,        // Max IPC message size in bytes (min 1024).
    enableStickySession: false,        // Sticky session support (planned).
    enableGracefulReload: false,       // Graceful reload on SIGUSR1 (planned).
    autoRestart: true,                 // Auto-restart workers on failure.
    restartDelay: 1,                   // Seconds to wait before respawning a dead worker.
    fallbackCpuCores: 4,              // Fallback CPU core count if detection fails.
    pollInterval: 1000,               // Main loop poll interval in microseconds (min 100).
);
```

#### Factory Method

`WorkerPoolConfig::auto()` creates a config with automatic CPU core detection:

```php
$config = WorkerPoolConfig::auto(
    serverConfig: new ServerConfig(),
    balancer: BalancerType::LeastConnections,
);
```

#### Validation Rules

- `workerCount`: 1 to 1024 (or 0 for auto-detection)
- `backlog`: must be positive
- `maxQueueSize`: must be positive
- `maxIpcMessageSize`: at least 1024 bytes
- `restartDelay`: non-negative
- `fallbackCpuCores`: positive
- `pollInterval`: at least 100 microseconds

## Load Balancing

Load balancing is used in `CentralizedMaster` mode. The `BalancerType` enum defines the available algorithms:

- `BalancerType::LeastConnections` -- picks the worker with the fewest active connections
- `BalancerType::RoundRobin` -- cycles through workers in order
- `BalancerType::Weighted` -- planned for future implementation

### Least Connections

Best when request processing times vary. Workers with fewer active connections receive new ones first.

```php
use Duyler\WorkerPool\Balancer\LeastConnectionsBalancer;

$balancer = new LeastConnectionsBalancer();
```

### Round Robin

Best when request processing times are roughly equal. Distributes connections sequentially.

```php
use Duyler\WorkerPool\Balancer\RoundRobinBalancer;

$balancer = new RoundRobinBalancer();
```

### When to Use Which

| Scenario | Recommendation |
|----------|---------------|
| Uniform request times | Round Robin |
| Varied request times | Least Connections |
| Need simple fairness | Round Robin |
| Need adaptive distribution | Least Connections |

## Architecture

### Shared Socket (SO_REUSEPORT)

Each worker binds its own socket to the same port using `SO_REUSEPORT`. The kernel distributes incoming connections across workers. No IPC overhead for connection routing.

```
                        Client requests
                              |
                              v
                    +---------+---------+
                    |   Kernel balances  |
                    +---------+---------+
                              |
           +--------+--------+--------+--------+
           |        |        |        |        |
           v        v        v        v        v
        +-----+  +-----+  +-----+  +-----+  +-----+
        | W1  |  | W2  |  | W3  |  | W4  |  | WN  |
        |:8080|  |:8080|  |:8080|  |:8080|  |:8080|
        +-----+  +-----+  +-----+  +-----+  +-----+

        Master process monitors workers (health, respawn)
```

Requirements: `SO_REUSEPORT` support (Linux, Docker, macOS via Docker).

Use `SharedSocketMaster` when you want simple architecture, kernel-level load balancing is sufficient, or you need maximum compatibility.

### Centralized (FD Passing)

The master process accepts all connections and distributes them to workers via IPC using file descriptor passing (`SCM_RIGHTS`). This enables custom load balancing and sticky sessions.

```
                        Client requests
                              |
                              v
                    +---------+---------+
                    |   Master accepts   |
                    |   all connections  |
                    +---------+---------+
                              |
                    +---------+---------+
                    |  Connection Queue  |
                    +---------+---------+
                              |
                    +---------+---------+
                    | Load Balancer picks|
                    | a worker           |
                    +---------+---------+
                              |
              +-------+------+------+-------+
              |       |      |      |       |
              v       v      v      v       v
           +----+  +----+  +----+  +----+  +----+
           | W1 |  | W2 |  | W3 |  | W4 |  | WN |
           +----+  +----+  +----+  +----+  +----+
              ^       ^      ^      ^       ^
              |       |      |      |       |
              +-- FD passed via Unix socket -+
```

Requirements: Linux only (`SCM_RIGHTS`, `socket_sendmsg`, `socket_recvmsg`).

Use `CentralizedMaster` when you need custom load balancing, sticky sessions, or centralized connection queue.

### Choosing with MasterFactory

`MasterFactory` selects the best architecture automatically based on platform capabilities. It also handles creating the required DI wrappers (`SocketWrapper`, `ForkWrapper`, `SocketMsgWrapper`) so you do not have to wire them manually.

```php
use Duyler\WorkerPool\Balancer\LeastConnectionsBalancer;
use Duyler\WorkerPool\Master\MasterFactory;

// Automatically picks CentralizedMaster on Linux (FD passing available),
// SharedSocketMaster on other platforms.
$master = MasterFactory::createRecommended(
    config: $poolConfig,
    serverConfig: $serverConfig,
    eventDrivenWorker: new MyApp(),
);

// Or create with explicit balancer (CentralizedMaster when FD passing supported)
$master = MasterFactory::create(
    config: $poolConfig,
    serverConfig: $serverConfig,
    eventDrivenWorker: new MyApp(),
    balancer: new LeastConnectionsBalancer(),
);

// Check what the factory would pick
echo MasterFactory::recommendedMaster();
// "CentralizedMaster - Centralized queue with custom load balancing" (Linux)
// "SharedSocketMaster - Distributed architecture with kernel load balancing" (other)

// Get comparison of both modes
$comparison = MasterFactory::getComparison();
```

## Worker Types

### EventDrivenWorkerInterface

The primary worker type for applications with their own event loop. The `run()` method is called once on worker startup and never returns. The master passes connections to the `Server` instance, and the application polls `hasRequest()` in its loop.

```php
use Duyler\HttpServer\ServerInterface;
use Duyler\WorkerPool\Worker\EventDrivenWorkerInterface;

class MyApp implements EventDrivenWorkerInterface
{
    public function run(int $workerId, ServerInterface $server): void
    {
        // Initialize once (database, event bus, etc.)
        $db = new Database();

        // Application event loop
        while (true) {
            if ($server->hasRequest()) {
                $requestData = $server->getRequest();
                if ($requestData !== null) {
                    $response = $this->handle($requestData->request, $db);
                    $server->respond($requestData->respond($response));
                }
            }
            usleep(1000);
        }
    }
}
```

The `ServerInterface` instance passed to `run()` provides:

- `hasRequest(): bool` -- check for pending requests
- `getRequest(): ?RequestData` -- get next request
- `respond(ResponseData $responseData): void` -- send response
- `hasPendingResponse(): bool` -- check for pending responses
- `enableNotification(): void` -- enable notification socket pair
- `getSocketResource(): mixed` -- get socket for EvIo integration
- `setEventLoopActive(bool $active): void` -- set event loop active flag

### WorkerCallbackInterface

A simpler worker type for direct socket handling. The `handle()` method is called for each incoming connection with the raw client socket.

```php
use Duyler\WorkerPool\Worker\WorkerCallbackInterface;

class RawHandler implements WorkerCallbackInterface
{
    public function handle(mixed $clientSocket, array $metadata): void
    {
        // $clientSocket is Socket|resource
        // $metadata contains 'worker_id', 'client_ip'

        socket_write($clientSocket, "HTTP/1.1 200 OK\r\n\r\nOK");
        socket_close($clientSocket);
    }
}
```

### HttpWorkerAdapter

A ready-made adapter that handles HTTP parsing and PSR-7 conversion for callback-style workers. Useful as a starting point or for simple HTTP endpoints. Requires a `SocketWrapperInterface` instance for socket operations.

```php
use Duyler\WorkerPool\Socket\SocketWrapper;
use Duyler\WorkerPool\Worker\HttpWorkerAdapter;

$adapter = new HttpWorkerAdapter(new SocketWrapper());
$adapter->handleConnection($clientSocket, ['worker_id' => $workerId]);
// Returns "Hello from Worker Pool!" as a plain text 200 response.
// Override processRequest() for custom logic.
```

## IPC System

### UnixSocketChannel

Point-to-point IPC channel over Unix domain sockets with length-prefixed JSON messages. Requires a `SocketWrapperInterface` instance for socket operations.

```php
use Duyler\WorkerPool\IPC\UnixSocketChannel;
use Duyler\WorkerPool\IPC\Message;
use Duyler\WorkerPool\IPC\MessageType;
use Duyler\WorkerPool\Socket\SocketWrapper;

$socketWrapper = new SocketWrapper();

// Server side
$channel = new UnixSocketChannel('/tmp/worker.sock', socketWrapper: $socketWrapper, isServer: true);
$channel->connect();
$channel->send(Message::workerReady(workerId: 1));

// Client side
$channel = new UnixSocketChannel('/tmp/worker.sock', socketWrapper: $socketWrapper, isServer: false);
$channel->connect();
$msg = $channel->receive();
// $msg->type === MessageType::WorkerReady
// $msg->data === ['worker_id' => 1]
```

### Message

Structured IPC message with type, data, and timestamp.

```php
use Duyler\WorkerPool\IPC\Message;
use Duyler\WorkerPool\IPC\MessageType;

// Create messages
$msg = new Message(type: MessageType::Shutdown, data: ['reason' => 'restart']);

// Factory methods
Message::workerReady(workerId: 1);
Message::connectionClosed(connectionId: 42);
Message::workerMetrics(metrics: ['memory' => 64000]);
Message::shutdown();
Message::reload();

// Serialization
$serialized = $msg->serialize(); // JSON string
$restored = Message::unserialize($serialized);
```

### MessageType

```php
enum MessageType: string
{
    case ConnectionClosed = 'connection_closed';
    case WorkerReady = 'worker_ready';
    case WorkerMetrics = 'worker_metrics';
    case Shutdown = 'shutdown';
    case Reload = 'reload';
}
```

### FdPasser

Passes file descriptors between processes via `SCM_RIGHTS`. Used internally by `CentralizedMaster` and `ConnectionRouter`. Requires Linux. Takes `SocketWrapperInterface` and `SocketMsgWrapperInterface` via constructor injection.

```php
use Duyler\WorkerPool\IPC\FdPasser;
use Duyler\WorkerPool\Socket\SocketMsgWrapper;
use Duyler\WorkerPool\Socket\SocketWrapper;

$fdPasser = new FdPasser(new SocketWrapper(), new SocketMsgWrapper());

// Check platform support
$supported = $fdPasser->isSupported(); // true on Linux with socket_sendmsg

// Send a file descriptor
$fdPasser->sendFd(
    controlSocket: $masterToWorkerSocket,
    fdToSend: $clientSocket,
    metadata: ['client_ip' => '192.168.1.1', 'worker_id' => 2],
);

// Receive a file descriptor
$result = $fdPasser->receiveFd($workerSocket);
// $result === ['fd' => Socket, 'metadata' => ['client_ip' => '...', 'worker_id' => 2]]
// or null if no FD available
```

## Signal Handling

The worker pool handles POSIX signals for graceful lifecycle management.

### Master Process Signals

| Signal | Behavior |
|--------|----------|
| SIGTERM | Triggers graceful shutdown. Sends SIGTERM to all workers, waits for them to exit. |
| SIGINT | Same as SIGTERM. Triggered by Ctrl+C. |
| SIGUSR1 | Reload trigger (via `SignalManager`). Available for graceful reload. |
| SIGUSR2 | Available for custom handlers. |

### Worker Process Signals

Workers inherit signal handling from the master. In event-driven mode, the application is responsible for handling signals within its event loop.

### SignalHandler

Low-level signal registration and dispatch:

```php
use Duyler\WorkerPool\Signal\SignalHandler;

$handler = new SignalHandler();
$handler->register(SIGTERM, function (): void {
    // Handle shutdown
});
$handler->dispatch(); // call pcntl_signal_dispatch()
$handler->unregister(SIGTERM);
```

### SignalManager

Higher-level manager with shutdown/reload state tracking:

```php
use Duyler\WorkerPool\Signal\SignalHandler;
use Duyler\WorkerPool\Signal\SignalManager;

$manager = new SignalManager(new SignalHandler());

$manager->setupMasterSignals(
    onShutdown: function (int $signal): void { /* cleanup */ },
    onReload: function (int $signal): void { /* reload config */ },
);

// Check flags
$manager->isShutdownRequested(); // bool
$manager->isReloadRequested();   // bool
```

## API Reference

### MasterInterface

```php
interface MasterInterface
{
    public function start(): void;
    public function stop(): void;
    public function isRunning(): bool;
    /** @return array<string, mixed> */
    public function getMetrics(): array;
}
```

### SharedSocketMaster

```php
final class SharedSocketMaster extends AbstractMaster
{
    public function __construct(
        WorkerPoolConfig $config,
        ServerConfig $serverConfig,
        SocketWrapperInterface $socketWrapper,
        ForkWrapperInterface $forkWrapper,
        ?WorkerCallbackInterface $workerCallback = null,
        ?EventDrivenWorkerInterface $eventDrivenWorker = null,
        ?LoggerInterface $logger = null,
    );
    public function start(): void;
    public function stop(): void;
    public function isRunning(): bool;
    /** @return array<string, mixed> */
    public function getMetrics(): array;
}
```

Note: Use `MasterFactory::createRecommended()` to avoid constructing DI wrappers manually.

Metrics returned by `getMetrics()`:

```php
[
    'architecture' => 'shared_socket',
    'total_workers' => 4,
    'active_workers' => 4,
    'total_connections' => 128,
    'is_running' => true,
]
```

### CentralizedMaster

```php
final class CentralizedMaster extends AbstractMaster
{
    public function __construct(
        WorkerPoolConfig $config,
        BalancerInterface $balancer,
        SocketWrapperInterface $socketWrapper,
        SocketMsgWrapperInterface $socketMsgWrapper,
        ForkWrapperInterface $forkWrapper,
        ?ServerConfig $serverConfig = null,
        ?WorkerCallbackInterface $workerCallback = null,
        ?EventDrivenWorkerInterface $eventDrivenWorker = null,
        ?LoggerInterface $logger = null,
    );
    public function start(): void;
    public function stop(): void;
    public function isRunning(): bool;
    /** @return array<string, mixed> */
    public function getMetrics(): array;
    public function getBalancer(): BalancerInterface;
}
```

Note: Use `MasterFactory::create()` to avoid constructing DI wrappers manually.

Metrics returned by `getMetrics()`:

```php
[
    'total_workers' => 4,
    'alive_workers' => 4,
    'total_connections' => 128,
    'total_requests' => 1024,
    'queue_size' => 3,
    'is_running' => true,
]
```

### MasterFactory

```php
final class MasterFactory
{
    public static function create(
        WorkerPoolConfig $config,
        ServerConfig $serverConfig,
        ?WorkerCallbackInterface $workerCallback = null,
        ?EventDrivenWorkerInterface $eventDrivenWorker = null,
        ?BalancerInterface $balancer = null,
        ?LoggerInterface $logger = null,
        ?SocketWrapperInterface $socketWrapper = null,
        ?SocketMsgWrapperInterface $socketMsgWrapper = null,
        ?ForkWrapperInterface $forkWrapper = null,
    ): MasterInterface;

    public static function createRecommended(
        WorkerPoolConfig $config,
        ServerConfig $serverConfig,
        ?WorkerCallbackInterface $workerCallback = null,
        ?EventDrivenWorkerInterface $eventDrivenWorker = null,
        ?LoggerInterface $logger = null,
        ?SocketWrapperInterface $socketWrapper = null,
        ?SocketMsgWrapperInterface $socketMsgWrapper = null,
        ?ForkWrapperInterface $forkWrapper = null,
    ): MasterInterface;

    public static function recommendedMaster(): string;
    /** @return array<string, array<string, string>> */
    public static function getComparison(): array;
}
```

The `socketWrapper`, `socketMsgWrapper`, and `forkWrapper` parameters default to their concrete implementations (`SocketWrapper`, `SocketMsgWrapper`, `ForkWrapper`). Pass custom implementations for testing or when you need to override low-level behavior.

### BalancerInterface

```php
interface BalancerInterface
{
    /** @param array<int, int> $connections worker_id => active_connections */
    public function selectWorker(array $connections): ?int;
    public function onConnectionEstablished(int $workerId): void;
    public function onConnectionClosed(int $workerId): void;
    public function onWorkerRemoved(int $workerId): void;
    public function reset(): void;
}
```

### ProcessInfo

Immutable value object representing a worker process:

```php
final readonly class ProcessInfo
{
    public float $startedAt;
    public float $lastActivityAt;

    public function __construct(
        public int $workerId,
        public int $pid,
        public ProcessState $state,
        ForkWrapperInterface $forkWrapper,
        public int $connections = 0,
        public int $totalRequests = 0,
        ?float $startedAt = null,
        ?float $lastActivityAt = null,
        public int $memoryUsage = 0,
    );

    public function withState(ProcessState $state): self;
    public function withConnections(int $connections): self;
    public function withIncrementedRequests(): self;
    public function withMemoryUsage(int $memoryUsage): self;
    public function getUptime(): float;      // seconds since start
    public function getIdleTime(): float;     // seconds since last activity
    public function isAlive(): bool;          // checks via forkWrapper.kill(pid, 0)
    /** @return array<string, mixed> */
    public function toArray(): array;
}
```

### ProcessState

```php
enum ProcessState: string
{
    case Starting = 'starting';
    case Ready = 'ready';
    case Busy = 'busy';
    case Stopping = 'stopping';
    case Stopped = 'stopped';
    case Failed = 'failed';
}
```

### BalancerType

```php
enum BalancerType: string
{
    case LeastConnections = 'least_connections';
    case RoundRobin = 'round_robin';
    case Weighted = 'weighted';
}
```

### DI Wrappers

The refactored codebase uses constructor injection for all low-level system calls. These wrappers enable unit testing without real sockets, processes, or signals.

#### SocketWrapperInterface

```php
interface SocketWrapperInterface
{
    public function create(int $domain, int $type, int $protocol): Socket|false;
    public function bind(Socket $socket, string $address, int $port = 0): bool;
    public function listen(Socket $socket, int $backlog = 0): bool;
    public function accept(Socket $socket): Socket|false;
    public function read(Socket $socket, int $length, int $type = PHP_BINARY_READ): string|false;
    public function write(Socket $socket, string $data, ?int $length = null): int|false;
    public function close(Socket $socket): void;
    public function setNonBlock(Socket $socket): void;
    public function setOption(Socket $socket, int $level, int $name, int|array $value): bool;
    public function getPeerName(Socket $socket, string &$address, ?int &$port = null): bool;
    public function lastError(?Socket $socket = null): int;
    public function strerror(int $errorCode): string;
    public function select(?array &$read, ?array &$write, ?array &$except, int $timeout, int $usec = 0): int|false;
    public function createPair(int $domain, int $type, int $protocol, array &$pair): bool;
    public function connect(Socket $socket, string $address, ?int $port = null): bool;
}
```

#### SocketMsgWrapperInterface

```php
interface SocketMsgWrapperInterface
{
    public function sendmsg(Socket $socket, array $message, int $flags = 0): int|false;
    public function recvmsg(Socket $socket, array &$message, int $flags = 0): int|false;
    public function cmsgSpace(int $level, int $type, int $n = 0): ?int;
}
```

#### ForkWrapperInterface

```php
interface ForkWrapperInterface
{
    public function fork(): int;
    public function waitpid(int $pid, int &$status, int $options = 0): int;
    public function kill(int $pid, int $signal): bool;
}
```

### SystemInfo

```php
use Duyler\WorkerPool\Util\SystemInfo;

final class SystemInfo
{
    public function getCpuCores(int $fallback = 4): int;
    /** @return array<string, mixed> */
    public function getOsInfo(): array;
    public function isContainerEnvironment(): bool;
    public function supportsFdPassing(): bool;    // Linux + SCM_RIGHTS
    public function supportsReusePort(): bool;     // SO_REUSEPORT defined
    public static function resetCache(): void;
}
```

### Exceptions

```php
// Base class for all worker-pool exceptions
abstract class WorkerPoolExceptionBase extends Exception
{
    public function getErrorCode(): string;   // e.g. 'WORKER_POOL_ERROR'
    public function getContext(): array;
}

// General pool errors (fork failure, socket creation, etc.)
final class WorkerPoolException extends WorkerPoolExceptionBase
{
    protected string $errorCode = 'WORKER_POOL_ERROR';
}

// IPC-related errors (socket_sendmsg unavailable, bad message format)
final class IPCException extends WorkerPoolExceptionBase
{
    protected string $errorCode = 'IPC_ERROR';
}
```

## Integration with HttpServer

The worker-pool package depends on `duyler/http-server` and integrates tightly with `Duyler\HttpServer\Server`.

### How It Works

1. Master creates a `Server` instance in each worker process
2. Master sets the worker ID via `$server->setWorkerId($workerId)`
3. Master passes an external socket via `$server->setExternalSocketResource($socket)`
4. Master enables notifications via `$server->enableNotification()`
5. In CentralizedMaster, master registers a Fiber that receives FDs and calls `$server->addExternalConnection()`
6. The application's `EventDrivenWorkerInterface::run()` polls `$server->hasRequest()`
7. Responses go back through `$server->respond()`

### ServerConfig for Worker Pool

Pass the same `ServerConfig` to both `WorkerPoolConfig` and the master:

```php
use Duyler\HttpServer\Config\ServerConfig;
use Duyler\WorkerPool\Config\WorkerPoolConfig;

$serverConfig = new ServerConfig(
    host: '0.0.0.0',
    port: 8080,
    maxConnections: 1000,
    requestTimeout: 30,
    connectionTimeout: 60,
);

$poolConfig = new WorkerPoolConfig(
    serverConfig: $serverConfig,
    workerCount: 0, // auto-detect
);
```

### Reactive Event Loop (EvIo)

For zero-overhead wakeup, use the notification socket with the `ev` extension:

```php
use Ev;
use EvIo;
use EvSignal;
use Duyler\HttpServer\ServerInterface;
use Duyler\WorkerPool\Worker\EventDrivenWorkerInterface;

final class ReactiveApp implements EventDrivenWorkerInterface
{
    public function run(int $workerId, ServerInterface $server): void
    {
        $server->enableNotification();
        $notifySocket = $server->getSocketResource();

        $io = new EvIo($notifySocket, Ev::READ, function () use ($server): void {
            // Clear notification buffer
            $socket = $server->getSocketResource();
            if ($socket instanceof \Socket) {
                $er = error_reporting(0);
                socket_read($socket, 4096);
                error_reporting($er);
            }

            $server->setEventLoopActive(true);
            try {
                while ($server->hasRequest()) {
                    $requestData = $server->getRequest();
                    if ($requestData === null) break;
                    // ... handle request, call respond()
                }
            } finally {
                $server->setEventLoopActive(false);
            }
        });

        $sigTerm = new EvSignal(SIGTERM, function () use ($server): void {
            $server->stop();
            Ev::stop(Ev::BREAK_ALL);
        });

        Ev::run();
    }
}
```

Note: In `CentralizedMaster` mode, the Unix socket pair detects IPC activity (FD passing) but not HTTP data on passed client sockets. An `EvTimer` fallback in the event bus configuration is recommended for that mode.

## Testing

```bash
make tests      # Run all tests
make coverage   # Run tests with coverage
make psalm      # Run Psalm static analysis
make cs-fix     # Run PHP-CS-Fixer
make rector     # Run Rector refactoring
```

### Test Groups

Tests are organized into five groups by purpose:

| Group | Directory | Description |
|-------|-----------|-------------|
| Unit | `tests/Unit/` | Isolated tests with mocked DI wrappers |
| Integration | `tests/Integration/` | Cross-component tests with real sockets |
| Functional | `tests/worker-pool/` | End-to-end worker pool scenarios |
| Performance | `tests/Performance/` | Baseline benchmarks for throughput, latency, memory |
| Security | `tests/Security/` | Malformed requests, slowloris, oversized messages, IPC validation |

Run a specific test group:

```bash
docker-compose run --rm php vendor/bin/phpunit tests/Performance/
docker-compose run --rm php vendor/bin/phpunit tests/Security/
docker-compose run --rm php vendor/bin/phpunit tests/Unit/
docker-compose run --rm php vendor/bin/phpunit tests/Integration/
```

## License

MIT License. See [LICENSE.md](LICENSE.md) for details.

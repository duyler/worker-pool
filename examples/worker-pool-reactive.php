<?php

declare(strict_types=1);

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Server;
use Duyler\WorkerPool\Config\WorkerPoolConfig;
use Duyler\WorkerPool\Master\SharedSocketMaster;
use Duyler\WorkerPool\Worker\EventDrivenWorkerInterface;
use Nyholm\Psr7\Response;

/**
 * Example: Worker Pool with Reactive Event Loop
 *
 * This example demonstrates how to use Notification Socket Pair
 * in Worker Pool mode for production-ready reactive HTTP server.
 *
 * Architecture:
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │                           Master Process                                 │
 * │                                                                          │
 * │  - Manages worker lifecycle                                             │
 * │  - Monitors worker health                                               │
 * │  - Restarts failed workers                                              │
 * └─────────────────────────────────────────────────────────────────────────┘
 *                                    │
 *                                    │ fork()
 *                                    ▼
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │                  Worker 1, Worker 2, ..., Worker N                      │
 * │                                                                          │
 * │  ┌────────────────────────────────────────────────────────────────────┐ │
 * │  │ Shared Socket (SO_REUSEPORT)                                        │ │
 * │  │                                                                      │ │
 * │  │   Kernel distributes connections across workers automatically        │ │
 * │  └────────────────────────────────────────────────────────────────────┘ │
 * │                                                                          │
 * │  ┌────────────────────────────────────────────────────────────────────┐ │
 * │  │ Fiber (Connection Acceptor)                                         │ │
 * │  │                                                                      │ │
 * │  │   while (true) {                                                    │ │
 * │  │       $client = socket_accept($sharedSocket);                       │ │
 * │  │       $server->addExternalConnection($client);                      │ │
 * │  │       Fiber::suspend();                                              │ │
 * │  │   }                                                                  │ │
 * │  └────────────────────────────────────────────────────────────────────┘ │
 * │                                                                          │
 * │  ┌────────────────────────────────────────────────────────────────────┐ │
 * │  │ Application (EventDrivenWorkerInterface)                            │ │
 * │  │                                                                      │ │
 * │  │   - enableNotification() → creates socket pair                      │ │
 * │  │   - EvIo monitors notification socket                                │ │
 * │  │   - Server notifies when request is parsed                          │ │
 * │  │   - Event Loop processes all ready requests                         │ │
 * │  └────────────────────────────────────────────────────────────────────┘ │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * Requirements:
 * - PHP 8.4+
 * - ext-ev extension
 * - ext-pcntl extension
 * - ext-posix extension
 * - duyler/http-server
 */

require_once __DIR__ . '/../vendor/autoload.php';

if (!extension_loaded('ev')) {
    echo "Error: ext-ev extension is required for this example\n";
    exit(1);
}

if (!extension_loaded('pcntl')) {
    echo "Error: ext-pcntl extension is required for Worker Pool\n";
    exit(1);
}

/**
 * Reactive Worker Application
 *
 * Implements EventDrivenWorkerInterface for Worker Pool mode.
 * Uses Notification Socket Pair for zero-overhead wakeup.
 */
final class ReactiveWorkerApplication implements EventDrivenWorkerInterface
{
    public function run(int $workerId, Server $server): void
    {
        // IMPORTANT: Do NOT call $server->start()!
        // Master already manages the socket and passes connections to Server.
        // Server is automatically marked as "running" in Worker Pool mode.

        echo "[Worker {$workerId}] Starting reactive application\n";

        // Enable notification mechanism for reactive Event Loop
        $server->enableNotification();

        // Get notification socket for EvIo
        $notifySocket = $server->getSocketResource();

        if ($notifySocket === null) {
            echo "[Worker {$workerId}] Error: Notification socket not available\n";
            return;
        }

        echo "[Worker {$workerId}] Notification socket ready\n";

        // Create EvIo watcher on notification socket
        $ioWatcher = new EvIo(
            $notifySocket,
            Ev::READ,
            function (EvIo $watcher, int $revents) use ($server, $workerId): void {
                $this->handleNotification($server, $workerId);
            },
        );

        // Graceful shutdown handler
        $termWatcher = new EvSignal(SIGTERM, function () use ($server, $ioWatcher, $workerId): void {
            echo "[Worker {$workerId}] Shutting down...\n";
            $server->disableNotification();
            $ioWatcher->stop();
            $server->stop();
            Ev::stop(Ev::BREAK_ALL);
        });

        $intWatcher = new EvSignal(SIGINT, function () use ($server, $ioWatcher, $workerId): void {
            echo "[Worker {$workerId}] Received SIGINT\n";
            $server->disableNotification();
            $ioWatcher->stop();
            $server->stop();
            Ev::stop(Ev::BREAK_ALL);
        });

        echo "[Worker {$workerId}] Ready to accept connections\n";

        // Run Event Loop (blocks forever)
        Ev::run();

        echo "[Worker {$workerId}] Stopped\n";
    }

    private function handleNotification(Server $server, int $workerId): void
    {
        // Step 1: Clear notification buffer
        // Non-blocking socket may have no data, suppress expected errors
        $socket = $server->getSocketResource();
        if ($socket instanceof \Socket) {
            $previousErrorReporting = error_reporting(0);
            socket_read($socket, 4096);
            error_reporting($previousErrorReporting);
        }

        // Step 2: Set active flag to prevent redundant notifications
        $server->setEventLoopActive(true);

        try {
            // Step 3: Process all ready requests
            while ($server->hasRequest()) {
                $requestData = $server->getRequest();

                if ($requestData === null) {
                    break;
                }

                echo sprintf(
                    "[Worker %d] %s %s\n",
                    $workerId,
                    $requestData->request->getMethod(),
                    $requestData->request->getUri()->getPath(),
                );

                // Process request (add your business logic here)
                $response = $this->handleRequest($requestData->request);

                // Send response
                $server->respond($requestData->respond($response));
            }
        } finally {
            // Step 4: Clear active flag
            $server->setEventLoopActive(false);
        }
    }

    private function handleRequest(Psr\Http\Message\ServerRequestInterface $request): Psr\Http\Message\ResponseInterface
    {
        // Your application logic here
        // This is a simple example that returns JSON response

        $path = $request->getUri()->getPath();

        if ($path === '/health') {
            return new Response(
                200,
                ['Content-Type' => 'application/json'],
                json_encode(['status' => 'healthy', 'timestamp' => time()]),
            );
        }

        if ($path === '/metrics') {
            return new Response(
                200,
                ['Content-Type' => 'application/json'],
                json_encode([
                    'memory_usage' => memory_get_usage(true),
                    'memory_peak' => memory_get_peak_usage(true),
                ]),
            );
        }

        return new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode([
                'message' => 'Hello from reactive worker pool!',
                'path' => $path,
                'method' => $request->getMethod(),
            ]),
        );
    }
}

// Configure server
$serverConfig = new ServerConfig(
    host: '0.0.0.0',
    port: 8080,
);

// Configure worker pool (auto-detect CPU cores)
$workerPoolConfig = WorkerPoolConfig::auto($serverConfig);

// Create application instance
$application = new ReactiveWorkerApplication();

// Create master process
$master = new SharedSocketMaster(
    config: $workerPoolConfig,
    serverConfig: $serverConfig,
    eventDrivenWorker: $application,
);

echo "Starting Worker Pool with {$workerPoolConfig->workerCount} workers\n";
echo "Server will be available at http://0.0.0.0:8080\n";
echo "\n";
echo "Available endpoints:\n";
echo "  GET /        - Hello message\n";
echo "  GET /health  - Health check\n";
echo "  GET /metrics - Worker metrics\n";
echo "\n";
echo "Press Ctrl+C to stop\n";
echo "\n";

// Start master (blocks until shutdown)
$master->start();

echo "Worker Pool stopped\n";

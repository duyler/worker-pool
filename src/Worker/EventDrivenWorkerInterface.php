<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Worker;

use Duyler\HttpServer\ServerInterface;

/**
 * Event-Driven Worker Interface
 *
 * Used to run full-featured applications with their own event loop in Worker Pool.
 *
 * ## Architecture
 *
 * Unlike WorkerCallbackInterface which is called for each connection,
 * EventDrivenWorkerInterface is launched ONCE on worker startup and allows
 * the application to have full control over the event loop.
 *
 * ## Workflow
 *
 * 1. Master starts Worker process (fork)
 * 2. Worker calls `run(workerId, server)` ONCE
 * 3. Application initializes (Database, EventBus, etc.)
 * 4. Application starts its event loop (while true)
 * 5. Master passes connections via `Server::addExternalConnection()`
 * 6. Application polls `Server::hasRequest()` in its event loop
 * 7. Requests are processed asynchronously via Event Bus
 * 8. Responses are sent via `Server::respond()` in another tick
 *
 * ## Notification Socket Integration
 *
 * Server automatically creates notification socket pair when enableNotification() is called.
 * Event Loop should monitor the socket via getSocketResource():
 *
 * ```php
 * public function run(int $workerId, ServerInterface $server): void
 * {
 *     // IMPORTANT: Do NOT call $server->start()!
 *     // Master already manages the socket and passes connections to Server.
 *     // Server is automatically marked as "running" in Worker Pool mode.
 *
 *     // Get notification socket (after enableNotification() was called by Master)
 *     $notifySocket = $server->getSocketResource();
 *
 *     // Create EvIo watcher
 *     $ioWatcher = new EvIo($notifySocket, Ev::READ, function() use ($server) {
 *         $server->setEventLoopActive(true);
 *
 *         while ($server->hasRequest()) {
 *             $request = $server->getRequest();
 *             // ... process request ...
 *             $server->respond($response);
 *         }
 *
 *         $server->setEventLoopActive(false);
 *     });
 *
 *     Ev::run();
 * }
 * ```
 *
 * ## Usage Example
 *
 * ```php
 * class MyApp implements EventDrivenWorkerInterface
 * {
 *     public function run(int $workerId, ServerInterface $server): void
 *     {
 *         // IMPORTANT: Do NOT call $server->start()!
 *         // Master already manages the socket and passes connections to Server.
 *         // Server is automatically marked as "running" in Worker Pool mode.
 *
 *         // Initialization (ONCE)
 *         $eventBus = new EventBus();
 *         $db = new Database();
 *
 *         // Application event loop (INFINITE)
 *         while (true) {
 *             // Tick 1: Receive requests from Worker Pool
 *             if ($server->hasRequest()) {
 *                 $request = $server->getRequest();
 *                 $eventBus->dispatch('http.request', $request);
 *             }
 *
 *             // Tick 2: Process events
 *             $eventBus->tick();
 *
 *             // Tick 3: Send ready responses
 *             if ($server->hasPendingResponse()) {
 *                 $response = $eventBus->getResponse();
 *                 $server->respond($response);
 *             }
 *
 *             usleep(1000); // 1ms
 *         }
 *     }
 * }
 * ```
 *
 * @see WorkerCallbackInterface For synchronous connection handling
 */
interface EventDrivenWorkerInterface
{
    /**
     * Starts the application in the worker
     *
     * This method is called ONCE on worker startup and NEVER returns.
     * The application must start its own event loop inside this method.
     *
     * The Master process will pass new connections to Server via
     * the addExternalConnection() method. The application must periodically call
     * Server::hasRequest() to check for new requests.
     *
     * @param int $workerId Worker ID (1, 2, 3, ..., N)
     * @param ServerInterface $server Server instance for Worker Pool interaction
     *
     * @return void
     */
    public function run(int $workerId, ServerInterface $server): void;
}

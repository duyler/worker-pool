# Интеграция WorkerPool с HttpServer

## Обзор

WorkerPool использует классы из пакета `duyler/http-server` для обработки HTTP-соединений внутри воркеров. Основные зависимости:

- `Duyler\HttpServer\Server` - HTTP-сервер, работающий внутри каждого воркера
- `Duyler\HttpServer\ServerInterface` - интерфейс сервера, передаваемый в `EventDrivenWorkerInterface::run()`
- `Duyler\HttpServer\Config\ServerConfig` - конфигурация сервера (хост, порт, таймауты, лимиты)
- `Duyler\HttpServer\Socket\SocketErrorSuppressor` - трейт для подавления предупреждений сокетов

`WorkerPoolConfig` принимает `ServerConfig` как обязательный параметр конструктора, что создаёт жёсткую связку между конфигурацией пула и конфигурацией сервера.

## SharedSocketMaster: интеграция

В режиме `SharedSocketMaster` каждый воркер создаёт собственный экземпляр `Server` и слушающий сокет с `SO_REUSEPORT`. Ядро ОС распределяет входящие соединения автоматически.

Поток данных:

```
1. Master::start()
2. Master::spawnWorker($workerId)
3.   pcntl_fork()
4.   [child] runEventDrivenWorker($workerId)
5.   [child]   $server = new Server($this->serverConfig)
6.   [child]   $server->setWorkerId($workerId)
7.   [child]   $socket = createSharedSocket($workerId)
8.   [child]     socket_create(AF_INET, SOCK_STREAM, SOL_TCP)
9.   [child]     socket_set_option(SO_REUSEADDR)
10.  [child]     socket_set_option(SO_REUSEPORT)
11.  [child]     socket_bind($host, $port)
12.  [child]     socket_listen($backlog)
13.  [child]     socket_set_nonblock($socket)
14.  [child]   $server->setExternalSocketResource($socket)
15.  [child]   $server->enableNotification()
16.  [child]   $eventDrivenWorker->run($workerId, $server)
17.  [child]   // Application event loop starts
```

Ключевой момент: мастер вызывает `setWorkerId()` и `setExternalSocketResource()` до запуска воркера. Метод `setWorkerId()` переводит сервер в режим `ServerMode::WorkerPool` и устанавливает `$isRunning = true`. Это означает, что внутри воркера **не нужно** вызывать `$server->start()`.

## CentralizedMaster: интеграция

В режиме `CentralizedMaster` мастер принимает все соединения и передаёт файловые дескрипторы воркерам через IPC.

Поток данных:

```
1. Master::start()
2.   $socketManager->listen()           // Создание мастерского TCP-сокета
3.   spawnWorker($workerId) для каждого
4.     socket_create_pair(AF_UNIX)      // Unix socket pair для IPC
5.     pcntl_fork()
6.     [child] socket_close($masterSocket)
7.     [child] $socketManager->detachFromWorker()
8.     [child] runEventDrivenWorker($workerId, $workerSocket)
9.     [child]   $server = new Server($serverConfig)
10.    [child]   $server->setWorkerId($workerId)
11.    [child]   $server->setExternalSocketResource($workerSocket)  // Unix socket из пары
12.    [child]   $server->enableNotification()
13.    [child]   $fiber = new Fiber(function() use ($workerSocket) {
14.    [child]       while (true) {
15.    [child]           $result = $fdPasser->receiveFd($workerSocket)
16.    [child]           if ($result !== null) {
17.    [child]               $server->addExternalConnection($result['fd'], $result['metadata'])
18.    [child]           }
19.    [child]           Fiber::suspend()
20.    [child]       }
21.    [child]   })
22.    [child]   $fiber->start()
23.    [child]   $server->registerFiber($fiber)
24.    [child]   $eventDrivenWorker->run($workerId, $server)
```

Главный цикл мастера:

```
while (!$shouldStop) {
    signalHandler->dispatch()
    socket_select(...)              // Ожидание соединений
    acceptConnections()             // Приём в ConnectionQueue
    distributeConnections()         // Route через Balancer + FdPasser
    checkWorkers()                  // Перезапуск упавших
}
```

При передаче FD мастер создаёт метаданные:
```php
[
    'worker_id' => $workerId,
    'client_ip' => $clientIp,
]
```

Воркер принимает FD и передаёт соединение в `Server::addExternalConnection()`, которая:
1. Устанавливает контекст воркера (`setWorkerContext`)
2. Извлекает IP и порт клиента
3. Создаёт `Connection` и добавляет в `ConnectionPool`

## Пример интеграции: полный код запуска

### Базовый запуск с SharedSocketMaster

```php
<?php

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\WorkerPool\Config\WorkerPoolConfig;
use Duyler\WorkerPool\Master\SharedSocketMaster;
use Duyler\WorkerPool\Worker\EventDrivenWorkerInterface;
use Duyler\HttpServer\ServerInterface;
use Nyholm\Psr7\Response;

class SimpleApp implements EventDrivenWorkerInterface
{
    public function run(int $workerId, ServerInterface $server): void
    {
        // НЕ вызывайте $server->start()!
        // Server уже работает в WorkerPool-режиме.

        while (true) {
            if ($server->hasRequest()) {
                $requestData = $server->getRequest();
                if ($requestData !== null) {
                    $response = new Response(200, [], 'Hello!');
                    $server->respond($requestData->respond($response));
                }
            }
            usleep(1000);
        }
    }
}

$serverConfig = new ServerConfig(
    host: '0.0.0.0',
    port: 8080,
);

$workerPoolConfig = new WorkerPoolConfig(
    serverConfig: $serverConfig,
    workerCount: 4,
);

$master = new SharedSocketMaster(
    config: $workerPoolConfig,
    serverConfig: $serverConfig,
    eventDrivenWorker: new SimpleApp(),
);

$master->start(); // Блокирует до SIGTERM/SIGINT
```

### Запуск с CentralizedMaster

```php
<?php

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\WorkerPool\Balancer\LeastConnectionsBalancer;
use Duyler\WorkerPool\Config\WorkerPoolConfig;
use Duyler\WorkerPool\Master\CentralizedMaster;

$serverConfig = new ServerConfig(host: '0.0.0.0', port: 8080);

$workerPoolConfig = new WorkerPoolConfig(
    serverConfig: $serverConfig,
    workerCount: 4,
);

$master = new CentralizedMaster(
    config: $workerPoolConfig,
    balancer: new LeastConnectionsBalancer(),
    serverConfig: $serverConfig,
    eventDrivenWorker: new SimpleApp(),
);

$master->start();
```

### Запуск через MasterFactory

```php
<?php

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\WorkerPool\Config\WorkerPoolConfig;
use Duyler\WorkerPool\Master\MasterFactory;

$serverConfig = new ServerConfig(host: '0.0.0.0', port: 8080);
$workerPoolConfig = WorkerPoolConfig::auto($serverConfig);

$master = MasterFactory::createRecommended(
    config: $workerPoolConfig,
    serverConfig: $serverConfig,
    eventDrivenWorker: new SimpleApp(),
);

echo MasterFactory::recommendedMaster() . PHP_EOL;
$master->start();
```

## Конфигурация ServerConfig для WorkerPool

`ServerConfig` определяет параметры HTTP-сервера, которые применяются внутри каждого воркера. В контексте WorkerPool наибольшее значение имеют следующие параметры:

```php
$serverConfig = new ServerConfig(
    host: '0.0.0.0',              // Адрес привязки (должен совпадать с WorkerPoolConfig)
    port: 8080,                    // Порт (должен совпадать с WorkerPoolConfig)
    maxConnections: 1000,          // Максимум соединений на воркер
    maxRequestSize: 10485760,      // Максимум размер запроса (10 МБ)
    bufferSize: 8192,              // Размер буфера чтения
    requestTimeout: 30,            // Таймаут запроса (секунды)
    connectionTimeout: 60,         // Таймаут соединения (секунды)
    maxAcceptsPerCycle: 10,        // Максимум приёмов за цикл (для CentralizedMaster)
    socketBacklog: 511,            // Размер очереди listening socket
    enableKeepAlive: true,         // Keep-Alive соединения
    keepAliveTimeout: 30,          // Таймаут Keep-Alive
    keepAliveMaxRequests: 100,     // Максимум запросов на Keep-Alive
    memoryLimit: 134217728,        // Лимит памяти на воркер (128 МБ)
);
```

Параметр `maxAcceptsPerCycle` влияет на производительность `CentralizedMaster`: он определяет, сколько соединений мастер принимает за одну итерацию цикла перед распределением.

## Пример с Event Loop (reactive)

Полный пример реактивного сервера с EvIo, основанный на `examples/worker-pool-reactive.php`:

```php
<?php

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Server;
use Duyler\WorkerPool\Config\WorkerPoolConfig;
use Duyler\WorkerPool\Master\SharedSocketMaster;
use Duyler\WorkerPool\Worker\EventDrivenWorkerInterface;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ServerRequestInterface;

class ReactiveWorkerApplication implements EventDrivenWorkerInterface
{
    public function run(int $workerId, Server $server): void
    {
        // НЕ вызывайте $server->start()!

        // Включаем механизм нотификации для реактивного Event Loop
        $server->enableNotification();

        // Получаем сокет нотификации для EvIo
        $notifySocket = $server->getSocketResource();

        if ($notifySocket === null) {
            return;
        }

        // Создаём EvIo watcher на сокете нотификации
        $ioWatcher = new EvIo(
            $notifySocket,
            Ev::READ,
            function () use ($server, $workerId): void {
                $this->handleNotification($server, $workerId);
            },
        );

        // Обработка сигналов для graceful shutdown
        $termWatcher = new EvSignal(SIGTERM, function () use ($server, $ioWatcher): void {
            $server->disableNotification();
            $ioWatcher->stop();
            $server->stop();
            Ev::stop(Ev::BREAK_ALL);
        });

        $intWatcher = new EvSignal(SIGINT, function () use ($server, $ioWatcher): void {
            $server->disableNotification();
            $ioWatcher->stop();
            $server->stop();
            Ev::stop(Ev::BREAK_ALL);
        });

        // Запуск Event Loop (блокирует навсегда)
        Ev::run();
    }

    private function handleNotification(Server $server, int $workerId): void
    {
        // Очищаем буфер нотификации
        $socket = $server->getSocketResource();
        if ($socket instanceof \Socket) {
            $previousErrorReporting = error_reporting(0);
            socket_read($socket, 4096);
            error_reporting($previousErrorReporting);
        }

        // Устанавливаем флаг активного Event Loop
        $server->setEventLoopActive(true);

        try {
            while ($server->hasRequest()) {
                $requestData = $server->getRequest();
                if ($requestData === null) {
                    break;
                }

                $response = $this->handleRequest($requestData->request);
                $server->respond($requestData->respond($response));
            }
        } finally {
            $server->setEventLoopActive(false);
        }
    }

    private function handleRequest(ServerRequestInterface $request): Response
    {
        $path = $request->getUri()->getPath();

        if ($path === '/health') {
            return new Response(
                200,
                ['Content-Type' => 'application/json'],
                json_encode(['status' => 'healthy']),
            );
        }

        return new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode(['message' => 'Hello from worker!']),
        );
    }
}

// Конфигурация
$serverConfig = new ServerConfig(host: '0.0.0.0', port: 8080);
$workerPoolConfig = WorkerPoolConfig::auto($serverConfig);

// Запуск
$master = new SharedSocketMaster(
    config: $workerPoolConfig,
    serverConfig: $serverConfig,
    eventDrivenWorker: new ReactiveWorkerApplication(),
);

echo "Workers: {$workerPoolConfig->workerCount}\n";
$master->start();
```

Принцип работы нотификации:

1. `$server->enableNotification()` создаёт пару сокетов внутри Server
2. `$server->getSocketResource()` возвращает read-конец пары
3. EvIo мониторит этот сокет (спит до уведомления)
4. Когда Server парсит HTTP-запрос, он пишет в notify-сокет
5. EvIo просыпается и вызывает callback
6. Callback обрабатывает все готовые запросы через `hasRequest()`/`getRequest()`

Флаг `setEventLoopActive(true)` предотвращает избыточные уведомления: Server отправляет нотификацию только когда Event Loop неактивен.

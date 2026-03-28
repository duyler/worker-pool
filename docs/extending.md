# Создание собственных воркеров

WorkerPool предоставляет три способа реализации воркеров: event-driven, callback и через HTTP-адаптер. Каждый подход подходит для определённых сценариев.

## EventDrivenWorkerInterface

`Duyler\WorkerPool\Worker\EventDrivenWorkerInterface`

Используйте этот интерфейс, когда:
- Нужно запустить полноценное приложение с собственным циклом событий
- Требуется интеграция с Event Bus или другими асинхронными компонентами
- Хотите использовать реактивный Event Loop (EvIo)
- Нужна сложная логика инициализации (подключение к БД, кэш, etc.)

Интерфейс содержит единственный метод:

```php
public function run(int $workerId, ServerInterface $server): void;
```

Метод `run()` вызывается один раз при старте воркера и никогда не возвращается. Приложение организует бесконечный цикл внутри.

Через `ServerInterface` доступны:
- `hasRequest(): bool` - проверка наличия HTTP-запроса
- `getRequest(): ?RequestData` - получение запроса с уникальным ID
- `respond(ResponseData $responseData): void` - отправка ответа
- `hasPendingResponse(): bool` - наличие ожидающего ответа
- `getSocketResource(): mixed` - сокет для EvIo
- `setEventLoopActive(bool $active): void` - флаг активности
- `enableNotification(): void` - включение реактивных уведомлений
- `disableNotification(): void` - отключение уведомлений
- `registerFiber(Fiber $fiber): void` - регистрация фоновой Fiber
- `unregisterFiber(Fiber $fiber): bool` - удаление Fiber

Пример: обработчик задач с очередью

```php
<?php

use Duyler\HttpServer\ServerInterface;
use Duyler\WorkerPool\Worker\EventDrivenWorkerInterface;
use Nyholm\Psr7\Response;

class TaskProcessor implements EventDrivenWorkerInterface
{
    private array $taskQueue = [];
    private array $results = [];

    public function run(int $workerId, ServerInterface $server): void
    {
        // Инициализация ресурсов (выполняется один раз)
        // Например: подключение к БД, кэшу, etc.

        // Основной цикл приложения
        while (true) {
            // Шаг 1: Проверка входящих HTTP-запросов
            if ($server->hasRequest()) {
                $requestData = $server->getRequest();
                if ($requestData !== null) {
                    $response = $this->handleHttpRequest(
                        $requestData->request,
                        $workerId,
                    );
                    $server->respond($requestData->respond($response));
                }
            }

            // Шаг 2: Обработка фоновой очереди задач
            $this->processNextTask();

            // Шаг 3: Отправка отложенных результатов
            $this->flushResults();

            usleep(1000);
        }
    }

    private function handleHttpRequest(
        ServerRequestInterface $request,
        int $workerId,
    ): Response {
        $path = $request->getUri()->getPath();

        if ($path === '/task') {
            $body = $request->getParsedBody() ?? [];
            $this->taskQueue[] = $body;
            return new Response(202, [], json_encode(['queued' => true]));
        }

        if ($path === '/status') {
            return new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'worker_id' => $workerId,
                'queue_size' => count($this->taskQueue),
                'results' => count($this->results),
            ]));
        }

        return new Response(404, [], 'Not Found');
    }

    private function processNextTask(): void
    {
        if (empty($this->taskQueue)) {
            return;
        }

        $task = array_shift($this->taskQueue);
        // Обработка задачи...
        $this->results[] = ['task_id' => uniqid(), 'done' => true];
    }

    private function flushResults(): void
    {
        // Отправка результатов во внешнюю систему
    }
}
```

Запуск:

```php
$serverConfig = new ServerConfig(host: '0.0.0.0', port: 8080);
$workerPoolConfig = WorkerPoolConfig::auto($serverConfig);

$master = new SharedSocketMaster(
    config: $workerPoolConfig,
    serverConfig: $serverConfig,
    eventDrivenWorker: new TaskProcessor(),
);
$master->start();
```

## WorkerCallbackInterface

`Duyler\WorkerPool\Worker\WorkerCallbackInterface`

Используйте этот интерфейс для простых синхронных обработчиков, когда не нужен собственный цикл событий. Метод `handle()` вызывается для каждого клиентского соединения.

```php
public function handle(mixed $clientSocket, array $metadata): void;
```

- `$clientSocket` - клиентский сокет (`Socket` или `resource`), зависит от режима
- `$metadata` - массив с ключами `worker_id` (int) и `client_ip` (string)

Пример: простой эхо-сервер

```php
<?php

use Duyler\WorkerPool\Worker\WorkerCallbackInterface;
use Socket;

class EchoHandler implements WorkerCallbackInterface
{
    public function handle(mixed $clientSocket, array $metadata): void
    {
        $workerId = $metadata['worker_id'];
        $clientIp = $metadata['client_ip'] ?? 'unknown';

        // Установить таймаут чтения
        if ($clientSocket instanceof Socket) {
            socket_set_option($clientSocket, SOL_SOCKET, SO_RCVTIMEO, [
                'sec' => 10,
                'usec' => 0,
            ]);
        }

        // Прочитать данные
        $buffer = '';
        if ($clientSocket instanceof Socket) {
            while ($chunk = socket_read($clientSocket, 4096)) {
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $buffer .= $chunk;

                // Простой HTTP-ответ
                $response = "HTTP/1.1 200 OK\r\n"
                    . "Content-Type: text/plain\r\n"
                    . "Connection: close\r\n"
                    . "\r\n"
                    . "Echo from worker {$workerId}\n"
                    . "Your IP: {$clientIp}\n"
                    . "Received: " . strlen($buffer) . " bytes\n";

                socket_write($clientSocket, $response);
                break;
            }

            socket_close($clientSocket);
        }
    }
}
```

Запуск:

```php
$serverConfig = new ServerConfig(host: '0.0.0.0', port: 8080);
$workerPoolConfig = new WorkerPoolConfig(
    serverConfig: $serverConfig,
    workerCount: 4,
);

$master = new SharedSocketMaster(
    config: $workerPoolConfig,
    serverConfig: $serverConfig,
    workerCallback: new EchoHandler(),
);
$master->start();
```

## HttpWorkerAdapter

`Duyler\WorkerPool\Worker\HttpWorkerAdapter`

Готовый адаптер для обработки HTTP-соединений. Не реализует интерфейсы воркеров, а предоставляет метод `handleConnection()` для ручного вызова.

По умолчанию возвращает `200 OK` с текстом "Hello from Worker Pool!". Для кастомной обработки нужно создать собственный класс по аналогии.

Пример использования в callback-режиме:

```php
<?php

use Duyler\WorkerPool\Worker\HttpWorkerAdapter;
use Duyler\WorkerPool\Worker\WorkerCallbackInterface;

class HttpCallbackHandler implements WorkerCallbackInterface
{
    private HttpWorkerAdapter $adapter;

    public function __construct()
    {
        $this->adapter = new HttpWorkerAdapter();
    }

    public function handle(mixed $clientSocket, array $metadata): void
    {
        if ($clientSocket instanceof \Socket) {
            $this->adapter->handleConnection($clientSocket, $metadata);
        }
    }
}
```

## Лучшие практики

### Обработка сигналов в воркере

В режиме `EventDrivenWorkerInterface` с EvIo регистрируйте обработчики сигналов для корректного завершения:

```php
public function run(int $workerId, ServerInterface $server): void
{
    $server->enableNotification();
    $notifySocket = $server->getSocketResource();

    $ioWatcher = new EvIo($notifySocket, Ev::READ, function () use ($server): void {
        $server->setEventLoopActive(true);
        try {
            while ($server->hasRequest()) {
                $requestData = $server->getRequest();
                if ($requestData === null) break;
                $server->respond($requestData->respond($this->handle($requestData->request)));
            }
        } finally {
            $server->setEventLoopActive(false);
        }
    });

    // Graceful shutdown
    new EvSignal(SIGTERM, function () use ($server, $ioWatcher): void {
        $server->disableNotification();
        $ioWatcher->stop();
        $server->stop();
        Ev::stop(Ev::BREAK_ALL);
    });

    new EvSignal(SIGINT, function () use ($server, $ioWatcher): void {
        $server->disableNotification();
        $ioWatcher->stop();
        $server->stop();
        Ev::stop(Ev::BREAK_ALL);
    });

    Ev::run();
}
```

В режиме polling (без EvIo) проверяйте сигналы через `pcntl_signal_dispatch()`:

```php
public function run(int $workerId, ServerInterface $server): void
{
    $running = true;

    pcntl_signal(SIGTERM, function () use (&$running): void {
        $running = false;
    });

    pcntl_signal(SIGINT, function () use (&$running): void {
        $running = false;
    });

    while ($running) {
        pcntl_signal_dispatch();

        if ($server->hasRequest()) {
            $requestData = $server->getRequest();
            if ($requestData !== null) {
                $response = $this->handle($requestData->request);
                $server->respond($requestData->respond($response));
            }
        }

        usleep(1000);
    }
}
```

### Graceful shutdown

При graceful shutdown нужно:
1. Перестать принимать новые запросы
2. Завершить обработку текущих запросов
3. Освободить ресурсы (закрыть соединения с БД, кэш)

```php
public function run(int $workerId, ServerInterface $server): void
{
    $database = new PDO('mysql:host=localhost;dbname=app', 'user', 'pass');

    $running = true;
    pcntl_signal(SIGTERM, function () use (&$running): void {
        $running = false;
    });

    while ($running) {
        pcntl_signal_dispatch();

        if ($server->hasRequest()) {
            $requestData = $server->getRequest();
            if ($requestData !== null) {
                $response = $this->handleRequest($requestData->request, $database);
                $server->respond($requestData->respond($response));
            }
        }

        usleep(1000);
    }

    // Очистка перед выходом
    $database = null; // Закрытие соединения

    // Мастер получил SIGTERM, отправит SIGTERM воркерам
    // Воркер завершит текущий запрос и выйдет
}
```

### Error handling

Оборачивайте обработку запросов в try/catch и возвращайте корректные HTTP-ответы при ошибках:

```php
public function run(int $workerId, ServerInterface $server): void
{
    while (true) {
        if ($server->hasRequest()) {
            $requestData = $server->getRequest();
            if ($requestData === null) {
                continue;
            }

            try {
                $response = $this->handleRequest($requestData->request);
            } catch (ValidationError $e) {
                $response = new Response(
                    422,
                    ['Content-Type' => 'application/json'],
                    json_encode(['error' => $e->getMessage()]),
                );
            } catch (Throwable $e) {
                error_log("[Worker {$workerId}] " . $e->getMessage());
                $response = new Response(
                    500,
                    ['Content-Type' => 'application/json'],
                    json_encode(['error' => 'Internal Server Error']),
                );
            }

            $server->respond($requestData->respond($response));
        }

        usleep(1000);
    }
}
```

### Что НЕ нужно делать в воркере

1. **Не вызывайте `$server->start()`**. Мастер уже установил режим `ServerMode::WorkerPool` через `setWorkerId()`, и сервер помечен как работающий (`$isRunning = true`). Вызов `start()` выведет предупреждение и ничего не сделает.

2. **Не вызывайте `exit()` напрямую** в нормальном потоке работы. При получении сигнала мастер отправит SIGTERM воркерам, и `AbstractMaster` корректно дождётся завершения через `waitForWorkers()`.

3. **Не создавайте собственные слушающие сокеты** в режиме `SharedSocketMaster`. Мастер создаёт сокет с `SO_REUSEPORT` и передаёт его через `setExternalSocketResource()`.

4. **Не используйте блокирующие операции** в основном цикле event-driven воркера. Длительные операции выносите в Fiber или отдельный процесс.

### Инициализация ресурсов

Ресурсы, общие для всех запросов (подключения к БД, кэш, логгеры), инициализируйте один раз до основного цикла:

```php
public function run(int $workerId, ServerInterface $server): void
{
    // Инициализация (один раз на воркер)
    $logger = new FileLogger("/var/log/worker-{$workerId}.log");
    $cache = new RedisCache('127.0.0.1', 6379);

    // Основной цикл
    while (true) {
        if ($server->hasRequest()) {
            $requestData = $server->getRequest();
            if ($requestData !== null) {
                $response = $this->handleRequest(
                    $requestData->request,
                    $cache,
                    $logger,
                );
                $server->respond($requestData->respond($response));
            }
        }

        usleep(1000);
    }
}
```

Каждый воркер работает в отдельном процессе (после fork), поэтому ресурсы не разделяются между воркерами. Подключения к БД нужно создавать в каждом воркере отдельно.

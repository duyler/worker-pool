# Компоненты WorkerPool

## Balancer

### BalancerInterface

`Duyler\WorkerPool\Balancer\BalancerInterface`

Интерфейс для реализации алгоритмов балансировки нагрузки. Используется в `CentralizedMaster` и `ConnectionRouter` для выбора воркера при распределении соединений.

Методы:

```php
public function selectWorker(array $connections): ?int;
```
Выбирает ID воркера на основе карты `worker_id => active_connections_count`. Возвращает `null`, если нет доступных воркеров.

```php
public function onConnectionEstablished(int $workerId): void;
```
Уведомление о том, что соединение установлено с указанным воркером.

```php
public function onConnectionClosed(int $workerId): void;
```
Уведомление о закрытии соединения на воркере.

```php
public function onWorkerRemoved(int $workerId): void;
```
Уведомление об удалении воркера (например, при падении процесса).

```php
public function reset(): void;
```
Сброс внутреннего состояния балансировщика.

### LeastConnectionsBalancer

`Duyler\WorkerPool\Balancer\LeastConnectionsBalancer`

Выбирает воркер с наименьшим числом активных соединений. При равенстве значений выбор происходит случайным образом через `array_rand()`.

Внутреннее состояние: массив `$connections` (`array<int, int>`), где ключ это ID воркера, а значение это количество активных соединений.

Принцип работы `selectWorker()`:
1. Принимает карту `$connections` с текущим числом соединений
2. Находит минимальное значение через `min()`
3. Собирает все воркеры с минимальным значением
4. Возвращает случайного из них

Метод `getConnections(): array` возвращает текущее состояние счётчиков соединений.

Пример:

```php
use Duyler\WorkerPool\Balancer\LeastConnectionsBalancer;

$balancer = new LeastConnectionsBalancer();

$workerId = $balancer->selectWorker([
    1 => 5,  // Worker 1: 5 соединений
    2 => 3,  // Worker 2: 3 соединения
    3 => 7,  // Worker 3: 7 соединений
]);
// $workerId будет 2 (наименьшее число соединений)

$balancer->onConnectionEstablished(2);
// теперь Worker 2: 4 соединения
```

### RoundRobinBalancer

`Duyler\WorkerPool\Balancer\RoundRobinBalancer`

Циклическое распределение соединений. Не учитывает текущую нагрузку воркеров, просто переключается между ними по порядку.

Внутреннее состояние: `$currentIndex` (int) для отслеживания текущей позиции и `$workerIds` (array<int>) для хранения списка ID воркеров.

Методы `onConnectionEstablished()` и `onConnectionClosed()` не выполняют действий. Метод `getCurrentIndex(): int` возвращает текущий индекс.

Пример:

```php
use Duyler\WorkerPool\Balancer\RoundRobinBalancer;

$balancer = new RoundRobinBalancer();

echo $balancer->selectWorker([1 => 0, 2 => 0, 3 => 0]); // 1
echo $balancer->selectWorker([1 => 0, 2 => 0, 3 => 0]); // 2
echo $balancer->selectWorker([1 => 0, 2 => 0, 3 => 0]); // 3
echo $balancer->selectWorker([1 => 0, 2 => 0, 3 => 0]); // 1 (снова)
```

---

## IPC

### FdPasser

`Duyler\WorkerPool\IPC\FdPasser`

Передача файловых дескрипторов между процессами через `socket_sendmsg`/`socket_recvmsg`. Работает только на Linux с поддержкой `SCM_RIGHTS`.

Класс `readonly`, не имеет изменяемого состояния.

Методы:

```php
public function isSupported(): bool;
```
Проверяет доступность FD passing: ОС Linux, наличие `socket_sendmsg`/`socket_recvmsg`.

```php
public function sendFd(Socket $controlSocket, Socket $fdToSend, array $metadata = []): bool;
```
Отправляет файловый дескриптор через Unix socket. Метаданные сериализуются в JSON и передаются в поле `iov`. Сам FD передаётся через `control` с уровнем `SOL_SOCKET` и типом `SCM_RIGHTS`. Возвращает `true` при успехе.

```php
public function receiveFd(Socket $controlSocket): ?array;
```
Принимает файловый дескриптор. Возвращает массив `['fd' => Socket|resource, 'metadata' => array]` или `null`, если нет данных. Вызов неблокирующий (`MSG_DONTWAIT`).

Пример:

```php
use Duyler\WorkerPool\IPC\FdPasser;

$fdPasser = new FdPasser($logger);

// На стороне мастера: отправить FD воркеру
$fdPasser->sendFd($workerSocket, $clientSocket, [
    'worker_id' => 1,
    'client_ip' => '127.0.0.1',
]);

// На стороне воркера: принять FD от мастера
$result = $fdPasser->receiveFd($workerSocket);
if ($result !== null) {
    $clientSocket = $result['fd'];       // Socket|resource
    $metadata = $result['metadata'];      // ['worker_id' => 1, 'client_ip' => '127.0.0.1']
}
```

### UnixSocketChannel

`Duyler\WorkerPool\IPC\UnixSocketChannel`

Канал обмена сообщениями через Unix domain sockets. Поддерживает режимы server (bind + listen) и client (connect). Протокол: 4 байта заголовка (big-endian, формат `N`) + JSON-тело.

Методы:

```php
public function __construct(string $socketPath, bool $isServer = false, int $maxIpcMessageSize = 1048576);
```

```php
public function connect(): bool;
```
Создаёт Unix socket. В режиме сервера привязывается к пути и начинает слушать. В режиме клиента подключается к существующему сокету. Устанавливает неблокирующий режим.

```php
public function accept(): ?Socket;
```
Принимает клиентское подключение (только для серверного режима).

```php
public function send(Message $message): bool;
```
Отправляет сообщение. Сериализует сообщение через `Message::serialize()`, добавляет 4-байтовый заголовок с длиной.

```php
public function receive(): ?Message;
```
Читает 4-байтовый заголовок, затем тело указанной длины. Десериализует через `Message::unserialize()`. Бросает `IPCException` при некорректной длине.

```php
public function close(): void;
```
Закрывает сокет и удаляет файл сокета (для сервера).

### Message

`Duyler\WorkerPool\IPC\Message`

Неизменяемая (`readonly`) структура IPC-сообщения. Содержит тип сообщения, массив данных и временную метку.

```php
public function __construct(MessageType $type, array $data = [], ?float $timestamp = null);
```

Фабричные методы для создания типовых сообщений:

```php
Message::connectionClosed(int $connectionId): self;
Message::workerReady(int $workerId): self;
Message::workerMetrics(array $metrics): self;
Message::shutdown(): self;
Message::reload(): self;
```

Сериализация:

```php
$message->serialize(): string;                // JSON: {type, data, timestamp}
Message::unserialize(string $data): self;     // Парсинг JSON
```

### MessageType

`Duyler\WorkerPool\IPC\MessageType`

Перечисление типов IPC-сообщений:

| Значение             | Строка               | Назначение              |
|----------------------|----------------------|-------------------------|
| ConnectionClosed     | connection_closed    | Соединение закрыто      |
| WorkerReady          | worker_ready         | Воркер готов            |
| WorkerMetrics        | worker_metrics       | Метрики воркера         |
| Shutdown             | shutdown             | Команда остановки       |
| Reload               | reload               | Команда перезагрузки    |

---

## Master

### MasterInterface

`Duyler\WorkerPool\Master\MasterInterface`

Интерфейс мастера процессов. Определяет базовый контракт для управления пулом воркеров.

```php
public function start(): void;
public function stop(): void;
public function isRunning(): bool;
public function getMetrics(): array;
```

### AbstractMaster

`Duyler\WorkerPool\Master\AbstractMaster`

Абстрактный базовый класс для мастеров. Реализует общую логику: регистрацию сигналов, проверку состояния воркеров, graceful shutdown.

Хранит массив `$workers` (`array<int, ProcessInfo>`) и флаг `$shouldStop`.

Конструктор принимает `WorkerPoolConfig` и опциональный `LoggerInterface`. В конструкторе создаётся `SignalHandler` и вызывается `setupSignals()`.

Ключевые методы:

```php
public function stop(): void;
```
Устанавливает `$shouldStop = true` и отправляет `SIGTERM` каждому воркеру.

```php
public function getWorkers(): array;
```
Возвращает массив всех воркеров `array<int, ProcessInfo>`.

```php
public function getWorkerCount(): int;
```
Возвращает количество воркеров.

```php
public function isRunning(): bool;
```
Возвращает `true`, если мастер не остановлен.

```php
protected function checkWorkers(): void;
```
Проверяет состояние каждого воркера через `pcntl_waitpid` с `WNOHANG`. Если воркер завершился, удаляет его из массива. При включённом `autoRestart` пересоздаёт воркер с задержкой `restartDelay`.

```php
protected function waitForWorkers(): void;
```
Блокирующее ожидание завершения всех воркеров.

Абстрактные методы, которые должны реализовать наследники:

```php
abstract protected function run(): void;
abstract protected function spawnWorker(int $workerId): void;
```

### SharedSocketMaster

`Duyler\WorkerPool\Master\SharedSocketMaster`

Мастер с разделяемым сокетом (SO_REUSEPORT). Каждый воркер создаёт собственный слушающий сокет на одном порту, а ядро распределяет соединения.

Конструктор:

```php
public function __construct(
    WorkerPoolConfig $config,
    ServerConfig $serverConfig,
    ?WorkerCallbackInterface $workerCallback = null,
    ?EventDrivenWorkerInterface $eventDrivenWorker = null,
    ?LoggerInterface $logger = null,
);
```

Один из `workerCallback` или `eventDrivenWorker` должен быть передан, иначе бросается `InvalidArgumentException`.

`start()` создаёт `$config->workerCount` воркеров и запускает главный цикл.

`spawnWorker()` вызывает `pcntl_fork()`. В дочернем процессе:
- При наличии `eventDrivenWorker` вызывается `runEventDrivenWorker()`: создаётся `Server`, устанавливается `workerId`, создаётся разделяемый сокет с `SO_REUSEPORT`/`SO_REUSEADDR`, сокет передаётся в `Server::setExternalSocketResource()`, включается нотификация, вызывается `$eventDrivenWorker->run()`.
- При наличии `workerCallback` вызывается `runCallbackWorker()`: воркер самостоятельно создаёт сокет и в цикле вызывает `socket_accept()`, передавая клиентский сокет в `$workerCallback->handle()`.

`getMetrics()` возвращает:
```php
[
    'architecture' => 'shared_socket',
    'total_workers' => int,
    'active_workers' => int,
    'total_connections' => int,
    'is_running' => bool,
]
```

### CentralizedMaster

`Duyler\WorkerPool\Master\CentralizedMaster`

Централизованный мастер с FD passing. Принимает все соединения, ставит в очередь, маршрутизирует воркерам.

Конструктор:

```php
public function __construct(
    WorkerPoolConfig $config,
    BalancerInterface $balancer,
    ?ServerConfig $serverConfig = null,
    ?WorkerCallbackInterface $workerCallback = null,
    ?EventDrivenWorkerInterface $eventDrivenWorker = null,
    ?LoggerInterface $logger = null,
);
```

Обязательно передать `BalancerInterface` и один из `workerCallback`/`eventDrivenWorker`.

Внутренние компоненты:
- `FdPasser` для передачи FD
- `WorkerManager` для управления воркерами
- `ConnectionRouter` для маршрутизации
- `SocketManager` (если передан `ServerConfig`) для слушающего сокета
- `ConnectionQueue` для очереди соединений

`start()` вызывает `$socketManager->listen()`, создаёт воркеры и запускает главный цикл.

`spawnWorker()` создаёт Unix socket pair (`AF_UNIX`, `SOCK_STREAM`), форкает процесс. В дочернем процессе мастерский конец пары закрывается, сокет-менеджер отсоединяется через `detachFromWorker()`. В режиме `eventDrivenWorker`:
- Создаётся `Server`
- Unix socket из пары устанавливается как внешний ресурс
- Создаётся `Fiber`, который в бесконечном цикле вызывает `$fdPasser->receiveFd()` и передаёт соединение в `$server->addExternalConnection()`
- Fiber запускается и регистрируется через `$server->registerFiber()`
- Вызывается `$eventDrivenWorker->run()`

Главный цикл `run()`:
1. Обработка сигналов
2. `socket_select()` на слушающем сокете
3. `acceptConnections()` приём новых соединений в очередь
4. `distributeConnections()` маршрутизация через `ConnectionRouter`
5. `checkWorkers()` проверка состояния воркеров

`getMetrics()` возвращает расширенную статистику:
```php
[
    'total_workers' => int,
    'alive_workers' => int,
    'total_connections' => int,
    'total_requests' => int,
    'queue_size' => int,
    'is_running' => bool,
]
```

### MasterFactory

`Duyler\WorkerPool\Master\MasterFactory`

Фабрика для создания мастера по конфигурации и возможностям системы.

```php
public static function create(
    WorkerPoolConfig $config,
    ServerConfig $serverConfig,
    ?WorkerCallbackInterface $workerCallback = null,
    ?EventDrivenWorkerInterface $eventDrivenWorker = null,
    ?BalancerInterface $balancer = null,
    ?LoggerInterface $logger = null,
): MasterInterface;
```
Создаёт `CentralizedMaster`, если система поддерживает FD passing И передан балансировщик. Иначе создаёт `SharedSocketMaster`.

```php
public static function createRecommended(
    WorkerPoolConfig $config,
    ServerConfig $serverConfig,
    ?WorkerCallbackInterface $workerCallback = null,
    ?EventDrivenWorkerInterface $eventDrivenWorker = null,
    ?LoggerInterface $logger = null,
): MasterInterface;
```
Создаёт `CentralizedMaster` с `LeastConnectionsBalancer`, если система поддерживает FD passing. Иначе `SharedSocketMaster`.

```php
public static function recommendedMaster(): string;
```
Возвращает строковое описание рекомендуемой архитектуры.

```php
public static function getComparison(): array;
```
Возвращает массив сравнения двух архитектур по ключам: architecture, load_balancing, requirements, platforms, complexity, use_case.

### ConnectionQueue

`Duyler\WorkerPool\Master\ConnectionQueue`

FIFO-очередь клиентских сокетов для `CentralizedMaster`.

```php
public function __construct(int $maxSize);
```

Методы:

```php
public function enqueue(Socket $socket): bool;    // Добавить в очередь. false при переполнении
public function dequeue(): ?Socket;               // Извлечь из очереди. null если пусто
public function size(): int;                      // Текущий размер
public function isEmpty(): bool;                  // Проверка пустоты
public function isFull(): bool;                   // Проверка переполнения
public function clear(): void;                    // Очистка с закрытием всех сокетов
```

Деструктор автоматически вызывает `clear()`.

### ConnectionRouter

`Duyler\WorkerPool\Master\ConnectionRouter`

Маршрутизатор соединений для `CentralizedMaster`. Класс `readonly`.

```php
public function route(
    Socket $clientSocket,
    array $workers,         // array<int, ProcessInfo>
    array $workerSockets,   // array<int, Socket>
    array $metadata = [],
): bool;
```

Алгоритм:
1. `selectWorker()` фильтрует живых воркеров в состоянии `Ready` и вызывает `$balancer->selectWorker()`
2. Извлекает IP клиента через `socket_getpeername()`
3. Вызывает `$fdPasser->sendFd()` для передачи FD
4. Уведомляет балансировщик через `onConnectionEstablished()`
5. При ошибке закрывает клиентский сокет и возвращает `false`

```php
public function getBalancer(): BalancerInterface;
```

### SocketManager

`Duyler\WorkerPool\Master\SocketManager`

Управление слушающим сокетом для `CentralizedMaster`. Создаёт, привязывает и слушает TCP-сокет на основе `ServerConfig`. Использует трейт `SocketErrorSuppressor` из http-server.

```php
public function listen(): void;            // Создание и запуск сокета
public function accept(): ?Socket;         // Принятие соединения (неблокирующее)
public function getSocket(): ?Socket;      // Получение мастерского сокета
public function detachFromWorker(): void;  // Отсоединение в дочернем процессе
public function isListening(): bool;       // Статус
public function close(): void;             // Закрытие сокета
public function disableAutoClose(): void;  // Отключение авто-закрытия в деструкторе
```

`detachFromWorker()` обнуляет ссылку на сокет без его закрытия, чтобы сокет оставался доступен в мастер-процессе после fork.

### WorkerManager

`Duyler\WorkerPool\Master\WorkerManager`

Вспомогательный класс для управления воркерами. Инкапсулирует операции fork, check, stop.

```php
public function spawn(int $workerId, callable $workerProcess): ProcessInfo;
```
Форкает процесс. В дочернем процессе вызывает `$workerProcess($workerId)` и `exit(0)`.

```php
public function getWorker(int $workerId): ?ProcessInfo;
public function removeWorker(int $workerId): void;
public function updateWorker(int $workerId, ProcessInfo $processInfo): void;
public function countAlive(): int;
public function check(bool $shouldRestart = true): void;
public function stopAll(): void;     // Отправка SIGTERM всем воркерам
public function waitAll(): void;     // Блокирующее ожидание
```

---

## Process

### ProcessInfo

`Duyler\WorkerPool\Process\ProcessInfo`

Неизменяемая (`readonly`) структура с информацией о процессе. Каждое изменение создаёт новый экземпляр через `with*`-методы.

Поля:

| Поле            | Тип           | Описание                        |
|-----------------|---------------|---------------------------------|
| workerId        | int           | Идентификатор воркера           |
| pid             | int           | PID процесса                    |
| state           | ProcessState  | Текущее состояние               |
| connections     | int           | Число активных соединений       |
| totalRequests   | int           | Всего обработано запросов       |
| startedAt       | float         | Время запуска (microtime)       |
| lastActivityAt  | float         | Время последней активности      |
| memoryUsage     | int           | Использование памяти            |

Методы-конструкторы:

```php
public function withState(ProcessState $state): self;
public function withConnections(int $connections): self;
public function withIncrementedRequests(): self;
public function withMemoryUsage(int $memoryUsage): self;
```

Утилитарные методы:

```php
public function getUptime(): float;     // Время работы в секундах
public function getIdleTime(): float;   // Время простоя в секундах
public function isAlive(): bool;        // Проверка через posix_kill($pid, 0)
public function toArray(): array;       // Экспорт в массив
```

### ProcessState

`Duyler\WorkerPool\Process\ProcessState`

Перечисление состояний процесса:

| Значение   | Строка     | Описание                      |
|-----------|------------|-------------------------------|
| Starting  | starting   | Инициализация                 |
| Ready     | ready      | Готов к работе                |
| Busy      | busy       | Обрабатывает запросы          |
| Stopping  | stopping   | Останавливается               |
| Stopped   | stopped    | Остановлен                    |
| Failed    | failed     | Завершился с ошибкой          |

---

## Signal

### SignalHandler

`Duyler\WorkerPool\Signal\SignalHandler`

Обработчик сигналов через `pcntl_signal()`. Поддерживает несколько обработчиков на один сигнал.

```php
public function register(int $signal, Closure $handler): void;
```
Регистрирует обработчик. При первой регистрации на сигнал вызывает `installSignal()`, который устанавливает `pcntl_signal()` с диспетчером, вызывающим все зарегистрированные обработчики.

```php
public function unregister(int $signal): void;       // Удаление всех обработчиков сигнала
public function dispatch(): void;                     // Вызов pcntl_signal_dispatch()
public function reset(): void;                        // Сброс всех обработчиков
public function getRegisteredSignals(): array;        // Карта signal => count
public function isSignalsSupported(): bool;           // Проверка pcntl_signal
public function hasHandlers(int $signal): bool;       // Наличие обработчиков
```

```php
public static function createDefault(): self;
```
Создаёт обработчик с пустыми обработчиками для SIGTERM, SIGINT, SIGUSR1, SIGUSR2.

### SignalManager

`Duyler\WorkerPool\Signal\SignalManager`

Менеджер сигналов с флагами состояния. Оборачивает `SignalHandler`.

```php
public function setupMasterSignals(Closure $onShutdown, Closure $onReload): void;
```
Регистрирует SIGTERM/SIGINT для shutdown и SIGUSR1 для reload.

```php
public function setupWorkerSignals(Closure $onShutdown): void;
```
Регистрирует SIGTERM/SIGINT для shutdown.

```php
public function isShutdownRequested(): bool;
public function isReloadRequested(): bool;
public function dispatch(): void;
public function reset(): void;
public function resetFlags(): void;
```

---

## Worker

### EventDrivenWorkerInterface

`Duyler\WorkerPool\Worker\EventDrivenWorkerInterface`

Интерфейс для event-driven воркеров. Метод `run()` вызывается один раз при запуске воркера и никогда не возвращается. Приложение должно организовать собственный цикл событий внутри.

```php
public function run(int $workerId, ServerInterface $server): void;
```

Воркер получает `ServerInterface` для взаимодействия с сервером:
- `hasRequest()` - проверить наличие запросов
- `getRequest()` - получить следующий запрос
- `respond()` - отправить ответ
- `getSocketResource()` - получить сокет для EvIo

Подробности использования описаны в [extending.md](extending.md).

### WorkerCallbackInterface

`Duyler\WorkerPool\Worker\WorkerCallbackInterface`

Интерфейс для синхронного callback-режима. Метод `handle()` вызывается для каждого клиентского соединения.

```php
public function handle(mixed $clientSocket, array $metadata): void;
```

- `$clientSocket` - клиентский сокет (`Socket` или `resource`)
- `$metadata` - массив с ключами `worker_id`, `client_ip`

### HttpWorkerAdapter

`Duyler\WorkerPool\Worker\HttpWorkerAdapter`

Адаптер для обработки HTTP-соединений в синхронном режиме. Класс `readonly`.

Создаёт `HttpParser` и `Psr17Factory` для парсинга HTTP-запросов и формирования PSR-7 ответов.

```php
public function handleConnection(Socket $clientSocket, array $metadata = []): void;
```

Обрабатывает одно соединение: читает запрос, парсит, формирует ответ, отправляет и закрывает сокет. Устанавливает таймауты на чтение/запись (30 секунд). Максимальный размер запроса: 10 МБ.

Метод `processRequest()` по умолчанию возвращает `200 OK` с текстом "Hello from Worker Pool!". Для кастомной обработки нужно создать собственный класс.

---

## Config

### WorkerPoolConfig

`Duyler\WorkerPool\Config\WorkerPoolConfig`

Конфигурация пула воркеров. Класс `readonly`.

```php
public function __construct(
    public ServerConfig $serverConfig,
    int $workerCount = 0,
    public BalancerType $balancer = BalancerType::LeastConnections,
    public int $backlog = 128,
    public int $maxQueueSize = 1000,
    public int $maxIpcMessageSize = 1048576,
    public bool $enableStickySession = false,
    public bool $enableGracefulReload = false,
    public bool $autoRestart = true,
    public int $restartDelay = 1,
    public int $fallbackCpuCores = 4,
    public int $pollInterval = 1000,
);
```

| Параметр             | Тип            | По умолчанию           | Описание                            |
|---------------------|----------------|------------------------|--------------------------------------|
| serverConfig        | ServerConfig   | обязателен             | Конфигурация HTTP-сервера           |
| workerCount         | int            | 0                      | Количество воркеров (0 = авто)      |
| balancer            | BalancerType   | LeastConnections       | Тип балансировщика                  |
| backlog             | int            | 128                    | Размер очереди сокетов              |
| maxQueueSize        | int            | 1000                   | Максимум в ConnectionQueue          |
| maxIpcMessageSize   | int            | 1048576 (1 МБ)        | Максимальный размер IPC сообщения   |
| enableStickySession | bool           | false                  | Sticky sessions (зарезервировано)   |
| enableGracefulReload| bool           | false                  | Graceful reload (зарезервировано)   |
| autoRestart         | bool           | true                   | Автоперезапуск упавших воркеров     |
| restartDelay        | int            | 1                      | Задержка перед перезапуском (сек.)  |
| fallbackCpuCores    | int            | 4                      | Fallback при ошибке определения CPU |
| pollInterval        | int            | 1000                   | Интервал опроса (микросекунды)      |

При `workerCount = 0` автоматически определяется количество ядер CPU через `SystemInfo`.

Валидация:
- workerCount: от 1 до 1024
- backlog: положительное число
- maxQueueSize: положительное число
- restartDelay: неотрицательное число
- maxIpcMessageSize: минимум 1024 байта
- fallbackCpuCores: положительное число
- pollInterval: минимум 100 микросекунд

Фабричный метод:

```php
public static function auto(
    ServerConfig $serverConfig,
    BalancerType $balancer = BalancerType::LeastConnections,
): self;
```
Создаёт конфигурацию с автоопределением количества воркеров.

### BalancerType

`Duyler\WorkerPool\Config\BalancerType`

Перечисление типов балансировщика:

| Значение          | Строка              |
|-------------------|---------------------|
| LeastConnections  | least_connections   |
| RoundRobin        | round_robin         |
| Weighted          | weighted            |

---

## Exception

### WorkerPoolExceptionBase

`Duyler\WorkerPool\Exception\WorkerPoolExceptionBase`

Абстрактный базовый класс для всех исключений WorkerPool. Расширяет `Exception`.

```php
public function __construct(
    string $message = '',
    int $code = 0,
    ?Throwable $previous = null,
    array $context = [],
);

public function getErrorCode(): string;    // Код ошибки (например, 'WORKER_POOL_ERROR')
public function getContext(): array;       // Контекст ошибки
```

### WorkerPoolException

`Duyler\WorkerPool\Exception\WorkerPoolException`

Общее исключение пула воркеров. Код ошибки: `WORKER_POOL_ERROR`.

Бросается при:
- Ошибках fork
- Ошибках создания сокета
- Ошибках bind/listen

### IPCException

`Duyler\WorkerPool\Exception\IPCException`

Исключение межпроцессного взаимодействия. Код ошибки: `IPC_ERROR`.

Бросается при:
- Недоступности `socket_sendmsg`/`socket_recvmsg`
- Отсутствии `SCM_RIGHTS`
- Ошибках Unix socket

---

## Util

### SystemInfo

`Duyler\WorkerPool\Util\SystemInfo`

Информация о системе. Определяет количество ядер CPU, доступность FD passing, поддержку SO_REUSEPORT, контейнерную среду.

```php
public function getCpuCores(int $fallback = 4): int;
```
Определяет количество логических ядер. Результат кэшируется в статическом свойстве. Методы определения зависят от ОС:
- Windows: `wmic cpu get NumberOfCores` или env `NUMBER_OF_PROCESSORS`
- Linux: `nproc`, `/proc/cpuinfo`, `lscpu`
- BSD/macOS: `sysctl -n hw.ncpu`, `hw.logicalcpu`, `hw.physicalcpu`

```php
public function getOsInfo(): array;
```
Возвращает `['os', 'os_family', 'php_version', 'sapi', 'cpu_cores']`.

```php
public static function resetCache(): void;
```
Сбрасывает кэш количества ядер.

```php
public function isContainerEnvironment(): bool;
```
Проверяет наличие `/.dockerenv` или `/run/.containerenv`.

```php
public function supportsFdPassing(): bool;
```
Проверяет: Linux + `socket_sendmsg` + `socket_recvmsg` + `SCM_RIGHTS`.

```php
public function supportsReusePort(): bool;
```
Проверяет наличие константы `SO_REUSEPORT`.

# Архитектура WorkerPool

## Обзор

WorkerPool представляет собой менеджер процессов с балансировкой нагрузки для параллельного выполнения задач. Он создаёт пул воркер-процессов через `pcntl_fork()`, распределяет соединения между ними и обеспечивает контроль жизненного цикла.

WorkerPool зависит от пакета `duyler/http-server` и использует его классы `Server`, `ServerInterface` и `ServerConfig` для обработки HTTP-соединений внутри воркеров.

```
WorkerPool --> HttpServer
   |              |
   |              +-- ServerInterface
   |              +-- Server
   |              +-- ServerConfig
   |              +-- Socket\SocketErrorSuppressor
   |              +-- Parser\HttpParser
   |
   +-- Config\WorkerPoolConfig (содержит ServerConfig)
   +-- Master\* (создаёт Server в воркерах)
   +-- Worker\HttpWorkerAdapter (использует HttpParser)
```

## Двойная архитектура

WorkerPool поддерживает два режима работы, каждый из которых реализован в отдельном классе мастера.

### SharedSocket (SO_REUSEPORT)

В режиме `SharedSocketMaster` каждый воркер создаёт собственный слушающий сокет на одном порту. Ядро ОС через опцию `SO_REUSEPORT` автоматически распределяет входящие соединения между воркерами.

```
                    +------------------+
                    |  Клиенты (HTTP)   |
                    +--------+---------+
                             |
                    +--------v---------+
                    |    Ядро Linux     |
                    |  (SO_REUSEPORT)   |
                    +--+-----+-----+---+
                       |     |     |
               +-------v+ +--v---+ +-v-------+
               |Worker 1| |Worker 2| |Worker N |
               | :8080  | | :8080  | | :8080   |
               +--------+ +--------+ +---------+
                    ^
                    |
           +--------+---------+
           |  Master Process   |
           |  (мониторинг,     |
           |   рестарт)        |
           +------------------+
```

Характеристики:
- Балансировка на уровне ядра, нулевой overhead на IPC
- Простая архитектура
- Работает на Linux, macOS (через Docker)
- Не требует `socket_sendmsg`/`socket_recvmsg`

### Centralized (FD Passing)

В режиме `CentralizedMaster` мастер-процесс единолично принимает все соединения, ставит их в очередь и передаёт файловый дескриптор (FD) конкретному воркеру через IPC-канал с использованием `sendmsg`/`recvmsg` и `SCM_RIGHTS`.

```
                    +------------------+
                    |  Клиенты (HTTP)   |
                    +--------+---------+
                             |
                    +--------v---------+
                    |  Master Process   |
                    |  (accept, queue,  |
                    |   route, FD pass) |
                    |                   |
                    |  ConnectionQueue  |
                    |  ConnectionRouter |
                    |  Balancer         |
                    +--+-----+-----+---+
                       |     |     |
                   FD pass  FD pass  FD pass
                       |     |     |
               +-------v+ +--v---+ +-v-------+
               |Worker 1| |Worker 2| |Worker N |
               | recv FD | recv FD | recv FD  |
               +--------+ +--------+ +---------+
```

Характеристики:
- Кастомная балансировка (Least Connections, Round Robin)
- Централизованная очередь соединений
- Поддержка sticky sessions
- Требует Linux (`SCM_RIGHTS`, `socket_sendmsg`, `socket_recvmsg`)

Выбор между режимами осуществляет `MasterFactory`. Метод `createRecommended()` автоматически выбирает `CentralizedMaster` на Linux (если доступен FD passing) и `SharedSocketMaster` на остальных платформах.

## Lifecycle процессов

Жизненный цикл воркера следует паттерну: fork, init, loop, shutdown.

```
                   Master Process
                        |
                   pcntl_fork()
                        |
              +---------+---------+
              |                   |
         child (pid=0)       parent (pid>0)
              |                   |
      +-------v-------+   +------v--------+
      | Инициализация |   | ProcessInfo   |
      |               |   | state: Ready  |
      | Создание      |   +---------------+
      | Server,       |
      | сокета,       |
      | Fiber         |
      +-------+-------+
              |
      +-------v-------+
      | Рабочий цикл  |
      |               |
      | while (true)  |
      |   accept/FD   |
      |   process     |
      |   respond     |
      +-------+-------+
              |
      +-------v-------+
      | SIGTERM/SIGINT|
      |               |
      | graceful stop |
      | exit(0)       |
      +---------------+
```

Состояния процесса описаны в перечислении `ProcessState`:

| Состояние    | Описание                              |
|-------------|---------------------------------------|
| Starting    | Процесс запущен, идёт инициализация   |
| Ready       | Воркер готов принимать соединения      |
| Busy        | Воркер обрабатывает запросы            |
| Stopping    | Воркер получает команду остановки      |
| Stopped     | Воркер остановлен                      |
| Failed      | Воркер завершился с ошибкой            |

Если воркер умирает (определяется через `pcntl_waitpid` с `WNOHANG`), мастер автоматически перезапускает его при включённой опции `autoRestart` с задержкой `restartDelay` секунд.

## Межпроцессное взаимодействие (IPC)

Для обмена данными между мастером и воркерами используются Unix domain sockets.

### FdPasser

Класс `FdPasser` реализует передачу файловых дескрипторов между процессами через `socket_sendmsg`/`socket_recvmsg` с уровнем `SOL_SOCKET` и типом `SCM_RIGHTS`. Вместе с FD передаётся JSON-метаданных (IP клиента, ID воркера).

Доступность FD passing проверяется через `SystemInfo::supportsFdPassing()`, который требует:
- `PHP_OS_FAMILY === 'Linux'`
- Наличие функций `socket_sendmsg` и `socket_recvmsg`
- Определённую константу `SCM_RIGHTS`

### UnixSocketChannel

`UnixSocketChannel` предоставляет канальный обмен сообщениями через Unix domain sockets с бинарным протоколом: 4 байта заголовка (длина в формате `N`) + тело (JSON). Поддерживает режимы server и client.

### Message и MessageType

Структурированные IPC-сообщения с типами:
- `ConnectionClosed` - соединение закрыто
- `WorkerReady` - воркер готов к работе
- `WorkerMetrics` - метрики воркера
- `Shutdown` - команда остановки
- `Reload` - команда перезагрузки

## Обработка сигналов

Мастер обрабатывает сигналы через `SignalHandler`:

| Сигнал  | Действие                                    |
|---------|---------------------------------------------|
| SIGTERM | Graceful shutdown: отправка SIGTERM воркерам |
| SIGINT  | Graceful shutdown: отправка SIGTERM воркерам |

В классе `AbstractMaster` сигналы регистрируются в конструкторе через `setupSignals()`. При получении SIGTERM/SIGINT вызывается `stop()`, который отправляет SIGTERM каждому воркеру и дожидается их завершения через `waitForWorkers()`.

Диспетчеризация сигналов происходит в главном цикле мастера через `$this->signalHandler->dispatch()`, который вызывает `pcntl_signal_dispatch()`.

В классе `SignalManager` добавлена поддержка `SIGUSR1` для перезагрузки и флаги `shutdownRequested`/`reloadRequested` для отслеживания состояния.

## Балансировка нагрузки

Балансировка работает только в режиме `CentralizedMaster`. В `SharedSocketMaster` балансировку выполняет ядро ОС.

### Least Connections

`LeastConnectionsBalancer` выбирает воркер с наименьшим числом активных соединений. При равенстве соединений выбор происходит случайно. Балансировщик ведёт внутренний счётчик соединений, который инкрементируется при `onConnectionEstablished()` и декрементируется при `onConnectionClosed()`.

### Round Robin

`RoundRobinBalancer` распределяет соединения циклически. Не учитывает текущую нагрузку воркеров. Методы `onConnectionEstablished()` и `onConnectionClosed()` не выполняют действий.

Оба балансировщика реализуют интерфейс `BalancerInterface` с методами:
- `selectWorker(array $connections): ?int` - выбор воркера по карте `worker_id => connections`
- `onConnectionEstablished(int $workerId): void` - уведомление о новом соединении
- `onConnectionClosed(int $workerId): void` - уведомление о закрытии соединения
- `onWorkerRemoved(int $workerId): void` - уведомление об удалении воркера
- `reset(): void` - сброс состояния

Тип балансировщика задаётся в `WorkerPoolConfig` через enum `BalancerType` со значениями: `LeastConnections`, `RoundRobin`, `Weighted`.

## Маршрутизация соединений

В `CentralizedMaster` маршрутизацией занимается `ConnectionRouter`. Он получает соединение из `ConnectionQueue`, вызывает `selectWorker()` у балансировщика, передаёт FD воркеру через `FdPasser::sendFd()` и уведомляет балансировщик о новом соединении.

`ConnectionQueue` представляет собой FIFO-очередь сокетов с ограничением `maxSize`. При переполнении новые соединения отклоняются.

## Главный цикл мастера

```
while (!$this->shouldStop) {
    $this->signalHandler->dispatch();   // обработка сигналов

    // CentralizedMaster:
    socket_select(...);                  // ожидание соединений
    $this->acceptConnections();          // приём в очередь
    $this->distributeConnections();      // распределение по воркерам

    $this->checkWorkers();              // проверка состояния воркеров

    usleep($this->config->pollInterval); // пауза между итерациями
}

$this->waitForWorkers();                 // ожидание завершения воркеров
```

Интервал опроса задаётся параметром `pollInterval` в микросекундах (минимум 100, по умолчанию 1000).

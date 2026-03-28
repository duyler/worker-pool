<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\Contract;

use Duyler\WorkerPool\Contract\WorkerContextInterface;
use Duyler\WorkerPool\Contract\WorkerFactoryInterface;
use Duyler\WorkerPool\Contract\WorkerInterface;
use Duyler\WorkerPool\Contract\SocketConfigInterface;
use Duyler\WorkerPool\Contract\SocketFactoryInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Socket;

use function assert;
use function is_int;

use const AF_INET;
use const SOCK_STREAM;
use const SOL_TCP;

class ContractTypeSafetyTest extends TestCase
{
    #[Test]
    public function worker_context_interface_has_correct_return_types(): void
    {
        $context = new class implements WorkerContextInterface {
            public function getWorkerId(): int
            {
                return 1;
            }

            public function getWorkerPid(): ?int
            {
                return 1234;
            }

            public function getMetadata(): array
            {
                return ['key' => 'value'];
            }

            public function isActive(): bool
            {
                return true;
            }

            public function getSocketResource(): mixed
            {
                return null;
            }

            public function notifyEventLoop(): void {}
        };

        $this->assertIsInt($context->getWorkerId());
        $this->assertTrue(null === $context->getWorkerPid() || is_int($context->getWorkerPid()));
        $this->assertIsArray($context->getMetadata());
        $this->assertIsBool($context->isActive());
    }

    #[Test]
    public function socket_config_interface_has_correct_return_types(): void
    {
        $config = new class implements SocketConfigInterface {
            public function getHost(): string
            {
                return '127.0.0.1';
            }

            public function getPort(): int
            {
                return 8080;
            }

            public function getBacklog(): int
            {
                return 128;
            }

            public function getMaxConnections(): int
            {
                return 1000;
            }
        };

        $this->assertIsString($config->getHost());
        $this->assertIsInt($config->getPort());
        $this->assertIsInt($config->getBacklog());
        $this->assertIsInt($config->getMaxConnections());
    }

    #[Test]
    public function socket_factory_interface_has_correct_return_types(): void
    {
        $socketConfig = new class implements SocketConfigInterface {
            public function getHost(): string
            {
                return '127.0.0.1';
            }

            public function getPort(): int
            {
                return 8080;
            }

            public function getBacklog(): int
            {
                return 128;
            }

            public function getMaxConnections(): int
            {
                return 1000;
            }
        };

        $factory = new class ($socketConfig) implements SocketFactoryInterface {
            public function __construct(private readonly SocketConfigInterface $config) {}

            public function createListeningSocket(int $workerId): Socket
            {
                $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
                assert(false !== $socket);
                return $socket;
            }

            public function createSharedSocket(int $workerId): Socket
            {
                $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
                assert(false !== $socket);
                return $socket;
            }

            public function getConfig(): SocketConfigInterface
            {
                return $this->config;
            }
        };

        $this->assertInstanceOf(Socket::class, $factory->createListeningSocket(1));
        $this->assertInstanceOf(Socket::class, $factory->createSharedSocket(1));
        $this->assertInstanceOf(SocketConfigInterface::class, $factory->getConfig());
    }

    #[Test]
    public function worker_interface_has_correct_signature(): void
    {
        $worker = new class implements WorkerInterface {
            public function run(int $workerId, WorkerContextInterface $context): void {}
        };

        $context = new class implements WorkerContextInterface {
            public function getWorkerId(): int
            {
                return 1;
            }

            public function getWorkerPid(): ?int
            {
                return null;
            }

            public function getMetadata(): array
            {
                return [];
            }

            public function isActive(): bool
            {
                return true;
            }

            public function getSocketResource(): mixed
            {
                return null;
            }

            public function notifyEventLoop(): void {}
        };

        $worker->run(1, $context);
        $this->assertTrue(true);
    }

    #[Test]
    public function worker_factory_interface_has_correct_signature(): void
    {
        $factory = new class implements WorkerFactoryInterface {
            public function createWorkerContext(int $workerId, ?Socket $socket = null): WorkerContextInterface
            {
                return new class ($workerId, $socket) implements WorkerContextInterface {
                    public function __construct(
                        private readonly int $workerId,
                        private readonly mixed $socket,
                    ) {}

                    public function getWorkerId(): int
                    {
                        return $this->workerId;
                    }

                    public function getWorkerPid(): ?int
                    {
                        return null;
                    }

                    public function getMetadata(): array
                    {
                        return [];
                    }

                    public function isActive(): bool
                    {
                        return true;
                    }

                    public function getSocketResource(): mixed
                    {
                        return $this->socket;
                    }

                    public function notifyEventLoop(): void {}
                };
            }
        };

        $context = $factory->createWorkerContext(1);
        $this->assertInstanceOf(WorkerContextInterface::class, $context);
        $this->assertSame(1, $context->getWorkerId());
    }
}

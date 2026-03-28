<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Master;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\WorkerPool\Balancer\BalancerInterface;
use Duyler\WorkerPool\Balancer\LeastConnectionsBalancer;
use Duyler\WorkerPool\Config\WorkerPoolConfig;
use Duyler\WorkerPool\Util\SystemInfo;
use Duyler\WorkerPool\Worker\EventDrivenWorkerInterface;
use Duyler\WorkerPool\Worker\WorkerCallbackInterface;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class MasterFactory
{
    public static function create(
        WorkerPoolConfig $config,
        ServerConfig $serverConfig,
        ?WorkerCallbackInterface $workerCallback = null,
        ?EventDrivenWorkerInterface $eventDrivenWorker = null,
        ?BalancerInterface $balancer = null,
        ?LoggerInterface $logger = null,
    ): MasterInterface {
        if (null === $workerCallback && null === $eventDrivenWorker) {
            throw new InvalidArgumentException(
                'Either workerCallback or eventDrivenWorker must be provided',
            );
        }

        $systemInfo = new SystemInfo();

        if ($systemInfo->supportsFdPassing() && null !== $balancer) {
            return new CentralizedMaster(
                config: $config,
                balancer: $balancer,
                serverConfig: $serverConfig,
                workerCallback: $workerCallback,
                eventDrivenWorker: $eventDrivenWorker,
                logger: $logger ?? new NullLogger(),
            );
        }

        return new SharedSocketMaster(
            config: $config,
            serverConfig: $serverConfig,
            workerCallback: $workerCallback,
            eventDrivenWorker: $eventDrivenWorker,
            logger: $logger ?? new NullLogger(),
        );
    }

    public static function createRecommended(
        WorkerPoolConfig $config,
        ServerConfig $serverConfig,
        ?WorkerCallbackInterface $workerCallback = null,
        ?EventDrivenWorkerInterface $eventDrivenWorker = null,
        ?LoggerInterface $logger = null,
    ): MasterInterface {
        if (null === $workerCallback && null === $eventDrivenWorker) {
            throw new InvalidArgumentException(
                'Either workerCallback or eventDrivenWorker must be provided',
            );
        }

        $systemInfo = new SystemInfo();

        if ($systemInfo->supportsFdPassing()) {
            $balancer = new LeastConnectionsBalancer();

            return new CentralizedMaster(
                config: $config,
                balancer: $balancer,
                serverConfig: $serverConfig,
                workerCallback: $workerCallback,
                eventDrivenWorker: $eventDrivenWorker,
                logger: $logger ?? new NullLogger(),
            );
        }

        return new SharedSocketMaster(
            config: $config,
            serverConfig: $serverConfig,
            workerCallback: $workerCallback,
            eventDrivenWorker: $eventDrivenWorker,
            logger: $logger ?? new NullLogger(),
        );
    }

    public static function recommendedMaster(): string
    {
        $systemInfo = new SystemInfo();

        if ($systemInfo->supportsFdPassing()) {
            return 'CentralizedMaster - Centralized queue with custom load balancing';
        }

        return 'SharedSocketMaster - Distributed architecture with kernel load balancing';
    }

    /**
     * @return array<string, array<string, string>>
     */
    public static function getComparison(): array
    {
        return [
            'SharedSocketMaster' => [
                'architecture' => 'Distributed (each worker has socket)',
                'load_balancing' => 'Kernel (automatic)',
                'requirements' => 'SO_REUSEPORT',
                'platforms' => 'Linux, Docker, macOS (via Docker)',
                'complexity' => 'Low',
                'use_case' => 'Simple setup, kernel balancing sufficient',
            ],
            'CentralizedMaster' => [
                'architecture' => 'Centralized (master accepts, distributes via IPC)',
                'load_balancing' => 'Custom (Least Connections, Round Robin)',
                'requirements' => 'SCM_RIGHTS (socket_sendmsg)',
                'platforms' => 'Linux only',
                'complexity' => 'High',
                'use_case' => 'Custom balancing, sticky sessions needed',
            ],
        ];
    }
}

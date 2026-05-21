<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Master;

use Duyler\WorkerPool\Balancer\BalancerInterface;
use Duyler\WorkerPool\IPC\FdPasser;
use Duyler\WorkerPool\Process\ProcessInfo;
use Duyler\WorkerPool\Process\ProcessState;
use Duyler\WorkerPool\Socket\SocketWrapperInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Socket;
use Throwable;

final readonly class ConnectionRouter
{
    public function __construct(
        private SocketWrapperInterface $socketWrapper,
        private BalancerInterface $balancer,
        private FdPasser $fdPasser,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * @param Socket $clientSocket
     * @param array<int, ProcessInfo> $workers
     * @param array<int, Socket> $workerSockets
     * @param array<string, mixed> $metadata
     */
    public function route(
        Socket $clientSocket,
        array $workers,
        array $workerSockets,
        array $metadata = [],
    ): bool {
        $workerId = $this->selectWorker($workers);

        if (null === $workerId) {
            $this->logger->warning('No available workers');
            $this->socketWrapper->close($clientSocket);
            return false;
        }

        if (false === isset($workerSockets[$workerId])) {
            $this->logger->error('Worker socket not found', ['worker_id' => $workerId]);
            $this->socketWrapper->close($clientSocket);
            return false;
        }

        $clientIp = '';
        $this->socketWrapper->getPeerName($clientSocket, $clientIp);

        $this->logger->debug('Passing FD to worker', ['worker_id' => $workerId]);

        try {
            $this->fdPasser->sendFd(
                controlSocket: $workerSockets[$workerId],
                fdToSend: $clientSocket,
                metadata: array_merge($metadata, [
                    'worker_id' => $workerId,
                    'client_ip' => $clientIp,
                ]),
            );

            $this->logger->debug('FD passed successfully', ['worker_id' => $workerId]);

            $this->balancer->onConnectionEstablished($workerId);

            return true;
        } catch (Throwable $e) {
            $this->logger->error('Failed to pass FD to worker', [
                'worker_id' => $workerId,
                'error' => $e->getMessage(),
            ]);
            $this->socketWrapper->close($clientSocket);

            return false;
        }
    }

    public function getBalancer(): BalancerInterface
    {
        return $this->balancer;
    }

    /**
     * @param array<int, ProcessInfo> $workers
     */
    private function selectWorker(array $workers): ?int
    {
        $connections = [];

        foreach ($workers as $worker) {
            if ($worker->isAlive() && $worker->state === ProcessState::Ready) {
                $connections[$worker->workerId] = $worker->connections;
            }
        }

        return $this->balancer->selectWorker($connections);
    }
}

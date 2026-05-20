<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\Master;

use PHPUnit\Framework\Attributes\CoversClass;

use Duyler\WorkerPool\Master\WorkerManager;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function count;

#[Group('pcntl')]
#[CoversClass(WorkerManager::class)]
final class ForkWorkerManagerTest extends TestCase
{
    #[Test]
    public function spawnCreatesChildProcess(): void
    {
        $manager = new WorkerManager();

        $info = $manager->spawn(1, function (int $workerId): void {
            usleep(100000);
            exit(0);
        });

        $this->assertSame(1, $info->workerId);
        $this->assertGreaterThan(0, $info->pid);
        $this->assertSame(1, $manager->countAlive());

        $manager->stopAll();
        $manager->waitAll();
    }

    #[Test]
    public function stopAllSendsSigterm(): void
    {
        $manager = new WorkerManager();

        $manager->spawn(1, function (): void {
            sleep(30);
            exit(0);
        });

        $this->assertSame(1, $manager->countAlive());

        $manager->stopAll();
        $manager->waitAll();

        $this->assertSame(0, $manager->countAlive());
    }

    #[Test]
    public function checkRemovesDeadWorkers(): void
    {
        $manager = new WorkerManager();

        $manager->spawn(1, function (): void {
            usleep(50000);
            exit(0);
        });

        usleep(100000);

        $manager->check();

        $workers = $manager->getWorkers();
        $this->assertEmpty($workers);
    }

    #[Test]
    public function spawnMultipleWorkers(): void
    {
        $manager = new WorkerManager();

        $manager->spawn(1, function (): void {
            usleep(200000);
            exit(0);
        });
        $manager->spawn(2, function (): void {
            usleep(200000);
            exit(0);
        });

        $this->assertSame(2, count($manager->getWorkers()));

        $manager->stopAll();
        $manager->waitAll();
    }
}

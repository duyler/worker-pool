<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class EnvironmentTest extends TestCase
{
    #[Test]
    public function environment_is_ready(): void
    {
        $this->assertTrue(true);
    }
}

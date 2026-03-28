<?php

declare(strict_types=1);

namespace Duyler\WorkerPool\Tests\Unit\Config;

use Duyler\WorkerPool\Config\BalancerType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BalancerTypeTest extends TestCase
{
    #[Test]
    public function least_connections_case_exists(): void
    {
        $this->assertSame('least_connections', BalancerType::LeastConnections->value);
    }

    #[Test]
    public function round_robin_case_exists(): void
    {
        $this->assertSame('round_robin', BalancerType::RoundRobin->value);
    }

    #[Test]
    public function weighted_case_exists(): void
    {
        $this->assertSame('weighted', BalancerType::Weighted->value);
    }

    #[Test]
    public function all_cases_are_string_backed(): void
    {
        foreach (BalancerType::cases() as $case) {
            $this->assertIsString($case->value);
        }
    }

    #[Test]
    public function has_three_cases(): void
    {
        $this->assertCount(3, BalancerType::cases());
    }

    #[Test]
    public function can_be_created_from_string(): void
    {
        $balancer = BalancerType::from('least_connections');
        $this->assertSame(BalancerType::LeastConnections, $balancer);
    }

    #[Test]
    public function try_from_returns_correct_case(): void
    {
        $balancer = BalancerType::tryFrom('round_robin');
        $this->assertSame(BalancerType::RoundRobin, $balancer);
    }

    #[Test]
    public function try_from_returns_null_for_invalid_value(): void
    {
        $balancer = BalancerType::tryFrom('invalid');
        $this->assertNull($balancer);
    }
}

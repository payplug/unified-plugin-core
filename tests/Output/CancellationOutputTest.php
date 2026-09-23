<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Tests\Output;

use PayplugUnifiedCore\Output\CancellationOutput;
use PHPUnit\Framework\TestCase;

final class CancellationOutputTest extends TestCase
{
    public function testConstructorAssignsAllProperties(): void
    {
        $output = new CancellationOutput(200, '{"id":"pay_123"}', 400, 1000);

        self::assertSame(200, $output->status);
        self::assertSame('{"id":"pay_123"}', $output->body);
        self::assertSame(400, $output->cancelledAmount);
        self::assertSame(1000, $output->requestedAmount);
    }

    public function testConstructorComputesRemainingCancellableAmountWhenBothAmountsArePresent(): void
    {
        $output = new CancellationOutput(200, '{}', 400, 1000);

        self::assertSame(600, $output->remainingCancellableAmount);
    }

    public function testConstructorFloorsRemainingCancellableAmountAtZero(): void
    {
        $output = new CancellationOutput(200, '{}', 1200, 1000);

        self::assertSame(0, $output->remainingCancellableAmount);
    }

    public function testConstructorLeavesRemainingCancellableAmountNullWhenEitherAmountIsMissing(): void
    {
        self::assertNull((new CancellationOutput(200, '{}', null, 1000))->remainingCancellableAmount);
        self::assertNull((new CancellationOutput(200, '{}', 400, null))->remainingCancellableAmount);
    }

    public function testConstructorAllowsAllDerivedFieldsToBeNull(): void
    {
        $output = new CancellationOutput(200, '{}', null, null);

        self::assertNull($output->cancelledAmount);
        self::assertNull($output->requestedAmount);
        self::assertNull($output->remainingCancellableAmount);
    }
}

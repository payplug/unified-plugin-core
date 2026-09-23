<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Tests\Output;

use PayplugUnifiedCore\Output\CaptureOutput;
use PHPUnit\Framework\TestCase;

final class CaptureOutputTest extends TestCase
{
    public function testConstructorAssignsAllProperties(): void
    {
        $output = new CaptureOutput(200, '{"id":"pay_123"}', 300, 1000, '2026-09-25T12:00:00Z');

        self::assertSame(200, $output->status);
        self::assertSame('{"id":"pay_123"}', $output->body);
        self::assertSame(300, $output->capturedAmount);
        self::assertSame(1000, $output->requestedAmount);
        self::assertSame('2026-09-25T12:00:00Z', $output->maxCaptureDate);
    }

    public function testConstructorComputesRemainingCapturableAmountWhenBothAmountsArePresent(): void
    {
        $output = new CaptureOutput(200, '{}', 300, 1000, null);

        self::assertSame(700, $output->remainingCapturableAmount);
    }

    public function testConstructorFloorsRemainingCapturableAmountAtZero(): void
    {
        // Defensive: the Unified API itself rejects an over-capture before this ever happens in
        // practice, but the computation must not surface a negative "remaining" value regardless.
        $output = new CaptureOutput(200, '{}', 1200, 1000, null);

        self::assertSame(0, $output->remainingCapturableAmount);
    }

    public function testConstructorLeavesRemainingCapturableAmountNullWhenCapturedAmountIsMissing(): void
    {
        $output = new CaptureOutput(200, '{}', null, 1000, null);

        self::assertNull($output->remainingCapturableAmount);
    }

    public function testConstructorLeavesRemainingCapturableAmountNullWhenRequestedAmountIsMissing(): void
    {
        $output = new CaptureOutput(200, '{}', 300, null, null);

        self::assertNull($output->remainingCapturableAmount);
    }

    public function testConstructorAllowsAllDerivedFieldsToBeNull(): void
    {
        $output = new CaptureOutput(200, '{}', null, null, null);

        self::assertNull($output->capturedAmount);
        self::assertNull($output->requestedAmount);
        self::assertNull($output->maxCaptureDate);
        self::assertNull($output->remainingCapturableAmount);
    }
}

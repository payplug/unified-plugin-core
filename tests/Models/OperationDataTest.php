<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Tests\Models;

use PayplugUnifiedCore\Exceptions\InvalidOperationDataException;
use PayplugUnifiedCore\Models\OperationData;
use PayplugUnifiedCore\Models\PaymentOutcome;
use PHPUnit\Framework\TestCase;

final class OperationDataTest extends TestCase
{
    public function testConstructorAssignsAllProperties(): void
    {
        $data = new OperationData('op_123', '4001', PaymentOutcome::PAID, 4999, 'order_456');

        self::assertSame('op_123', $data->operationId);
        self::assertSame('4001', $data->execCode);
        self::assertSame(PaymentOutcome::PAID, $data->outcome);
        self::assertSame(4999, $data->amount);
        self::assertSame('order_456', $data->orderId);
    }

    public function testConstructorThrowsWhenOperationIdIsEmpty(): void
    {
        $this->expectException(InvalidOperationDataException::class);
        $this->expectExceptionMessage('operationId must not be empty.');

        new OperationData('', '4001', PaymentOutcome::PAID, 4999, 'order_456');
    }

    public function testConstructorAllowsZeroAmount(): void
    {
        $data = new OperationData('op_123', '4001', PaymentOutcome::CAPTURE_REQUIRED, 0, 'order_456');

        self::assertSame(0, $data->amount);
    }

    public function testConstructorThrowsWhenExecCodeIsEmpty(): void
    {
        $this->expectException(InvalidOperationDataException::class);
        $this->expectExceptionMessage('execCode must not be empty.');

        new OperationData('op_123', '', PaymentOutcome::PAID, 4999, 'order_456');
    }

    public function testConstructorThrowsWhenOutcomeIsNotAValidPaymentOutcome(): void
    {
        $this->expectException(InvalidOperationDataException::class);
        $this->expectExceptionMessage('"bogus_outcome" is not a valid PaymentOutcome.');

        new OperationData('op_123', '4001', 'bogus_outcome', 4999, 'order_456');
    }

    public function testConstructorThrowsWhenAmountIsNegative(): void
    {
        $this->expectException(InvalidOperationDataException::class);
        $this->expectExceptionMessage('amount must not be negative.');

        new OperationData('op_123', '4001', PaymentOutcome::PAID, -1, 'order_456');
    }

    public function testConstructorThrowsWhenOrderIdIsEmpty(): void
    {
        $this->expectException(InvalidOperationDataException::class);
        $this->expectExceptionMessage('orderId must not be empty.');

        new OperationData('op_123', '4001', PaymentOutcome::PAID, 4999, '');
    }
}

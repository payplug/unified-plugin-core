<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Tests\Utilities\Helpers;

use PayplugUnifiedCore\Exceptions\InvalidCommonFieldsException;
use PayplugUnifiedCore\Exceptions\InvalidPaymentException;
use PayplugUnifiedCore\Exceptions\InvalidTokenException;
use PayplugUnifiedCore\Utilities\Helpers\Assert;
use PHPUnit\Framework\TestCase;

final class AssertTest extends TestCase
{
    public function testNotEmptyPassesForANonEmptyString(): void
    {
        Assert::notEmpty('acc_123', 'accountId', InvalidCommonFieldsException::class);

        $this->expectNotToPerformAssertions();
    }

    public function testNotEmptyThrowsTheGivenExceptionClassWithAFieldNamedMessage(): void
    {
        $this->expectException(InvalidCommonFieldsException::class);
        $this->expectExceptionMessage('accountId must not be empty.');

        Assert::notEmpty('', 'accountId', InvalidCommonFieldsException::class);
    }

    public function testNotEmptyUsesTheCallerSuppliedExceptionClass(): void
    {
        $this->expectException(InvalidTokenException::class);

        Assert::notEmpty('', 'accessToken', InvalidTokenException::class);
    }

    public function testNotNegativePassesForZero(): void
    {
        Assert::notNegative(0, 'amount', InvalidCommonFieldsException::class);

        $this->expectNotToPerformAssertions();
    }

    public function testNotNegativePassesForAPositiveValue(): void
    {
        Assert::notNegative(1000, 'amount', InvalidCommonFieldsException::class);

        $this->expectNotToPerformAssertions();
    }

    public function testNotNegativeThrowsForANegativeValue(): void
    {
        $this->expectException(InvalidCommonFieldsException::class);
        $this->expectExceptionMessage('amount must not be negative.');

        Assert::notNegative(-1, 'amount', InvalidCommonFieldsException::class);
    }

    public function testPositivePassesForAPositiveValue(): void
    {
        Assert::positive(3600, 'expiresIn', InvalidTokenException::class);

        $this->expectNotToPerformAssertions();
    }

    public function testPositiveThrowsForZero(): void
    {
        $this->expectException(InvalidTokenException::class);
        $this->expectExceptionMessage('expiresIn must be greater than zero.');

        Assert::positive(0, 'expiresIn', InvalidTokenException::class);
    }

    public function testPositiveThrowsForANegativeValue(): void
    {
        $this->expectException(InvalidTokenException::class);
        $this->expectExceptionMessage('expiresIn must be greater than zero.');

        Assert::positive(-1, 'expiresIn', InvalidTokenException::class);
    }

    public function testPaymentMethodIdNotSetPassesWhenPaymentMethodIsNull(): void
    {
        Assert::paymentMethodIdNotSet(null, 'PaymentDto', InvalidPaymentException::class);

        $this->expectNotToPerformAssertions();
    }

    public function testPaymentMethodIdNotSetPassesWhenIdKeyIsAbsent(): void
    {
        Assert::paymentMethodIdNotSet(['details' => ['selectedBrand' => 'VISA']], 'PaymentDto', InvalidPaymentException::class);

        $this->expectNotToPerformAssertions();
    }

    public function testPaymentMethodIdNotSetThrowsWhenIdKeyIsSet(): void
    {
        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage("paymentMethod must not set 'id' directly; use PaymentDto instead.");

        Assert::paymentMethodIdNotSet(['id' => 'alias_789'], 'PaymentDto', InvalidPaymentException::class);
    }

    public function testPaymentMethodIdNotSetThrowsWhenIdKeyIsExplicitlyNull(): void
    {
        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage("paymentMethod must not set 'id' directly; use PaymentDto instead.");

        Assert::paymentMethodIdNotSet(['id' => null], 'PaymentDto', InvalidPaymentException::class);
    }
}

<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Tests\Validators;

use PayplugUnifiedCore\Exceptions\InvalidCommonFieldsException;
use PayplugUnifiedCore\Exceptions\InvalidPaymentException;
use PayplugUnifiedCore\Tests\Support\PaymentDtoBuilder;
use PayplugUnifiedCore\Validators\PaymentDtoValidator;
use PHPUnit\Framework\TestCase;

final class PaymentDtoValidatorTest extends TestCase
{
    public function testValidatePassesForAFullyPopulatedValidDto(): void
    {
        PaymentDtoValidator::validate(PaymentDtoBuilder::valid()->build());

        $this->expectNotToPerformAssertions();
    }

    public function testValidateThrowsWhenAliasIdIsEmpty(): void
    {
        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('aliasId must not be empty.');

        PaymentDtoValidator::validate(PaymentDtoBuilder::valid()->withAliasId('')->build());
    }

    public function testValidateThrowsWhenRecurringModeIsEmpty(): void
    {
        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('recurringMode must not be empty.');

        PaymentDtoValidator::validate(PaymentDtoBuilder::valid()->withRecurringMode('')->build());
    }

    public function testValidateThrowsWhenPaymentMethodSetsIdDirectly(): void
    {
        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage("paymentMethod must not set 'id' directly; use the aliasId constructor argument instead.");

        PaymentDtoValidator::validate(PaymentDtoBuilder::valid()
            ->withPaymentMethod(['id' => 'other_alias'])
            ->build());
    }

    public function testValidateThrowsWhenAccountIdIsEmptyAndWrapsTheUnderlyingInvalidCommonFieldsException(): void
    {
        try {
            PaymentDtoValidator::validate(PaymentDtoBuilder::valid()->withAccountId('')->build());
            self::fail('Expected InvalidPaymentException to be thrown.');
        } catch (InvalidPaymentException $e) {
            self::assertSame('accountId must not be empty.', $e->getMessage());
            self::assertInstanceOf(InvalidCommonFieldsException::class, $e->getPrevious());
        }
    }

    public function testValidateThrowsWhenOrderIdIsEmpty(): void
    {
        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('orderId must not be empty.');

        PaymentDtoValidator::validate(PaymentDtoBuilder::valid()->withOrderId('')->build());
    }

    public function testValidateThrowsWhenCurrencyIsEmpty(): void
    {
        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('currency must not be empty.');

        PaymentDtoValidator::validate(PaymentDtoBuilder::valid()->withCurrency('')->build());
    }

    public function testValidateThrowsWhenAmountIsNegative(): void
    {
        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('amount must not be negative.');

        PaymentDtoValidator::validate(PaymentDtoBuilder::valid()->withAmount(-1)->build());
    }

    public function testValidatePassesWhenAmountIsZero(): void
    {
        PaymentDtoValidator::validate(PaymentDtoBuilder::valid()->withAmount(0)->build());

        $this->expectNotToPerformAssertions();
    }

    public function testValidateThrowsWhenPaymentMethodSetsSaveFutureUsageDirectly(): void
    {
        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage("paymentMethod must not set 'saveFutureUsage'; creating an alias while paying with one is not supported.");

        PaymentDtoValidator::validate(PaymentDtoBuilder::valid()
            ->withPaymentMethod(['saveFutureUsage' => true])
            ->build());
    }

    public function testValidateThrowsWhenPaymentMethodSetsSaveFutureUsageToFalse(): void
    {
        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage("paymentMethod must not set 'saveFutureUsage'; creating an alias while paying with one is not supported.");

        PaymentDtoValidator::validate(PaymentDtoBuilder::valid()
            ->withPaymentMethod(['saveFutureUsage' => false])
            ->build());
    }

    public function testValidatePassesWhenPaymentMethodOmitsSaveFutureUsage(): void
    {
        PaymentDtoValidator::validate(PaymentDtoBuilder::valid()
            ->withPaymentMethod(['details' => ['selectedBrand' => 'VISA']])
            ->build());

        $this->expectNotToPerformAssertions();
    }
}

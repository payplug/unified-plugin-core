<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Tests\Validators;

use PayplugUnifiedCore\Dto\BrowserDto;
use PayplugUnifiedCore\Dto\CustomerDto;
use PayplugUnifiedCore\Exceptions\InvalidCommonFieldsException;
use PayplugUnifiedCore\Exceptions\InvalidHostedFieldException;
use PayplugUnifiedCore\Tests\Support\HostedFieldDtoBuilder;
use PayplugUnifiedCore\Validators\HostedFieldDtoValidator;
use PHPUnit\Framework\TestCase;

final class HostedFieldDtoValidatorTest extends TestCase
{
    public function testValidatePassesForAFullyPopulatedValidDto(): void
    {
        HostedFieldDtoValidator::validate(HostedFieldDtoBuilder::valid()
            ->withBrowser(new BrowserDto('10.1.1.1', 'https://shop.example.com/cart', 'Mozilla/5.0'))
            ->withCustomer(new CustomerDto('john.snow', 'john.snow@example.com'))
            ->build());

        $this->expectNotToPerformAssertions();
    }

    public function testValidatePassesWhenBrowserReferrerIsEmptyString(): void
    {
        HostedFieldDtoValidator::validate(HostedFieldDtoBuilder::valid()
            ->withBrowser(new BrowserDto('10.1.1.1', '', 'Mozilla/5.0'))
            ->build());

        $this->expectNotToPerformAssertions();
    }

    public function testValidatePassesForTheMinimalRequiredFieldsOnly(): void
    {
        HostedFieldDtoValidator::validate(HostedFieldDtoBuilder::valid()->build());

        $this->expectNotToPerformAssertions();
    }

    public function testValidatePassesWhenAmountIsZero(): void
    {
        HostedFieldDtoValidator::validate(HostedFieldDtoBuilder::valid()->withAmount(0)->build());

        $this->expectNotToPerformAssertions();
    }

    public function testValidateThrowsWhenAccountIdIsEmptyAndWrapsTheUnderlyingInvalidCommonFieldsException(): void
    {
        try {
            HostedFieldDtoValidator::validate(HostedFieldDtoBuilder::valid()->withAccountId('')->build());
            self::fail('Expected InvalidHostedFieldException to be thrown.');
        } catch (InvalidHostedFieldException $e) {
            self::assertSame('accountId must not be empty.', $e->getMessage());
            self::assertInstanceOf(InvalidCommonFieldsException::class, $e->getPrevious());
        }
    }

    public function testValidateThrowsWhenHfTokenIsEmpty(): void
    {
        $this->expectException(InvalidHostedFieldException::class);
        $this->expectExceptionMessage('hfToken must not be empty.');

        HostedFieldDtoValidator::validate(HostedFieldDtoBuilder::valid()->withHfToken('')->build());
    }

    public function testValidateThrowsWhenOrderIdIsEmpty(): void
    {
        $this->expectException(InvalidHostedFieldException::class);
        $this->expectExceptionMessage('orderId must not be empty.');

        HostedFieldDtoValidator::validate(HostedFieldDtoBuilder::valid()->withOrderId('')->build());
    }

    public function testValidateThrowsWhenCurrencyIsEmpty(): void
    {
        $this->expectException(InvalidHostedFieldException::class);
        $this->expectExceptionMessage('currency must not be empty.');

        HostedFieldDtoValidator::validate(HostedFieldDtoBuilder::valid()->withCurrency('')->build());
    }

    public function testValidateThrowsWhenAmountIsNegative(): void
    {
        $this->expectException(InvalidHostedFieldException::class);
        $this->expectExceptionMessage('amount must not be negative.');

        HostedFieldDtoValidator::validate(HostedFieldDtoBuilder::valid()->withAmount(-1)->build());
    }
}

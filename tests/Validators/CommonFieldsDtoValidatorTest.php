<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Tests\Validators;

use PayplugUnifiedCore\DataValues\AuthorizationType;
use PayplugUnifiedCore\Dto\CommonFieldsDto;
use PayplugUnifiedCore\Exceptions\InvalidCommonFieldsException;
use PayplugUnifiedCore\Validators\CommonFieldsDtoValidator;
use PHPUnit\Framework\TestCase;

final class CommonFieldsDtoValidatorTest extends TestCase
{
    public function testValidatePassesForAValidDto(): void
    {
        CommonFieldsDtoValidator::validate(new CommonFieldsDto('acc_123', 1000, 'EUR', 'order_456', 'submerchant_789'));

        $this->expectNotToPerformAssertions();
    }

    public function testValidatePassesWhenAmountIsZero(): void
    {
        CommonFieldsDtoValidator::validate(new CommonFieldsDto('acc_123', 0, 'EUR', 'order_456', 'submerchant_789'));

        $this->expectNotToPerformAssertions();
    }

    public function testValidateThrowsWhenAccountIdIsEmpty(): void
    {
        $this->expectException(InvalidCommonFieldsException::class);
        $this->expectExceptionMessage('accountId must not be empty.');

        CommonFieldsDtoValidator::validate(new CommonFieldsDto('', 1000, 'EUR', 'order_456', 'submerchant_789'));
    }

    public function testValidateThrowsWhenOrderIdIsEmpty(): void
    {
        $this->expectException(InvalidCommonFieldsException::class);
        $this->expectExceptionMessage('orderId must not be empty.');

        CommonFieldsDtoValidator::validate(new CommonFieldsDto('acc_123', 1000, 'EUR', '', 'submerchant_789'));
    }

    public function testValidateThrowsWhenCurrencyIsEmpty(): void
    {
        $this->expectException(InvalidCommonFieldsException::class);
        $this->expectExceptionMessage('currency must not be empty.');

        CommonFieldsDtoValidator::validate(new CommonFieldsDto('acc_123', 1000, '', 'order_456', 'submerchant_789'));
    }

    public function testValidateThrowsWhenAmountIsNegative(): void
    {
        $this->expectException(InvalidCommonFieldsException::class);
        $this->expectExceptionMessage('amount must not be negative.');

        CommonFieldsDtoValidator::validate(new CommonFieldsDto('acc_123', -1, 'EUR', 'order_456', 'submerchant_789'));
    }

    public function testValidatePassesWhenAuthorizationTypeIsNull(): void
    {
        CommonFieldsDtoValidator::validate(new CommonFieldsDto('acc_123', 1000, 'EUR', 'order_456', 'submerchant_789'));

        $this->expectNotToPerformAssertions();
    }

    /**
     * @dataProvider validAuthorizationTypeProvider
     */
    public function testValidatePassesForEveryValidAuthorizationType(string $authorizationType): void
    {
        $dto = new CommonFieldsDto('acc_123', 1000, 'EUR', 'order_456', 'submerchant_789');
        $dto->authorizationType = $authorizationType;

        CommonFieldsDtoValidator::validate($dto);

        $this->expectNotToPerformAssertions();
    }

    /**
     * @return array<string, array{string}>
     */
    public function validAuthorizationTypeProvider(): array
    {
        return [
            'PRE_AUTHORIZATION' => [AuthorizationType::PRE_AUTHORIZATION],
            'FINAL_AUTHORIZATION' => [AuthorizationType::FINAL_AUTHORIZATION],
        ];
    }

    public function testValidateThrowsWhenAuthorizationTypeIsInvalid(): void
    {
        $dto = new CommonFieldsDto('acc_123', 1000, 'EUR', 'order_456', 'submerchant_789');
        $dto->authorizationType = 'SOMETHING_ELSE';

        $this->expectException(InvalidCommonFieldsException::class);
        $this->expectExceptionMessage('"SOMETHING_ELSE" is not a valid AuthorizationType.');

        CommonFieldsDtoValidator::validate($dto);
    }

    public function testValidatePassesWhenAuthorizationTypeIsUppercase(): void
    {
        $dto = new CommonFieldsDto('acc_123', 1000, 'EUR', 'order_456', 'submerchant_789');
        $dto->authorizationType = 'FINAL_AUTHORIZATION';

        CommonFieldsDtoValidator::validate($dto);

        $this->expectNotToPerformAssertions();
    }
}

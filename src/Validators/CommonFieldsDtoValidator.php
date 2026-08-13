<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Validators;

use PayplugUnifiedCore\Dto\CommonFieldsDto;
use PayplugUnifiedCore\Exceptions\InvalidCommonFieldsException;
use PayplugUnifiedCore\Exceptions\PayplugException;
use PayplugUnifiedCore\Utilities\Helpers\Assert;

/**
 * Validates the payment-creation fields common to every Unified API payment method — the fields
 * CommonFieldsDto holds no validation for itself. Reusable by any future payment-method DTO
 * (raw-card, wallet) that composes a CommonFieldsDto, not just HostedFieldDto.
 *
 * <code>
 * try {
 *     CommonFieldsDtoValidator::validate($commonFieldsDto);
 * } catch (InvalidCommonFieldsException $e) {
 *     // reject the request, log $e->getMessage()
 * }
 * </code>
 */
final class CommonFieldsDtoValidator
{
    /**
     * @codeCoverageIgnore
     */
    private function __construct()
    {
    }

    /**
     * @throws InvalidCommonFieldsException on the first validation problem found
     */
    public static function validate(CommonFieldsDto $dto): void
    {
        Assert::notEmpty($dto->accountId, 'accountId', InvalidCommonFieldsException::class);
        Assert::notEmpty($dto->orderId, 'orderId', InvalidCommonFieldsException::class);
        Assert::notEmpty($dto->currency, 'currency', InvalidCommonFieldsException::class);
        Assert::notNegative($dto->amount, 'amount', InvalidCommonFieldsException::class);
    }

    /**
     * validate(), wrapping InvalidCommonFieldsException into $exceptionClass — every
     * payment-method-specific validator (HostedFieldDtoValidator, PaymentDtoValidator) delegates
     * to CommonFieldsDtoValidator this same way, so callers of createPayment() only ever
     * need to catch one exception type per DTO rather than also knowing about this one.
     *
     * @param class-string<PayplugException> $exceptionClass
     * @throws PayplugException wrapping the original InvalidCommonFieldsException, message and
     *                          previous-exception both preserved
     */
    public static function validateOrWrap(CommonFieldsDto $dto, string $exceptionClass): void
    {
        try {
            self::validate($dto);
        } catch (InvalidCommonFieldsException $e) {
            throw new $exceptionClass($e->getMessage(), 0, $e);
        }
    }
}

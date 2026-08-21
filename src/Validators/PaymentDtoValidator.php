<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Validators;

use PayplugUnifiedCore\Dto\PaymentDto;
use PayplugUnifiedCore\Exceptions\InvalidPaymentException;
use PayplugUnifiedCore\Utilities\Helpers\Assert;

/**
 * Validates a CMS-built PaymentDto before UnifiedApiPaymentService::createPayment()
 * uses it — the sibling of HostedFieldDtoValidator, for the alias-based payment flow. Delegates
 * the fields common to every payment method to CommonFieldsDtoValidator, wrapping its
 * InvalidCommonFieldsException into InvalidPaymentException so callers only ever need to catch one
 * exception type per DTO.
 *
 * <code>
 * try {
 *     PaymentDtoValidator::validate($dto);
 * } catch (InvalidPaymentException $e) {
 *     // reject the request, log $e->getMessage()
 * }
 * </code>
 */
final class PaymentDtoValidator
{
    /**
     * @codeCoverageIgnore
     */
    private function __construct()
    {
    }

    /**
     * @throws InvalidPaymentException on the first validation problem found
     */
    public static function validate(PaymentDto $dto): void
    {
        CommonFieldsDtoValidator::validateOrWrap($dto->common, InvalidPaymentException::class);

        Assert::notEmpty($dto->aliasId, 'aliasId', InvalidPaymentException::class);
        Assert::notEmpty($dto->recurringMode, 'recurringMode', InvalidPaymentException::class);

        Assert::paymentMethodIdNotSet($dto->paymentMethod, 'the aliasId constructor argument', InvalidPaymentException::class);
    }
}

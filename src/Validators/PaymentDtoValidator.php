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

        self::assertSaveFutureUsageNotSet($dto->paymentMethod);
    }

    /**
     * PaymentDto::$paymentMethod's own docblock documents that its shape has no saveFutureUsage
     * key at all — creating an alias while paying with one makes no sense — but nothing enforced
     * that at runtime until now. Mirrors Assert::paymentMethodIdNotSet's array_key_exists() check
     * rather than a truthy check, since the key itself is disallowed regardless of its value.
     *
     * @param array<string, mixed>|null $paymentMethod
     * @throws InvalidPaymentException if $paymentMethod sets a saveFutureUsage key directly
     */
    private static function assertSaveFutureUsageNotSet(?array $paymentMethod): void
    {
        if ($paymentMethod !== null && \array_key_exists('saveFutureUsage', $paymentMethod)) {
            throw new InvalidPaymentException("paymentMethod must not set 'saveFutureUsage'; creating an alias while paying with one is not supported.");
        }
    }
}

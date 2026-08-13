<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Validators;

use PayplugUnifiedCore\Dto\HostedFieldDto;
use PayplugUnifiedCore\Exceptions\InvalidHostedFieldException;
use PayplugUnifiedCore\Utilities\Helpers\Assert;

/**
 * Validates a CMS-built HostedFieldDto before UnifiedApiPaymentService::createPayment()
 * uses it. Delegates the fields common to every payment method to CommonFieldsDtoValidator,
 * wrapping its InvalidCommonFieldsException into InvalidHostedFieldException so callers of
 * createPayment() still only ever need to catch one exception type. The old browser/customer
 * "all sub-fields present together" checks are gone — impossible to violate now that both are
 * typed BrowserDto/CustomerDto objects rather than loose arrays.
 *
 * hfToken is HostedFieldDto's only mandatory payment-method field (PaymentDto, a sibling DTO, is
 * the one that pays with an already-created alias instead — hfToken and an alias identifier never
 * coexist on the same object, by construction, not by a runtime mutual-exclusivity check).
 *
 * paymentMethod.details.fullName is otherwise optional (see HostedFieldDto's own docblock — a real
 * working Postman example omits it entirely for a plain payment), but the Unified API silently
 * rejects an alias-creation request (paymentMethod.saveFutureUsage: true) missing it — confirmed
 * against a real staging failure — so it's the one paymentMethod sub-field this validator does
 * enforce, and only conditionally, once saveFutureUsage is true.
 *
 * <code>
 * try {
 *     HostedFieldDtoValidator::validate($dto);
 * } catch (InvalidHostedFieldException $e) {
 *     // reject the request, log $e->getMessage()
 * }
 * </code>
 */
final class HostedFieldDtoValidator
{
    /**
     * @codeCoverageIgnore
     */
    private function __construct()
    {
    }

    /**
     * @throws InvalidHostedFieldException on the first validation problem found
     */
    public static function validate(HostedFieldDto $dto): void
    {
        CommonFieldsDtoValidator::validateOrWrap($dto->common, InvalidHostedFieldException::class);

        Assert::notEmpty($dto->hfToken, 'hfToken', InvalidHostedFieldException::class);

        Assert::paymentMethodIdNotSet($dto->paymentMethod, 'PaymentDto', InvalidHostedFieldException::class);

        self::assertFullNameSetWhenSavingFutureUsage($dto->paymentMethod);
    }

    /**
     * @param array<string, mixed>|null $paymentMethod
     * @throws InvalidHostedFieldException if saveFutureUsage is true and details.fullName is
     *                                      missing, non-string, or empty
     */
    private static function assertFullNameSetWhenSavingFutureUsage(?array $paymentMethod): void
    {
        if ($paymentMethod === null || ($paymentMethod['saveFutureUsage'] ?? false) !== true) {
            return;
        }

        $fullName = $paymentMethod['details']['fullName'] ?? null;

        if (!\is_string($fullName) || $fullName === '') {
            throw new InvalidHostedFieldException('paymentMethod.details.fullName must not be empty when paymentMethod.saveFutureUsage is true.');
        }
    }
}

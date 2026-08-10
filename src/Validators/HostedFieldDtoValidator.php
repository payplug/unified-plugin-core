<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Validators;

use PayplugUnifiedCore\Dto\HostedFieldDto;
use PayplugUnifiedCore\Exceptions\InvalidCommonFieldsException;
use PayplugUnifiedCore\Exceptions\InvalidHostedFieldException;

/**
 * Validates a CMS-built HostedFieldDto before UnifiedApiHostedPaymentService::createHostedPayment()
 * uses it. Delegates the fields common to every payment method to CommonFieldsDtoValidator,
 * wrapping its InvalidCommonFieldsException into InvalidHostedFieldException so callers of
 * createHostedPayment() still only ever need to catch one exception type. The old browser/customer
 * "all sub-fields present together" checks are gone — impossible to violate now that both are
 * typed BrowserDto/CustomerDto objects rather than loose arrays.
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
        try {
            CommonFieldsDtoValidator::validate($dto->common);
        } catch (InvalidCommonFieldsException $e) {
            throw new InvalidHostedFieldException($e->getMessage(), 0, $e);
        }

        if ($dto->hfToken === '') {
            throw new InvalidHostedFieldException('hfToken must not be empty.');
        }
    }
}

<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Utilities\Helpers;

use PayplugUnifiedCore\Models\PaymentOutcome;

/**
 * Maps a Payplug execCode to the coarse PaymentOutcome vocabulary UPC exposes to CMS
 * plugins. Deliberately minimal: the platform's execCode catalog is a cross-processor
 * internal error taxonomy (100+ codes across acceptance/validation/bank/fraud categories)
 * far more detailed than any merchant-facing outcome needs. Only "0000" (success) is
 * distinguished; every other code — functional error, technical error, fraud decline, or
 * unknown — maps to FAILED. Extend this mapping if a future ticket needs finer-grained
 * outcomes (e.g. distinguishing AUTHORIZED/CAPTURE_REQUIRED), which isn't derivable from
 * execCode alone with the fields currently documented.
 *
 * Shared between the synchronous payment-creation flow and the asynchronous webhook
 * confirmation flow (see WebhookNotificationHelper), so this execCode -> PaymentOutcome
 * decision lives in exactly one place.
 *
 * Example:
 * <code>
 * $outcome = ExecCodeMapper::toPaymentOutcome($response['execCode']); // '0000' => PaymentOutcome::PAID
 * </code>
 */
final class ExecCodeMapper
{
    private const SUCCESS_EXEC_CODE = '0000';

    /**
     * @codeCoverageIgnore
     */
    private function __construct()
    {
    }

    public static function toPaymentOutcome(string $execCode): string
    {
        return $execCode === self::SUCCESS_EXEC_CODE ? PaymentOutcome::PAID : PaymentOutcome::FAILED;
    }
}

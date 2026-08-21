<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Utilities\Helpers;

use PayplugUnifiedCore\DataValues\PaymentOutcome;

/**
 * Maps a Payplug execCode to the coarse PaymentOutcome vocabulary UPC exposes to CMS
 * plugins. Deliberately minimal: the platform's execCode catalog is a cross-processor
 * internal error taxonomy (100+ codes across acceptance/validation/bank/fraud categories)
 * far more detailed than any merchant-facing outcome needs.
 *
 * Per PayPlug's own catalog ("Gestion des execcodes", Confluence PT space): "0000" (Opération
 * réussie / ACCEPTED_TRANSACTION) is PAID; "0001" (Authentification 3DSecure requise /
 * ACCEPTED_THREEDSECURE) is THREE_DS_PENDING — categorized "Acceptation", not an error, meaning
 * the operation is still awaiting the customer's 3DS challenge and is not yet a final outcome.
 * Observed live in production (2026-08-21) arriving via a genuine asynchronous webhook before
 * the real, final one for the same operation — a caller must not treat THREE_DS_PENDING as
 * terminal, and must not let it consume any idempotency/dedupe tracking meant for the
 * operation's eventual final notification.
 *
 * Every other code — functional error, technical error, fraud decline, or unknown — maps to
 * FAILED. The catalog also lists two sibling non-terminal "Acceptation" codes not mapped here,
 * since neither has ever been observed in a real notification: "0002" (En attente de la réponse
 * du fournisseur / WAITING_PROVIDER) and "0003" (Transaction en cours, notification en attente /
 * WAITING_STATUS — by its own definition, the real notification hasn't been sent yet). If either
 * is ever seen arriving as a real notification, it would hit the exact same misclassification
 * "0001" did and belongs in this mapping too. "0004" (Transaction partiellement acceptée /
 * PARTIALLY_ACCEPTED_TRANSACTION) is deliberately excluded even though it's also "Acceptation" —
 * it's a marketplace/split-payment outcome, not covered by any flow that consumes this mapper
 * today, and may need to resolve to something other than "still pending" if it ever shows up.
 *
 * The comparison is an exact string match, so `0`, `"0"`, or a float would NOT match "0000" or
 * "0001" and would fall into FAILED instead — the safe direction.
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

    private const PENDING_THREE_DS_EXEC_CODE = '0001';

    /**
     * @codeCoverageIgnore
     */
    private function __construct()
    {
    }

    public static function toPaymentOutcome(string $execCode): string
    {
        if ($execCode === self::SUCCESS_EXEC_CODE) {
            return PaymentOutcome::PAID;
        }

        if ($execCode === self::PENDING_THREE_DS_EXEC_CODE) {
            return PaymentOutcome::THREE_DS_PENDING;
        }

        return PaymentOutcome::FAILED;
    }
}

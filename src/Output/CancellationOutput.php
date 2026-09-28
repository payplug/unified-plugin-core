<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Output;

/**
 * Output of UnifiedApiPaymentService::cancelPayment(). Unvalidated, same reasoning as
 * PaymentOutput/CaptureOutput. remainingCancellableAmount = requestedAmount - cancelledAmount,
 * floored at 0, null unless both are present.
 *
 * This is only correct when nothing was ever captured against the authorization before this
 * cancellation: it does not subtract any prior capturePayment() amount. Example: 1000 authorized,
 * 600 captured, then a 400 cancellation of the remainder — this reports 1000 - 400 = 600 remaining
 * cancellable, when in fact nothing is left to cancel (0). cancelPayment()'s own docblock states
 * the API requires the *exact* remaining authorized-but-uncaptured balance whenever $amount is
 * given after any capture, so this value must not be reused to build a follow-up cancelPayment()
 * call without independently accounting for prior captures.
 */
final class CancellationOutput
{
    /** @var int */
    public $status;

    /** @var string */
    public $body;

    /** @var int|null in cents; null when the response carries no "amount" field */
    public $cancelledAmount;

    /** @var int|null in cents; null when the response carries no "requestedAmount" field */
    public $requestedAmount;

    /** @var int|null in cents; null unless both cancelledAmount and requestedAmount are present */
    public $remainingCancellableAmount;

    public function __construct(int $status, string $body, ?int $cancelledAmount, ?int $requestedAmount)
    {
        $this->status = $status;
        $this->body = $body;
        $this->cancelledAmount = $cancelledAmount;
        $this->requestedAmount = $requestedAmount;
        $this->remainingCancellableAmount = $requestedAmount !== null && $cancelledAmount !== null
            ? max(0, $requestedAmount - $cancelledAmount)
            : null;
    }
}

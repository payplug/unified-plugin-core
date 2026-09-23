<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Output;

/**
 * Output of UnifiedApiPaymentService::cancelPayment(). Unvalidated, same reasoning as
 * PaymentOutput/CaptureOutput. remainingCancellableAmount = requestedAmount - cancelledAmount,
 * floored at 0, null unless both are present.
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

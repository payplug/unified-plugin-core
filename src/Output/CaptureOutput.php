<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Output;

/**
 * Output of UnifiedApiPaymentService::capturePayment(). Unvalidated, same reasoning as
 * PaymentOutput. "amount" is treated as the cumulative amount captured so far.
 * remainingCapturableAmount = requestedAmount - capturedAmount, floored at 0, null unless both
 * are present.
 */
final class CaptureOutput
{
    /** @var int */
    public $status;

    /** @var string */
    public $body;

    /** @var int|null in cents; null when the response carries no "amount" field */
    public $capturedAmount;

    /** @var int|null in cents; null when the response carries no "requestedAmount" field */
    public $requestedAmount;

    /** @var string|null ISO-8601 date-time; null when the response carries no "maxCaptureDate" field */
    public $maxCaptureDate;

    /** @var int|null in cents; null unless both capturedAmount and requestedAmount are present */
    public $remainingCapturableAmount;

    public function __construct(
        int $status,
        string $body,
        ?int $capturedAmount,
        ?int $requestedAmount,
        ?string $maxCaptureDate
    ) {
        $this->status = $status;
        $this->body = $body;
        $this->capturedAmount = $capturedAmount;
        $this->requestedAmount = $requestedAmount;
        $this->maxCaptureDate = $maxCaptureDate;
        $this->remainingCapturableAmount = $requestedAmount !== null && $capturedAmount !== null
            ? max(0, $requestedAmount - $capturedAmount)
            : null;
    }
}

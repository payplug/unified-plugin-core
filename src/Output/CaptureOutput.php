<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Output;

/**
 * Output of UnifiedApiPaymentService::capturePayment(). Unvalidated, same reasoning as
 * PaymentOutput. "amount" is treated as the cumulative amount captured so far.
 * remainingCapturableAmount = requestedAmount - capturedAmount, floored at 0, null unless both
 * are present.
 *
 * Two unverified assumptions caveat this field, neither confirmed against a real success response
 * (only the rejection path is covered by integration tests so far — see
 * tests/Integration/UnifiedApiPaymentServiceTest.php): (1) that "amount" is genuinely cumulative
 * across successive partial captures against the same authorization, not a per-call delta; and
 * (2) that "requestedAmount" on this response reflects the amount actually authorized, not the
 * amount originally requested at creation. The second one matters specifically under a partial
 * authorization (PaymentOutput::$remainingCapturableAmount already accounts for this at creation
 * time by preferring "amount" over "requestedAmount" — see that class): if "requestedAmount" here
 * is instead the original request (e.g. 1000 when only 700 was authorized), a capture of part of
 * that 700 would understate how little remains capturable, e.g. reporting 700 remaining after a
 * 300 capture when the true remaining authorized-but-uncaptured balance is 400. Do not treat this
 * value as authoritative for building a subsequent capturePayment()/cancelPayment() call without
 * independently confirming the remaining balance.
 */
final class CaptureOutput
{
    /** @var int */
    public $status;

    /** @var string */
    public $body;

    /** @var int|null in minor units of the currency; null when the response carries no "amount" field */
    public $capturedAmount;

    /** @var int|null in minor units of the currency; null when the response carries no "requestedAmount" field */
    public $requestedAmount;

    /** @var string|null ISO-8601 date-time; null when the response carries no "maxCaptureDate" field */
    public $maxCaptureDate;

    /** @var int|null in minor units of the currency; null unless both capturedAmount and requestedAmount are present */
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

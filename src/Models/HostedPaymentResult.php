<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Models;

/**
 * Output of UnifiedApiHostedPaymentService::createHostedPayment() — unlike OperationData, its
 * constructor holds no validation, since it's produced entirely internally from a Unified API
 * response the service has already checked for a 2xx status, and never crosses an external
 * boundary itself (same reasoning as AuthorizationRequest).
 *
 * redirectUrl distinguishes a direct success from a 3DS-pending outcome: null means the payment
 * was processed synchronously; a non-null URL means the end-user must be redirected there to
 * complete 3DS/SCA authentication before the payment is final. Mapping the eventual outcome to a
 * PaymentOutcome constant is a separate concern (see PRE-3588), handled once the asynchronous
 * webhook/3DS-return confirmation comes back — this class only carries what's known synchronously,
 * at creation time.
 */
final class HostedPaymentResult
{
    /** @var int */
    public $status;

    /** @var string */
    public $body;

    /** @var string|null */
    public $redirectUrl;

    public function __construct(int $status, string $body, ?string $redirectUrl)
    {
        $this->status = $status;
        $this->body = $body;
        $this->redirectUrl = $redirectUrl;
    }
}

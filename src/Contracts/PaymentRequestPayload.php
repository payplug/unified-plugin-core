<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Contracts;

/**
 * The contract UnifiedApiPaymentService::createPayment() depends on rather than a
 * concrete DTO — both HostedFieldDto (hosted-fields payment, hfToken) and PaymentDto (payment
 * against an existing alias) implement it, letting the service accept either without a native PHP
 * union type (this repo's PHP 7.1 floor doesn't support them) or a docblock-only union.
 */
interface PaymentRequestPayload
{
    /**
     * @return array<string, mixed>
     */
    public function createPayloadBody(): array;
}

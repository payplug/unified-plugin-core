<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Dto;

/**
 * The payment-creation fields common to every Unified API payment method (hosted-fields today;
 * raw-card/wallet flows would reuse this exact shape if built later) — everything except the
 * payment-method-specific piece (hfToken for hosted-fields, paymentMethod for others). Like
 * HostedFieldDto, this constructor holds no validation of its own; CommonFieldsDtoValidator is
 * the separate collaborator that checks it.
 */
final class CommonFieldsDto
{
    /** @var string */
    public $accountId;

    /** @var int */
    public $amount;

    /** @var string */
    public $currency;

    /** @var string */
    public $orderId;

    /** @var string|null */
    public $description;

    /** @var bool true for an immediate payment, false for an authorization-only hold */
    public $capture = true;

    /** @var string|null label shown on the customer's bank statement */
    public $descriptor;

    /** @var string|null webhook URL the Unified API calls with the final outcome */
    public $notificationUrl;

    /** @var string|null free-form text echoed back verbatim in that notification */
    public $extraData;

    /** @var string */
    public $submerchantExternalId;

    public function __construct(string $accountId, int $amount, string $currency, string $orderId, string $submerchantExternalId)
    {
        $this->accountId = $accountId;
        $this->amount = $amount;
        $this->currency = $currency;
        $this->orderId = $orderId;
        $this->submerchantExternalId = $submerchantExternalId;
    }
}

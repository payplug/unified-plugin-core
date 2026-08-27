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

    /**
     * @var string|null unlike the other optional fields below, always sent to the Unified API even
     *      when null — see BuildsCommonPayloadBody::buildPayloadBody()
     */
    public $description;

    /** @var bool true for an immediate payment, false for an authorization-only hold */
    public $capture = true;

    /** @var string|null label shown on the customer's bank statement */
    public $descriptor;

    /** @var string|null webhook URL the Unified API calls with the final outcome */
    public $notificationUrl;

    /** @var string|null free-form text echoed back verbatim in that notification */
    public $extraData;

    /** @var string|null the end user is redirected here after successfully completing a 3DS/SCA challenge */
    public $successUrl;

    /** @var string|null the end user is redirected here if they cancel the 3DS/SCA challenge */
    public $cancelUrl;

    /** @var string */
    public $submerchantExternalId;

    /** @var BillingDto|null nested under the body's "billing" key when set */
    public $billing;

    /** @var ShippingDto|null nested under the body's "shipping" key when set */
    public $shipping;

    public function __construct(string $accountId, int $amount, string $currency, string $orderId, string $submerchantExternalId)
    {
        $this->accountId = $accountId;
        $this->amount = $amount;
        $this->currency = $currency;
        $this->orderId = $orderId;
        $this->submerchantExternalId = $submerchantExternalId;
    }
}

<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Dto;

use PayplugUnifiedCore\Contracts\PaymentRequestPayload;
use PayplugUnifiedCore\Traits\BuildsCommonPayloadBody;

/**
 * Input to UnifiedApiPaymentService::createPayment() for paying with an
 * already-created alias — no hfToken, no card data at all. The sibling of HostedFieldDto, which
 * handles the hfToken-driven flows (plain hosted-fields payment, optionally also creating an
 * alias). Like HostedFieldDto, this constructor holds no validation — PaymentDtoValidator is a
 * separate collaborator the service calls explicitly before using this DTO.
 *
 * Composes CommonFieldsDto/BrowserDto/CustomerDto (all Dto/, above), same as HostedFieldDto: a
 * frictionless 3DS attempt on a saved alias still benefits from browser/customer context, and the
 * common payment-creation fields (account/amount/currency/orderId/capture/...) are identical
 * regardless of payment method.
 *
 * createPayloadBody() builds the exact Unified API request body this DTO describes. The alias is
 * sent both as paymentMethod.storedId — where the Unified API reads it since its contract change
 * (confirmed intentional by the API team on 2026-10-06, PRE-3717: a request carrying it only as
 * paymentMethod.id is rejected with 400) — and, for now, still as the paymentMethod.id the previous
 * contract expected, so the same request is accepted by a platform still on either contract. The
 * API accepts both together (checked on staging). Drop paymentMethod.id once the API team confirms
 * the previous contract is retired everywhere.
 *
 * @see \PayplugUnifiedCore\Validators\PaymentDtoValidator
 */
final class PaymentDto implements PaymentRequestPayload
{
    use BuildsCommonPayloadBody;

    /** @var CommonFieldsDto */
    public $common;

    /** @var string identifier of a previously created alias to pay with */
    public $aliasId;

    /** @var string 'ONE_CLICK' or 'SUBSCRIPTION' — always required for an alias-based payment */
    public $recurringMode;

    /** @var BrowserDto|null */
    public $browser;

    /** @var CustomerDto|null */
    public $customer;

    /**
     * @var array{details?: array{fullName?: string, selectedBrand?: string, validityDate?: string}}|null
     *      supplementary card metadata (e.g. overriding the alias's saved brand for a one-click
     *      payment), nested exactly as the Unified API expects it. Must not set 'id' directly —
     *      createPayloadBody() merges $aliasId into paymentMethod.id and paymentMethod.storedId on
     *      the caller's behalf. Must not set 'storedId' either (PaymentDtoValidator rejects both).
     */
    public $paymentMethod;

    /**
     * @param array{details?: array{fullName?: string, selectedBrand?: string, validityDate?: string}}|null $paymentMethod
     */
    public function __construct(
        CommonFieldsDto $common,
        string $aliasId,
        string $recurringMode,
        ?BrowserDto $browser = null,
        ?CustomerDto $customer = null,
        ?array $paymentMethod = null
    ) {
        $this->common = $common;
        $this->aliasId = $aliasId;
        $this->recurringMode = $recurringMode;
        $this->browser = $browser;
        $this->customer = $customer;
        $this->paymentMethod = $paymentMethod;
    }

    /**
     * @return array<string, mixed>
     */
    public function createPayloadBody(): array
    {
        $paymentMethod = $this->paymentMethod ?? [];
        // Previous-contract field, kept for the PRE-3717 transition: remove paymentMethod.id once
        // the API team confirms that contract is retired everywhere — paymentMethod.storedId is
        // the current one.
        $paymentMethod['id'] = $this->aliasId;
        $paymentMethod['storedId'] = $this->aliasId;

        return $this->buildPayloadBody([
            'paymentMethod' => $paymentMethod,
            'recurringMode' => $this->recurringMode,
        ]);
    }
}

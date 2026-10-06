<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Dto;

use PayplugUnifiedCore\Contracts\PaymentRequestPayload;
use PayplugUnifiedCore\Traits\BuildsCommonPayloadBody;

/**
 * Input to UnifiedApiPaymentService::createPayment(), built by the CMS plugin itself.
 * Unlike OperationData/TokenOutput, this constructor holds no validation — HostedFieldDtoValidator
 * is a separate collaborator the service calls explicitly before using this DTO, rather than
 * validation being folded into construction.
 *
 * Composes CommonFieldsDto/BrowserDto/CustomerDto (all Dto/, above) rather than repeating their
 * fields — those three are shared by any future payment-method DTO, not hosted-fields-specific.
 *
 * hfToken is mandatory: a hosted-fields payment cannot exist without one, and it never coexists
 * with an alias identifier on this object — paying with an already-created alias instead (no card
 * data at all) is PaymentDto's job, a separate DTO, not a nullable/mutually-exclusive pair of
 * fields on this one. recurringMode is the one field this DTO keeps for the alias-adjacent case:
 * set it alongside paymentMethod.saveFutureUsage=true to create an alias from this hosted-fields
 * payment for future reuse; omit it otherwise.
 *
 * createPayloadBody() builds the exact Unified API request body this DTO describes. The token is
 * sent both as paymentMethod.hfToken — where the Unified API reads it since its contract change
 * (confirmed intentional by the API team on 2026-10-06, PRE-3717: a request carrying it only at
 * the top level is rejected with 400) — and, for now, still as the top-level hfToken the previous
 * contract expected, so the same request is accepted by a platform still on either contract. The
 * API accepts the duplicate (checked on staging). Drop the top-level copy once the API team
 * confirms the previous contract is retired everywhere.
 *
 * @see \PayplugUnifiedCore\Validators\HostedFieldDtoValidator
 */
final class HostedFieldDto implements PaymentRequestPayload
{
    use BuildsCommonPayloadBody;

    /** @var CommonFieldsDto */
    public $common;

    /** @var string */
    public $hfToken;

    /**
     * @var string|null 'ONE_CLICK' or 'SUBSCRIPTION' — only meaningful (and only sent) alongside
     *      paymentMethod.saveFutureUsage=true, to create an alias from this payment; omit
     *      otherwise.
     */
    public $recurringMode;

    /** @var BrowserDto|null */
    public $browser;

    /** @var CustomerDto|null */
    public $customer;

    /**
     * @var array{details?: array{fullName?: string, selectedBrand?: string, validityDate?: string}, saveFutureUsage?: bool}|null
     *      supplementary card metadata, nested exactly as the Unified API expects it. Must not set
     *      'id' or 'storedId' directly — those keys belong to PaymentDto's alias-payment flow, not
     *      this one — nor 'hfToken': createPayloadBody() fills it in from $hfToken
     *      (HostedFieldDtoValidator rejects all three).
     */
    public $paymentMethod;

    /**
     * @param array{details?: array{fullName?: string, selectedBrand?: string, validityDate?: string}, saveFutureUsage?: bool}|null $paymentMethod
     */
    public function __construct(
        CommonFieldsDto $common,
        string $hfToken,
        ?string $recurringMode = null,
        ?BrowserDto $browser = null,
        ?CustomerDto $customer = null,
        ?array $paymentMethod = null
    ) {
        $this->common = $common;
        $this->hfToken = $hfToken;
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
        $paymentMethodSpecificFields = [
            // Previous-contract field, kept for the PRE-3717 transition: remove the top-level hfToken
            // once the API team confirms that contract is retired everywhere — paymentMethod.hfToken
            // below is the current one.
            'hfToken' => $this->hfToken,
            'paymentMethod' => ['hfToken' => $this->hfToken] + ($this->paymentMethod ?? []),
        ];

        if ($this->recurringMode !== null) {
            $paymentMethodSpecificFields['recurringMode'] = $this->recurringMode;
        }

        return $this->buildPayloadBody($paymentMethodSpecificFields);
    }
}

<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Dto;

/**
 * Input to UnifiedApiHostedPaymentService::createHostedPayment(), built by the CMS plugin itself.
 * Unlike OperationData/TokenOutput, this constructor holds no validation — HostedFieldDtoValidator
 * is a separate collaborator the service calls explicitly before using this DTO, rather than
 * validation being folded into construction.
 *
 * Composes CommonFieldsDto/BrowserDto/CustomerDto (all Dto/, above) rather than repeating their
 * fields — those three are shared by any future payment-method DTO, not hosted-fields-specific.
 *
 * createPayloadBody() builds the exact Unified API request body this DTO describes.
 *
 * @see \PayplugUnifiedCore\Validators\HostedFieldDtoValidator
 */
final class HostedFieldDto
{
    /** @var CommonFieldsDto */
    public $common;

    /** @var string */
    public $hfToken;

    /** @var BrowserDto|null */
    public $browser;

    /** @var CustomerDto|null */
    public $customer;

    /**
     * @var array{details?: array{fullName?: string, selectedBrand?: string, validityDate?: string}}|null
     *      supplementary card metadata, nested exactly as the Unified API expects it.
     */
    public $paymentMethod;

    /**
     * @param array{details?: array{fullName?: string, selectedBrand?: string, validityDate?: string}}|null $paymentMethod
     */
    public function __construct(
        CommonFieldsDto $common,
        string $hfToken,
        ?BrowserDto $browser = null,
        ?CustomerDto $customer = null,
        ?array $paymentMethod = null
    ) {
        $this->common = $common;
        $this->hfToken = $hfToken;
        $this->browser = $browser;
        $this->customer = $customer;
        $this->paymentMethod = $paymentMethod;
    }

    /**
     * @return array<string, mixed>
     */
    public function createPayloadBody(): array
    {
        $body = [
            'account' => ['id' => $this->common->accountId],
            'amount' => $this->common->amount,
            'currency' => $this->common->currency,
            'orderId' => $this->common->orderId,
            'capture' => $this->common->capture,
            'hfToken' => $this->hfToken,
        ];

        if ($this->paymentMethod !== null && $this->paymentMethod !== []) {
            $body['paymentMethod'] = $this->paymentMethod;
        }

        if ($this->browser !== null) {
            $body['browser'] = $this->browser->toArray();
        }

        if ($this->customer !== null) {
            $body['customer'] = $this->customer->toArray();
        }

        if ($this->common->description !== null) {
            $body['description'] = $this->common->description;
        }

        if ($this->common->descriptor !== null) {
            $body['descriptor'] = $this->common->descriptor;
        }

        if ($this->common->notificationUrl !== null) {
            $body['notificationUrl'] = $this->common->notificationUrl;
        }

        if ($this->common->extraData !== null) {
            $body['extraData'] = $this->common->extraData;
        }

        return $body;
    }
}

<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Tests\Support;

use PayplugUnifiedCore\Dto\BrowserDto;
use PayplugUnifiedCore\Dto\CommonFieldsDto;
use PayplugUnifiedCore\Dto\CustomerDto;
use PayplugUnifiedCore\Dto\PaymentDto;

/**
 * Fluent test builder for a minimal-but-valid PaymentDto, mirroring HostedFieldDtoBuilder's
 * pattern. Deliberately test-only (not shipped under src/) and deliberately NOT used by
 * PaymentDtoTest itself, for the same reason HostedFieldDtoBuilder isn't used by
 * HostedFieldDtoTest — that file proves the real constructor contract this builder assumes.
 */
final class PaymentDtoBuilder
{
    /** @var string */
    private $accountId = 'acc_123';

    /** @var int */
    private $amount = 1000;

    /** @var string */
    private $currency = 'EUR';

    /** @var string */
    private $orderId = 'order_456';

    /** @var string */
    private $submerchantExternalId = 'submerchant_789';

    /** @var string */
    private $aliasId = 'alias_789';

    /** @var string */
    private $recurringMode = 'ONE_CLICK';

    /** @var string|null */
    private $description;

    /** @var string|null */
    private $descriptor;

    /** @var string|null */
    private $notificationUrl;

    /** @var string|null */
    private $extraData;

    /** @var string|null */
    private $successUrl;

    /** @var string|null */
    private $cancelUrl;

    /** @var BrowserDto|null */
    private $browser;

    /** @var CustomerDto|null */
    private $customer;

    /**
     * @var array{details?: array{fullName?: string, selectedBrand?: string, validityDate?: string}}|null
     */
    private $paymentMethod;

    public static function valid(): self
    {
        return new self();
    }

    public function withAccountId(string $accountId): self
    {
        $this->accountId = $accountId;

        return $this;
    }

    public function withAmount(int $amount): self
    {
        $this->amount = $amount;

        return $this;
    }

    public function withCurrency(string $currency): self
    {
        $this->currency = $currency;

        return $this;
    }

    public function withOrderId(string $orderId): self
    {
        $this->orderId = $orderId;

        return $this;
    }

    public function withSubmerchantExternalId(string $submerchantExternalId): self
    {
        $this->submerchantExternalId = $submerchantExternalId;

        return $this;
    }

    public function withAliasId(string $aliasId): self
    {
        $this->aliasId = $aliasId;

        return $this;
    }

    public function withRecurringMode(string $recurringMode): self
    {
        $this->recurringMode = $recurringMode;

        return $this;
    }

    public function withDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function withDescriptor(?string $descriptor): self
    {
        $this->descriptor = $descriptor;

        return $this;
    }

    public function withNotificationUrl(?string $notificationUrl): self
    {
        $this->notificationUrl = $notificationUrl;

        return $this;
    }

    public function withExtraData(?string $extraData): self
    {
        $this->extraData = $extraData;

        return $this;
    }

    public function withSuccessUrl(?string $successUrl): self
    {
        $this->successUrl = $successUrl;

        return $this;
    }

    public function withCancelUrl(?string $cancelUrl): self
    {
        $this->cancelUrl = $cancelUrl;

        return $this;
    }

    public function withBrowser(?BrowserDto $browser): self
    {
        $this->browser = $browser;

        return $this;
    }

    public function withCustomer(?CustomerDto $customer): self
    {
        $this->customer = $customer;

        return $this;
    }

    /**
     * @param array{details?: array{fullName?: string, selectedBrand?: string, validityDate?: string}}|null $paymentMethod
     */
    public function withPaymentMethod(?array $paymentMethod): self
    {
        $this->paymentMethod = $paymentMethod;

        return $this;
    }

    public function build(): PaymentDto
    {
        $common = new CommonFieldsDto($this->accountId, $this->amount, $this->currency, $this->orderId, $this->submerchantExternalId);
        $common->description = $this->description;
        $common->descriptor = $this->descriptor;
        $common->notificationUrl = $this->notificationUrl;
        $common->extraData = $this->extraData;
        $common->successUrl = $this->successUrl;
        $common->cancelUrl = $this->cancelUrl;

        return new PaymentDto(
            $common,
            $this->aliasId,
            $this->recurringMode,
            $this->browser,
            $this->customer,
            $this->paymentMethod
        );
    }
}

<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Traits;

/**
 * The account/submerchantExternalId/amount/currency/orderId/description/capture skeleton and the
 * optional browser/customer/descriptor/notificationUrl/extraData/billing/shipping/successUrl/
 * cancelUrl fields are identical across every payment method that composes
 * CommonFieldsDto/BrowserDto/CustomerDto (HostedFieldDto, PaymentDto) — only the
 * payment-method-specific fields (hfToken; paymentMethod+recurringMode) differ. `description` is
 * part of the required skeleton, not the optional fields below it: the Unified API rejects a
 * request missing that key entirely, even though its own docs describe it as optional — so
 * `CommonFieldsDto::$description` stays a nullable public property (unlike a truly required field
 * such as `accountId`), but this method still sends the key unconditionally, `null` included.
 * `submerchantExternalId` is the opposite case — it is part of the skeleton's key order but is
 * omitted entirely when null or empty, since only a submerchant-routed account has one.
 * `billing`/`shipping` are each sent as-is from `BillingDto::toArray()`/`ShippingDto::toArray()`
 * (which already nest their own composed `AddressDto` under an `"address"` key) — this method
 * does no additional wrapping of its own. Used via `use` rather than a shared abstract base class,
 * since both DTOs are otherwise unrelated `final class`es with no other reason to share a type
 * hierarchy.
 *
 * Assumes the using class declares `CommonFieldsDto $common`, `?BrowserDto $browser`, and
 * `?CustomerDto $customer` properties with those exact names — both current users already do, as
 * part of composing those three DTOs (see HostedFieldDto/PaymentDto's own docblocks).
 */
trait BuildsCommonPayloadBody
{
    /**
     * @param array<string, mixed> $paymentMethodSpecificFields fields to insert between "capture"
     *        and "browser", preserving the caller's own key order for its payment-method-specific
     *        data (e.g. HostedFieldDto's "hfToken", PaymentDto's "paymentMethod"/"recurringMode")
     * @return array<string, mixed>
     */
    private function buildPayloadBody(array $paymentMethodSpecificFields): array
    {
        $body = ['account' => ['id' => $this->common->accountId]];

        // Unlike `description`, this key is omitted entirely when the CMS supplies no submerchant.
        // It comes from the MID configuration for the payment's currency: the EUR ones carry a
        // submerchant, the other-currency ones have none, and sending a submerchant that the
        // configuration does not own is itself rejected. An empty string counts as "none" too — a
        // CMS reading an unset value out of its own settings storage yields '' far more often than
        // a real null, and sending '' is rejected exactly like sending someone else's submerchant.
        // Assigned here rather than in the literal above so it keeps its original position in the
        // body's key order whenever it is present.
        if ($this->common->submerchantExternalId !== null && $this->common->submerchantExternalId !== '') {
            $body['submerchantExternalId'] = $this->common->submerchantExternalId;
        }

        $body['amount'] = $this->common->amount;
        $body['currency'] = $this->common->currency;
        $body['orderId'] = $this->common->orderId;
        $body['description'] = $this->common->description;
        $body['capture'] = $this->common->capture;

        foreach ($paymentMethodSpecificFields as $key => $value) {
            $body[$key] = $value;
        }

        if ($this->browser !== null) {
            $body['browser'] = $this->browser->toArray();
        }

        if ($this->customer !== null) {
            $body['customer'] = $this->customer->toArray();
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

        if ($this->common->billing !== null) {
            $body['billing'] = $this->common->billing->toArray();
        }

        if ($this->common->shipping !== null) {
            $body['shipping'] = $this->common->shipping->toArray();
        }

        $redirect = $this->buildRedirectBody();

        if ($redirect !== []) {
            $body['redirect'] = $redirect;
        }

        return $body;
    }

    /**
     * The body's "redirect" object, carrying whichever of the two 3DS/SCA return URLs the caller
     * actually set — a merchant-level default may already cover one of them, so a partial object
     * is valid and neither key is ever sent as null. Empty when the caller set neither, which
     * buildPayloadBody() reads as "omit the key entirely".
     *
     * @return array<string, string>
     */
    private function buildRedirectBody(): array
    {
        $redirect = [];

        if ($this->common->successUrl !== null) {
            $redirect['successUrl'] = $this->common->successUrl;
        }

        if ($this->common->cancelUrl !== null) {
            $redirect['cancelUrl'] = $this->common->cancelUrl;
        }

        return $redirect;
    }
}

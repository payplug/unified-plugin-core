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
        $body = [
            'account' => ['id' => $this->common->accountId],
            'submerchantExternalId' => $this->common->submerchantExternalId,
            'amount' => $this->common->amount,
            'currency' => $this->common->currency,
            'orderId' => $this->common->orderId,
            'description' => $this->common->description,
            'capture' => $this->common->capture,
        ];

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

        if ($this->common->successUrl !== null || $this->common->cancelUrl !== null) {
            $redirect = [];
            if ($this->common->successUrl !== null) {
                $redirect['successUrl'] = $this->common->successUrl;
            }
            if ($this->common->cancelUrl !== null) {
                $redirect['cancelUrl'] = $this->common->cancelUrl;
            }
            $body['redirect'] = $redirect;
        }

        return $body;
    }
}

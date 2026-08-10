<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Services;

use PayplugUnifiedCore\Dto\HostedFieldDto;
use PayplugUnifiedCore\Exceptions\ApiException;
use PayplugUnifiedCore\Exceptions\InvalidHostedFieldException;
use PayplugUnifiedCore\Output\HostedPaymentOutput;
use PayplugUnifiedCore\Validators\HostedFieldDtoValidator;

/**
 * Creates/confirms a payment from a hosted-fields token (hfToken) against the Unified API,
 * authenticated via TokenManager's client-credentials JWT — the create-side sibling of
 * UnifiedApiPaymentService (PRE-3576's GetPayment). No constructor of its own — it inherits
 * AbstractUnifiedApiService's five-argument one directly, matching UnifiedApiPaymentService,
 * since every argument that constructor takes is connection-level configuration shared across
 * every call this service makes.
 *
 * createHostedPayment() takes a single HostedFieldDto built by the CMS plugin, validated by
 * HostedFieldDtoValidator (throwing InvalidHostedFieldException) before any of its fields are used
 * — replacing this method's original 11-parameter signature (PRE-3587), safe as a breaking change
 * since no consumer had integrated against it yet. accountId — the Unified API processing account
 * the payment is created against — lives on the DTO rather than the service's constructor: unlike
 * clientId/clientSecret/baseUrl, it's data about this specific payment request, not shared
 * connection configuration, and has no relationship to the OAuth2 clientId/clientSecret pair.
 *
 * The request body itself is built entirely by HostedFieldDto::createPayloadBody() (including
 * capture, which defaults to true on the DTO but can be set to false for an authorization-only
 * hold) — every field that body needs lives on the DTO, so this service just forwards it to
 * sendPostJson() rather than reconstructing it.
 */
final class UnifiedApiHostedPaymentService extends AbstractUnifiedApiService
{
    private const PAYMENTS_PATH = '/payments';

    /**
     * @throws InvalidHostedFieldException if $dto fails validation — thrown before any network call.
     * @throws ApiException if the request fails, returns a non-2xx status, or the response is
     *                      malformed. getCode() carries the HTTP status when one was received,
     *                      and 0 when the client's response shape was unusable.
     */
    public function createHostedPayment(HostedFieldDto $dto): HostedPaymentOutput
    {
        HostedFieldDtoValidator::validate($dto);

        $url = $this->baseUrl . self::PAYMENTS_PATH;

        $response = $this->sendPostJson($url, $dto->createPayloadBody());

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new ApiException(\sprintf('Unified API hosted payment request failed with HTTP status %d.', $response['status']), $response['status']);
        }

        return new HostedPaymentOutput($response['status'], $response['body'], $this->extractRedirectUrl($response['body']));
    }

    /**
     * The presence of a "redirect" object (with a "url" field) in an otherwise-2xx response is the
     * Unified API's own signal that 3DS/SCA authentication is pending — its absence means the
     * payment was processed synchronously. A body that isn't valid JSON, or has no such field,
     * yields null rather than an exception: this method only extracts one derived field, it does
     * not validate the full payment representation (out of scope, same as GetPayment).
     */
    private function extractRedirectUrl(string $body): ?string
    {
        $data = json_decode($body, true);

        if (!\is_array($data) || !isset($data['redirect']['url']) || !\is_string($data['redirect']['url'])) {
            return null;
        }

        return $data['redirect']['url'];
    }
}

<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Contracts;

/**
 * Narrow HTTP contract for reading resources from the Unified API (starting with payment
 * retrieval) — modeled on IOAuthHttpClient but deliberately separate, since OAuth2 token exchange
 * (POST + form-encoded) and Unified API resource reads (GET + bearer token) are different shapes.
 * UPC makes no network call itself; the CMS plugin supplies whatever HTTP stack it already has.
 *
 * Sylius implementation sketch:
 * <code>
 * final class GuzzleUnifiedApiHttpClient implements IUnifiedApiHttpClient
 * {
 *     private $client;
 *
 *     public function get(string $url, array $headers = []): array
 *     {
 *         $response = $this->client->get($url, ['headers' => $headers, 'http_errors' => false]);
 *         return ['status' => $response->getStatusCode(), 'body' => (string) $response->getBody()];
 *     }
 * }
 * </code>
 *
 * WooCommerce implementation sketch:
 * <code>
 * final class WpUnifiedApiHttpClient implements IUnifiedApiHttpClient
 * {
 *     public function get(string $url, array $headers = []): array
 *     {
 *         $response = wp_remote_get($url, ['headers' => $headers]);
 *         return [
 *             'status' => wp_remote_retrieve_response_code($response),
 *             'body' => wp_remote_retrieve_body($response),
 *         ];
 *     }
 * }
 * </code>
 */
interface IUnifiedApiHttpClient
{
    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string}
     */
    public function get(string $url, array $headers = []): array;
}

<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Contracts;

/**
 * Narrow HTTP contract for calling the Unified API (payment retrieval, and — since PRE-3587 —
 * hosted-fields payment creation) — modeled on IOAuthHttpClient but deliberately separate, since
 * OAuth2 token exchange (POST + form-encoded) and Unified API calls (GET/POST + bearer token +
 * JSON) are different shapes. postJson() is named distinctly from IOAuthHttpClient::post() (rather
 * than reusing "post") precisely so a class implementing both contracts — as UPC's own test-only
 * curl double does — never has to guess which body encoding a single shared method should apply.
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
 *
 *     public function postJson(string $url, array $body, array $headers = []): array
 *     {
 *         $response = $this->client->post($url, ['json' => $body, 'headers' => $headers, 'http_errors' => false]);
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
 *
 *     public function postJson(string $url, array $body, array $headers = []): array
 *     {
 *         $response = wp_remote_post($url, ['body' => wp_json_encode($body), 'headers' => $headers]);
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

    /**
     * @param array<string, mixed> $body JSON-serializable request payload
     * @param array<string, string> $headers
     * @return array{status: int, body: string}
     */
    public function postJson(string $url, array $body, array $headers = []): array;
}

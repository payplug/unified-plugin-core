<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Contracts;

/**
 * Narrow HTTP contract for OAuth2 token exchange only — not a general-purpose Unified API HTTP
 * client (that's separate, future scope). UPC makes no network call itself; the CMS plugin
 * supplies whatever HTTP stack it already has.
 *
 * Sylius implementation sketch:
 * <code>
 * final class GuzzleOAuthHttpClient implements IOAuthHttpClient
 * {
 *     private $client;
 *
 *     public function post(string $url, array $formParams, array $headers = []): array
 *     {
 *         $response = $this->client->post($url, [
 *             'form_params' => $formParams,
 *             'headers' => $headers,
 *             'http_errors' => false,
 *         ]);
 *         return ['status' => $response->getStatusCode(), 'body' => (string) $response->getBody()];
 *     }
 * }
 * </code>
 *
 * WooCommerce implementation sketch:
 * <code>
 * final class WpOAuthHttpClient implements IOAuthHttpClient
 * {
 *     public function post(string $url, array $formParams, array $headers = []): array
 *     {
 *         $response = wp_remote_post($url, ['body' => $formParams, 'headers' => $headers]);
 *         return [
 *             'status' => wp_remote_retrieve_response_code($response),
 *             'body' => wp_remote_retrieve_body($response),
 *         ];
 *     }
 * }
 * </code>
 */
interface IOAuthHttpClient
{
    /**
     * @param array<string, string> $formParams
     * @param array<string, string> $headers
     * @return array{status: int, body: string}
     */
    public function post(string $url, array $formParams, array $headers = []): array;
}

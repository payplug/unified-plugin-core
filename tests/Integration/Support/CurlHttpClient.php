<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Tests\Integration\Support;

use PayplugUnifiedCore\Contracts\IOAuthHttpClient;
use PayplugUnifiedCore\Contracts\IUnifiedApiHttpClient;
use RuntimeException;

/**
 * Real curl-based HTTP client used only by tests/Integration/ — UPC ships no concrete
 * implementation of either contract, since each CMS plugin supplies its own HTTP stack.
 *
 * Bounded by explicit timeouts (curl's own defaults are 300s to connect and unlimited overall, so
 * an unreachable VPN-only host would otherwise stall the suite for minutes), and a transport-level
 * failure throws instead of being reported as an HTTP response: without that, curl_exec() === false
 * degrades to status 0 + empty body, which the calling service then misreports as "failed with HTTP
 * status 0" — hiding the actual cause (DNS, refused connection, timeout) behind a status code that
 * no server ever sent.
 */
final class CurlHttpClient implements IOAuthHttpClient, IUnifiedApiHttpClient
{
    private const CONNECT_TIMEOUT_SECONDS = 10;
    private const TIMEOUT_SECONDS = 30;

    public function post(string $url, array $formParams, array $headers = []): array
    {
        return $this->request('POST', $url, $headers, http_build_query($formParams));
    }

    public function get(string $url, array $headers = []): array
    {
        return $this->request('GET', $url, $headers);
    }

    public function postJson(string $url, array $body, array $headers = []): array
    {
        return $this->request('POST', $url, $headers, (string) json_encode($body));
    }

    /**
     * @param 'GET'|'POST' $method
     * @param array<string, string> $headers
     * @return array{status: int, body: string}
     * @throws RuntimeException if the request never reached a server (DNS, refused, timeout)
     */
    private function request(string $method, string $url, array $headers, ?string $body = null): array
    {
        $headerLines = [];

        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
        ];

        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, $options);
        $responseBody = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errorNumber = curl_errno($ch);
        $errorMessage = curl_error($ch);
        curl_close($ch);

        if ($responseBody === false) {
            throw new RuntimeException(\sprintf(
                '%s %s failed at the transport layer: %s (curl errno %d). Is the VPN connected?',
                $method,
                $url,
                $errorMessage !== '' ? $errorMessage : 'unknown curl error',
                $errorNumber
            ));
        }

        return ['status' => $status, 'body' => (string) $responseBody];
    }
}

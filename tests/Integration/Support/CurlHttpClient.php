<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Tests\Integration\Support;

use PayplugUnifiedCore\Contracts\IOAuthHttpClient;
use PayplugUnifiedCore\Contracts\IUnifiedApiHttpClient;

/**
 * Real curl-based HTTP client used only by tests/Integration/ — UPC ships no concrete
 * implementation of either contract, since each CMS plugin supplies its own HTTP stack.
 */
final class CurlHttpClient implements IOAuthHttpClient, IUnifiedApiHttpClient
{
    public function post(string $url, array $formParams, array $headers = []): array
    {
        return $this->request('POST', $url, $headers, http_build_query($formParams));
    }

    public function get(string $url, array $headers = []): array
    {
        return $this->request('GET', $url, $headers);
    }

    /**
     * @param 'GET'|'POST' $method
     * @param array<string, string> $headers
     * @return array{status: int, body: string}
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
        ];

        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, $options);
        $responseBody = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['status' => $status, 'body' => (string) $responseBody];
    }
}

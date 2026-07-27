<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Tests\Integration;

use PayplugUnifiedCore\Auth\OAuth2Client;
use PayplugUnifiedCore\Auth\TokenManager;
use PayplugUnifiedCore\Services\UnifiedApiPaymentService;
use PayplugUnifiedCore\Tests\Integration\Support\CurlHttpClient;
use PayplugUnifiedCore\Tests\Integration\Support\InMemoryTokenCache;
use PHPUnit\Framework\TestCase;

/**
 * Exercises real network I/O against the identity provider and the Unified API staging
 * environment — requires VPN access, so this suite never runs in CI (phpunit.xml.dist's
 * `integration` testsuite is excluded from the `unit` suite the `quality`/`coverage` CI jobs run).
 * Run locally via `make test-integration` with the env vars below set (see `.env.example`).
 */
final class UnifiedApiPaymentServiceTest extends TestCase
{
    public function testGetPaymentFetchesARealFixturePayment(): void
    {
        $env = $this->requireEnv([
            'UPC_IT_OAUTH_BASE_URL',
            'UPC_IT_OAUTH_SCOPE',
            'UPC_IT_OAUTH_AUDIENCE',
            'UPC_IT_CLIENT_ID',
            'UPC_IT_CLIENT_SECRET',
            'UPC_IT_UNIFIED_API_BASE_URL',
            'UPC_IT_PAYMENT_ID',
        ]);

        if ($env === null) {
            return;
        }

        $httpClient = new CurlHttpClient();
        $oauth2Client = new OAuth2Client(
            $httpClient,
            $env['UPC_IT_OAUTH_BASE_URL'],
            'https://merchant.example.com/callback',
            $env['UPC_IT_OAUTH_SCOPE'],
            $env['UPC_IT_OAUTH_AUDIENCE']
        );
        $tokenManager = new TokenManager(new InMemoryTokenCache(), $oauth2Client);

        $service = new UnifiedApiPaymentService(
            $httpClient,
            $tokenManager,
            $env['UPC_IT_UNIFIED_API_BASE_URL'],
            $env['UPC_IT_CLIENT_ID'],
            $env['UPC_IT_CLIENT_SECRET']
        );

        $response = $service->getPayment($env['UPC_IT_PAYMENT_ID']);

        self::assertSame(200, $response['status']);

        $body = json_decode($response['body'], true);
        self::assertIsArray($body);
        self::assertSame($env['UPC_IT_PAYMENT_ID'], $body['id']);
        self::assertSame('CAPTURED', $body['operations'][0]['status'] ?? null);
    }

    /**
     * @param string[] $names
     * @return array<string, string>|null null if any var is unset (test already marked skipped)
     */
    // @phpstan-ignore-next-line return.unusedType (phpstan-phpunit models markTestSkipped() as never-returning, so it can't see this method's null branch is reachable at runtime)
    private function requireEnv(array $names): ?array
    {
        $values = [];

        foreach ($names as $name) {
            $value = getenv($name);

            if ($value === false || $value === '') {
                self::markTestSkipped(\sprintf('Set %s to run this integration test (requires VPN access).', $name));

                // @phpstan-ignore-next-line deadCode.unreachable (markTestSkipped() throws a SkippedTestError at runtime, which PHPStan's phpstan-phpunit stubs model as never-returning; this explicit return stays as documentation of the intended control flow for readers of the source)
                return null;
            }

            $values[$name] = $value;
        }

        return $values;
    }
}

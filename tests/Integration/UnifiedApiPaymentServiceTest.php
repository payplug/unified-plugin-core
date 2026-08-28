<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Tests\Integration;

use PayplugUnifiedCore\Auth\OAuth2Client;
use PayplugUnifiedCore\Auth\TokenManager;
use PayplugUnifiedCore\Exceptions\ApiException;
use PayplugUnifiedCore\Services\UnifiedApiPaymentService;
use PayplugUnifiedCore\Tests\Integration\Support\CurlHttpClient;
use PayplugUnifiedCore\Tests\Integration\Support\InMemoryTokenCache;
use PayplugUnifiedCore\Tests\Support\HostedFieldDtoBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Exercises real network I/O against the identity provider and the Unified API staging
 * environment — requires VPN access, so this suite never runs in CI (phpunit.xml.dist's
 * `integration` testsuite is excluded from the `unit` suite the `quality`/`coverage` CI jobs run).
 * Run locally via `make test-integration` with the env vars below set (see `.env.example`).
 *
 * testCreatePaymentCreatesARealPaymentFromAFreshHfToken's UPC_IT_HF_TOKEN needs a *freshly minted*
 * hosted-fields token before each run: unlike UPC_IT_PAYMENT_ID (a static fixture), an hfToken is
 * single-use and short-lived — it comes from actually driving the hosted-fields JS SDK in a
 * browser, which this suite cannot do itself.
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
        self::assertSame($env['UPC_IT_PAYMENT_ID'], $body['id'] ?? null);
        self::assertSame('CAPTURED', $body['operations'][0]['status'] ?? null);
    }

    public function testCreatePaymentCreatesARealPaymentFromAFreshHfToken(): void
    {
        $env = $this->requireEnv([
            'UPC_IT_OAUTH_BASE_URL',
            'UPC_IT_OAUTH_SCOPE',
            'UPC_IT_OAUTH_AUDIENCE',
            'UPC_IT_CLIENT_ID',
            'UPC_IT_CLIENT_SECRET',
            'UPC_IT_UNIFIED_API_BASE_URL',
            'UPC_IT_ACCOUNT_ID',
            'UPC_IT_HF_TOKEN',
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

        $dto = HostedFieldDtoBuilder::valid()
            ->withAccountId($env['UPC_IT_ACCOUNT_ID'])
            ->withOrderId('upc-it-' . time())
            ->withHfToken($env['UPC_IT_HF_TOKEN'])
            ->build();

        $result = $service->createPayment($dto);

        self::assertGreaterThanOrEqual(200, $result->status);
        self::assertLessThan(300, $result->status);

        $body = json_decode($result->body, true);
        self::assertIsArray($body);
        self::assertArrayHasKey('id', $body);

        // Either outcome is a valid result of a real hosted-fields payment; this only asserts the
        // response was actually parsed into one of the two known shapes.
        if ($result->redirectUrl !== null) {
            self::assertArrayHasKey('redirect', $body);
        }
    }

    /**
     * UPC_IT_PAYMENT_ID is a static staging fixture also relied on, unrefunded, by
     * testGetPaymentFetchesARealFixturePayment()'s own 'CAPTURED' assertion — so this test cannot
     * actually complete a real refund against it without breaking that invariant on every future
     * run (a payment can only be refunded down to zero once). Instead it drives the real
     * createRefund() request all the way to the staging Unified API with a deliberately
     * over-large amount, proving the auth/URL/JSON wiring end-to-end via the API's own business
     * rejection (400) rather than by actually moving money.
     */
    public function testCreateRefundIsRejectedByTheApiWhenTheAmountExceedsThePayment(): void
    {
        $env = $this->requireEnv([
            'UPC_IT_OAUTH_BASE_URL',
            'UPC_IT_OAUTH_SCOPE',
            'UPC_IT_OAUTH_AUDIENCE',
            'UPC_IT_CLIENT_ID',
            'UPC_IT_CLIENT_SECRET',
            'UPC_IT_UNIFIED_API_BASE_URL',
            'UPC_IT_ACCOUNT_ID',
            'UPC_IT_PAYMENT_ID',
            'UPC_IT_SUBMERCHANT_ID',
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

        try {
            $service->createRefund(
                $env['UPC_IT_PAYMENT_ID'],
                $env['UPC_IT_ACCOUNT_ID'],
                'upc-it-refund-test',
                'UPC integration test refund',
                $env['UPC_IT_SUBMERCHANT_ID'],
                999999999
            );
            self::fail('Expected the Unified API to reject a refund amount larger than the payment.');
        } catch (ApiException $e) {
            self::assertSame(400, $e->getCode());
        }
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

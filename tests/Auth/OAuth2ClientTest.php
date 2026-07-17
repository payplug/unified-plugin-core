<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Tests\Auth;

use Mockery;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use PayplugUnifiedCore\Auth\OAuth2Client;
use PayplugUnifiedCore\Contracts\IOAuthHttpClient;
use PayplugUnifiedCore\Exceptions\ApiException;

final class OAuth2ClientTest extends MockeryTestCase
{
    public function testBuildAuthorizationUrlReturnsUrlStateAndCodeVerifier(): void
    {
        $httpClient = Mockery::mock(IOAuthHttpClient::class);
        $client = new OAuth2Client($httpClient, 'https://api-qa.payplug.com', 'https://merchant.example.com/callback', 'payments', 'https://www.payplug.com');

        $request = $client->buildAuthorizationUrl('client_abc');

        self::assertStringStartsWith('https://api-qa.payplug.com/oauth2/auth?', $request->url);
        self::assertStringContainsString('client_id=client_abc', $request->url);
        self::assertStringContainsString('redirect_uri=' . urlencode('https://merchant.example.com/callback'), $request->url);
        self::assertStringContainsString('audience=' . urlencode('https://www.payplug.com'), $request->url);
        self::assertStringContainsString('code_challenge_method=S256', $request->url);
        self::assertNotSame('', $request->state);
        self::assertNotSame('', $request->codeVerifier);
    }

    public function testBuildAuthorizationUrlGeneratesADifferentStateAndVerifierEachCall(): void
    {
        $httpClient = Mockery::mock(IOAuthHttpClient::class);
        $client = new OAuth2Client($httpClient, 'https://api-qa.payplug.com', 'https://merchant.example.com/callback', 'payments', 'https://www.payplug.com');

        $first = $client->buildAuthorizationUrl('client_abc');
        $second = $client->buildAuthorizationUrl('client_abc');

        self::assertNotSame($first->state, $second->state);
        self::assertNotSame($first->codeVerifier, $second->codeVerifier);
    }

    public function testExchangeAuthorizationCodeReturnsTokenOnSuccess(): void
    {
        $httpClient = Mockery::mock(IOAuthHttpClient::class);
        $httpClient->shouldReceive('post')
            ->once()
            ->with(
                'https://api-qa.payplug.com/oauth2/token',
                [
                    'grant_type' => 'authorization_code',
                    'client_id' => 'client_abc',
                    'code' => 'auth_code_123',
                    'redirect_uri' => 'https://merchant.example.com/callback',
                    'code_verifier' => 'verifier_123',
                ],
                ['Content-Type' => 'application/x-www-form-urlencoded']
            )
            ->andReturn([
                'status' => 200,
                'body' => json_encode(['access_token' => 'jwt-token', 'expires_in' => 3600, 'token_type' => 'Bearer']),
            ]);

        $client = new OAuth2Client($httpClient, 'https://api-qa.payplug.com', 'https://merchant.example.com/callback', 'payments', 'https://www.payplug.com');

        $token = $client->exchangeAuthorizationCode('client_abc', 'auth_code_123', 'verifier_123');

        self::assertSame('jwt-token', $token->accessToken);
        self::assertSame(3600, $token->expiresIn);
        self::assertSame('Bearer', $token->tokenType);
    }

    public function testExchangeAuthorizationCodeThrowsApiExceptionOnNon2xxStatus(): void
    {
        $httpClient = Mockery::mock(IOAuthHttpClient::class);
        $httpClient->shouldReceive('post')->once()->andReturn(['status' => 400, 'body' => '{"error":"invalid_grant"}']);

        $client = new OAuth2Client($httpClient, 'https://api-qa.payplug.com', 'https://merchant.example.com/callback', 'payments', 'https://www.payplug.com');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('OAuth2 token request failed with HTTP status 400.');

        $client->exchangeAuthorizationCode('client_abc', 'bad_code', 'verifier_123');
    }

    public function testExchangeAuthorizationCodeThrowsApiExceptionOnMalformedBody(): void
    {
        $httpClient = Mockery::mock(IOAuthHttpClient::class);
        $httpClient->shouldReceive('post')->once()->andReturn(['status' => 200, 'body' => '{"unexpected":"shape"}']);

        $client = new OAuth2Client($httpClient, 'https://api-qa.payplug.com', 'https://merchant.example.com/callback', 'payments', 'https://www.payplug.com');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('OAuth2 token response is malformed.');

        $client->exchangeAuthorizationCode('client_abc', 'auth_code_123', 'verifier_123');
    }

    public function testExchangeAuthorizationCodeThrowsApiExceptionOnPresentButEmptyAccessToken(): void
    {
        $httpClient = Mockery::mock(IOAuthHttpClient::class);
        $httpClient->shouldReceive('post')->once()->andReturn([
            'status' => 200,
            'body' => json_encode(['access_token' => '', 'expires_in' => 3600, 'token_type' => 'Bearer']),
        ]);

        $client = new OAuth2Client($httpClient, 'https://api-qa.payplug.com', 'https://merchant.example.com/callback', 'payments', 'https://www.payplug.com');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('OAuth2 token response is malformed.');

        $client->exchangeAuthorizationCode('client_abc', 'auth_code_123', 'verifier_123');
    }

    public function testGetClientCredentialsTokenReturnsTokenOnSuccess(): void
    {
        $httpClient = Mockery::mock(IOAuthHttpClient::class);
        $httpClient->shouldReceive('post')
            ->once()
            ->with(
                'https://api-qa.payplug.com/oauth2/token',
                [
                    'grant_type' => 'client_credentials',
                    'audience' => 'https://www.payplug.com',
                ],
                [
                    'Authorization' => 'Basic ' . base64_encode('client_abc:secret_xyz'),
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ]
            )
            ->andReturn([
                'status' => 200,
                'body' => json_encode(['access_token' => 'jwt-token-2', 'expires_in' => 300, 'token_type' => 'Bearer']),
            ]);

        $client = new OAuth2Client($httpClient, 'https://api-qa.payplug.com', 'https://merchant.example.com/callback', 'payments', 'https://www.payplug.com');

        $token = $client->getClientCredentialsToken('client_abc', 'secret_xyz');

        self::assertSame('jwt-token-2', $token->accessToken);
        self::assertSame(300, $token->expiresIn);
        self::assertSame('Bearer', $token->tokenType);
    }

    public function testGetClientCredentialsTokenThrowsApiExceptionOnNon2xxStatus(): void
    {
        $httpClient = Mockery::mock(IOAuthHttpClient::class);
        $httpClient->shouldReceive('post')->once()->andReturn(['status' => 401, 'body' => '{"error":"invalid_client"}']);

        $client = new OAuth2Client($httpClient, 'https://api-qa.payplug.com', 'https://merchant.example.com/callback', 'payments', 'https://www.payplug.com');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('OAuth2 token request failed with HTTP status 401.');

        $client->getClientCredentialsToken('client_abc', 'wrong_secret');
    }
}

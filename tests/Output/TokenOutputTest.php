<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Tests\Output;

use PayplugUnifiedCore\Exceptions\InvalidTokenException;
use PayplugUnifiedCore\Output\TokenOutput;
use PHPUnit\Framework\TestCase;

final class TokenOutputTest extends TestCase
{
    public function testConstructorAssignsAllProperties(): void
    {
        $token = new TokenOutput('jwt-access-token', 3600, 'Bearer');

        self::assertSame('jwt-access-token', $token->accessToken);
        self::assertSame(3600, $token->expiresIn);
        self::assertSame('Bearer', $token->tokenType);
    }

    public function testConstructorAssignsTheOptionalIdToken(): void
    {
        $token = new TokenOutput('jwt-access-token', 3600, 'Bearer', 'jwt-id-token');

        self::assertSame('jwt-id-token', $token->idToken);
    }

    public function testIdTokenIsNullWhenNotSupplied(): void
    {
        $token = new TokenOutput('jwt-access-token', 3600, 'Bearer');

        self::assertNull($token->idToken);
    }

    public function testConstructorDoesNotValidateTheIdToken(): void
    {
        // The id_token is display-only metadata a grant may legitimately omit, so an empty one must
        // not invalidate an otherwise usable token response.
        $token = new TokenOutput('jwt-access-token', 3600, 'Bearer', '');

        self::assertSame('', $token->idToken);
    }

    public function testConstructorThrowsWhenAccessTokenIsEmpty(): void
    {
        $this->expectException(InvalidTokenException::class);
        $this->expectExceptionMessage('accessToken must not be empty.');

        new TokenOutput('', 3600, 'Bearer');
    }

    public function testConstructorThrowsWhenExpiresInIsZero(): void
    {
        $this->expectException(InvalidTokenException::class);
        $this->expectExceptionMessage('expiresIn must be greater than zero.');

        new TokenOutput('jwt-access-token', 0, 'Bearer');
    }

    public function testConstructorThrowsWhenExpiresInIsNegative(): void
    {
        $this->expectException(InvalidTokenException::class);
        $this->expectExceptionMessage('expiresIn must be greater than zero.');

        new TokenOutput('jwt-access-token', -1, 'Bearer');
    }

    public function testConstructorThrowsWhenTokenTypeIsEmpty(): void
    {
        $this->expectException(InvalidTokenException::class);
        $this->expectExceptionMessage('tokenType must not be empty.');

        new TokenOutput('jwt-access-token', 3600, '');
    }
}

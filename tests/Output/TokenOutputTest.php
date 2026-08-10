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

<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Tests\Models;

use PayplugUnifiedCore\Models\AuthorizationRequest;
use PHPUnit\Framework\TestCase;

final class AuthorizationRequestTest extends TestCase
{
    public function testConstructorAssignsAllProperties(): void
    {
        $request = new AuthorizationRequest(
            'https://api.payplug.com/oauth2/auth?client_id=abc',
            'random-state',
            'random-code-verifier'
        );

        self::assertSame('https://api.payplug.com/oauth2/auth?client_id=abc', $request->url);
        self::assertSame('random-state', $request->state);
        self::assertSame('random-code-verifier', $request->codeVerifier);
    }
}

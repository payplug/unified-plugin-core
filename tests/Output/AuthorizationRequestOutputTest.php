<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Tests\Output;

use PayplugUnifiedCore\Output\AuthorizationRequestOutput;
use PHPUnit\Framework\TestCase;

final class AuthorizationRequestOutputTest extends TestCase
{
    public function testConstructorAssignsAllProperties(): void
    {
        $request = new AuthorizationRequestOutput(
            'https://api.payplug.com/oauth2/auth?client_id=abc',
            'random-state',
            'random-code-verifier'
        );

        self::assertSame('https://api.payplug.com/oauth2/auth?client_id=abc', $request->url);
        self::assertSame('random-state', $request->state);
        self::assertSame('random-code-verifier', $request->codeVerifier);
    }
}

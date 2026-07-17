<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Tests\Utilities\Helpers;

use PayplugUnifiedCore\Utilities\Helpers\PkceHelper;
use PHPUnit\Framework\TestCase;

final class PkceHelperTest extends TestCase
{
    public function testGenerateCodeVerifierReturnsStringWithinRfc7636LengthBounds(): void
    {
        $verifier = PkceHelper::generateCodeVerifier();

        self::assertMatchesRegularExpression('/^[A-Za-z0-9\-_]{43,128}$/', $verifier);
    }

    public function testGenerateCodeVerifierReturnsDifferentValuesEachCall(): void
    {
        self::assertNotSame(PkceHelper::generateCodeVerifier(), PkceHelper::generateCodeVerifier());
    }

    public function testDeriveCodeChallengeMatchesRfc7636TestVector(): void
    {
        // RFC 7636 Appendix B's worked example.
        $codeVerifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';

        self::assertSame('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', PkceHelper::deriveCodeChallenge($codeVerifier));
    }

    public function testDeriveCodeChallengeIsDeterministicForSameVerifier(): void
    {
        $verifier = PkceHelper::generateCodeVerifier();

        self::assertSame(PkceHelper::deriveCodeChallenge($verifier), PkceHelper::deriveCodeChallenge($verifier));
    }

    public function testGenerateStateReturnsNonEmptyString(): void
    {
        self::assertNotSame('', PkceHelper::generateState());
    }

    public function testGenerateStateReturnsDifferentValuesEachCall(): void
    {
        self::assertNotSame(PkceHelper::generateState(), PkceHelper::generateState());
    }
}

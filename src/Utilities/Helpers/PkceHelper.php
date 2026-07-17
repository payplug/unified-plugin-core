<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Utilities\Helpers;

final class PkceHelper
{
    private const VERIFIER_BYTES = 64; // -> 86 base64url chars, within RFC 7636's 43-128 bound
    private const STATE_BYTES = 32;

    /**
     * @codeCoverageIgnore
     */
    private function __construct()
    {
    }

    /**
     * Generates a PKCE code_verifier (RFC 7636 §4.1) — a cryptographically random string proving,
     * at token-exchange time, that the same client that started the authorization request is the
     * one completing it.
     *
     * <code>
     * $codeVerifier = PkceHelper::generateCodeVerifier();
     * </code>
     */
    public static function generateCodeVerifier(): string
    {
        return self::base64UrlEncode(random_bytes(self::VERIFIER_BYTES));
    }

    /**
     * Derives the S256 code_challenge sent in the authorization request from a code_verifier
     * produced by generateCodeVerifier(). Only the S256 method is supported; "plain" is not
     * supported.
     *
     * <code>
     * $codeChallenge = PkceHelper::deriveCodeChallenge($codeVerifier);
     * </code>
     */
    public static function deriveCodeChallenge(string $codeVerifier): string
    {
        return self::base64UrlEncode(hash('sha256', $codeVerifier, true));
    }

    /**
     * Generates the opaque `state` value sent with the authorization request and echoed back on
     * the callback, guarding against CSRF.
     *
     * <code>
     * $state = PkceHelper::generateState();
     * </code>
     */
    public static function generateState(): string
    {
        return self::base64UrlEncode(random_bytes(self::STATE_BYTES));
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

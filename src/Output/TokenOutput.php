<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Output;

use PayplugUnifiedCore\Exceptions\InvalidTokenException;
use PayplugUnifiedCore\Utilities\Helpers\Assert;

/**
 * Value object for a freshly-minted OAuth2 token response. Construct this only from data that
 * has already crossed UPC's external boundary (an OAuth2 token-endpoint response) — the
 * constructor validates the result, it does not sanitize raw untrusted input itself.
 */
final class TokenOutput
{
    /** @var string */
    public $accessToken;

    /** @var int */
    public $expiresIn;

    /** @var string */
    public $tokenType;

    /**
     * The OpenID Connect ID token, when the grant issues one: an authorization-code exchange made
     * with the `openid` scope carries the signed claims about the person who just logged in (their
     * email among them), which is the only place that identity surfaces — the access token
     * authorizes an account, it does not name a user. Deliberately unvalidated and optional: the
     * client_credentials grant authenticates a machine and has no id_token at all, so requiring one
     * here would reject a perfectly usable token response.
     *
     * @var string|null
     */
    public $idToken;

    public function __construct(string $accessToken, int $expiresIn, string $tokenType, ?string $idToken = null)
    {
        Assert::notEmpty($accessToken, 'accessToken', InvalidTokenException::class);
        Assert::positive($expiresIn, 'expiresIn', InvalidTokenException::class);
        Assert::notEmpty($tokenType, 'tokenType', InvalidTokenException::class);

        $this->accessToken = $accessToken;
        $this->expiresIn = $expiresIn;
        $this->tokenType = $tokenType;
        $this->idToken = $idToken;
    }
}

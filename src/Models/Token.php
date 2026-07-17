<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Models;

use PayplugUnifiedCore\Exceptions\InvalidTokenException;

/**
 * Value object for a freshly-minted OAuth2 token response. Construct this only from data that
 * has already crossed UPC's external boundary (an OAuth2 token-endpoint response) — the
 * constructor validates the result, it does not sanitize raw untrusted input itself.
 */
final class Token
{
    /** @var string */
    public $accessToken;

    /** @var int */
    public $expiresIn;

    /** @var string */
    public $tokenType;

    public function __construct(string $accessToken, int $expiresIn, string $tokenType)
    {
        if ($accessToken === '') {
            throw new InvalidTokenException('accessToken must not be empty.');
        }

        if ($expiresIn <= 0) {
            throw new InvalidTokenException('expiresIn must be greater than zero.');
        }

        if ($tokenType === '') {
            throw new InvalidTokenException('tokenType must not be empty.');
        }

        $this->accessToken = $accessToken;
        $this->expiresIn = $expiresIn;
        $this->tokenType = $tokenType;
    }
}

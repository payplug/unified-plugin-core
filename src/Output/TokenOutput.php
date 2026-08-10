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

    public function __construct(string $accessToken, int $expiresIn, string $tokenType)
    {
        Assert::notEmpty($accessToken, 'accessToken', InvalidTokenException::class);
        Assert::positive($expiresIn, 'expiresIn', InvalidTokenException::class);
        Assert::notEmpty($tokenType, 'tokenType', InvalidTokenException::class);

        $this->accessToken = $accessToken;
        $this->expiresIn = $expiresIn;
        $this->tokenType = $tokenType;
    }
}

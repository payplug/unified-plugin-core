<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Models;

/**
 * Output of OAuth2Client::buildAuthorizationUrl() — the URL to redirect the merchant's browser
 * to, plus the state and codeVerifier the caller must persist (session) to validate and complete
 * the flow on callback. Unlike OperationData, this never crosses an external boundary — it's
 * produced entirely by UPC's own OAuth2Client — so its constructor holds no validation.
 */
final class AuthorizationRequest
{
    /** @var string */
    public $url;

    /** @var string */
    public $state;

    /** @var string */
    public $codeVerifier;

    public function __construct(string $url, string $state, string $codeVerifier)
    {
        $this->url = $url;
        $this->state = $state;
        $this->codeVerifier = $codeVerifier;
    }
}

<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Dto;

/**
 * End-user browser fingerprint for a payment-creation call. All three fields are required
 * together by the Unified API schema whenever browser data is sent at all — encoding that as
 * required constructor parameters, instead of a loose array, makes a partial BrowserDto
 * impossible to construct, which is what actually enforces the "all or nothing" rule now (see
 * Validators/HostedFieldDtoValidator, which used to check this at runtime and no longer needs to).
 * Sending it is what lets the issuer attempt a frictionless (challenge-free) 3DS flow instead of
 * always forcing one.
 */
final class BrowserDto
{
    /** @var string */
    public $ip;

    /** @var string */
    public $referrer;

    /** @var string */
    public $userAgent;

    public function __construct(string $ip, string $referrer, string $userAgent)
    {
        $this->ip = $ip;
        $this->referrer = $referrer;
        $this->userAgent = $userAgent;
    }

    /**
     * @return array{ip: string, referrer: string, userAgent: string}
     */
    public function toArray(): array
    {
        return [
            'ip' => $this->ip,
            'referrer' => $this->referrer,
            'userAgent' => $this->userAgent,
        ];
    }
}

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
 *
 * The Unified API caps browser.ip at 15 characters (an IPv4's maximum length) and rejects an IPv6
 * address, so toArray() swaps any IPv6 value for 0.0.0.0. The $ip property itself keeps whatever
 * the caller passed in; only the serialized payload changes.
 */
final class BrowserDto
{
    private const IPV6_FALLBACK = '0.0.0.0';

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
            'ip' => self::isIpv6($this->ip) ? self::IPV6_FALLBACK : $this->ip,
            'referrer' => $this->referrer,
            'userAgent' => $this->userAgent,
        ];
    }

    /**
     * Any colon means this is not a bare IPv4 (IPv6, or an ip:port value). Broader than
     * filter_var(FILTER_FLAG_IPV6) on purpose, which rejects a zone-suffixed address such as
     * fe80::1%eth0 — that value would otherwise reach the API untouched and be rejected there.
     */
    private static function isIpv6(string $ip): bool
    {
        return strpos($ip, ':') !== false;
    }
}

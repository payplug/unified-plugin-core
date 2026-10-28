<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\DataValues;

/**
 * The Payplug platform an Apple Pay domain-association file comes from. Each value maps to a
 * source URL (DomainAssociationService) and an expected pspId (DomainAssociationValidator), both
 * frozen in UPC. The plugin passes the platform it already talks to, the same one as its baseUrl:
 * PRODUCTION for secure.payplug.com, QA for Payplug's QA platform, secure-qa.payplug.com.
 */
final class DomainAssociationEnvironment
{
    public const PRODUCTION = 'production';
    public const QA = 'qa';

    private const ALL = [
        self::PRODUCTION,
        self::QA,
    ];

    /**
     * @codeCoverageIgnore
     */
    private function __construct()
    {
    }

    /**
     * Exact, case-sensitive match: the value selects a frozen URL/pspId pair, so only the constants
     * themselves are accepted.
     */
    public static function isValid(string $value): bool
    {
        return \in_array($value, self::ALL, true);
    }
}

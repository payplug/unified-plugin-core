<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Validators;

use PayplugUnifiedCore\DataValues\DomainAssociationEnvironment;

/**
 * Checks that a string is the Apple Pay domain-association file of a given Payplug environment
 * (DomainAssociationEnvironment, production by default): hex-encoded JSON whose pspId is that
 * environment's (PROD_PSP_ID or QA_PSP_ID). Rejects an HTML page returned with a 200, a truncated
 * file, a BOM-prefixed file, the other environment's file and any unknown environment.
 *
 * Both pspIds are frozen here: a change of either needs a UPC release updating the constant, then
 * a release of each plugin.
 *
 * Returns a bool instead of throwing like the DTO validators: both callers (a stored value about
 * to be served, a freshly fetched one about to be stored) treat invalid content as a branch, and
 * the serving path must not need a try/catch.
 *
 * Only trailing "\r" and "\n" are ignored, and only on a copy: the content that is stored and
 * served is never modified.
 *
 * <code>
 * $content = $storage->readContent();
 * if ($content === null || !DomainAssociationValidator::isValid($content)) {
 *     // respond 404
 * }
 * </code>
 */
final class DomainAssociationValidator
{
    public const PROD_PSP_ID = '0E0C552360010ECC0B5A039C787913D7AC0294C406F4AD7978FF5619BDF31977';
    public const QA_PSP_ID = '8AA6F4941A057694381109ACBFD6135D3D3D8DC87FADE3B1CD59B09D6A893470';

    private const PSP_IDS = [
        DomainAssociationEnvironment::PRODUCTION => self::PROD_PSP_ID,
        DomainAssociationEnvironment::QA => self::QA_PSP_ID,
    ];

    private const MAX_LENGTH = 65536;
    private const MAX_JSON_DEPTH = 16;

    /**
     * @codeCoverageIgnore
     */
    private function __construct()
    {
    }

    /**
     * An unknown $environment makes every content invalid rather than throwing, since this also runs
     * on the public serving path.
     */
    public static function isValid(string $raw, string $environment = DomainAssociationEnvironment::PRODUCTION): bool
    {
        $json = self::decode($raw);

        return \array_key_exists($environment, self::PSP_IDS)
            && \is_array($json)
            && isset($json['pspId'])
            && \is_string($json['pspId'])
            && strtoupper($json['pspId']) === self::PSP_IDS[$environment];
    }

    /**
     * @return mixed the decoded JSON, or null when $raw is not a bounded, well-formed hex string
     */
    private static function decode(string $raw)
    {
        $hex = rtrim($raw, "\r\n");
        if (\strlen($raw) > self::MAX_LENGTH
            || $hex === ''
            || \strlen($hex) % 2 !== 0
            || preg_match('/^[0-9A-Fa-f]+$/D', $hex) !== 1
        ) {
            return null;
        }

        $binary = hex2bin($hex);

        return $binary === false ? null : json_decode($binary, true, self::MAX_JSON_DEPTH);
    }
}

<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Validators;

/**
 * Checks that a string is a production Apple Pay domain-association file: hex-encoded JSON whose
 * pspId is PROD_PSP_ID. Rejects an HTML page returned with a 200, a truncated file, a BOM-prefixed
 * file and the recette file.
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

    private const MAX_LENGTH = 65536;
    private const MAX_JSON_DEPTH = 16;

    /**
     * @codeCoverageIgnore
     */
    private function __construct()
    {
    }

    public static function isValid(string $raw): bool
    {
        $json = self::decode($raw);

        return \is_array($json)
            && isset($json['pspId'])
            && \is_string($json['pspId'])
            && strtoupper($json['pspId']) === self::PROD_PSP_ID;
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

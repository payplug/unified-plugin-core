<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Tests\Support;

use PayplugUnifiedCore\Validators\DomainAssociationValidator;

/**
 * Synthetic Apple Pay domain-association contents: hex-encoded JSON shaped like the real file
 * (pspId, version, createdOn, signature), with a dummy signature.
 */
final class DomainAssociationFixtures
{
    public static function payload(string $pspId = DomainAssociationValidator::PROD_PSP_ID, int $createdOn = 1700000000): string
    {
        return bin2hex((string) json_encode([
            'pspId' => $pspId,
            'version' => 1,
            'createdOn' => $createdOn,
            'signature' => str_repeat('ab', 64),
        ]));
    }

    public static function valid(): string
    {
        return self::payload() . "\n";
    }

    public static function validWithCrlf(): string
    {
        return self::payload() . "\r\n";
    }

    public static function validCreatedLater(): string
    {
        return self::payload(DomainAssociationValidator::PROD_PSP_ID, 1800000000) . "\n";
    }

    public static function htmlChallengePage(): string
    {
        return "<!DOCTYPE html>\n<html><head><title>Just a moment...</title></head><body>Checking your browser</body></html>\n";
    }

    public static function truncated(): string
    {
        $payload = self::payload();

        return substr($payload, 0, intdiv(\strlen($payload), 4) * 2);
    }

    public static function bomPrefixed(): string
    {
        return "\xEF\xBB\xBF" . self::valid();
    }

    public static function recettePspId(): string
    {
        return self::payload(str_repeat('AB', 32)) . "\n";
    }

    public static function oddLength(): string
    {
        return substr(self::payload(), 0, -1);
    }

    public static function nonHex(): string
    {
        return 'zz' . self::payload();
    }

    public static function jsonArrayPayload(): string
    {
        return bin2hex('[1,2]');
    }

    public static function pspIdAsArray(): string
    {
        return bin2hex('{"pspId":["x"]}');
    }

    public static function invalidJsonBytes(): string
    {
        return bin2hex("\xFF\xFE{not json");
    }

    public static function tooDeeplyNested(): string
    {
        $json = '{"pspId":"' . DomainAssociationValidator::PROD_PSP_ID . '","nested":'
            . str_repeat('[', 20) . str_repeat(']', 20) . '}';

        return bin2hex($json);
    }

    /**
     * A structurally valid production payload whose hex encoding is exactly $hexLength characters
     * ($hexLength must be even and large enough to hold the pspId).
     */
    public static function validWithHexLength(int $hexLength): string
    {
        $base = (string) json_encode(['pspId' => DomainAssociationValidator::PROD_PSP_ID, 'signature' => '']);
        $padding = intdiv($hexLength, 2) - \strlen($base);

        return bin2hex((string) json_encode([
            'pspId' => DomainAssociationValidator::PROD_PSP_ID,
            'signature' => str_repeat('a', $padding),
        ]));
    }
}

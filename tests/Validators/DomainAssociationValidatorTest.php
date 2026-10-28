<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Tests\Validators;

use PayplugUnifiedCore\DataValues\DomainAssociationEnvironment;
use PayplugUnifiedCore\Tests\Support\DomainAssociationFixtures;
use PayplugUnifiedCore\Validators\DomainAssociationValidator;
use PHPUnit\Framework\TestCase;

final class DomainAssociationValidatorTest extends TestCase
{
    public function testProductionPspIdIsThePayplugProductionValue(): void
    {
        // @phpstan-ignore-next-line staticMethod.alreadyNarrowedType (regression guard: keeps failing if the production pspId changes)
        self::assertSame('0E0C552360010ECC0B5A039C787913D7AC0294C406F4AD7978FF5619BDF31977', DomainAssociationValidator::PROD_PSP_ID);
    }

    public function testQaPspIdIsThePayplugQaValue(): void
    {
        // @phpstan-ignore-next-line staticMethod.alreadyNarrowedType (regression guard: keeps failing if the QA pspId changes)
        self::assertSame('8AA6F4941A057694381109ACBFD6135D3D3D8DC87FADE3B1CD59B09D6A893470', DomainAssociationValidator::QA_PSP_ID);
    }

    public function testAcceptsTheQaFileInQa(): void
    {
        self::assertTrue(DomainAssociationValidator::isValid(DomainAssociationFixtures::validQa(), DomainAssociationEnvironment::QA));
    }

    public function testAcceptsTheProductionFileWhenProductionIsExplicit(): void
    {
        self::assertTrue(DomainAssociationValidator::isValid(DomainAssociationFixtures::valid(), DomainAssociationEnvironment::PRODUCTION));
    }

    public function testRejectsTheQaFileInProduction(): void
    {
        self::assertFalse(DomainAssociationValidator::isValid(DomainAssociationFixtures::validQa()));
    }

    public function testRejectsTheProductionFileInQa(): void
    {
        self::assertFalse(DomainAssociationValidator::isValid(DomainAssociationFixtures::valid(), DomainAssociationEnvironment::QA));
    }

    public function testRejectsEveryFileForAnUnknownEnvironment(): void
    {
        self::assertFalse(DomainAssociationValidator::isValid(DomainAssociationFixtures::valid(), 'sandbox'));
        self::assertFalse(DomainAssociationValidator::isValid(DomainAssociationFixtures::validQa(), 'sandbox'));
    }

    /**
     * @dataProvider validContentProvider
     */
    public function testAcceptsValidProductionContent(string $raw): void
    {
        self::assertTrue(DomainAssociationValidator::isValid($raw));
    }

    /**
     * @return array<string, array{string}>
     */
    public function validContentProvider(): array
    {
        return [
            'trailing LF' => [DomainAssociationFixtures::valid()],
            'trailing CRLF' => [DomainAssociationFixtures::validWithCrlf()],
            'no trailing newline' => [DomainAssociationFixtures::payload()],
            'lowercase pspId' => [DomainAssociationFixtures::payload(strtolower(DomainAssociationValidator::PROD_PSP_ID))],
            'uppercase hex digits' => [strtoupper(DomainAssociationFixtures::payload())],
        ];
    }

    /**
     * @dataProvider invalidContentProvider
     */
    public function testRejectsInvalidContent(string $raw): void
    {
        self::assertFalse(DomainAssociationValidator::isValid($raw));
    }

    /**
     * @return array<string, array{string}>
     */
    public function invalidContentProvider(): array
    {
        return [
            'empty' => [''],
            'newline only' => ["\n"],
            'CRLF only' => ["\r\n"],
            'HTML challenge page' => [DomainAssociationFixtures::htmlChallengePage()],
            'truncated' => [DomainAssociationFixtures::truncated()],
            'UTF-8 BOM prefix' => [DomainAssociationFixtures::bomPrefixed()],
            'unknown pspId' => [DomainAssociationFixtures::unknownPspId()],
            'odd length' => [DomainAssociationFixtures::oddLength()],
            'non-hex characters' => [DomainAssociationFixtures::nonHex()],
            'JSON array' => [DomainAssociationFixtures::jsonArrayPayload()],
            'pspId is an array' => [DomainAssociationFixtures::pspIdAsArray()],
            'pspId missing' => [bin2hex('{"version":1}')],
            'pspId is an integer' => [bin2hex('{"pspId":1}')],
            'hex of invalid JSON bytes' => [DomainAssociationFixtures::invalidJsonBytes()],
            'JSON nested deeper than 16 levels' => [DomainAssociationFixtures::tooDeeplyNested()],
            'leading space' => [' ' . DomainAssociationFixtures::valid()],
            'leading newline' => ["\n" . DomainAssociationFixtures::valid()],
            'trailing space' => [DomainAssociationFixtures::payload() . ' '],
            'trailing tab' => [DomainAssociationFixtures::payload() . "\t"],
            'newline inside the hex' => [substr(DomainAssociationFixtures::payload(), 0, 10) . "\n" . substr(DomainAssociationFixtures::payload(), 10)],
        ];
    }

    public function testAcceptsRawContentOfExactly64KiB(): void
    {
        $raw = DomainAssociationFixtures::validWithHexLength(65534) . "\r\n";

        self::assertSame(65536, \strlen($raw));
        self::assertTrue(DomainAssociationValidator::isValid($raw));
    }

    public function testRejectsRawContentLongerThan64KiBEvenWhenOtherwiseValid(): void
    {
        $hex = DomainAssociationFixtures::validWithHexLength(65536);
        $raw = $hex . "\n";

        self::assertSame(65537, \strlen($raw));
        self::assertTrue(DomainAssociationValidator::isValid($hex));
        self::assertFalse(DomainAssociationValidator::isValid($raw));
    }
}

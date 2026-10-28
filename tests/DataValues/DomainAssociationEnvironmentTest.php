<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Tests\DataValues;

use PayplugUnifiedCore\DataValues\DomainAssociationEnvironment;
use PHPUnit\Framework\TestCase;

final class DomainAssociationEnvironmentTest extends TestCase
{
    public function testConstantValues(): void
    {
        // @phpstan-ignore-next-line staticMethod.alreadyNarrowedType (regression guard: adapters may persist these values)
        self::assertSame('production', DomainAssociationEnvironment::PRODUCTION);
        // @phpstan-ignore-next-line staticMethod.alreadyNarrowedType (regression guard: adapters may persist these values)
        self::assertSame('qa', DomainAssociationEnvironment::QA);
    }

    /**
     * @dataProvider validProvider
     */
    public function testIsValidAcceptsEveryConstant(string $value): void
    {
        self::assertTrue(DomainAssociationEnvironment::isValid($value));
    }

    /**
     * @return array<string, array{string}>
     */
    public function validProvider(): array
    {
        return [
            'production' => [DomainAssociationEnvironment::PRODUCTION],
            'qa' => [DomainAssociationEnvironment::QA],
        ];
    }

    /**
     * @dataProvider invalidProvider
     */
    public function testIsValidRejectsAnythingElse(string $value): void
    {
        self::assertFalse(DomainAssociationEnvironment::isValid($value));
    }

    /**
     * @return array<string, array{string}>
     */
    public function invalidProvider(): array
    {
        return [
            'empty' => [''],
            'other casing' => ['QA'],
            'sandbox' => ['sandbox'],
            'staging' => ['staging'],
            'surrounding spaces' => [' qa '],
        ];
    }
}

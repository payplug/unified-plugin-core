<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Tests\DataValues;

use PayplugUnifiedCore\DataValues\AuthorizationType;
use PHPUnit\Framework\TestCase;

final class AuthorizationTypeTest extends TestCase
{
    public function testConstantsHaveTheExpectedValues(): void
    {
        // @phpstan-ignore-next-line staticMethod.alreadyNarrowedType
        self::assertSame('pre_authorization', AuthorizationType::PRE_AUTHORIZATION);
        // @phpstan-ignore-next-line staticMethod.alreadyNarrowedType
        self::assertSame('final_authorization', AuthorizationType::FINAL_AUTHORIZATION);
    }

    /**
     * @dataProvider validValueProvider
     */
    public function testIsValidReturnsTrueForEveryConstant(string $value): void
    {
        self::assertTrue(AuthorizationType::isValid($value));
    }

    /**
     * @return array<string, array{string}>
     */
    public function validValueProvider(): array
    {
        return [
            'PRE_AUTHORIZATION' => [AuthorizationType::PRE_AUTHORIZATION],
            'FINAL_AUTHORIZATION' => [AuthorizationType::FINAL_AUTHORIZATION],
        ];
    }

    public function testIsValidReturnsTrueForAnUppercaseValue(): void
    {
        self::assertTrue(AuthorizationType::isValid('FINAL_AUTHORIZATION'));
        self::assertTrue(AuthorizationType::isValid('PRE_AUTHORIZATION'));
    }

    public function testIsValidReturnsFalseForAnUnknownValue(): void
    {
        self::assertFalse(AuthorizationType::isValid('something_else'));
    }

    public function testIsValidReturnsFalseForAnEmptyString(): void
    {
        self::assertFalse(AuthorizationType::isValid(''));
    }
}

<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Tests\Dto;

use PayplugUnifiedCore\Dto\AddressDto;
use PHPUnit\Framework\TestCase;

final class AddressDtoTest extends TestCase
{
    public function testConstructorDefaultsAllFieldsToNull(): void
    {
        $address = new AddressDto();

        self::assertNull($address->line);
        self::assertNull($address->city);
        self::assertNull($address->country);
        self::assertNull($address->state);
        self::assertNull($address->zipCode);
    }

    public function testConstructorAssignsAllProperties(): void
    {
        $address = new AddressDto('1 rue de Rivoli', 'Paris', 'FR', 'IDF', '75001');

        self::assertSame('1 rue de Rivoli', $address->line);
        self::assertSame('Paris', $address->city);
        self::assertSame('FR', $address->country);
        self::assertSame('IDF', $address->state);
        self::assertSame('75001', $address->zipCode);
    }

    public function testToArrayReturnsExpectedShapeWhenEveryFieldIsProvided(): void
    {
        $address = new AddressDto('1 rue de Rivoli', 'Paris', 'FR', 'IDF', '75001');

        self::assertSame([
            'line' => '1 rue de Rivoli',
            'city' => 'Paris',
            'country' => 'FR',
            'state' => 'IDF',
            'zipCode' => '75001',
        ], $address->toArray());
    }

    public function testToArrayOmitsFieldsThatAreNull(): void
    {
        $address = new AddressDto('1 rue de Rivoli', null, 'FR');

        self::assertSame([
            'line' => '1 rue de Rivoli',
            'country' => 'FR',
        ], $address->toArray());
    }

    public function testToArrayReturnsEmptyArrayWhenNoFieldIsProvided(): void
    {
        self::assertSame([], (new AddressDto())->toArray());
    }
}

<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Tests\Dto;

use PayplugUnifiedCore\Dto\AddressDto;
use PayplugUnifiedCore\Dto\ContactDto;
use PayplugUnifiedCore\Dto\ShippingDto;
use PayplugUnifiedCore\Dto\ShippingScheduleDto;
use PHPUnit\Framework\TestCase;

final class ShippingDtoTest extends TestCase
{
    public function testConstructorDefaultsAllFieldsToNull(): void
    {
        $shipping = new ShippingDto();

        self::assertNull($shipping->address);
        self::assertNull($shipping->contact);
        self::assertNull($shipping->email);
        self::assertNull($shipping->companyName);
        self::assertNull($shipping->schedule);
    }

    public function testConstructorAssignsAllProperties(): void
    {
        $address = new AddressDto('1 rue de Rivoli', 'Paris', 'FR', 'IDF', '75001');
        $contact = $this->createContact();
        $schedule = new ShippingScheduleDto('billing-shipping', 'lessThanOneHour', '2024-01-01');

        $shipping = new ShippingDto($address, $contact, 'john.snow@example.com', 'Acme Corp', $schedule);

        self::assertSame($address, $shipping->address);
        self::assertSame($contact, $shipping->contact);
        self::assertSame('john.snow@example.com', $shipping->email);
        self::assertSame('Acme Corp', $shipping->companyName);
        self::assertSame($schedule, $shipping->schedule);
    }

    public function testToArrayReturnsExpectedShapeWhenEveryFieldIsProvided(): void
    {
        $address = new AddressDto('1 rue de Rivoli', 'Paris', 'FR');
        $contact = $this->createContact();
        $schedule = new ShippingScheduleDto('billing-shipping', 'lessThanOneHour', '2024-01-01');

        $shipping = new ShippingDto($address, $contact, 'john.snow@example.com', 'Acme Corp', $schedule);

        self::assertSame([
            'address' => ['line' => '1 rue de Rivoli', 'city' => 'Paris', 'country' => 'FR'],
            'firstName' => 'John',
            'lastName' => 'Snow',
            'phone' => '+33100000000',
            'mobilePhone' => '+33600000000',
            'email' => 'john.snow@example.com',
            'companyName' => 'Acme Corp',
            'addressType' => 'billing-shipping',
            'timeFrame' => 'lessThanOneHour',
            'addressDate' => '2024-01-01',
        ], $shipping->toArray());
    }

    public function testToArrayOmitsFieldsThatAreNull(): void
    {
        $shipping = new ShippingDto(null, new ContactDto('John', 'Snow'));

        self::assertSame([
            'firstName' => 'John',
            'lastName' => 'Snow',
        ], $shipping->toArray());
    }

    public function testToArrayReturnsEmptyArrayWhenNoFieldIsProvided(): void
    {
        self::assertSame([], (new ShippingDto())->toArray());
    }

    private function createContact(): ContactDto
    {
        return new ContactDto('John', 'Snow', '+33100000000', '+33600000000');
    }
}

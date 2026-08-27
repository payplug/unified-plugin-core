<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Tests\Dto;

use PayplugUnifiedCore\Dto\AddressDto;
use PayplugUnifiedCore\Dto\BillingDto;
use PayplugUnifiedCore\Dto\ContactDto;
use PHPUnit\Framework\TestCase;

final class BillingDtoTest extends TestCase
{
    public function testConstructorDefaultsAllFieldsToNull(): void
    {
        $billing = new BillingDto();

        self::assertNull($billing->address);
        self::assertNull($billing->contact);
        self::assertNull($billing->title);
    }

    public function testConstructorAssignsAllProperties(): void
    {
        $address = new AddressDto('1 rue de Rivoli', 'Paris', 'FR', 'IDF', '75001');
        $contact = $this->createContact();

        $billing = new BillingDto($address, $contact, 'MR');

        self::assertSame($address, $billing->address);
        self::assertSame($contact, $billing->contact);
        self::assertSame('MR', $billing->title);
    }

    public function testToArrayReturnsExpectedShapeWhenEveryFieldIsProvided(): void
    {
        $address = new AddressDto('1 rue de Rivoli', 'Paris', 'FR');
        $contact = $this->createContact();

        $billing = new BillingDto($address, $contact, 'MR');

        self::assertSame([
            'address' => ['line' => '1 rue de Rivoli', 'city' => 'Paris', 'country' => 'FR'],
            'firstName' => 'John',
            'lastName' => 'Snow',
            'phone' => '+33100000000',
            'mobilePhone' => '+33600000000',
            'title' => 'MR',
        ], $billing->toArray());
    }

    public function testToArrayOmitsFieldsThatAreNull(): void
    {
        $billing = new BillingDto(null, new ContactDto('John', 'Snow'));

        self::assertSame([
            'firstName' => 'John',
            'lastName' => 'Snow',
        ], $billing->toArray());
    }

    public function testToArrayReturnsEmptyArrayWhenNoFieldIsProvided(): void
    {
        self::assertSame([], (new BillingDto())->toArray());
    }

    private function createContact(): ContactDto
    {
        return new ContactDto('John', 'Snow', '+33100000000', '+33600000000');
    }
}

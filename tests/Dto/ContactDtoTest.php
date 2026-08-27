<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Tests\Dto;

use PayplugUnifiedCore\Dto\ContactDto;
use PHPUnit\Framework\TestCase;

final class ContactDtoTest extends TestCase
{
    public function testConstructorDefaultsAllFieldsToNull(): void
    {
        $contact = new ContactDto();

        self::assertNull($contact->firstName);
        self::assertNull($contact->lastName);
        self::assertNull($contact->phone);
        self::assertNull($contact->mobilePhone);
    }

    public function testConstructorAssignsAllProperties(): void
    {
        $contact = new ContactDto('John', 'Snow', '+33100000000', '+33600000000');

        self::assertSame('John', $contact->firstName);
        self::assertSame('Snow', $contact->lastName);
        self::assertSame('+33100000000', $contact->phone);
        self::assertSame('+33600000000', $contact->mobilePhone);
    }

    public function testToArrayReturnsExpectedShapeWhenEveryFieldIsProvided(): void
    {
        $contact = new ContactDto('John', 'Snow', '+33100000000', '+33600000000');

        self::assertSame([
            'firstName' => 'John',
            'lastName' => 'Snow',
            'phone' => '+33100000000',
            'mobilePhone' => '+33600000000',
        ], $contact->toArray());
    }

    public function testToArrayOmitsFieldsThatAreNull(): void
    {
        $contact = new ContactDto('John', null, '+33100000000');

        self::assertSame([
            'firstName' => 'John',
            'phone' => '+33100000000',
        ], $contact->toArray());
    }

    public function testToArrayReturnsEmptyArrayWhenNoFieldIsProvided(): void
    {
        self::assertSame([], (new ContactDto())->toArray());
    }
}

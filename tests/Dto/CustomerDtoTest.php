<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Tests\Dto;

use PayplugUnifiedCore\Dto\CustomerDto;
use PHPUnit\Framework\TestCase;

final class CustomerDtoTest extends TestCase
{
    public function testConstructorAssignsAllProperties(): void
    {
        $customer = new CustomerDto('john.snow', 'john.snow@example.com');

        self::assertSame('john.snow', $customer->id);
        self::assertSame('john.snow@example.com', $customer->email);
    }

    public function testToArrayReturnsExpectedShape(): void
    {
        $customer = new CustomerDto('john.snow', 'john.snow@example.com');

        self::assertSame([
            'id' => 'john.snow',
            'email' => 'john.snow@example.com',
        ], $customer->toArray());
    }
}

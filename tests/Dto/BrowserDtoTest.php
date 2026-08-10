<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Tests\Dto;

use PayplugUnifiedCore\Dto\BrowserDto;
use PHPUnit\Framework\TestCase;

final class BrowserDtoTest extends TestCase
{
    public function testConstructorAssignsAllProperties(): void
    {
        $browser = new BrowserDto('10.1.1.1', 'https://shop.example.com/cart', 'Mozilla/5.0');

        self::assertSame('10.1.1.1', $browser->ip);
        self::assertSame('https://shop.example.com/cart', $browser->referrer);
        self::assertSame('Mozilla/5.0', $browser->userAgent);
    }

    public function testToArrayReturnsExpectedShape(): void
    {
        $browser = new BrowserDto('10.1.1.1', 'https://shop.example.com/cart', 'Mozilla/5.0');

        self::assertSame([
            'ip' => '10.1.1.1',
            'referrer' => 'https://shop.example.com/cart',
            'userAgent' => 'Mozilla/5.0',
        ], $browser->toArray());
    }
}

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

    /**
     * @dataProvider ipv4Provider
     */
    public function testToArraySendsAnIpv4AddressUnchanged(string $ip): void
    {
        $browser = new BrowserDto($ip, 'https://shop.example.com/cart', 'Mozilla/5.0');

        self::assertSame($ip, $browser->toArray()['ip']);
    }

    /**
     * @return array<string, array{string}>
     */
    public function ipv4Provider(): array
    {
        return [
            'private' => ['10.1.1.1'],
            'longest possible' => ['255.255.255.255'],
            'loopback' => ['127.0.0.1'],
        ];
    }

    /**
     * The Unified API caps browser.ip at 15 characters (an IPv4's maximum length), so any IPv6
     * address is replaced by 0.0.0.0 at serialization time — even a short one like ::1, so the
     * rule stays "IPv6 never reaches the API" rather than depending on the address's length.
     *
     * @dataProvider ipv6Provider
     */
    public function testToArrayReplacesAnIpv6AddressWithTheUnspecifiedIpv4Address(string $ip): void
    {
        $browser = new BrowserDto($ip, 'https://shop.example.com/cart', 'Mozilla/5.0');

        self::assertSame([
            'ip' => '0.0.0.0',
            'referrer' => 'https://shop.example.com/cart',
            'userAgent' => 'Mozilla/5.0',
        ], $browser->toArray());
    }

    /**
     * @return array<string, array{string}>
     */
    public function ipv6Provider(): array
    {
        return [
            'full form' => ['2001:0db8:85a3:0000:0000:8a2e:0370:7334'],
            'compressed' => ['2001:db8::8a2e:370:7334'],
            'loopback' => ['::1'],
            'unspecified' => ['::'],
            'ipv4-mapped' => ['::ffff:192.0.2.1'],
            'link-local with zone id' => ['fe80::1%eth0'],
        ];
    }

    public function testToArrayLeavesTheIpPropertyItselfUntouched(): void
    {
        $browser = new BrowserDto('2001:db8::1', 'https://shop.example.com/cart', 'Mozilla/5.0');

        self::assertSame('0.0.0.0', $browser->toArray()['ip']);
        self::assertSame('2001:db8::1', $browser->ip);
    }
}

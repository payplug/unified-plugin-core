<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Tests\Exceptions;

use PayplugUnifiedCore\Exceptions\InvalidCurrencyException;
use PayplugUnifiedCore\Exceptions\PayplugException;
use PHPUnit\Framework\TestCase;

final class InvalidCurrencyExceptionTest extends TestCase
{
    public function testExtendsPayplugException(): void
    {
        // @phpstan-ignore-next-line staticMethod.alreadyNarrowedType (regression guard: keeps failing if the class stops extending its parent)
        self::assertInstanceOf(PayplugException::class, new InvalidCurrencyException());
    }

    public function testConstructorStoresMessageCodeAndPrevious(): void
    {
        $previous = new \Exception('previous');
        $exception = new InvalidCurrencyException('invalid currency', 22, $previous);

        self::assertSame('invalid currency', $exception->getMessage());
        self::assertSame(22, $exception->getCode());
        self::assertSame($previous, $exception->getPrevious());
    }
}

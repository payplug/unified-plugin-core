<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Tests\Exceptions;

use PayplugUnifiedCore\Exceptions\AmountExceedsAvailableException;
use PayplugUnifiedCore\Exceptions\PayplugException;
use PHPUnit\Framework\TestCase;

final class AmountExceedsAvailableExceptionTest extends TestCase
{
    public function testExtendsPayplugException(): void
    {
        // @phpstan-ignore-next-line staticMethod.alreadyNarrowedType (regression guard: keeps failing if the class stops extending its parent)
        self::assertInstanceOf(PayplugException::class, new AmountExceedsAvailableException());
    }

    public function testConstructorStoresMessageCodeAndPrevious(): void
    {
        $previous = new \Exception('previous');
        $exception = new AmountExceedsAvailableException('amount exceeds the amount still available on payment "pay_123".', 400, $previous);

        self::assertSame('amount exceeds the amount still available on payment "pay_123".', $exception->getMessage());
        self::assertSame(400, $exception->getCode());
        self::assertSame($previous, $exception->getPrevious());
    }
}

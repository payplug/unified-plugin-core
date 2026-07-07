<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Tests\Exceptions;

use PayplugUnifiedCore\Exceptions\PayplugException;
use PayplugUnifiedCore\Exceptions\RefundAmountException;
use PHPUnit\Framework\TestCase;

final class RefundAmountExceptionTest extends TestCase
{
    public function testExtendsPayplugException(): void
    {
        // @phpstan-ignore-next-line staticMethod.alreadyNarrowedType (regression guard: keeps failing if the class stops extending its parent)
        self::assertInstanceOf(PayplugException::class, new RefundAmountException());
    }

    public function testConstructorStoresMessageCodeAndPrevious(): void
    {
        $previous = new \Exception('previous');
        $exception = new RefundAmountException('refund amount invalid', 7, $previous);

        self::assertSame('refund amount invalid', $exception->getMessage());
        self::assertSame(7, $exception->getCode());
        self::assertSame($previous, $exception->getPrevious());
    }
}

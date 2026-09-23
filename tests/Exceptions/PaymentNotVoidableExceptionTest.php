<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Tests\Exceptions;

use PayplugUnifiedCore\Exceptions\PaymentNotVoidableException;
use PayplugUnifiedCore\Exceptions\PayplugException;
use PHPUnit\Framework\TestCase;

final class PaymentNotVoidableExceptionTest extends TestCase
{
    public function testExtendsPayplugException(): void
    {
        // @phpstan-ignore-next-line staticMethod.alreadyNarrowedType (regression guard: keeps failing if the class stops extending its parent)
        self::assertInstanceOf(PayplugException::class, new PaymentNotVoidableException());
    }

    public function testConstructorStoresMessageCodeAndPrevious(): void
    {
        $previous = new \Exception('previous');
        $exception = new PaymentNotVoidableException('The reference transaction is not voidable.', 400, $previous);

        self::assertSame('The reference transaction is not voidable.', $exception->getMessage());
        self::assertSame(400, $exception->getCode());
        self::assertSame($previous, $exception->getPrevious());
    }
}

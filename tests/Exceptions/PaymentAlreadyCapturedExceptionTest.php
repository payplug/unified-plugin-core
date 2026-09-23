<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Tests\Exceptions;

use PayplugUnifiedCore\Exceptions\PaymentAlreadyCapturedException;
use PayplugUnifiedCore\Exceptions\PayplugException;
use PHPUnit\Framework\TestCase;

final class PaymentAlreadyCapturedExceptionTest extends TestCase
{
    public function testExtendsPayplugException(): void
    {
        // @phpstan-ignore-next-line staticMethod.alreadyNarrowedType (regression guard: keeps failing if the class stops extending its parent)
        self::assertInstanceOf(PayplugException::class, new PaymentAlreadyCapturedException());
    }

    public function testConstructorStoresMessageCodeAndPrevious(): void
    {
        $previous = new \Exception('previous');
        $exception = new PaymentAlreadyCapturedException('Payment "pay_123" has already been captured.', 400, $previous);

        self::assertSame('Payment "pay_123" has already been captured.', $exception->getMessage());
        self::assertSame(400, $exception->getCode());
        self::assertSame($previous, $exception->getPrevious());
    }
}

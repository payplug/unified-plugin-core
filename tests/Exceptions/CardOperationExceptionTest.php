<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Tests\Exceptions;

use PayplugUnifiedCore\Exceptions\CardOperationException;
use PayplugUnifiedCore\Exceptions\PayplugException;
use PHPUnit\Framework\TestCase;

final class CardOperationExceptionTest extends TestCase
{
    public function testExtendsPayplugException(): void
    {
        // @phpstan-ignore-next-line staticMethod.alreadyNarrowedType (regression guard: keeps failing if the class stops extending its parent)
        self::assertInstanceOf(PayplugException::class, new CardOperationException());
    }

    public function testConstructorStoresMessageCodeAndPrevious(): void
    {
        $previous = new \Exception('previous');
        $exception = new CardOperationException('card operation failed', 11, $previous);

        self::assertSame('card operation failed', $exception->getMessage());
        self::assertSame(11, $exception->getCode());
        self::assertSame($previous, $exception->getPrevious());
    }
}

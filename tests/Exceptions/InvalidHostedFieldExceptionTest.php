<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Tests\Exceptions;

use PayplugUnifiedCore\Exceptions\InvalidHostedFieldException;
use PayplugUnifiedCore\Exceptions\PayplugException;
use PHPUnit\Framework\TestCase;

final class InvalidHostedFieldExceptionTest extends TestCase
{
    public function testExtendsPayplugException(): void
    {
        // @phpstan-ignore-next-line staticMethod.alreadyNarrowedType (regression guard: keeps failing if the class stops extending its parent)
        self::assertInstanceOf(PayplugException::class, new InvalidHostedFieldException());
    }

    public function testConstructorStoresMessageCodeAndPrevious(): void
    {
        $previous = new \Exception('previous');
        $exception = new InvalidHostedFieldException('hfToken must not be empty.', 0, $previous);

        self::assertSame('hfToken must not be empty.', $exception->getMessage());
        self::assertSame(0, $exception->getCode());
        self::assertSame($previous, $exception->getPrevious());
    }
}

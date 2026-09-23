<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Tests\Exceptions;

use PayplugUnifiedCore\Exceptions\AuthorizationExpiredException;
use PayplugUnifiedCore\Exceptions\PayplugException;
use PHPUnit\Framework\TestCase;

final class AuthorizationExpiredExceptionTest extends TestCase
{
    public function testExtendsPayplugException(): void
    {
        // @phpstan-ignore-next-line staticMethod.alreadyNarrowedType (regression guard: keeps failing if the class stops extending its parent)
        self::assertInstanceOf(PayplugException::class, new AuthorizationExpiredException());
    }

    public function testConstructorStoresMessageCodeAndPrevious(): void
    {
        $previous = new \Exception('previous');
        $exception = new AuthorizationExpiredException('Authorization has expired.', 400, $previous);

        self::assertSame('Authorization has expired.', $exception->getMessage());
        self::assertSame(400, $exception->getCode());
        self::assertSame($previous, $exception->getPrevious());
    }
}

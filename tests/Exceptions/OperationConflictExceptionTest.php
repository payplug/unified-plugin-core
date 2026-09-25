<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Tests\Exceptions;

use PayplugUnifiedCore\Exceptions\OperationConflictException;
use PayplugUnifiedCore\Exceptions\PayplugException;
use PHPUnit\Framework\TestCase;

final class OperationConflictExceptionTest extends TestCase
{
    public function testExtendsPayplugException(): void
    {
        // @phpstan-ignore-next-line staticMethod.alreadyNarrowedType (regression guard: keeps failing if the class stops extending its parent)
        self::assertInstanceOf(PayplugException::class, new OperationConflictException());
    }

    public function testConstructorStoresMessageCodeAndPrevious(): void
    {
        $previous = new \Exception('previous');
        $exception = new OperationConflictException('Unified API operation request conflicted with HTTP status 409.', 409, $previous);

        self::assertSame('Unified API operation request conflicted with HTTP status 409.', $exception->getMessage());
        self::assertSame(409, $exception->getCode());
        self::assertSame($previous, $exception->getPrevious());
    }
}

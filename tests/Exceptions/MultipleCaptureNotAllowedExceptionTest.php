<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Tests\Exceptions;

use PayplugUnifiedCore\Exceptions\MultipleCaptureNotAllowedException;
use PayplugUnifiedCore\Exceptions\PayplugException;
use PHPUnit\Framework\TestCase;

final class MultipleCaptureNotAllowedExceptionTest extends TestCase
{
    public function testExtendsPayplugException(): void
    {
        // @phpstan-ignore-next-line staticMethod.alreadyNarrowedType (regression guard: keeps failing if the class stops extending its parent)
        self::assertInstanceOf(PayplugException::class, new MultipleCaptureNotAllowedException());
    }

    public function testConstructorStoresMessageCodeAndPrevious(): void
    {
        $previous = new \Exception('previous');
        $exception = new MultipleCaptureNotAllowedException('Duplicate request.', 200, $previous);

        self::assertSame('Duplicate request.', $exception->getMessage());
        self::assertSame(200, $exception->getCode());
        self::assertSame($previous, $exception->getPrevious());
    }
}

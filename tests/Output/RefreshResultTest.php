<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Tests\Output;

use PayplugUnifiedCore\Output\RefreshResult;
use PHPUnit\Framework\TestCase;

final class RefreshResultTest extends TestCase
{
    public function testUpdatedHasUpdatedStatusAndNoError(): void
    {
        $result = RefreshResult::updated();

        self::assertSame(RefreshResult::STATUS_UPDATED, $result->status);
        self::assertNull($result->error);
        self::assertFalse($result->isFailed());
    }

    public function testUnchangedHasUnchangedStatusAndNoError(): void
    {
        $result = RefreshResult::unchanged();

        self::assertSame(RefreshResult::STATUS_UNCHANGED, $result->status);
        self::assertNull($result->error);
        self::assertFalse($result->isFailed());
    }

    public function testFailedCarriesTheMessage(): void
    {
        $result = RefreshResult::failed('boom');

        self::assertSame(RefreshResult::STATUS_FAILED, $result->status);
        self::assertSame('boom', $result->error);
        self::assertTrue($result->isFailed());
    }

    public function testConstantsAreTheDocumentedStrings(): void
    {
        // @phpstan-ignore-next-line staticMethod.alreadyNarrowedType
        self::assertSame('updated', RefreshResult::STATUS_UPDATED);
        // @phpstan-ignore-next-line staticMethod.alreadyNarrowedType
        self::assertSame('unchanged', RefreshResult::STATUS_UNCHANGED);
        // @phpstan-ignore-next-line staticMethod.alreadyNarrowedType
        self::assertSame('failed', RefreshResult::STATUS_FAILED);
    }
}

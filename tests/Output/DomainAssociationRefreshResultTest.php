<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Tests\Output;

use PayplugUnifiedCore\Output\DomainAssociationRefreshResult;
use PHPUnit\Framework\TestCase;

final class DomainAssociationRefreshResultTest extends TestCase
{
    public function testUpdatedHasUpdatedStatusAndNoError(): void
    {
        $result = DomainAssociationRefreshResult::updated();

        self::assertSame(DomainAssociationRefreshResult::STATUS_UPDATED, $result->status);
        self::assertNull($result->error);
        self::assertFalse($result->isFailed());
    }

    public function testUnchangedHasUnchangedStatusAndNoError(): void
    {
        $result = DomainAssociationRefreshResult::unchanged();

        self::assertSame(DomainAssociationRefreshResult::STATUS_UNCHANGED, $result->status);
        self::assertNull($result->error);
        self::assertFalse($result->isFailed());
    }

    public function testFailedCarriesTheMessage(): void
    {
        $result = DomainAssociationRefreshResult::failed('boom');

        self::assertSame(DomainAssociationRefreshResult::STATUS_FAILED, $result->status);
        self::assertSame('boom', $result->error);
        self::assertTrue($result->isFailed());
    }

    public function testConstantsAreTheDocumentedStrings(): void
    {
        // @phpstan-ignore-next-line staticMethod.alreadyNarrowedType
        self::assertSame('updated', DomainAssociationRefreshResult::STATUS_UPDATED);
        // @phpstan-ignore-next-line staticMethod.alreadyNarrowedType
        self::assertSame('unchanged', DomainAssociationRefreshResult::STATUS_UNCHANGED);
        // @phpstan-ignore-next-line staticMethod.alreadyNarrowedType
        self::assertSame('failed', DomainAssociationRefreshResult::STATUS_FAILED);
    }
}

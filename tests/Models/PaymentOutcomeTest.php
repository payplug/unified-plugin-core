<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Tests\Models;

use PayplugUnifiedCore\Models\PaymentOutcome;
use PHPUnit\Framework\TestCase;

final class PaymentOutcomeTest extends TestCase
{
    public function testConstantValues(): void
    {
        // @phpstan-ignore-next-line staticMethod.alreadyNarrowedType
        self::assertSame('paid', PaymentOutcome::PAID);
        // @phpstan-ignore-next-line staticMethod.alreadyNarrowedType
        self::assertSame('authorized', PaymentOutcome::AUTHORIZED);
        // @phpstan-ignore-next-line staticMethod.alreadyNarrowedType
        self::assertSame('capture_required', PaymentOutcome::CAPTURE_REQUIRED);
        // @phpstan-ignore-next-line staticMethod.alreadyNarrowedType
        self::assertSame('three_ds_pending', PaymentOutcome::THREE_DS_PENDING);
        // @phpstan-ignore-next-line staticMethod.alreadyNarrowedType
        self::assertSame('refunded', PaymentOutcome::REFUNDED);
        // @phpstan-ignore-next-line staticMethod.alreadyNarrowedType
        self::assertSame('failed', PaymentOutcome::FAILED);
    }

    /**
     * @dataProvider validOutcomeProvider
     */
    public function testIsValidReturnsTrueForKnownOutcomes(string $outcome): void
    {
        self::assertTrue(PaymentOutcome::isValid($outcome));
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function validOutcomeProvider(): array
    {
        return [
            'paid' => [PaymentOutcome::PAID],
            'authorized' => [PaymentOutcome::AUTHORIZED],
            'capture_required' => [PaymentOutcome::CAPTURE_REQUIRED],
            'three_ds_pending' => [PaymentOutcome::THREE_DS_PENDING],
            'refunded' => [PaymentOutcome::REFUNDED],
            'failed' => [PaymentOutcome::FAILED],
        ];
    }

    public function testIsValidReturnsFalseForUnknownOutcome(): void
    {
        self::assertFalse(PaymentOutcome::isValid('bogus_outcome'));
    }
}

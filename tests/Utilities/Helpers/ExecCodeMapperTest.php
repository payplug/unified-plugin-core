<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Tests\Utilities\Helpers;

use PayplugUnifiedCore\DataValues\PaymentOutcome;
use PayplugUnifiedCore\Utilities\Helpers\ExecCodeMapper;
use PHPUnit\Framework\TestCase;

final class ExecCodeMapperTest extends TestCase
{
    public function testToPaymentOutcomeMapsSuccessCodeToPaid(): void
    {
        self::assertSame(PaymentOutcome::PAID, ExecCodeMapper::toPaymentOutcome('0000'));
    }

    public function testToPaymentOutcomeMapsPendingThreeDsCodeToThreeDsPending(): void
    {
        self::assertSame(PaymentOutcome::THREE_DS_PENDING, ExecCodeMapper::toPaymentOutcome('0001'));
    }

    /**
     * @dataProvider failureExecCodeProvider
     */
    public function testToPaymentOutcomeMapsNonSuccessCodesToFailed(string $execCode): void
    {
        self::assertSame(PaymentOutcome::FAILED, ExecCodeMapper::toPaymentOutcome($execCode));
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function failureExecCodeProvider(): array
    {
        return [
            'bank refused the transaction' => ['4001'],
            '3D secure authentication failed' => ['4008'],
            'exchange protocol failure' => ['5001'],
            'declined by the merchant' => ['6001'],
            'unknown/future exec code' => ['9999'],
            'empty string' => [''],
        ];
    }
}

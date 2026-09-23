<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Tests\Output;

use PayplugUnifiedCore\Output\PaymentOutput;
use PHPUnit\Framework\TestCase;

final class PaymentOutputTest extends TestCase
{
    public function testConstructorAssignsAllProperties(): void
    {
        $output = new PaymentOutput(200, '{"id":"pay_123"}', 'https://3ds.example.com/challenge', '<html>challenge</html>', 'alias_789');

        self::assertSame(200, $output->status);
        self::assertSame('{"id":"pay_123"}', $output->body);
        self::assertSame('https://3ds.example.com/challenge', $output->redirectUrl);
        self::assertSame('<html>challenge</html>', $output->redirectHtml);
        self::assertSame('alias_789', $output->aliasId);
    }

    public function testConstructorAllowsNullRedirectUrlAndRedirectHtmlAndAliasId(): void
    {
        $output = new PaymentOutput(200, '{"id":"pay_123"}', null, null, null);

        self::assertNull($output->redirectUrl);
        self::assertNull($output->redirectHtml);
        self::assertNull($output->aliasId);
    }

    public function testConstructorDefaultsMaxCaptureDateAndRemainingCapturableAmountToNull(): void
    {
        $output = new PaymentOutput(200, '{"id":"pay_123"}', null, null, null);

        self::assertNull($output->maxCaptureDate);
        self::assertNull($output->remainingCapturableAmount);
    }

    public function testConstructorAssignsMaxCaptureDateAndRemainingCapturableAmountWhenProvided(): void
    {
        $output = new PaymentOutput(200, '{"id":"pay_123"}', null, null, null, '2026-09-25T12:00:00Z', 700);

        self::assertSame('2026-09-25T12:00:00Z', $output->maxCaptureDate);
        self::assertSame(700, $output->remainingCapturableAmount);
    }
}

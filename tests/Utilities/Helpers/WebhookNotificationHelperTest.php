<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Tests\Utilities\Helpers;

use PayplugUnifiedCore\Exceptions\InvalidNotificationException;
use PayplugUnifiedCore\Utilities\Helpers\WebhookNotificationHelper;
use PHPUnit\Framework\TestCase;

final class WebhookNotificationHelperTest extends TestCase
{
    public function testVerifySignaturePassesWhenAuthorizationHeaderMatches(): void
    {
        WebhookNotificationHelper::verifySignature(['Authorization' => 'Bearer secret123'], 'Bearer secret123');

        $this->expectNotToPerformAssertions();
    }

    public function testVerifySignatureIsCaseInsensitiveOnTheHeaderName(): void
    {
        WebhookNotificationHelper::verifySignature(['authorization' => 'Bearer secret123'], 'Bearer secret123');

        $this->expectNotToPerformAssertions();
    }

    public function testVerifySignatureThrowsWhenAuthorizationHeaderIsMissing(): void
    {
        $this->expectException(InvalidNotificationException::class);
        $this->expectExceptionMessage('Webhook notification is missing the Authorization header.');

        WebhookNotificationHelper::verifySignature(['Content-Type' => 'application/json'], 'Bearer secret123');
    }

    public function testVerifySignatureThrowsWhenAuthorizationHeaderDoesNotMatch(): void
    {
        $this->expectException(InvalidNotificationException::class);
        $this->expectExceptionMessage('Webhook notification signature does not match.');

        WebhookNotificationHelper::verifySignature(['Authorization' => 'Bearer wrong'], 'Bearer secret123');
    }
}

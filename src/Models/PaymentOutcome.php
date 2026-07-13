<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Models;

/**
 * Expresses UPC's payment result intent to the CMS, decoupled from any CMS's native
 * order-status vocabulary. PHP 8.1 enum syntax is excluded by this library's PHP 7.1
 * floor, so this uses class constants instead.
 *
 * Example:
 * <code>
 * if ($operationData->outcome === PaymentOutcome::PAID) {
 *     $order->setStateToPaid();
 * }
 * </code>
 */
final class PaymentOutcome
{
    public const PAID = 'paid';
    public const AUTHORIZED = 'authorized';
    public const CAPTURE_REQUIRED = 'capture_required';
    public const THREE_DS_PENDING = 'three_ds_pending';
    public const REFUNDED = 'refunded';
    public const FAILED = 'failed';

    private const ALL = [
        self::PAID,
        self::AUTHORIZED,
        self::CAPTURE_REQUIRED,
        self::THREE_DS_PENDING,
        self::REFUNDED,
        self::FAILED,
    ];

    /**
     * @codeCoverageIgnore
     */
    private function __construct()
    {
    }

    public static function isValid(string $value): bool
    {
        return \in_array($value, self::ALL, true);
    }
}

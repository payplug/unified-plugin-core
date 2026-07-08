<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Utilities\Helpers;

final class AmountHelper
{
    /**
     * @codeCoverageIgnore
     */
    private function __construct()
    {
    }

    /**
     * Converts a major-unit amount (e.g. a CMS cart or order total) into the integer
     * number of cents expected by the Payplug API.
     *
     * Example:
     * <code>
     * $amountInCents = AmountHelper::toCents($cart->getTotalPaid()); // 49.99 => 4999
     * </code>
     */
    public static function toCents(float $amount): int
    {
        return (int) round($amount * 100, 0, PHP_ROUND_HALF_UP);
    }

    /**
     * Converts an integer number of cents (e.g. an amount returned by the Payplug API)
     * back into a major-unit float amount for display in the Plugin.
     *
     * Example:
     * <code>
     * $displayAmount = AmountHelper::fromCents($payment->amount); // 4999 => 49.99
     * </code>
     */
    public static function fromCents(int $cents): float
    {
        return $cents / 100;
    }
}

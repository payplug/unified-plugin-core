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
     * $mode only affects genuinely ambiguous amounts — a fraction landing exactly on a
     * half-cent boundary (e.g. 19.995). It has no effect on amounts already decided to
     * 2 decimals (or on non-ambiguous multi-decimal results), since those round the
     * same way under every mode. Pass the CMS's own configured rounding preference
     * (e.g. PrestaShop's merchant-configurable round mode) here instead of pre-rounding
     * the amount yourself, so this helper is the single place that decision is applied.
     *
     * Example:
     * <code>
     * $amountInCents = AmountHelper::toCents($cart->getTotalPaid()); // 49.99 => 4999
     * $amountInCents = AmountHelper::toCents($cart->getTotalPaid(), PHP_ROUND_HALF_EVEN);
     * </code>
     *
     * @param float $amount
     * @param 1|2|3|4 $mode one of the PHP_ROUND_HALF_* constants
     * @return int
     */
    public static function toCents(float $amount, int $mode = PHP_ROUND_HALF_UP): int
    {
        return (int) round($amount * 100, 0, $mode);
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

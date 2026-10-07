<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Utilities\Helpers;

use PayplugUnifiedCore\Exceptions\InvalidCurrencyException;

/**
 * Converts between a major-unit amount and the integer number of minor units of the currency
 * expected by the Unified API. The number of minor units per major unit depends on the currency:
 * 100 for a 2-decimal currency (EUR, USD, ...), 1 for a zero-decimal one (JPY, KRW, ...), whose
 * amount is sent as is.
 *
 * The currency is an ISO 4217 alpha-3 code, compared case-insensitively after trimming. An empty
 * or malformed code (anything other than exactly 3 ASCII letters) throws InvalidCurrencyException.
 * A 3-decimal currency (BHD, IQD, JOD, KWD, LYD, OMR, TND) is not supported and throws
 * InvalidCurrencyException rather than being converted with a wrong factor. Any other well-formed
 * code that is not in ZERO_DECIMAL_CURRENCIES is treated as 2-decimal.
 */
final class AmountHelper
{
    /**
     * Currencies with an ISO 4217 minor-unit exponent of 0. The currencies the Unified API accepts,
     * and whether it expects each of these amounts as is, are still to be confirmed with the
     * Unified API team.
     */
    private const ZERO_DECIMAL_CURRENCIES = [
        'BIF',
        'CLP',
        'DJF',
        'GNF',
        'ISK',
        'JPY',
        'KMF',
        'KRW',
        'PYG',
        'RWF',
        'UGX',
        'VND',
        'VUV',
        'XAF',
        'XOF',
        'XPF',
    ];

    /**
     * Currencies with an ISO 4217 minor-unit exponent of 3. Rejected rather than converted: the
     * 2-decimal factor would be off by a factor of 10. Supporting them later is a matter of
     * moving a code out of this list and giving it a factor of 1000.
     */
    private const THREE_DECIMAL_CURRENCIES = ['BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND'];

    /**
     * @codeCoverageIgnore
     */
    private function __construct()
    {
    }

    /**
     * Converts a major-unit amount (e.g. a CMS cart or order total) into the integer
     * number of minor units of the currency expected by the Unified API.
     *
     * $mode only affects genuinely ambiguous amounts — a fraction landing exactly on a
     * half-minor-unit boundary (e.g. 19.995 EUR, or 1000.5 JPY). It has no effect on amounts
     * already decided to the currency's own number of decimals, since those round the
     * same way under every mode. Pass the CMS's own configured rounding preference
     * (e.g. PrestaShop's merchant-configurable round mode) here instead of pre-rounding
     * the amount yourself, so this helper is the single place that decision is applied.
     *
     * Example:
     * <code>
     * $amount = AmountHelper::toCents(19.99, 'EUR'); // 1999
     * $amount = AmountHelper::toCents(1000.0, 'JPY'); // 1000
     * $amount = AmountHelper::toCents($order->total_paid, $currency->iso_code, (int) Configuration::get('PS_ROUND_MODE'));
     * </code>
     *
     * @param float $amount
     * @param string $currency ISO 4217 alpha-3 code of the amount's currency
     * @param 1|2|3|4 $mode one of the PHP_ROUND_HALF_* constants
     * @return int
     * @throws InvalidCurrencyException if $currency is empty, not a 3-letter code, or a 3-decimal currency
     */
    public static function toCents(float $amount, string $currency, int $mode = PHP_ROUND_HALF_UP): int
    {
        return (int) round($amount * self::factorFor($currency), 0, $mode);
    }

    /**
     * Converts an integer number of minor units of the currency (e.g. an amount returned by the
     * Unified API) back into a major-unit float amount for display in the Plugin.
     *
     * Example:
     * <code>
     * $displayAmount = AmountHelper::fromCents(4999, 'EUR'); // 49.99
     * $displayAmount = AmountHelper::fromCents(1000, 'JPY'); // 1000.0
     * </code>
     *
     * @param string $currency ISO 4217 alpha-3 code of the amount's currency
     * @throws InvalidCurrencyException if $currency is empty, not a 3-letter code, or a 3-decimal currency
     */
    public static function fromCents(int $cents, string $currency): float
    {
        return $cents / self::factorFor($currency);
    }

    /**
     * @throws InvalidCurrencyException
     */
    private static function factorFor(string $currency): int
    {
        $code = strtoupper(trim($currency));

        Assert::notEmpty($code, 'currency', InvalidCurrencyException::class);

        if (preg_match('/\A[A-Z]{3}\z/', $code) !== 1) {
            throw new InvalidCurrencyException(\sprintf("currency must be a 3-letter ISO 4217 code, got '%s'.", $currency));
        }

        if (\in_array($code, self::THREE_DECIMAL_CURRENCIES, true)) {
            throw new InvalidCurrencyException(\sprintf("currency '%s' is a 3-decimal currency and is not supported.", $code));
        }

        return \in_array($code, self::ZERO_DECIMAL_CURRENCIES, true) ? 1 : 100;
    }
}

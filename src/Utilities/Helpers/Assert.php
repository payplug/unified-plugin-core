<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Utilities\Helpers;

use PayplugUnifiedCore\Exceptions\PayplugException;

/**
 * Shared "field must not be empty / must not be negative / must be positive" checks, factored out
 * of CommonFieldsDtoValidator/OperationData/TokenOutput once all three had independently
 * hand-rolled the identical `if ($value === '') { throw ... }` / `if ($value < 0) { throw ... }`
 * pattern with only the field name and exception class differing. Each caller supplies its own
 * exception class so the thrown type still matches that caller's existing contract exactly.
 *
 * Example:
 * <code>
 * Assert::notEmpty($dto->accountId, 'accountId', InvalidCommonFieldsException::class);
 * Assert::notNegative($dto->amount, 'amount', InvalidCommonFieldsException::class);
 * </code>
 */
final class Assert
{
    /**
     * @codeCoverageIgnore
     */
    private function __construct()
    {
    }

    /**
     * @param class-string<PayplugException> $exceptionClass
     * @throws PayplugException if $value is an empty string
     */
    public static function notEmpty(string $value, string $fieldName, string $exceptionClass): void
    {
        if ($value === '') {
            throw new $exceptionClass($fieldName . ' must not be empty.');
        }
    }

    /**
     * @param class-string<PayplugException> $exceptionClass
     * @throws PayplugException if $value is negative
     */
    public static function notNegative(int $value, string $fieldName, string $exceptionClass): void
    {
        if ($value < 0) {
            throw new $exceptionClass($fieldName . ' must not be negative.');
        }
    }

    /**
     * @param class-string<PayplugException> $exceptionClass
     * @throws PayplugException if $value is zero or negative
     */
    public static function positive(int $value, string $fieldName, string $exceptionClass): void
    {
        if ($value <= 0) {
            throw new $exceptionClass($fieldName . ' must be greater than zero.');
        }
    }
}

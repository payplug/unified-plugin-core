<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\DataValues;

/**
 * Unified API's "operation.authorizationType" values for an authorization-only creation
 * (capture === false). The API accepts either casing; these constants use lower snake_case as
 * the canonical form. PRE_AUTHORIZATION: hold requiring a later final authorization/capture.
 * FINAL_AUTHORIZATION: hold on the definitive amount, awaiting only capture.
 */
final class AuthorizationType
{
    public const PRE_AUTHORIZATION = 'pre_authorization';
    public const FINAL_AUTHORIZATION = 'final_authorization';

    private const ALL = [
        self::PRE_AUTHORIZATION,
        self::FINAL_AUTHORIZATION,
    ];

    /**
     * @codeCoverageIgnore
     */
    private function __construct()
    {
    }

    public static function isValid(string $value): bool
    {
        return \in_array(strtolower($value), self::ALL, true);
    }
}

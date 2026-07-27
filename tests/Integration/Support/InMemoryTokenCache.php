<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Tests\Integration\Support;

use PayplugUnifiedCore\Contracts\ITokenCache;

/**
 * Minimal in-process ITokenCache for tests/Integration/ — a single test run never needs real
 * expiry or persistence across processes.
 */
final class InMemoryTokenCache implements ITokenCache
{
    /** @var array<string, string> */
    private $values = [];

    public function get(string $key): ?string
    {
        return $this->values[$key] ?? null;
    }

    public function set(string $key, string $value, int $ttlSeconds): void
    {
        $this->values[$key] = $value;
    }

    public function delete(string $key): void
    {
        unset($this->values[$key]);
    }
}

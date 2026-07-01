<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Tests;

use PHPUnit\Framework\TestCase;

final class ScaffoldingTest extends TestCase
{
    public function testRuntimeMeetsMinimumPhpVersionConstraint(): void
    {
        self::assertGreaterThanOrEqual(70100, PHP_VERSION_ID);
    }
}

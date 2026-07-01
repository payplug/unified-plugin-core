<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Tests;

use PHPUnit\Framework\TestCase;

final class ScaffoldingTest extends TestCase
{
    public function testRuntimeMeetsMinimumPhpVersionConstraint(): void
    {
        self::assertTrue(version_compare(PHP_VERSION, '7.1.0', '>='));
    }
}

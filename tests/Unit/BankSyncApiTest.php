<?php

/* SPDX-License-Identifier: GPL-3.0-or-later */

use PHPUnit\Framework\TestCase;

/**
 * The API class needs Dolibarr to load, so its source is checked instead.
 */
final class BankSyncApiTest extends TestCase
{
    /**
     * Restler only demands authentication for an API class tagged @access protected.
     */
    public function testApiClassRequiresAuthentication(): void
    {
        self::assertMatchesRegularExpression('/\*\s+@access protected\s*\n(?:\s*\*.*\n)*?\s*\*\s+@class\s+DolibarrApiAccess/', self::source());
    }

    /**
     * A public method without @url would be exposed under a route Restler derives on its own.
     */
    public function testEveryPublicMethodHasARoute(): void
    {
        preg_match_all('/(\/\*\*(?:(?!\*\/).)*\*\/)\s*public function (\w+)\(/s', self::source(), $methods, PREG_SET_ORDER);
        $checked = 0;
        foreach ($methods as [, $docblock, $name]) {
            if ('__construct' === $name) {
                continue;
            }
            self::assertStringContainsString('@url ', $docblock, $name.' has no @url');
            ++$checked;
        }
        self::assertGreaterThanOrEqual(5, $checked);
        self::assertSame($checked, preg_match_all('/public function (?!__construct)\w+\(/', self::source()), 'a public method lacks a docblock');
    }

    private static function source(): string
    {
        return (string) file_get_contents(__DIR__.'/../../class/api_banksync.class.php');
    }
}

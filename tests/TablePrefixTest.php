<?php

declare(strict_types=1);

namespace Crownd\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Every table has the prefix crownd_, so Crownd can share a database with other
 * applications (common on shared hosting with only one database).
 */
final class TablePrefixTest extends TestCase
{
    public function testAllTablesArePrefixed(): void
    {
        $root = dirname(__DIR__);
        $files = array_merge(
            glob($root . '/migrations/*.sql') ?: [],
            glob($root . '/src/*.php') ?: [],
            glob($root . '/src/*/*.php') ?: [],
        );
        $unprefixed = [];
        foreach ($files as $file) {
            $code = (string) file_get_contents($file);
            preg_match_all('/\b(?:FROM|JOIN|INTO|TABLE|REFERENCES|EXISTS)\s+`?([a-z_][a-z0-9_.]*)/', $code, $m);
            preg_match_all("/->(?:insert|update)\\(\\s*'([a-z_]+)'/", $code, $m2);
            preg_match_all('/\bUPDATE\s+`?([a-z_]+)`?\s+(?:SET|[a-z]+\s+SET)/', $code, $m3);
            foreach ([...$m[1], ...$m2[1], ...$m3[1]] as $table) {
                if (!str_starts_with($table, 'crownd_') && $table !== 'information_schema.tables') {
                    $unprefixed[] = basename($file) . ': ' . $table;
                }
            }
        }
        self::assertSame([], array_values(array_unique($unprefixed)));
    }
}

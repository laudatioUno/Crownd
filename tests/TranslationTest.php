<?php

declare(strict_types=1);

namespace Crownd\Tests;

use Crownd\I18n\Translator;
use PHPUnit\Framework\TestCase;

/** Every literal t('…') key used in templates and PHP code must exist in German. */
final class TranslationTest extends TestCase
{
    public function testAllKeysExist(): void
    {
        $root = dirname(__DIR__);
        $tr = new Translator($root . '/lang', 'de');
        $files = array_merge(
            glob($root . '/templates/*.twig') ?: [],
            glob($root . '/templates/*/*.twig') ?: [],
            glob($root . '/templates/*/*/*.twig') ?: [],
            glob($root . '/src/*/*.php') ?: [],
        );
        $missing = [];
        foreach ($files as $file) {
            $code = (string) file_get_contents($file);
            preg_match_all("/(?:\\bt|flash\\([^,]+,)\\(?\\s*'([a-z_]+\\.[a-z0-9_.]+)'/", $code, $m);
            preg_match_all("/'((?:validation|upload|auth|vote|matches|players|teams|squads|users|settings|install)\\.[a-z0-9_.]+)'/", $code, $m2);
            foreach (array_unique([...$m[1], ...$m2[1]]) as $key) {
                if (!$tr->has($key) && !str_ends_with($key, '.') && !str_ends_with($key, '_')) {
                    $missing[] = basename($file) . ': ' . $key;
                }
            }
        }
        self::assertSame([], array_values(array_unique($missing)));
    }
}

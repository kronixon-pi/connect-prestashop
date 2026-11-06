<?php

declare(strict_types=1);

namespace Kronixon\Connect\Tests\Unit\Install;

use PHPUnit\Framework\TestCase;

/**
 * translations/{bg,de}.php (PrestaShop's legacy module translations, keyed by source file and md5 of
 * the English string) hold exactly the strings of kronixonconnect.php and the settings template.
 */
final class TranslationsTest extends TestCase
{
    private const ROOT = __DIR__.'/../../..';

    /**
     * @return iterable<string, array{string}>
     */
    public function languages(): iterable
    {
        yield 'bg' => ['bg'];
        yield 'de' => ['de'];
    }

    /**
     * @dataProvider languages
     */
    public function test_every_string_is_translated_and_no_key_is_stale(string $iso): void
    {
        $_MODULE = [];
        include self::ROOT.'/translations/'.$iso.'.php';
        $expected = [];
        foreach ($this->sourceStrings() as $key => $english) {
            $expected[] = '<{kronixonconnect}prestashop>'.$key;
            self::assertArrayHasKey('<{kronixonconnect}prestashop>'.$key, $_MODULE, $iso.': '.$english);
            self::assertSame(substr_count($english, '%s'), substr_count((string) $_MODULE['<{kronixonconnect}prestashop>'.$key], '%s'), $iso.' placeholders: '.$english);
        }
        $stale = array_diff(array_keys($_MODULE), $expected);

        self::assertSame([], array_values($stale), 'keys without a source string');
    }

    /**
     * @return array<string, string> source_md5 => English string
     */
    private function sourceStrings(): array
    {
        $strings = [];
        preg_match_all("/->l\\('((?:[^'\\\\]|\\\\.)*)'\\)/", (string) file_get_contents(self::ROOT.'/kronixonconnect.php'), $m);
        foreach ($m[1] as $english) {
            $strings['kronixonconnect_'.md5($english)] = $english;
        }
        preg_match_all("/\\{l s='((?:[^'\\\\]|\\\\.)*)' mod='kronixonconnect'/", (string) file_get_contents(self::ROOT.'/views/templates/admin/configure.tpl'), $m);
        foreach ($m[1] as $english) {
            $strings['configure_'.md5($english)] = $english;
        }
        self::assertGreaterThan(40, count($strings));

        return $strings;
    }
}

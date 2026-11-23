<?php

declare(strict_types=1);

namespace Kronixon\Connect\Tests\Unit\Release;

use PHPUnit\Framework\TestCase;

/**
 * Every `upgrade/upgrade-<version>.php` may run more than once (a killed worker, docs/RELEASING.md "Upgrade
 * scripts"), and a rollback leaves its DB changes behind: a DDL statement that fails on its second run or that
 * cannot be run twice needs a guard. A grep, not a proof: it catches the usual mistakes before a release.
 */
final class UpgradeScriptsTest extends TestCase
{
    public function test_every_upgrade_script_guards_its_ddl(): void
    {
        $scripts = glob(dirname(__DIR__, 3).'/upgrade/upgrade-*.php') ?: [];
        self::assertNotEmpty($scripts);
        foreach ($scripts as $script) {
            self::assertSame([], self::problems((string) file_get_contents($script)), basename($script));
        }
    }

    /**
     * @return iterable<string, array{0: string, 1: list<string>}>
     */
    public static function samples(): iterable
    {
        yield 'create table' => ["execute('CREATE TABLE `'._DB_PREFIX_.'kx_x` (id INT)')", ['CREATE TABLE without IF NOT EXISTS']];
        yield 'create table if not exists' => ["execute('CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.'kx_x` (id INT)')", []];
        yield 'drop table' => ["execute('DROP TABLE `'._DB_PREFIX_.'kx_x`')", ['DROP TABLE without IF EXISTS']];
        yield 'drop table if exists' => ["execute('drop table if exists `kx_x`')", []];
        yield 'add column' => ["execute('ALTER TABLE `'._DB_PREFIX_.'kx_x` ADD COLUMN `y` INT')", ['ALTER TABLE … ADD/DROP/CHANGE/RENAME without an existence check']];
        yield 'add without COLUMN' => ["execute('ALTER TABLE `kx_x` ADD `y` INT')", ['ALTER TABLE … ADD/DROP/CHANGE/RENAME without an existence check']];
        yield 'add index' => ["execute('ALTER TABLE `kx_x`\n    ADD INDEX `i` (`y`)')", ['ALTER TABLE … ADD/DROP/CHANGE/RENAME without an existence check']];
        yield 'drop column' => ["execute('ALTER TABLE `kx_x` DROP COLUMN `y`')", ['ALTER TABLE … ADD/DROP/CHANGE/RENAME without an existence check']];
        yield 'add column after SHOW COLUMNS' => ["if (\$db->executeS(\"SHOW COLUMNS FROM `kx_x` LIKE 'y'\") === []) { execute('ALTER TABLE `kx_x` ADD COLUMN `y` INT'); }", []];
        yield 'add index after information_schema' => ["\$db->getValue('SELECT 1 FROM information_schema.statistics …'); execute('ALTER TABLE `kx_x` ADD INDEX `i` (`y`)')", []];
        yield 'modify is idempotent' => ["execute('ALTER TABLE `kx_x` MODIFY `y` VARBINARY(764) NOT NULL')", []];
    }

    /**
     * @dataProvider samples
     *
     * @param  list<string>  $expected
     */
    public function test_the_check_finds_unguarded_ddl(string $code, array $expected): void
    {
        self::assertSame($expected, self::problems($code));
    }

    /**
     * @return list<string>
     */
    private static function problems(string $code): array
    {
        $problems = [];
        if (preg_match('/\bCREATE\s+TABLE\b(?!\s+IF\s+NOT\s+EXISTS\b)/i', $code) === 1) {
            $problems[] = 'CREATE TABLE without IF NOT EXISTS';
        }
        if (preg_match('/\bDROP\s+TABLE\b(?!\s+IF\s+EXISTS\b)/i', $code) === 1) {
            $problems[] = 'DROP TABLE without IF EXISTS';
        }
        $altersShape = preg_match('/\bALTER\s+TABLE\b[^;]{0,200}?\s(ADD|DROP|CHANGE|RENAME)\s/is', $code) === 1;
        if ($altersShape && preg_match('/\bSHOW\s+(COLUMNS|INDEX|KEYS)\b|\binformation_schema\b/i', $code) !== 1) {
            $problems[] = 'ALTER TABLE … ADD/DROP/CHANGE/RENAME without an existence check';
        }

        return $problems;
    }
}

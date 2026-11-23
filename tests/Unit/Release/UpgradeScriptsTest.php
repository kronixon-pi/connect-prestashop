<?php

declare(strict_types=1);

namespace Kronixon\Connect\Tests\Unit\Release;

use PHPUnit\Framework\TestCase;

/**
 * Every `upgrade/upgrade-<version>.php` may run more than once (a killed worker, docs/RELEASING.md "Upgrade
 * scripts"), and a rollback leaves its DB changes behind: a DDL statement that fails on its second run needs a guard.
 *
 * A grep, not a proof: it catches the usual mistakes before a release, the review of a new script checks the rest.
 * - `CREATE TABLE` needs `IF NOT EXISTS`, `DROP TABLE` needs `IF EXISTS`.
 * - `ALTER TABLE … ADD/DROP/CHANGE/RENAME`, `CREATE INDEX`, `DROP INDEX` and `RENAME TABLE` each need their own
 *   existence check (`SHOW COLUMNS/INDEX/KEYS/TABLES`, `information_schema`) shortly before them and after the
 *   previous such statement: one check does not cover the next statement. MariaDB's `ADD COLUMN IF NOT EXISTS` and
 *   `CREATE INDEX IF NOT EXISTS` do not count, MySQL refuses them.
 * - Whether the check's answer really decides the statement is not looked at.
 * - DML (`INSERT`, `UPDATE … SET x = x * 100`, a Configuration value computed from itself) is out of scope.
 * - Not seen: a statement built by string concatenation (`'ALTER TABLE x '.'ADD y'`), and an `ADD/DROP/CHANGE/RENAME`
 *   more than 200 characters after its `ALTER TABLE`.
 */
final class UpgradeScriptsTest extends TestCase
{
    /** How far before a statement its existence check may start (a few lines of code). */
    private const GUARD_WINDOW = 600;

    private const GUARD = '/\bSHOW\s+(?:FULL\s+)?(?:COLUMNS|FIELDS|INDEX|INDEXES|KEYS|TABLES)\b|\binformation_schema\b/i';

    private const NEEDS_GUARD = '/(?<alter>\bALTER\s+TABLE\b[^;]{0,200}?\s(?:ADD|DROP|CHANGE|RENAME)\s)'
        .'|(?<index>\b(?:CREATE\s+(?:UNIQUE\s+|FULLTEXT\s+|SPATIAL\s+)?|DROP\s+)INDEX\b)'
        .'|(?<rename>\bRENAME\s+TABLE\b)/is';

    private const ALTER = 'ALTER TABLE … ADD/DROP/CHANGE/RENAME without an existence check';

    private const INDEX = 'CREATE/DROP INDEX without an existence check';

    private const RENAME = 'RENAME TABLE without an existence check';

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
        $showY = "if (\$db->executeS(\"SHOW COLUMNS FROM `kx_x` LIKE 'y'\") === []) {\n    ";
        $showZ = "if (\$db->executeS(\"SHOW COLUMNS FROM `kx_x` LIKE 'z'\") === []) {\n    ";
        yield 'create table' => ["execute('CREATE TABLE `'._DB_PREFIX_.'kx_x` (id INT)')", ['CREATE TABLE without IF NOT EXISTS']];
        yield 'create table if not exists' => ["execute('CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.'kx_x` (id INT)')", []];
        yield 'drop table' => ["execute('DROP TABLE `'._DB_PREFIX_.'kx_x`')", ['DROP TABLE without IF EXISTS']];
        yield 'drop table if exists' => ["execute('drop table if exists `kx_x`')", []];
        yield 'add column' => ["execute('ALTER TABLE `'._DB_PREFIX_.'kx_x` ADD COLUMN `y` INT')", [self::ALTER]];
        yield 'add without COLUMN' => ["execute('ALTER TABLE `kx_x` ADD `y` INT')", [self::ALTER]];
        yield 'add column if not exists (MariaDB only)' => ["execute('ALTER TABLE `kx_x` ADD COLUMN IF NOT EXISTS `y` INT')", [self::ALTER]];
        yield 'add index' => ["execute('ALTER TABLE `kx_x`\n    ADD INDEX `i` (`y`)')", [self::ALTER]];
        yield 'drop column' => ["execute('ALTER TABLE `kx_x` DROP COLUMN `y`')", [self::ALTER]];
        yield 'rename in alter' => ["execute('ALTER TABLE `kx_x` RENAME TO `kx_y`')", [self::ALTER]];
        yield 'add column after SHOW COLUMNS' => [$showY."execute('ALTER TABLE `kx_x` ADD COLUMN `y` INT');\n}", []];
        yield 'add index after information_schema' => ["\$db->getValue('SELECT 1 FROM information_schema.statistics …'); execute('ALTER TABLE `kx_x` ADD INDEX `i` (`y`)')", []];
        yield 'modify is idempotent' => ["execute('ALTER TABLE `kx_x` MODIFY `y` VARBINARY(764) NOT NULL')", []];
        yield 'one guarded add, one not' => [$showY."\$db->execute('ALTER TABLE `kx_x` ADD COLUMN `y` INT');\n}\n\$db->execute('ALTER TABLE `kx_x` ADD COLUMN `z` INT');", [self::ALTER]];
        yield 'two adds, each guarded' => [$showY."\$db->execute('ALTER TABLE `kx_x` ADD COLUMN `y` INT');\n}\n".$showZ."\$db->execute('ALTER TABLE `kx_x` ADD COLUMN `z` INT');\n}", []];
        yield 'the check after the statement' => ["\$db->execute('ALTER TABLE `kx_x` ADD COLUMN `y` INT');\n\$db->executeS(\"SHOW COLUMNS FROM `kx_x` LIKE 'y'\");", [self::ALTER]];
        yield 'the check far away' => ["\$db->executeS(\"SHOW COLUMNS FROM `kx_x` LIKE 'y'\");\n".str_repeat("\$module->registerHook('actionSomething');\n", 20)."\$db->execute('ALTER TABLE `kx_x` ADD COLUMN `y` INT');", [self::ALTER]];
        yield 'create index' => ["\$db->execute('CREATE INDEX `i` ON `kx_x` (`y`)');", [self::INDEX]];
        yield 'create unique index' => ["\$db->execute('CREATE UNIQUE INDEX `i` ON `kx_x` (`y`)');", [self::INDEX]];
        yield 'create index if not exists (MariaDB only)' => ["\$db->execute('CREATE INDEX IF NOT EXISTS `i` ON `kx_x` (`y`)');", [self::INDEX]];
        yield 'create index after SHOW INDEX' => ["if (\$db->executeS(\"SHOW INDEX FROM `kx_x` WHERE Key_name = 'i'\") === []) {\n    \$db->execute('CREATE INDEX `i` ON `kx_x` (`y`)');\n}", []];
        yield 'drop index' => ["\$db->execute('DROP INDEX `i` ON `kx_x`');", [self::INDEX]];
        yield 'drop index in alter' => ["\$db->execute('ALTER TABLE `kx_x` DROP INDEX `i`');", [self::ALTER]];
        yield 'rename table' => ["\$db->execute('RENAME TABLE `kx_a` TO `kx_b`');", [self::RENAME]];
        yield 'rename table after SHOW TABLES' => ["if (\$db->executeS(\"SHOW TABLES LIKE 'kx_a'\") !== []) {\n    \$db->execute('RENAME TABLE `kx_a` TO `kx_b`');\n}", []];
        yield 'DML is out of scope' => ["\$db->execute('INSERT INTO `kx_map` (k, v) VALUES (1, 2)'); \$db->execute('UPDATE `kx_x` SET price = price * 100');", []];
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
        // Each statement looks for its check between the previous statement and itself, at most GUARD_WINDOW back.
        preg_match_all(self::NEEDS_GUARD, $code, $statements, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $previous = 0;
        foreach ($statements as $statement) {
            [$text, $at] = $statement[0];
            $from = max($previous, $at - self::GUARD_WINDOW);
            if (preg_match(self::GUARD, substr($code, $from, $at - $from)) !== 1) {
                if (($statement['alter'][1] ?? -1) >= 0) {
                    $problems[] = self::ALTER;
                } elseif (($statement['index'][1] ?? -1) >= 0) {
                    $problems[] = self::INDEX;
                } else {
                    $problems[] = self::RENAME;
                }
            }
            $previous = $at + strlen($text);
        }

        return array_values(array_unique($problems));
    }
}

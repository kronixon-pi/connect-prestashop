<?php

declare(strict_types=1);

namespace Kronixon\Connect\Tests\Unit\Install;

use Kronixon\Connect\Install\Installer;
use Kronixon\Connect\Install\Steps;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Installer::install() against an in-memory shop: the real sql/*.sql files run through a fake
 * executor that tracks the tables, and the module row is a list of names.
 */
final class InstallRollbackTest extends TestCase
{
    /** @var array<string, true> */
    private $tables = [];

    /** @var list<string> */
    private $moduleRows = [];

    /** @var string|null a CREATE of this table fails */
    private $failingTable;

    /** @var list<string> */
    private $log = [];

    public function test_a_failing_sql_step_leaves_no_module_row_and_no_table(): void
    {
        $this->failingTable = 'ps_kx_entity_map'; // the 4th of 7 CREATE statements

        $failed = $this->install($this->addModule());

        self::assertSame('tables', $failed, 'the failed step is named (the module shows it in _errors)');
        self::assertSame([], $this->moduleRows, 'no module row');
        self::assertSame([], array_keys($this->tables), 'no table');
        self::assertNotContains('module:add', $this->log, 'the module row is added only after the tables');
        self::assertContains('DROP ps_kx_nonce', $this->log, 'the tables created before the failure are dropped');
    }

    public function test_a_failing_module_install_drops_the_tables(): void
    {
        $failed = $this->install(function (): bool {
            $this->moduleRows[] = 'kronixonconnect'; // PrestaShop may fail after inserting the row

            return false;
        });

        self::assertSame('module', $failed);
        self::assertSame([], $this->moduleRows);
        self::assertSame([], array_keys($this->tables));
    }

    public function test_a_failing_later_step_also_removes_the_service_account(): void
    {
        // The configuration step needs PrestaShop's Configuration class, which is absent here: it
        // throws, so every earlier step and the account undo run (live check: scripts/ps-smoke.sh).
        $failed = $this->install($this->addModule());

        self::assertSame('configuration', $failed);
        self::assertSame([], $this->moduleRows);
        self::assertSame([], array_keys($this->tables));
        self::assertNotContains('account:create', $this->log, 'the account is created after the configuration');
    }

    public function test_a_successful_install_keeps_everything(): void
    {
        // writeDefaults() needs PrestaShop's Configuration, so the defaults step is checked live
        // (scripts/ps-smoke.sh); here the order up to the module row is what matters.
        $steps = [
            'tables' => [[$this->installer(), 'createTables'], [$this->installer(), 'dropTables']],
            'module' => [$this->addModule(), $this->removeModule()],
        ];

        self::assertNull(Steps::run($steps));
        self::assertSame(['kronixonconnect'], $this->moduleRows);
        self::assertCount(7, $this->tables);
        self::assertSame('CREATE ps_kx_nonce', $this->log[0]);
        self::assertSame('module:add', end($this->log));
    }

    public function test_steps_undo_in_reverse_order_and_survive_a_throwing_step(): void
    {
        $log = [];
        $step = static function (string $name, bool $ok) use (&$log): array {
            return [static function () use (&$log, $name, $ok): bool {
                $log[] = 'do '.$name;
                if (! $ok) {
                    throw new RuntimeException('step failed');
                }

                return true;
            }, static function () use (&$log, $name): bool {
                $log[] = 'undo '.$name;

                return true;
            }];
        };

        self::assertSame('c', Steps::run(['a' => $step('a', true), 'b' => $step('b', true), 'c' => $step('c', false), 'd' => $step('d', true)]));
        self::assertSame(['do a', 'do b', 'do c', 'undo c', 'undo b', 'undo a'], $log);
    }

    /** @param callable(): bool $addModule */
    private function install(callable $addModule): ?string
    {
        return $this->installer()->install($addModule, $this->removeModule(), $this->hooks(true), $this->hooks(true), function (): bool {
            $this->log[] = 'account:create';

            return true;
        }, function (): bool {
            $this->log[] = 'account:remove';

            return true;
        });
    }

    private function installer(): Installer
    {
        return new Installer(function (string $sql): bool {
            if (preg_match('/\ACREATE TABLE IF NOT EXISTS `([a-z_]+)`/', $sql, $m) === 1) {
                $this->log[] = 'CREATE '.$m[1];
                if ($m[1] === $this->failingTable) {
                    return false;
                }
                $this->tables[$m[1]] = true;

                return true;
            }
            if (preg_match('/\ADROP TABLE IF EXISTS `([a-z_]+)`/', $sql, $m) === 1) {
                $this->log[] = 'DROP '.$m[1];
                unset($this->tables[$m[1]]);

                return true;
            }

            return false;
        }, __DIR__.'/../../../sql', 'ps_', 'InnoDB');
    }

    /** @return callable(): bool */
    private function addModule(): callable
    {
        return function (): bool {
            $this->log[] = 'module:add';
            $this->moduleRows[] = 'kronixonconnect';

            return true;
        };
    }

    /** @return callable(): bool */
    private function removeModule(): callable
    {
        return function (): bool {
            $this->log[] = 'module:remove';
            $this->moduleRows = array_values(array_diff($this->moduleRows, ['kronixonconnect']));

            return true;
        };
    }

    /** @return callable(): bool */
    private function hooks(bool $ok): callable
    {
        return static function () use ($ok): bool {
            return $ok;
        };
    }
}

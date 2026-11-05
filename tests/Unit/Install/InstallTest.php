<?php

declare(strict_types=1);

namespace Kronixon\Connect\Tests\Unit\Install;

use FilesystemIterator;
use InvalidArgumentException;
use Kronixon\Connect\Autoloader;
use Kronixon\Connect\Compat\Version;
use Kronixon\Connect\Install\Hooks;
use Kronixon\Connect\Install\SqlFile;
use Kronixon\Connect\Support\Uuid;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class InstallTest extends TestCase
{
    private const ROOT = __DIR__.'/../../..';

    public function test_install_sql_creates_the_seven_module_tables(): void
    {
        $statements = SqlFile::statements((string) file_get_contents(self::ROOT.'/sql/install.sql'), 'ps_', 'InnoDB');

        $tables = array_map(static function (string $sql): string {
            self::assertSame(1, preg_match('/\ACREATE TABLE IF NOT EXISTS `(ps_kx_[a-z_]+)`/', $sql, $m), $sql);

            return $m[1];
        }, $statements);
        self::assertSame(['ps_kx_nonce', 'ps_kx_idempotency', 'ps_kx_log', 'ps_kx_entity_map', 'ps_kx_image_map', 'ps_kx_redirect', 'ps_kx_outbox'], $tables);
        foreach ($statements as $sql) {
            self::assertStringNotContainsString('PREFIX_', $sql);
            self::assertStringContainsString('ENGINE=InnoDB DEFAULT CHARSET=utf8mb4', $sql);
            self::assertStringNotContainsString('--', $sql);
        }
    }

    public function test_uninstall_sql_drops_every_installed_table(): void
    {
        $created = SqlFile::statements((string) file_get_contents(self::ROOT.'/sql/install.sql'), 'pfx_', 'InnoDB');
        $dropped = SqlFile::statements((string) file_get_contents(self::ROOT.'/sql/uninstall.sql'), 'pfx_', 'InnoDB');
        $names = static function (array $statements): array {
            $out = [];
            foreach ($statements as $sql) {
                preg_match('/`(pfx_kx_[a-z_]+)`/', $sql, $m);
                $out[] = $m[1];
            }
            sort($out);

            return $out;
        };

        self::assertSame($names($created), $names($dropped));
    }

    public function test_indexed_text_columns_fit_the_utf8mb4_index_limit(): void
    {
        $sql = (string) file_get_contents(self::ROOT.'/sql/install.sql');
        preg_match_all('/`([a-z_0-9]+)` VARCHAR\(([0-9]+)\)/', $sql, $m, PREG_SET_ORDER);
        foreach ($m as [$all, $column, $length]) {
            if (preg_match('/KEY `[a-z_]+` \([^)]*`'.$column.'`/', $sql) === 1) {
                self::assertLessThanOrEqual(191, (int) $length, $column.' is indexed');
            }
        }
    }

    public function test_sql_file_refuses_unexpected_prefixes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SqlFile::statements('SELECT 1;', "ps_'; DROP", 'InnoDB');
    }

    public function test_hooks_per_version(): void
    {
        $ps178 = Hooks::forCompat(Version::select('1.7.8.11'));
        $ps9 = Hooks::forCompat(Version::select('9.1.5'));

        self::assertContains('actionObjectAttributeAddAfter', $ps178);
        self::assertNotContains('actionObjectProductAttributeAddAfter', $ps178);
        self::assertContains('actionObjectProductAttributeAddAfter', $ps9);
        self::assertSame(count(array_unique($ps9)), count($ps9));
        self::assertNotContains('actionValidateOrder', $ps9, 'order events are Phase 2');
    }

    public function test_every_registered_hook_has_a_handler_in_the_module_class(): void
    {
        $source = (string) file_get_contents(self::ROOT.'/kronixonconnect.php');
        foreach (array_merge(Hooks::forCompat(Version::select('1.7.8.11')), Hooks::forCompat(Version::select('9.1.5'))) as $hook) {
            self::assertStringContainsString('public function hook'.ucfirst($hook).'(', $source);
        }
    }

    public function test_the_autoloader_maps_the_namespace_and_ignores_others(): void
    {
        Autoloader::register();
        Autoloader::register();
        Autoloader::load('Other\\Vendor\\Thing');
        Autoloader::load('Kronixon\\Connect\\..\\..\\etc');

        self::assertTrue(class_exists(Uuid::class));
        self::assertFalse(class_exists('Kronixon\\Connect\\Missing\\Nope'));
    }

    public function test_uuid_v4(): void
    {
        $uuid = Uuid::v4();

        self::assertTrue(Uuid::isValid($uuid));
        self::assertSame('4', $uuid[14]);
        self::assertContains($uuid[19], ['8', '9', 'a', 'b']);
        self::assertNotSame($uuid, Uuid::v4());
        self::assertFalse(Uuid::isValid('0192b2e4-7c1a-7d3e-9f4b-2a6c8e0d1f3'));
    }

    public function test_versions_agree_across_config_xml_and_the_module_class(): void
    {
        $xml = simplexml_load_file(self::ROOT.'/config.xml');
        self::assertNotFalse($xml);

        self::assertSame(\Kronixon\Connect\Protocol::MODULE_VERSION, (string) $xml->version);
        self::assertSame('kronixonconnect', (string) $xml->name);
    }

    public function test_every_runtime_directory_has_an_index_guard(): void
    {
        foreach (['', '/controllers', '/controllers/front', '/sql', '/views', '/views/templates', '/views/templates/admin'] as $dir) {
            self::assertFileExists(self::ROOT.$dir.'/index.php');
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::ROOT.'/src', FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                self::assertFileExists($file->getPathname().'/index.php');
            }
        }
    }

    public function test_every_source_file_carries_the_licence_header_and_strict_types(): void
    {
        $files = [self::ROOT.'/kronixonconnect.php', self::ROOT.'/controllers/front/api.php'];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::ROOT.'/src', FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        foreach ($files as $path) {
            $source = (string) file_get_contents($path);
            self::assertStringContainsString('@license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0', $source, $path);
            if (strpos($path, '/src/') !== false && basename($path) !== 'index.php') {
                self::assertStringContainsString('declare(strict_types=1);', $source, $path);
            }
        }
    }
}

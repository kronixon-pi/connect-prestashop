<?php

declare(strict_types=1);

/*
 * Builds the releases scripts/update-smoke.sh installs and offers, from the work tree, as the release build makes
 * them, signed with the throw-away key of tests/Support/Releases.php (a fixed seed; never a release key):
 *
 *   php tests/Integration/update-releases.php <out dir>
 *
 * - kronixonconnect-<MODULE_VERSION>.zip: the build installed first, which updates itself;
 * - 0.7.1 (its upgrade script sets KXTEST_UPGRADED_071), 0.7.2 (its upgrade script fails), 0.7.3 (its upgrade
 *   script waits while /tmp/kx-update-hold exists, after writing its pid to /tmp/kx-update-pid), 0.7.4, 0.7.5 (its
 *   upgrade script counts its runs in /tmp/kx-075-runs and dies of the memory limit, which PHP cannot catch);
 * - a 0.7.1 with one byte changed, and a 0.7.1 of the stable channel;
 * - latest-<case>.json: the answer of `GET /v1/module/latest` the stub gives for each case, the file on the stub;
 * - platform-key.txt: the pinned key a release build expects in the pair answer.
 */

use Kronixon\Connect\Protocol;
use Kronixon\Connect\Tests\Support\Releases;

require dirname(__DIR__, 2).'/src/Autoloader.php';
require dirname(__DIR__).'/Support/Releases.php';
Kronixon\Connect\Autoloader::register();

$out = rtrim((string) ($argv[1] ?? ''), '/');
if ($out === '' || ! is_dir($out)) {
    fwrite(STDERR, "usage: update-releases.php <out dir>\n");
    exit(2);
}
$stub = 'http://127.0.0.1:8099/files/';

$upgrade = static function (string $version, string $body): string {
    $function = 'upgrade_module_'.str_replace('.', '_', $version);

    return "<?php\n\nif (! defined('_PS_VERSION_')) {\n    exit;\n}\n\n/** Test release of scripts/update-smoke.sh. */\nfunction {$function}(\$module): bool\n{\n{$body}\n}\n";
};
$scripts = [
    '0.7.1' => $upgrade('0.7.1', "    return (bool) Configuration::updateGlobalValue('KXTEST_UPGRADED_071', '1');"),
    '0.7.2' => $upgrade('0.7.2', "    Configuration::updateGlobalValue('KXTEST_UPGRADED_072', 'tried');\n\n    return false;"),
    '0.7.3' => $upgrade('0.7.3', "    file_put_contents('/tmp/kx-update-runs', getmypid().' '.(\$_SERVER['REQUEST_URI'] ?? 'cli').' '.microtime(true).\"\\n\", FILE_APPEND);\n    file_put_contents('/tmp/kx-update-pid', (string) getmypid());\n    for (\$i = 0; \$i < 120 && is_file('/tmp/kx-update-hold'); \$i++) {\n        usleep(500000);\n    }\n\n    return (bool) Configuration::updateGlobalValue('KXTEST_UPGRADED_073', '1');"),
    '0.7.4' => $upgrade('0.7.4', "    return (bool) Configuration::updateGlobalValue('KXTEST_UPGRADED_074', '1');"),
    '0.7.5' => $upgrade('0.7.5', "    file_put_contents('/tmp/kx-075-runs', getmypid().\"\\n\", FILE_APPEND);\n    ini_set('memory_limit', '128M');\n    \$eaten = [];\n    while (true) {\n        \$eaten[] = str_repeat('x', 1048576);\n    }"),
];
// A later release carries the earlier scripts, the one of 0.7.2 fixed.
$fixed072 = $upgrade('0.7.2', '    return true;');

$write = static function (string $name, string $bytes) use ($out): void {
    if (file_put_contents($out.'/'.$name, $bytes) !== strlen($bytes)) {
        throw new RuntimeException('cannot write '.$name);
    }
};
$answer = static function (string $case, string $zip, string $version, string $file, array $overrides = []) use ($write, $stub): void {
    $write('latest-'.$case.'.json', (string) json_encode([
        'latest' => Releases::latest($zip, $version, $overrides + ['url' => $stub.$file]),
        'compatible' => true,
        'reason' => null,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
};

$installed = Releases::build(Protocol::MODULE_VERSION);
$write('kronixonconnect-'.Protocol::MODULE_VERSION.'.zip', $installed);
$answer('current', $installed, Protocol::MODULE_VERSION, 'kronixonconnect-'.Protocol::MODULE_VERSION.'.zip');

$zips = [];
foreach ($scripts as $version => $script) {
    $files = Releases::files($version);
    foreach ($scripts as $earlier => $earlierScript) {
        if (version_compare($earlier, $version, '<=')) {
            $files['upgrade/upgrade-'.$earlier.'.php'] = $earlier === '0.7.2' && $version !== '0.7.2' ? $fixed072 : $earlierScript;
        }
    }
    $zips[$version] = Releases::build($version, 'staging', $files);
    $write('kronixonconnect-'.$version.'.zip', $zips[$version]);
    $answer($version, $zips[$version], $version, 'kronixonconnect-'.$version.'.zip');
}

// One byte of the PHP code changed: the announced hash, and a hash that follows the change (signature still the original's).
$at = strpos($zips['0.7.1'], 'class KronixonConnect extends Module');
$tampered = substr_replace($zips['0.7.1'], 'C', (int) $at, 1);
$write('tampered-0.7.1.zip', $tampered);
$answer('tampered-hash', $zips['0.7.1'], '0.7.1', 'tampered-0.7.1.zip');
$answer('tampered-signature', $tampered, '0.7.1', 'tampered-0.7.1.zip', ['signature' => Releases::sign($zips['0.7.1'])]);

// A stable build, signed with the same key, offered as staging: its manifest says otherwise.
$stable = Releases::build('0.7.1', 'stable');
$write('stable-0.7.1.zip', $stable);
$answer('other-channel', $stable, '0.7.1', 'stable-0.7.1.zip');

$write('platform-key.txt', base64_encode(Releases::publicKey()));
foreach (glob($out.'/*.zip') ?: [] as $zip) {
    printf("%s  %s\n", substr(hash_file('sha256', $zip), 0, 12), basename($zip));
}

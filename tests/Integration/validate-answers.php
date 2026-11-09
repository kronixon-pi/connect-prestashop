<?php

declare(strict_types=1);
/**
 * Kronixon Connect for PrestaShop — test tooling, not part of the module zip.
 *
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 *
 * Validates answers a real shop gave (written by catalog-walk.php and platform-driver.php as
 * `<schema file>--<name>.json`, e.g. `catalog-products.json--walk.json`) against `$defs.response` of
 * protocol/v1/schemas/module/<schema file>. Needs the development tools (tools/vendor) and runs without
 * PrestaShop. Prints one line per file and a count per schema; exit 1 on any invalid answer or none.
 *
 *   php validate-answers.php <dir>
 */
require dirname(__DIR__, 2).'/tools/vendor/autoload.php';

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

$dir = $argv[1] ?? '';
$files = glob(rtrim($dir, '/').'/*--*.json') ?: [];
$validator = new Validator();
$validator->resolver()->registerPrefix('https://connect.kronixon.com/protocol/v1/schemas/', dirname(__DIR__, 2).'/protocol/v1/schemas');
$failures = 0;
$counts = [];
foreach ($files as $file) {
    $schema = explode('--', basename($file), 2)[0];
    $result = $validator->validate(json_decode((string) file_get_contents($file), false), 'https://connect.kronixon.com/protocol/v1/schemas/module/'.$schema.'#/$defs/response');
    $counts[$schema] = ($counts[$schema] ?? 0) + 1;
    if ($result->isValid()) {
        echo '  ok   ', basename($file), "\n";
        continue;
    }
    $failures++;
    echo '  FAIL ', basename($file), ' ', json_encode((new ErrorFormatter())->format($result->error()), JSON_UNESCAPED_SLASHES), "\n";
}
ksort($counts);
echo 'validated ', count($files), ' answers: ', json_encode($counts), "\n";
exit($failures === 0 && $files !== [] ? 0 : 1);

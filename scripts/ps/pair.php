<?php

declare(strict_types=1);
/**
 * Kronixon Connect for PrestaShop
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License version 3.0
 * that is bundled with this package in the file LICENSE.
 * It is also available at https://opensource.org/licenses/AFL-3.0
 *
 * @author    Kronixon
 * @copyright 2026 Kronixon
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

/*
 * Test tool (not in the module zip): pairs the shop from the command line through the same Pairing
 * service the settings page uses, against a development platform reachable over plain http.
 *
 *   php modules/kronixonconnect/scripts/ps/pair.php <platform url> <code>   prints the outcome (JSON)
 *   php modules/kronixonconnect/scripts/ps/pair.php --unpair                 unpairs (reason "reset")
 *
 * PS_DOMAIN names the shop's domain, as for prepare-shop.php. Never prints a secret.
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__, 4);
$_SERVER['HTTP_HOST'] = getenv('PS_DOMAIN') ?: 'localhost';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';
define('_PS_ADMIN_DIR_', $root.'/admin-dev');

require $root.'/config/config.inc.php';

if (! Module::getInstanceByName('kronixonconnect')) {
    fwrite(STDERR, "kronixonconnect is not installed\n");
    exit(1);
}
$pairing = Kronixon\Connect\Services::pairing(Context::getContext());
if (($argv[1] ?? '') === '--unpair') {
    $pairing->unpair('reset', 5);
    echo "{\"state\":\"unpaired\"}\n";
    exit(0);
}
Configuration::updateGlobalValue(Kronixon\Connect\Platform\Pairing::ALLOW_HTTP, '1');
if (! $pairing->setPlatformUrl((string) ($argv[1] ?? ''))) {
    fwrite(STDERR, "usage: pair.php <platform url> <code> | --unpair\n");
    exit(2);
}
$outcome = $pairing->pair((string) ($argv[2] ?? ''));
echo json_encode($outcome, JSON_UNESCAPED_SLASHES), "\n";
exit(($outcome['state'] ?? '') === 'paired' ? 0 : 1);

<?php

declare(strict_types=1);
/**
 * Kronixon Connect for PrestaShop — test tooling, not part of the module zip.
 *
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 *
 * Prepares a disposable test shop: turns friendly URLs on and writes .htaccess, so that
 * /module/kronixonconnect/api?path=… resolves, then prints "<prestashop version> <php version>".
 * Run inside the shop container as www-data:
 *   php modules/kronixonconnect/scripts/ps/prepare-shop.php
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

Configuration::updateValue('PS_REWRITING_SETTINGS', 1);
if (! Tools::generateHtaccess()) {
    fwrite(STDERR, "Could not write .htaccess\n");
    exit(1);
}
echo _PS_VERSION_.' '.PHP_VERSION."\n";

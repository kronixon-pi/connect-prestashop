<?php

declare(strict_types=1);

/*
 * The shop side of scripts/update-smoke.sh, run by the shop's PHP from outside the module folder (the module is
 * installed from its zip and has no tests or scripts):
 *
 *   php update-driver.php <shop root> pair <platform url> <code>   pairs with the stub, prints the outcome
 *   php update-driver.php <shop root> token                        the AdminModules token of the demo employee
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = rtrim((string) ($argv[1] ?? ''), '/');
$_SERVER['HTTP_HOST'] = getenv('PS_DOMAIN') ?: 'localhost';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';
define('_PS_ADMIN_DIR_', $root.'/admin-dev');

require $root.'/config/config.inc.php';

$command = (string) ($argv[2] ?? '');
if ($command === 'token') {
    $employee = (int) Db::getInstance()->getValue('SELECT id_employee FROM '._DB_PREFIX_.'employee WHERE email = \'demo@prestashop.com\'', false);
    echo Tools::getAdminToken('AdminModules'.(int) Tab::getIdFromClassName('AdminModules').$employee), "\n";
    exit(0);
}
if ($command === 'pair') {
    if (! Module::getInstanceByName('kronixonconnect')) {
        fwrite(STDERR, "kronixonconnect is not installed\n");
        exit(1);
    }
    $pairing = Kronixon\Connect\Services::pairing(Context::getContext());
    Configuration::updateGlobalValue(Kronixon\Connect\Platform\Pairing::ALLOW_HTTP, '1');
    if (! $pairing->setPlatformUrl((string) ($argv[3] ?? ''))) {
        exit(2);
    }
    $outcome = $pairing->pair((string) ($argv[4] ?? ''));
    echo json_encode($outcome, JSON_UNESCAPED_SLASHES), "\n";
    exit(($outcome['state'] ?? '') === 'paired' ? 0 : 1);
}
fwrite(STDERR, "usage: update-driver.php <shop root> pair <url> <code> | token\n");
exit(2);

<?php

declare(strict_types=1);
/**
 * Kronixon Connect for PrestaShop — test tooling, not part of the module zip.
 *
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 *
 * The settings page in the back office as the image's demo employee (scripts/ps-smoke.sh, inside the
 * shop container as www-data, on a shop paired with platform-stub.php): a GET with the submit name of
 * any settings action, followed like a clicked link, changes nothing, with or without the admin token
 * (on 9.x the legacy URL answers a 308 to the Symfony route that adds a valid token). A POST of the
 * page's own form still acts. One line per check; exit 1 on any failure.
 *
 *   php settings-forms.php <shop base URL> <apache address, e.g. 127.0.0.1:80>
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__, 4);
$_SERVER['HTTP_HOST'] = getenv('PS_DOMAIN') ?: 'localhost';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';
require $root.'/config/config.inc.php';
require __DIR__.'/SignedClient.php';

use Kronixon\Connect\Auth\SecretStore;
use Kronixon\Connect\Platform\Pairing;
use Kronixon\Connect\Services;
use Kronixon\Connect\Support\SettingsForm;

Module::getInstanceByName('kronixonconnect');
$base = rtrim((string) ($argv[1] ?? ''), '/');
$connectTo = (string) ($argv[2] ?? '');
$kx = new SignedClient($base, $connectTo);
$db = Db::getInstance();
$p = _DB_PREFIX_;
$jar = (string) tempnam('/tmp', 'kx-jar-');
$admin = $base.'/admin-dev/index.php';
$email = 'demo@prestashop.com';

// A browser with a cookie jar: redirects followed as a browser does (303 and 302 become a GET, 307 and
// 308 keep the method and the body).
$browser = static function (string $url, ?array $post = null) use ($jar, $connectTo): array {
    $parts = (array) parse_url($url);
    $curl = curl_init($url);
    $options = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
        CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar, CURLOPT_CONNECT_TO => [$parts['host'].':'.($parts['port'] ?? 80).':'.$connectTo]];
    if ($post !== null) {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = http_build_query($post);
    }
    curl_setopt_array($curl, $options);
    $body = (string) curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

    return ['status' => $status, 'body' => $body];
};
// What the actions would change, read from the database and the stub (this process caches Configuration).
$state = static function () use ($db, $p): array {
    $names = [Pairing::PLATFORM_URL, SecretStore::STORE_ID, SecretStore::SECRET, SecretStore::PAIRED_AT, SecretStore::PAIR_STATE, Services::CRON_TOKEN];
    $rows = $db->executeS("SELECT name, value FROM {$p}configuration WHERE name IN ('".implode("', '", $names)."') ORDER BY name, id_configuration", true, false);
    $stub = json_decode((string) file_get_contents('/tmp/kx-stub/state.json'), true);

    return ['settings' => array_map('sha1', array_column(is_array($rows) ? $rows : [], 'value', 'name')),
        'stub' => [$stub['store_id'] ?? null, $stub['unpaired'] ?? null, $stub['pings'] ?? null, sha1((string) json_encode($stub['pair_request'] ?? null))], ];
};
$employee = (int) $db->getValue("SELECT id_employee FROM {$p}employee WHERE email = '".pSQL($email)."'", false);
$token = Tools::getAdminToken('AdminModules'.(int) Tab::getIdFromClassName('AdminModules').$employee);
$settings = $admin.'?controller=AdminModules&configure=kronixonconnect';

// 9.x logs in through its Symfony login form, 1.7.8 and 8.x through AdminLogin.
$login = $browser($admin.'/login');
if (preg_match('/id="_token" name="_token" value="([^"]*)"/', $login['body'], $csrf) === 1) {
    $browser($admin.'/login', ['email' => $email, 'passwd' => 'prestashop_demo', '_token' => $csrf[1]]);
} else {
    $browser($admin.'?controller=AdminLogin', ['ajax' => '1', 'submitLogin' => '1', 'email' => $email, 'passwd' => 'prestashop_demo']);
}
$page = $browser($settings.'&token='.$token);
$start = $state();
$kx->check('setup: the demo employee opens the settings page of a paired shop', $employee > 0 && $page['status'] === 200
    && isset($start['settings'][Pairing::PLATFORM_URL], $start['settings'][SecretStore::STORE_ID], $start['settings'][Services::CRON_TOKEN], $start['stub'][0])
    && strpos($page['body'], 'name="'.SettingsForm::ROTATE_CRON.'"') !== false && strpos($page['body'], 'name="'.SettingsForm::UNPAIR.'"') !== false, (string) $page['status']);

// Each action's submit name (and its field) in the query of a GET.
$queries = [
    SettingsForm::PLATFORM_URL => ['kx_platform_url' => 'https://pi.example.invalid'],
    SettingsForm::PAIR => ['kx_code' => 'K7PM-3XQ9'],
    SettingsForm::UNPAIR => [],
    SettingsForm::ROTATE_CRON => [],
    SettingsForm::PING => [],
];
foreach ($queries as $name => $fields) {
    foreach (['without a token' => '', 'with the admin token' => '&token='.$token] as $how => $withToken) {
        $before = $state();
        $answer = $browser($settings.$withToken.'&'.http_build_query([$name => '1'] + $fields));
        $after = $state();
        $kx->check("a GET with $name, $how, changes nothing", $answer['status'] > 0 && $after === $before, json_encode([$answer['status'], $before, $after]));
    }
}

// The page's own forms: a POST to their action still acts (a new cron token, a ping).
$action = preg_match('/<form method="post" action="([^"]*)"/', $browser($settings.'&token='.$token)['body'], $form) === 1 ? html_entity_decode($form[1], ENT_QUOTES | ENT_HTML401, 'UTF-8') : '';
$before = $state();
$answer = $browser($action, [SettingsForm::ROTATE_CRON => '1']);
$after = $state();
$kx->check('a POST of the form makes a new cron token', $action !== '' && $answer['status'] === 200 && $after['settings'][Services::CRON_TOKEN] !== $before['settings'][Services::CRON_TOKEN]
    && $after['stub'] === $before['stub'], json_encode([$answer['status'], $before, $after]));
$answer = $browser($action, [SettingsForm::PING => '1']);
$pinged = $state();
$kx->check('a POST of the form sends a ping', $answer['status'] === 200 && $pinged['stub'][2] === $after['stub'][2] + 1, json_encode([$answer['status'], $after['stub'], $pinged['stub']]));

unlink($jar);
exit($kx->failures() === 0 ? 0 : 1);

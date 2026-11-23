<?php

declare(strict_types=1);
/**
 * Kronixon Connect for PrestaShop — test tooling, not part of the module zip.
 *
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 *
 * One flush of the outbox in a process of its own (events-delivery.php kills it while it waits for the
 * platform). Prints the flush result as JSON.
 *
 *   php flush-cli.php
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

$_SERVER['HTTP_HOST'] = getenv('PS_DOMAIN') ?: 'localhost';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';
require dirname(__DIR__, 4).'/config/config.inc.php';
Module::getInstanceByName('kronixonconnect');

echo json_encode(Kronixon\Connect\Services::flushOutbox(Context::getContext(), 25)), "\n";

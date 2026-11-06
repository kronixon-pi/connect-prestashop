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
if (! defined('_PS_VERSION_')) {
    exit;
}

use Kronixon\Connect\Protocol;
use Kronixon\Connect\Services;
use Kronixon\Connect\Support\Runtime;

/**
 * The API front controller: `/module/kronixonconnect/api?path=<endpoint>` or
 * `/index.php?fc=module&module=kronixonconnect&controller=api&path=<endpoint>` (Spec §5.1).
 *
 * It answers from init(), before FrontController::init() runs, so no cookie, cart, SSL or canonical
 * redirect, geolocation or maintenance page interferes with a machine-to-machine call.
 */
class KronixonConnectApiModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $ajax = true;

    /** @var bool */
    public $content_only = true;

    /**
     * @return void
     */
    public function init(): void
    {
        Runtime::prepareApiRequest(Protocol::MAX_TIME_BUDGET_S + 30);
        if (isset($this->context->cookie)) {
            $this->context->cookie->disallowWriting();
        }

        $kernel = Services::kernel($this->context);
        $kernel->guardFatalErrors();

        $response = $kernel->handle($_SERVER, Runtime::readBody(Protocol::MAX_BODY_BYTES));
        $kernel->send($response, (string) ($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''), Runtime::hostCompressesOutput());
        exit;
    }
}

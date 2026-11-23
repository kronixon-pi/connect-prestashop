<?php

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

declare(strict_types=1);

if (! defined('_PS_VERSION_')) {
    exit;
}

use Kronixon\Connect\Outbox\Flusher;
use Kronixon\Connect\Outbox\Heartbeat;
use Kronixon\Connect\Protocol;
use Kronixon\Connect\Services;
use Kronixon\Connect\Support\Runtime;

/**
 * The cron URL (Spec §7.3): `/module/kronixonconnect/cron?token=<KX_CRON_TOKEN>`, called by the hosting's
 * cron every minute. It sends the outbox within the request budget and answers what it did as JSON, and
 * whether page views could deliver without it (`page_views`). Within 10 s of the last flush it answers
 * `too_soon` and does nothing else. While it is called, page views do not flush. Like the API controller
 * it answers from init(), which PrestaShop reaches only after its bootstrap: the check cannot run earlier.
 */
class KronixonConnectCronModuleFrontController extends ModuleFrontController
{
    /** Seconds after the last flush during which a call answers `too_soon` (a leaked token costs little). */
    public const MIN_INTERVAL_S = 10;

    /** @var bool */
    public $ajax = true;

    /** @var bool */
    public $content_only = true;

    /**
     * @return void
     */
    public function init(): void
    {
        if (isset($this->context->cookie)) {
            $this->context->cookie->disallowWriting();
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex');
        $settings = Services::settings();
        $expected = $settings->get(Services::CRON_TOKEN);
        $token = Tools::getValue('token');
        if ($expected === null || ! is_string($token) || ! hash_equals($expected, $token)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'forbidden']);
            exit;
        }
        // An update whose files were swapped in and whose settings page was not opened again is finished here, alone
        // in its request: after a rollback this request's classes are not those of the files (Update\Updater).
        $updater = Services::updater($this->context);
        if ($updater->offer()['finishing'] !== null) {
            Runtime::keepRunning(300);
        }
        $update = $updater->resume();
        if ($update !== null) {
            echo json_encode(['ok' => true, 'update' => ['outcome' => $update['outcome'], 'to' => $update['to']]]);
            exit;
        }
        // Whether page views can deliver without the cron on this server (Outbox\Delivery).
        $pageViews = Services::delivery()->finisher() !== null;
        // A call within MIN_INTERVAL_S of the last flush answers at once: no write, no count, no flush.
        if (time() - (int) $settings->get(Flusher::LAST_FLUSH) < self::MIN_INTERVAL_S) {
            echo json_encode(['ok' => true, 'flush' => ['state' => 'too_soon'], 'page_views' => $pageViews]);
            exit;
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(Protocol::MAX_TIME_BUDGET_S + 30);
        }
        ignore_user_abort(true);
        $settings->set(Heartbeat::CRON_AT, (string) time());
        $result = Services::flushOutbox($this->context, Services::budgetSeconds());
        echo json_encode(['ok' => true, 'flush' => $result ?? ['state' => $settings->get('KX_STORE_ID') === null ? 'unpaired' : 'busy'], 'page_views' => $pageViews]);
        exit;
    }
}

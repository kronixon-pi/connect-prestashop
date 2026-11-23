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
use Kronixon\Connect\Update\Updater;

/**
 * The cron URL (Spec §7.3): `/module/kronixonconnect/cron?token=<KX_CRON_TOKEN>`, called by the hosting's
 * cron every minute. It sends the outbox within the request budget and answers what it did as JSON, and
 * whether page views could deliver without it (`page_views`). Within 10 s of the last flush it answers
 * `too_soon` and does not flush. After the flush it finishes an update whose files were swapped in (`update`).
 * While it is called, page views do not flush. Like the API controller
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
        // Whether page views can deliver without the cron on this server (Outbox\Delivery).
        $pageViews = Services::delivery()->finisher() !== null;
        $answer = ['ok' => true, 'flush' => ['state' => 'too_soon'], 'page_views' => $pageViews];
        // A call within MIN_INTERVAL_S of the last flush does not flush: no write, no count.
        if (time() - (int) $settings->get(Flusher::LAST_FLUSH) >= self::MIN_INTERVAL_S) {
            if (function_exists('set_time_limit')) {
                @set_time_limit(Protocol::MAX_TIME_BUDGET_S + 30);
            }
            ignore_user_abort(true);
            $settings->set(Heartbeat::CRON_AT, (string) time());
            $result = Services::flushOutbox($this->context, Services::budgetSeconds());
            $answer['flush'] = $result ?? ['state' => $settings->get('KX_STORE_ID') === null ? 'unpaired' : 'busy'];
        }
        // Then, after the flush, so that an upgrade that dies cannot stop the store events: an update whose files
        // were swapped in and whose settings page was not opened again is finished here (Update\Updater). The
        // request ends after it: after a rollback this request's classes are not those of the files.
        $update = $settings->get(Updater::STATE) !== null ? $this->finishUpdate() : null;
        if ($update !== null) {
            $answer['update'] = ['outcome' => $update['outcome'], 'to' => $update['to']];
        }
        echo json_encode($answer);
        exit;
    }

    /**
     * @return array<string, mixed>|null the update's outcome, or null (nothing finished)
     */
    private function finishUpdate(): ?array
    {
        try {
            Runtime::keepRunning(300);

            return Services::updater($this->context)->resume();
        } catch (Throwable $error) {
            return null;
        }
    }
}

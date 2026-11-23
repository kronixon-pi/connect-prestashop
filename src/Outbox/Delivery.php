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

namespace Kronixon\Connect\Outbox;

use Kronixon\Connect\Auth\SecretStore;
use Kronixon\Connect\Support\Settings;

/**
 * How the outbox reaches the platform on this server (README "Store events"):
 *
 * - `cron`: the cron URL was called within the last hour;
 * - `page_views`: no cron, but the server can finish a page view's answer before the flush starts
 *   (`fastcgi_finish_request()` under PHP-FPM, `litespeed_finish_request()` under LiteSpeed), so a
 *   page view flushes after the visitor has the page;
 * - `pull_only`: neither (Apache mod_php without a cron): the module sends nothing on its own, and the
 *   events reach the platform only when it pulls them (`GET events`). The settings page and the
 *   heartbeat warn about it.
 *
 * A page view never waits for the platform: without a finish function no flush is registered.
 */
final class Delivery
{
    public const CRON = 'cron';

    public const PAGE_VIEWS = 'page_views';

    public const PULL_ONLY = 'pull_only';

    /** The functions that send the answer and close the connection, in the order they are tried. */
    public const FINISHERS = ['fastcgi_finish_request', 'litespeed_finish_request'];

    /** A page view flushes at most this often. */
    public const VISIT_EVERY_S = 60;

    /** A page view does not flush while a cron called within this many seconds. */
    public const CRON_QUIET_S = 300;

    /** @var callable(string): bool */
    private $canCall;

    /** @var callable(callable): void */
    private $register;

    /** @var callable(string): bool */
    private $finish;

    /**
     * @param  callable(string): bool  $canCall  whether a PHP function exists and is not disabled
     * @param  callable(callable): void  $register  register_shutdown_function
     * @param  (callable(string): bool)|null  $finish  calls a finish function by name; whether it finished the answer
     */
    public function __construct(callable $canCall, callable $register, ?callable $finish = null)
    {
        $this->canCall = $canCall;
        $this->register = $register;
        $this->finish = $finish ?? static function (string $function): bool {
            return $function() === true;
        };
    }

    /** The function that finishes the answer on this server, or null (mod_php, CLI). */
    public function finisher(): ?string
    {
        foreach (self::FINISHERS as $function) {
            if (($this->canCall)($function)) {
                return $function;
            }
        }

        return null;
    }

    /** cron, page_views or pull_only (see the class comment). */
    public function mode(Settings $settings, int $now): string
    {
        $cron = (int) $settings->get(Heartbeat::CRON_AT);
        if ($cron > 0 && $now - $cron <= Heartbeat::CRON_FRESH_S) {
            return self::CRON;
        }

        return $this->finisher() !== null ? self::PAGE_VIEWS : self::PULL_ONLY;
    }

    /**
     * On a page view: registers $flush to run after the answer when the server can finish the answer
     * first, the shop is paired, no cron runs, nothing asked to wait and the last flush is a minute old.
     * The registered function finishes the answer and flushes only if that worked.
     *
     * @param  callable(): void  $flush
     * @return bool whether a flush was registered
     */
    public function onPageView(Settings $settings, int $now, callable $flush): bool
    {
        $finisher = $this->finisher();
        if ($finisher === null || $settings->get(SecretStore::STORE_ID) === null
            || $now - (int) $settings->get(Flusher::LAST_FLUSH) < self::VISIT_EVERY_S
            || Flusher::waiting($settings, $now) || $now - (int) $settings->get(Heartbeat::CRON_AT) < self::CRON_QUIET_S) {
            return false;
        }
        $finish = $this->finish;
        ($this->register)(static function () use ($finish, $finisher, $flush): void {
            if (! $finish($finisher)) {
                return;
            }
            $flush();
        });

        return true;
    }
}

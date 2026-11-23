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

use Kronixon\Connect\Api\Response;
use Kronixon\Connect\Protocol;
use Kronixon\Connect\Support\Clock;
use Kronixon\Connect\Support\Settings;

/**
 * The payload of `module.heartbeat` (Spec §7.4), recorded once a day by the flush: versions, the hash
 * of GET capabilities, entity counts, the outbox, the cron, the module's warnings of the last 24 h.
 */
final class Heartbeat
{
    public const CRON_AT = 'KX_CRON_AT';

    /** A cron that called within this many seconds counts as configured. */
    public const CRON_FRESH_S = 3600;

    /** @var callable(): array<string, mixed> */
    private $capabilities;

    /** @var callable(): array<string, int> */
    private $counts;

    /** @var callable(): array{count: int, last: list<array{at: string, code: string, message: string}>} */
    private $errors;

    /** @var OutboxStore */
    private $store;

    /** @var Settings */
    private $settings;

    /** @var Clock */
    private $clock;

    /** @var string */
    private $psVersion;

    /** @var Delivery */
    private $delivery;

    /**
     * @param  callable(): array<string, mixed>  $capabilities  GET capabilities
     * @param  callable(): array<string, int>  $counts  entity => rows
     * @param  callable(): array{count: int, last: list<array{at: string, code: string, message: string}>}  $errors  warnings of 24 h
     */
    public function __construct(callable $capabilities, callable $counts, callable $errors, OutboxStore $store, Settings $settings, Clock $clock, string $psVersion, Delivery $delivery)
    {
        $this->capabilities = $capabilities;
        $this->counts = $counts;
        $this->errors = $errors;
        $this->store = $store;
        $this->settings = $settings;
        $this->clock = $clock;
        $this->psVersion = $psVersion;
        $this->delivery = $delivery;
    }

    /**
     * Records module.heartbeat in the outbox when the last one is a day old (the flush calls it, and so
     * does a pull, so a shop that never flushes still tells the platform how it delivers).
     */
    public function recordIfDue(): bool
    {
        $now = (int) floor($this->clock->now());
        if ($now - (int) $this->settings->get(Flusher::HEARTBEAT_AT) < Flusher::HEARTBEAT_S) {
            return false;
        }
        $this->store->insert(new Change('module.heartbeat', null, $this->payload()), Recorder::time($this->clock->now()));
        $this->settings->set(Flusher::HEARTBEAT_AT, (string) $now);

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $now = (int) floor($this->clock->now());
        $cron = (int) $this->settings->get(self::CRON_AT);
        $errors = ($this->errors)();
        $rate = (int) $this->settings->get('KX_RATE_LIMIT');

        return [
            'module_version' => Protocol::MODULE_VERSION,
            'ps_version' => $this->psVersion,
            'php' => PHP_VERSION,
            'capabilities_hash' => hash('sha256', (string) json_encode(($this->capabilities)(), Response::JSON_FLAGS)),
            'counts' => ($this->counts)(),
            'outbox' => $this->store->counts(),
            'cron' => ['configured' => $cron > 0 && $now - $cron <= self::CRON_FRESH_S, 'last_run_at' => $cron > 0 ? gmdate('Y-m-d\TH:i:s\Z', $cron) : null],
            // pull_only: no cron and no flush after page views; the platform warns (README §8).
            'delivery' => $this->delivery->mode($this->settings, $now),
            'errors_24h' => $errors['count'],
            'last_errors' => array_slice($errors['last'], 0, 20),
            'settings' => ['rate_limit' => $rate > 0 ? $rate : Protocol::RATE_LIMIT_PER_MINUTE],
        ];
    }
}

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

use Kronixon\Connect\Support\Clock;
use Kronixon\Connect\Support\Log;
use Kronixon\Connect\Support\Settings;
use Kronixon\Connect\Write\Database;
use Throwable;

/**
 * The hooks' side of the outbox: one INSERT of new rows on the request's own connection, so a change
 * made inside a transaction is recorded in it (a rollback takes both) and one made in autocommit is
 * recorded right after it. Never a read, never an UPDATE of an existing row, never the network: a
 * checkout waits for nothing of ours.
 *
 * A failed INSERT does not fail the shop's request: the time of the first one is kept (KX_OUTBOX_GAP)
 * and the next flush sends `module.outbox_overflow`, which tells the platform to read again what changed
 * since then.
 */
final class Recorder
{
    public const GAP = 'KX_OUTBOX_GAP';

    /** @var Database */
    private $db;

    /** @var OutboxStore */
    private $store;

    /** @var Settings */
    private $settings;

    /** @var Clock */
    private $clock;

    /** @var Log|null */
    private $log;

    public function __construct(Database $db, Settings $settings, Clock $clock, ?Log $log = null)
    {
        $this->db = $db;
        $this->store = new OutboxStore($db);
        $this->settings = $settings;
        $this->clock = $clock;
        $this->log = $log;
    }

    /**
     * The changes $changes() finds (ShopChanges); if finding them fails, that is a gap too.
     *
     * @param  callable(): list<Change>  $changes
     */
    public function capture(callable $changes): void
    {
        if (Origin::isPlatform()) {
            return;
        }
        try {
            $found = $changes();
        } catch (Throwable $error) {
            $this->gap($error);

            return;
        }
        $this->record(...$found);
    }

    public function record(Change ...$changes): void
    {
        if ($changes === [] || Origin::isPlatform()) {
            return;
        }
        try {
            $at = self::time($this->clock->now());
            $rows = [];
            foreach ($changes as $change) {
                $rows[] = $this->store->values($change, $at);
            }
            $this->db->execute('INSERT INTO '.$this->store->table().' (event_id, type, external_id, entity_key, payload, occurred_at) VALUES '.implode(', ', $rows));
        } catch (Throwable $error) {
            $this->gap($error);
        }
    }

    /** UTC with milliseconds, as kx_outbox.occurred_at stores it. */
    public static function time(float $now): string
    {
        $ms = (int) round($now * 1000);

        return gmdate('Y-m-d H:i:s', intdiv($ms, 1000)).sprintf('.%03d', $ms % 1000);
    }

    private function gap(Throwable $error): void
    {
        try {
            if ($this->settings->get(self::GAP) === null) {
                $this->settings->set(self::GAP, (string) (int) floor($this->clock->now()));
            }
        } catch (Throwable $ignored) {
            // nothing more can be done in this request
        }
        if ($this->log !== null) {
            $this->log->warning('outbox', 'A change could not be recorded', ['exception' => get_class($error)]);
        }
    }
}

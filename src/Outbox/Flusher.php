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

use Kronixon\Connect\Api\Budget;
use Kronixon\Connect\Api\Response;
use Kronixon\Connect\Platform\PlatformError;
use Kronixon\Connect\Protocol;
use Kronixon\Connect\Support\Clock;
use Kronixon\Connect\Support\Log;
use Kronixon\Connect\Support\Settings;
use Kronixon\Connect\Support\Uuid;
use Throwable;

/**
 * Sends the outbox to `POST /v1/stores/{id}/events` (README §8) in batches of at most 200 events and
 * 1 MB, oldest first, until it is empty or the budget is spent. One flusher at a time (the caller holds
 * the lock); it runs on its own connection, outside any request's transaction.
 *
 * A batch claims its rows before it is sent. When the process dies with a batch out, the next flush
 * sends that batch again with the same batch_id and event ids, so the platform sees duplicates, not new
 * events. Accepted and duplicate events are sent, rejected ones failed; a request the platform did not
 * answer (network, 5xx, 429, 401, 403, an unsigned answer) puts the rows back and waits:
 * `Retry-After`, else 2^failures × 30 s up to 6 h. A 400, 413 or 422 fails the batch's rows (the payload
 * is wrong). A row fails after 10 attempts (OutboxStore::MAX_ATTEMPTS); sending a batch again counts an
 * attempt, so a batch whose build or send kills the process every time fails too.
 *
 * Before sending, the flusher records what the platform must be told: a gap left by a failed hook and
 * an outbox above its cap (`module.outbox_overflow`), and the daily `module.heartbeat`. These go first,
 * in a batch of their own. Afterwards it removes sent rows older than 7 days and failed ones older than 30.
 */
final class Flusher
{
    public const LAST_FLUSH = 'KX_OUTBOX_LAST_FLUSH';

    public const RETRY_AT = 'KX_OUTBOX_RETRY_AT';

    public const FAILURES = 'KX_OUTBOX_FAILURES';

    public const LAST_ERROR = 'KX_OUTBOX_LAST_ERROR';

    public const LAST_SENT = 'KX_OUTBOX_LAST_SENT';

    public const HEARTBEAT_AT = 'KX_HEARTBEAT_AT';

    public const MAX_EVENTS = 200;

    public const MAX_BYTES = 1048576;

    public const HEARTBEAT_S = 86400;

    private const MAX_WAIT_S = 21600;

    /** Seconds one request may take at most; less when the budget is shorter. */
    private const REQUEST_S = 10;

    /** @var OutboxStore */
    private $store;

    /** @var Events */
    private $events;

    /** @var callable(array<string, mixed>, int): array<string, mixed> */
    private $send;

    /** @var Settings */
    private $settings;

    /** @var Clock */
    private $clock;

    /** @var Heartbeat */
    private $heartbeat;

    /** @var Log|null */
    private $log;

    /** @var bool whether this run still owes the pending module.* events their batch (nextBatch()) */
    private $moduleBatchDue = false;

    /**
     * @param  callable(array<string, mixed>, int): array<string, mixed>  $send  the signed POST; the verified 200 body, or PlatformError
     */
    public function __construct(OutboxStore $store, Events $events, callable $send, Settings $settings, Clock $clock, Heartbeat $heartbeat, ?Log $log = null)
    {
        $this->store = $store;
        $this->events = $events;
        $this->send = $send;
        $this->settings = $settings;
        $this->clock = $clock;
        $this->heartbeat = $heartbeat;
        $this->log = $log;
    }

    /** Whether the platform asked to wait (Retry-After or backoff): a visit does not flush then. */
    public static function waiting(Settings $settings, int $now): bool
    {
        return (int) $settings->get(self::RETRY_AT) > $now;
    }

    /**
     * @return array{sent: int, failed: int, batches: int, state: string} state: done, budget, waiting, error
     */
    public function run(Budget $budget): array
    {
        $result = ['sent' => 0, 'failed' => 0, 'batches' => 0, 'state' => 'done'];
        $now = $this->now();
        $this->settings->set(self::LAST_FLUSH, (string) $now);
        $this->announce();
        if (self::waiting($this->settings, $now)) {
            $result['state'] = 'waiting';

            return $result;
        }
        $this->moduleBatchDue = true;
        while (true) {
            if ($budget->remaining() < 1.5) {
                $result['state'] = 'budget';
                break;
            }
            $rows = $this->nextBatch();
            if ($rows === []) {
                break;
            }
            $outcome = $this->deliver($rows, max(1, min(self::REQUEST_S, (int) floor($budget->remaining() - 0.5))));
            $result['batches']++;
            $result['sent'] += $outcome['sent'];
            $result['failed'] += $outcome['failed'];
            if ($outcome['stop']) {
                $result['state'] = 'waiting';
                break;
            }
        }
        $this->prune($now);

        return $result;
    }

    /**
     * In this order: the rows of a batch a dead process left (sent again as they were, the attempt
     * counted, so a batch that kills the process every time fails after MAX_ATTEMPTS instead of
     * blocking the queue); then, once per run, the pending module.* events (gap, overflow, heartbeat)
     * in a batch of their own, also when a left batch went first; then pending rows of at most
     * MAX_EVENTS entities, oldest first. Claimed.
     *
     * @return list<array<string, string|null>>
     */
    private function nextBatch(): array
    {
        // Each pass fails or returns one left batch; the bound only guards against a store that does neither.
        for ($i = 0; $i < 100 && ($left = $this->store->unfinished(1)) !== []; $i++) {
            $rows = $this->store->resume((string) $left[0]['batch_id']);
            if ($rows !== []) {
                return $rows;
            }
        }
        if ($this->moduleBatchDue) {
            $this->moduleBatchDue = false;
            $ids = array_map('intval', array_column($this->store->pendingModule(self::MAX_EVENTS), 'id_outbox'));
            if ($ids !== []) {
                return $this->store->claim($ids, Uuid::v4());
            }
        }
        $ids = [];
        $keys = [];
        foreach ($this->store->pending(5 * self::MAX_EVENTS) as $row) {
            $key = (string) $row['entity_key'];
            if (! isset($keys[$key]) && count($keys) >= self::MAX_EVENTS) {
                continue;
            }
            $keys[$key] = true;
            $ids[] = (int) $row['id_outbox'];
        }

        return $ids === [] ? [] : $this->store->claim($ids, Uuid::v4());
    }

    /**
     * @param  non-empty-list<array<string, string|null>>  $rows  of one batch
     * @return array{sent: int, failed: int, stop: bool}
     */
    private function deliver(array $rows, int $timeout): array
    {
        $batchId = strtolower((string) $rows[0]['batch_id']);
        $built = $this->events->build($rows, true);
        $events = [];
        $idsOf = [];
        $gone = [];
        foreach ($built as $entry) {
            if ($entry['event'] === null) {
                $gone = array_merge($gone, $entry['ids']);
                continue;
            }
            $events[] = $entry['event'];
            $idsOf[(string) $entry['event']['event_id']] = $entry['ids'];
        }
        // An event whose entity is gone says nothing the *.deleted event does not.
        $this->store->sent($gone, $this->stamp());
        if ($events === []) {
            return ['sent' => 0, 'failed' => 0, 'stop' => false];
        }
        $body = ['batch_id' => $batchId, 'sent_at' => gmdate('Y-m-d\TH:i:s\Z', $this->now()), 'module_version' => Protocol::MODULE_VERSION, 'events' => $events];
        while (count($body['events']) > 1 && strlen((string) json_encode($body, Response::JSON_FLAGS)) > self::MAX_BYTES) {
            // The next batch takes them.
            $last = array_pop($body['events']);
            $this->store->unclaim($idsOf[(string) $last['event_id']]);
            unset($idsOf[(string) $last['event_id']]);
        }

        try {
            $answer = ($this->send)($body, $timeout);
        } catch (PlatformError $error) {
            return $this->refused(array_merge(...array_values($idsOf)), $error);
        } catch (Throwable $error) {
            return $this->refused(array_merge(...array_values($idsOf)), new PlatformError(0, PlatformError::NETWORK));
        }

        $sent = [];
        foreach (['accepted', 'duplicates'] as $list) {
            foreach ((array) ($answer[$list] ?? []) as $eventId) {
                if (is_string($eventId) && isset($idsOf[strtolower($eventId)])) {
                    $sent = array_merge($sent, $idsOf[strtolower($eventId)]);
                    unset($idsOf[strtolower($eventId)]);
                }
            }
        }
        $failed = 0;
        foreach ((array) ($answer['rejected'] ?? []) as $rejected) {
            $eventId = is_array($rejected) && is_string($rejected['event_id'] ?? null) ? strtolower($rejected['event_id']) : null;
            if ($eventId !== null && isset($idsOf[$eventId])) {
                $code = is_string($rejected['code'] ?? null) ? $rejected['code'] : 'rejected';
                $this->store->failed($idsOf[$eventId], 'rejected: '.$code);
                $failed++;
                unset($idsOf[$eventId]);
            }
        }
        $this->store->sent($sent, $this->stamp());
        if ($idsOf !== []) {
            // Not in the answer: the next batch tries them again.
            $this->store->release(array_merge(...array_values($idsOf)), 'not_answered');
        }
        $this->settings->set(self::FAILURES, '0');
        $this->settings->set(self::LAST_SENT, (string) $this->now());

        return ['sent' => count($body['events']) - $failed - count($idsOf), 'failed' => $failed, 'stop' => false];
    }

    /**
     * @param  list<int>  $ids
     * @return array{sent: int, failed: int, stop: bool}
     */
    private function refused(array $ids, PlatformError $error): array
    {
        $code = $error->errorCode();
        $this->settings->set(self::LAST_ERROR, $this->now().' '.$error->status().' '.$code);
        if (in_array($error->status(), [400, 413, 422], true)) {
            $this->store->failed($ids, $error->status().' '.$code);
            $this->log('The platform refused a batch of events', $error);

            return ['sent' => 0, 'failed' => count($ids), 'stop' => false];
        }
        $this->store->release($ids, $error->status().' '.$code);
        $failures = (int) $this->settings->get(self::FAILURES) + 1;
        $this->settings->set(self::FAILURES, (string) $failures);
        $wait = $error->retryAfter() ?? (int) min(self::MAX_WAIT_S, (2 ** min($failures, 20)) * 30);
        $this->settings->set(self::RETRY_AT, (string) ($this->now() + max(1, min(self::MAX_WAIT_S, $wait))));
        $this->log('Events not delivered, waiting', $error);

        return ['sent' => 0, 'failed' => 0, 'stop' => true];
    }

    /** What the platform must hear before the changes themselves: a gap, an overflow, the heartbeat. */
    private function announce(): void
    {
        $gap = $this->settings->get(Recorder::GAP);
        if ($gap !== null) {
            $this->store->insert(new Change('module.outbox_overflow', null, ['merged' => 0, 'since' => gmdate('Y-m-d\TH:i:s\Z', (int) $gap)]), Recorder::time($this->clock->now()));
            $this->settings->delete(Recorder::GAP);
        }
        // The overflow row is in before any pending row goes: a process that dies in between loses nothing.
        $overflow = $this->store->bound(OutboxStore::CAP, function (int $merged, string $since): void {
            $this->store->insert(new Change('module.outbox_overflow', null, ['merged' => $merged, 'since' => Events::iso($since)]), Recorder::time($this->clock->now()));
        });
        if ($overflow !== null && $this->log !== null) {
            $this->log->warning('outbox', 'The outbox overflowed; the platform is asked to read everything again', ['merged' => $overflow['merged']]);
        }
        $this->heartbeat->recordIfDue();
    }

    private function prune(int $now): void
    {
        $this->store->prune(gmdate('Y-m-d H:i:s', $now - 7 * 86400), gmdate('Y-m-d H:i:s', $now - 30 * 86400));
    }

    private function log(string $message, PlatformError $error): void
    {
        if ($this->log !== null) {
            $this->log->warning('outbox', $message, ['status' => $error->status(), 'error_code' => $error->errorCode()]);
        }
    }

    private function stamp(): string
    {
        return gmdate('Y-m-d H:i:s', $this->now());
    }

    private function now(): int
    {
        return (int) floor($this->clock->now());
    }
}

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

namespace Kronixon\Connect\Update;

use Kronixon\Connect\Platform\PlatformError;
use Kronixon\Connect\Support\Clock;
use Kronixon\Connect\Support\Log;
use Kronixon\Connect\Support\Settings;
use Throwable;

/**
 * The module's self-update (Spec §10, plan M6), only ever on the administrator's click:
 *
 * - check(): the platform's signed `GET /v1/module/latest`, kept in KX_UPDATE_CHECK — daily from the flush and on the
 *   settings page's button; the heartbeat tells the platform what is waiting for a click (heartbeat()).
 * - install(): the version the administrator confirmed is downloaded from the platform's host, checked (Package),
 *   written next to the module folder and swapped in (Swap). The request then ends: PHP already holds classes of the
 *   old files, so the upgrade runs in a fresh request.
 * - resume(), first thing in the next request of the settings page or the cron URL: with the new files' code,
 *   PrestaShop's own upgrade (`upgrade/upgrade-<version>.php`); on success the old folder is removed and
 *   `module.updated` recorded, on failure the old folder is put back, the module row gets its version and active
 *   flag back and `module.update_failed` is recorded. A process killed at any point leaves the journal
 *   (KX_UPDATE_STATE) for the next resume; an upgrade that never returns is run at most MAX_UPGRADE_ATTEMPTS
 *   times, then rolled back.
 */
final class Updater
{
    public const CHECK = 'KX_UPDATE_CHECK';

    public const STATE = 'KX_UPDATE_STATE';

    public const RESULT = 'KX_UPDATE_RESULT';

    /** Seconds between two automatic checks; after a failed one, CHECK_RETRY_S. */
    public const CHECK_EVERY_S = 86400;

    public const CHECK_RETRY_S = 21600;

    /** How long a request that still runs the old code after the swap waits for OPcache to see the new files. */
    public const SWAP_WAIT_S = 3600;

    /** Runs of the upgrade scripts that may die without returning (a killed worker is retried once) before the rollback. */
    public const MAX_UPGRADE_ATTEMPTS = 2;

    /** @var Settings */
    private $settings;

    /** @var ReleaseKeys */
    private $keys;

    /** @var Swap */
    private $swap;

    /** @var callable(array<string, string>): array<string, mixed> the signed answer of GET /v1/module/latest */
    private $ask;

    /** @var callable(Release): string the zip's bytes */
    private $download;

    /** @var callable(string, string): ?string the upgrade with the new code: null, or why it failed */
    private $upgrade;

    /** @var callable(string, bool): void the module row back to a version and active flag */
    private $restore;

    /** @var callable(string, array<string, mixed>): void records a module.* event */
    private $record;

    /** @var callable(): bool takes the update lock (false: another update runs) */
    private $lock;

    /** @var callable(): void */
    private $unlock;

    /** @var Clock */
    private $clock;

    /** @var Log */
    private $log;

    /** @var array{version: string, ps: string, php: string, api_host: string, active: bool} */
    private $shop;

    /**
     * @param  callable(array<string, string>): array<string, mixed>  $ask
     * @param  callable(Release): string  $download
     * @param  callable(string, string): ?string  $upgrade
     * @param  callable(string, bool): void  $restore
     * @param  callable(string, array<string, mixed>): void  $record
     * @param  callable(): bool  $lock
     * @param  callable(): void  $unlock
     * @param  array{version: string, ps: string, php: string, api_host: string, active: bool}  $shop  the running module version, PrestaShop, PHP, the build's API host, whether the module is enabled
     */
    public function __construct(Settings $settings, ReleaseKeys $keys, Swap $swap, callable $ask, callable $download, callable $upgrade, callable $restore, callable $record, callable $lock, callable $unlock, Clock $clock, Log $log, array $shop)
    {
        $this->settings = $settings;
        $this->keys = $keys;
        $this->swap = $swap;
        $this->ask = $ask;
        $this->download = $download;
        $this->upgrade = $upgrade;
        $this->restore = $restore;
        $this->record = $record;
        $this->lock = $lock;
        $this->unlock = $unlock;
        $this->clock = $clock;
        $this->log = $log;
        $this->shop = $shop;
    }

    /**
     * Asks the platform for the newest release and keeps its answer (or the error code).
     *
     * @return array<string, mixed> what KX_UPDATE_CHECK now holds
     */
    public function check(): array
    {
        $query = ['platform' => 'prestashop', 'version' => $this->shop['ps'], 'php' => $this->shop['php'], 'module' => $this->shop['version']];
        if ($this->keys->available()) {
            $query['channel'] = $this->keys->channel();
        }
        $check = ['at' => $this->now()];
        try {
            $answer = ($this->ask)($query);
            $check['latest'] = is_array($answer['latest'] ?? null) ? $answer['latest'] : null;
            $check['compatible'] = ($answer['compatible'] ?? false) === true;
            $check['reason'] = is_string($answer['reason'] ?? null) ? substr($answer['reason'], 0, 64) : null;
        } catch (PlatformError $error) {
            $check['error'] = $error->errorCode();
        }
        $this->settings->set(self::CHECK, (string) json_encode($check, JSON_UNESCAPED_SLASHES));

        return $check;
    }

    /** check() when the last one is a day old (six hours after a failed one); never throws. */
    public function checkIfDue(): void
    {
        $last = $this->stored(self::CHECK);
        $every = isset($last['error']) ? self::CHECK_RETRY_S : self::CHECK_EVERY_S;
        if ($this->now() - (int) ($last['at'] ?? 0) < $every) {
            return;
        }
        try {
            $this->check();
        } catch (Throwable $error) {
            $this->log->warning('update', 'The update check failed', ['error' => get_class($error)]);
        }
    }

    /**
     * What the settings page shows: the last check, the release waiting (if any) and the last outcome.
     *
     * @return array{checked_at: int|null, error: string|null, release: Release|null, incompatible: string|null, url: string|null, installable: bool, result: array<string, mixed>|null, finishing: string|null}
     */
    public function offer(): array
    {
        $check = $this->stored(self::CHECK);
        $state = $this->stored(self::STATE);
        $release = $this->waiting();
        $latest = $check['latest'] ?? null;
        $newer = is_array($latest) && is_string($latest['version'] ?? null) && version_compare($latest['version'], $this->shop['version'], '>');

        return [
            'checked_at' => isset($check['at']) ? (int) $check['at'] : null,
            'error' => is_string($check['error'] ?? null) ? $check['error'] : null,
            'release' => $release,
            'incompatible' => $newer && ($check['compatible'] ?? false) !== true ? (string) ($check['reason'] ?? 'incompatible') : null,
            'url' => $release !== null ? $release->url : null,
            'installable' => $release !== null && $this->keys->available(),
            'result' => $this->stored(self::RESULT) ?: null,
            // Files swapped in, the upgrade not run yet (this request still runs the old code).
            'finishing' => isset($state['to']) ? (string) $state['to'] : null,
        ];
    }

    /**
     * `update` of module.heartbeat: the newer compatible version waiting for the administrator's click.
     *
     * @return array{available: string|null, pending_confirmation: bool}
     */
    public function heartbeat(): array
    {
        $release = $this->waiting();

        return ['available' => $release !== null ? $release->version : null, 'pending_confirmation' => $release !== null];
    }

    /**
     * The administrator's click: downloads, checks and swaps in $version, which must be the one the page offered.
     * The caller ends the request and sends the browser to a page that calls resume().
     *
     * @throws UpdateError
     */
    public function install(string $version): void
    {
        if (! ($this->lock)()) {
            throw new UpdateError(UpdateError::BUSY, 'busy');
        }
        $release = null;
        try {
            if ($this->stored(self::STATE, true) !== []) {
                throw new UpdateError(UpdateError::BUSY, 'busy', 'an update waits to be finished');
            }
            $release = $this->waiting();
            if ($release === null || $release->version !== $version) {
                throw UpdateError::refused('stale_offer', $version);
            }
            Package::admissible($release, $this->keys, $this->shop['version']);
            $this->swap->preflight(2 * $release->size);
            $zip = ($this->download)($release);
            $files = Package::open($zip, $release, $this->keys, $this->shop['version'], $this->shop['api_host'], $this->shop['ps'], $this->shop['php']);
            unset($zip);
            $this->swap->stage($files);
            unset($files);
            // From here a resume() finishes the update, also after a kill.
            $this->settings->set(self::STATE, (string) json_encode(['step' => 'swapped', 'from' => $this->shop['version'], 'to' => $release->version, 'active' => $this->shop['active'], 'at' => $this->now()]));
            try {
                $this->swap->swap();
            } catch (UpdateError $error) {
                $this->settings->delete(self::STATE);
                throw $error;
            }
            $this->log->info('update', 'New module files are in place; the upgrade runs in the next request', ['from' => $this->shop['version'], 'to' => $release->version]);
        } catch (UpdateError $error) {
            if ($error->kind() !== UpdateError::BUSY) {
                $this->failed($this->shop['version'], $release !== null ? $release->version : $version, $error);
            }
            throw $error;
        } finally {
            ($this->unlock)();
        }
    }

    /**
     * Finishes an update whose files were swapped in: the upgrade with this request's (new) code, or the rollback.
     * Null when there is nothing to finish or another request is finishing or has finished it.
     *
     * Only the journal read from the database under the lock decides: the copy this request loaded when it started
     * may predate an upgrade, a rollback or an attempt another request has since finished or counted (a cron call
     * that spent its time sending events while the settings page finished the update).
     *
     * @return array<string, mixed>|null the outcome (KX_UPDATE_RESULT)
     */
    public function resume(): ?array
    {
        // A request that started with no journal has nothing to finish; the copy only spares it the lock.
        if ($this->stored(self::STATE) === [] || ! ($this->lock)()) {
            return null;
        }
        try {
            $state = $this->stored(self::STATE, true);
            if ($state === []) {
                return null;
            }
            $from = (string) ($state['from'] ?? '');
            $to = (string) ($state['to'] ?? '');
            if ($this->shop['version'] !== $to) {
                // This request still runs the old code: OPcache checks the files only every revalidate_freq seconds.
                if ($this->swap->swapped() && $this->now() - (int) ($state['at'] ?? 0) < self::SWAP_WAIT_S) {
                    return null;
                }
                // No swap happened (killed before it), or still the old code after an hour (files put back by hand).
                $this->settings->delete(self::STATE);
                $this->swap->discard();

                return $this->failed($from, $to, new UpdateError(UpdateError::FAILED, 'swap_lost'));
            }
            // The classes are those of $to, but the folder on disk is not (any more): another request rolled it back
            // after this one loaded them and died before it removed the journal. That outcome stands, nothing here
            // writes $to into the module row; a request with the old code finishes the journal.
            $live = $this->swap->liveVersion();
            if ($live !== $to) {
                $this->log->warning('update', 'The module folder is not the version this request runs; the upgrade is left alone', ['to' => $to, 'folder' => $live]);

                return null;
            }
            // An upgrade that dies where PHP cannot catch it (memory or time limit, exit, a killed worker) leaves the
            // journal as it was: the attempt is counted before the scripts run, and after MAX_UPGRADE_ATTEMPTS that
            // never returned the old version comes back instead of another run.
            $attempts = (int) ($state['attempts'] ?? 0);
            if ($attempts >= self::MAX_UPGRADE_ATTEMPTS) {
                $problem = 'upgrade_died: the upgrade stopped '.$attempts.' times before it returned';
            } else {
                $state['attempts'] = $attempts + 1;
                $this->settings->set(self::STATE, (string) json_encode($state));
                try {
                    $problem = ($this->upgrade)($from, $to);
                } catch (Throwable $error) {
                    $problem = get_class($error).': '.$error->getMessage();
                }
            }
            if ($problem === null) {
                if (! $this->swap->commit()) {
                    $this->log->warning('update', 'The previous module folder could not be removed', ['folder' => $this->swap->prev()]);
                }
                $this->settings->delete(self::STATE);
                $this->event('module.updated', ['from' => $from, 'to' => $to]);
                $this->log->info('update', 'Module updated', ['from' => $from, 'to' => $to]);

                return $this->result(['outcome' => 'updated', 'from' => $from, 'to' => $to]);
            }
            $restored = $this->swap->rollback();
            if ($restored) {
                ($this->restore)($from, ($state['active'] ?? true) === true);
            }
            $this->settings->delete(self::STATE);

            return $this->failed($from, $to, new UpdateError(UpdateError::FAILED, $restored ? 'upgrade_failed' : 'rollback_failed', $problem));
        } finally {
            ($this->unlock)();
        }
    }

    /** The newer, compatible, well-formed release of the last check, or null. */
    private function waiting(): ?Release
    {
        $check = $this->stored(self::CHECK);
        if (($check['compatible'] ?? false) !== true || ! is_array($check['latest'] ?? null)) {
            return null;
        }
        try {
            $release = Release::fromAnswer($check['latest']);
        } catch (UpdateError $error) {
            return null;
        }

        return version_compare($release->version, $this->shop['version'], '>') ? $release : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function failed(string $from, string $to, UpdateError $error): array
    {
        $detail = substr($error->getMessage(), 0, 255);
        $this->event('module.update_failed', ['from' => $from, 'to' => $to, 'error' => $detail]);
        $this->log->warning('update', 'Module update failed', ['from' => $from, 'to' => $to, 'error' => $detail]);

        return $this->result(['outcome' => 'failed', 'kind' => $error->kind(), 'code' => $error->code(), 'from' => $from, 'to' => $to]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function event(string $type, array $payload): void
    {
        try {
            ($this->record)($type, $payload);
        } catch (Throwable $error) {
            $this->log->warning('update', 'The update event could not be recorded', ['type' => $type]);
        }
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function result(array $result): array
    {
        $result['at'] = $this->now();
        $this->settings->set(self::RESULT, (string) json_encode($result, JSON_UNESCAPED_SLASHES));

        return $result;
    }

    /**
     * @param  bool  $fresh  the database now, not the copy the request loaded (Settings::fresh())
     * @return array<string, mixed>
     */
    private function stored(string $key, bool $fresh = false): array
    {
        $data = json_decode((string) ($fresh ? $this->settings->fresh($key) : $this->settings->get($key)), true);

        return is_array($data) ? $data : [];
    }

    private function now(): int
    {
        return (int) floor($this->clock->now());
    }
}

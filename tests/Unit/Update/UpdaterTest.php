<?php

declare(strict_types=1);

namespace Kronixon\Connect\Tests\Unit\Update;

use Kronixon\Connect\Platform\PlatformError;
use Kronixon\Connect\Protocol;
use Kronixon\Connect\Support\Log;
use Kronixon\Connect\Tests\Support\FixedClock;
use Kronixon\Connect\Tests\Support\MemoryFiles;
use Kronixon\Connect\Tests\Support\MemorySettings;
use Kronixon\Connect\Tests\Support\Releases;
use Kronixon\Connect\Update\Release;
use Kronixon\Connect\Update\ReleaseKeys;
use Kronixon\Connect\Update\Swap;
use Kronixon\Connect\Update\UpdateError;
use Kronixon\Connect\Update\Updater;
use PHPUnit\Framework\TestCase;

/**
 * The self-update from the check to the event (Spec §10): only on a click, checked before anything is written, the
 * upgrade with the new code in the next request, the old folder back when it fails.
 */
final class UpdaterTest extends TestCase
{
    private const MODULES = '/shop/modules';

    private const LIVE = '/shop/modules/kronixonconnect';

    /** @var string */
    private static $zip;

    /** @var MemorySettings */
    private $settings;

    /** @var MemoryFiles */
    private $fs;

    /** @var FixedClock */
    private $clock;

    /** @var array<string, mixed>|PlatformError the platform's answer */
    private $answer;

    /** @var string|null what the platform's host serves */
    private $served;

    /** @var list<array<string, string>> */
    private $asked = [];

    /** @var list<string> */
    private $downloads = [];

    /** @var list<array{0: string, 1: string}> */
    private $upgrades = [];

    /** @var string|null what the upgrade answers */
    private $upgradeFails;

    /** @var list<array{0: string, 1: bool}> */
    private $restored = [];

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private $events = [];

    /** @var bool */
    private $locked = false;

    public static function setUpBeforeClass(): void
    {
        self::$zip = Releases::build('0.9.0');
    }

    protected function setUp(): void
    {
        $this->settings = new MemorySettings();
        $this->fs = new MemoryFiles();
        $this->fs->seed(self::MODULES, []);
        $this->fs->seed(self::LIVE, ['kronixonconnect.php' => 'the running 0.7.0', 'src/Protocol.php' => 'old']);
        $this->clock = new FixedClock(1790772000.0);
        $this->answer = ['latest' => Releases::latest(self::$zip, '0.9.0'), 'compatible' => true, 'reason' => null];
        $this->served = self::$zip;
    }

    public function test_a_check_keeps_the_signed_answer_and_the_heartbeat_names_the_waiting_version(): void
    {
        $updater = $this->updater();
        self::assertSame(['available' => null, 'pending_confirmation' => false], $updater->heartbeat());

        $updater->check();

        self::assertSame(['platform' => 'prestashop', 'version' => '9.1.5', 'php' => '8.4.0', 'module' => Protocol::MODULE_VERSION, 'channel' => 'staging'], $this->asked[0]);
        self::assertSame(['available' => '0.9.0', 'pending_confirmation' => true], $updater->heartbeat());
        $offer = $updater->offer();
        self::assertInstanceOf(Release::class, $offer['release']);
        self::assertSame('0.9.0', $offer['release']->version);
        self::assertTrue($offer['installable']);
        self::assertSame('https://connect-staging.kronixon.com/dl/kronixonconnect-0.9.0.zip', $offer['url']);
        self::assertSame([], $this->downloads, 'a check downloads nothing');
        self::assertSame([], $this->events);
    }

    public function test_an_incompatible_or_older_release_is_offered_to_nobody(): void
    {
        $this->answer = ['latest' => Releases::latest(self::$zip, '0.9.0'), 'compatible' => false, 'reason' => 'php_too_old'];
        $updater = $this->updater();
        $updater->check();
        self::assertNull($updater->offer()['release']);
        self::assertSame('php_too_old', $updater->offer()['incompatible']);
        self::assertFalse($updater->heartbeat()['pending_confirmation']);

        $this->answer = ['latest' => Releases::latest(self::$zip, '0.6.0'), 'compatible' => true, 'reason' => null];
        $updater->check();
        self::assertNull($updater->offer()['release']);
        self::assertNull($updater->offer()['incompatible']);
    }

    public function test_the_automatic_check_runs_once_a_day_and_six_hours_after_a_failure(): void
    {
        $updater = $this->updater();
        $updater->checkIfDue();
        $updater->checkIfDue();
        self::assertCount(1, $this->asked);

        $this->clock->advance(Updater::CHECK_EVERY_S);
        $this->answer = new PlatformError(0, PlatformError::NETWORK);
        $updater->checkIfDue();
        self::assertCount(2, $this->asked);
        self::assertSame(PlatformError::NETWORK, $updater->offer()['error']);

        $this->clock->advance(Updater::CHECK_RETRY_S - 1);
        $updater->checkIfDue();
        self::assertCount(2, $this->asked);
        $this->clock->advance(1);
        $updater->checkIfDue();
        self::assertCount(3, $this->asked);
    }

    public function test_the_click_swaps_the_files_and_the_next_request_upgrades_with_the_new_code(): void
    {
        $this->updater()->check();
        $this->updater()->install('0.9.0');

        self::assertSame(['https://connect-staging.kronixon.com/dl/kronixonconnect-0.9.0.zip'], $this->downloads);
        self::assertStringContainsString("MODULE_VERSION = '0.9.0';", (string) $this->fs->read(self::LIVE.'/src/Protocol.php'));
        self::assertSame([], $this->upgrades, 'the upgrade does not run with the classes of the old files');
        self::assertSame([], $this->events);
        self::assertFalse($this->locked);
        // A second click before the upgrade finished changes nothing.
        $this->expectFailure(UpdateError::BUSY, 'busy', function (): void {
            $this->updater()->install('0.9.0');
        });

        // The next request runs the new files' code.
        $result = $this->updater('0.9.0')->resume();

        self::assertSame([['0.7.0', '0.9.0']], $this->upgrades);
        self::assertSame('updated', $result['outcome'] ?? null);
        self::assertSame([['module.updated', ['from' => '0.7.0', 'to' => '0.9.0']]], $this->events);
        self::assertFalse($this->fs->exists((new Swap($this->fs, self::MODULES))->prev()), 'the old folder is gone');
        self::assertNull($this->settings->get(Updater::STATE));
        self::assertNull($this->updater('0.9.0')->resume(), 'nothing left to finish');
        self::assertSame(['available' => null, 'pending_confirmation' => false], $this->updater('0.9.0')->heartbeat());
    }

    public function test_a_failed_upgrade_puts_the_old_folder_and_module_row_back(): void
    {
        $this->updater()->check();
        $this->updater()->install('0.9.0');
        $this->upgradeFails = 'upgrade-0.9.0.php failed';

        $result = $this->updater('0.9.0')->resume();

        self::assertSame(['outcome' => 'failed', 'kind' => UpdateError::FAILED, 'code' => 'upgrade_failed', 'from' => '0.7.0', 'to' => '0.9.0'], array_diff_key((array) $result, ['at' => true]));
        self::assertSame('the running 0.7.0', $this->fs->read(self::LIVE.'/kronixonconnect.php'));
        self::assertSame([['0.7.0', true]], $this->restored, 'the module row gets its version and active flag back');
        self::assertSame('module.update_failed', $this->events[0][0]);
        self::assertSame('0.9.0', $this->events[0][1]['to']);
        self::assertStringContainsString('upgrade-0.9.0.php failed', (string) $this->events[0][1]['error']);
        self::assertNull($this->settings->get(Updater::STATE));
    }

    public function test_a_process_killed_after_the_swap_is_finished_by_the_next_request(): void
    {
        $this->updater()->check();
        $this->updater()->install('0.9.0');
        // Killed before any page could resume: the journal stays, the files are the new ones.
        self::assertNotNull($this->settings->get(Updater::STATE));

        $this->clock->advance(3600);
        $result = $this->updater('0.9.0')->resume();

        self::assertSame('updated', $result['outcome'] ?? null);
    }

    public function test_a_request_that_still_runs_the_old_code_waits_for_the_new_one(): void
    {
        $this->updater()->check();
        $this->updater()->install('0.9.0');

        // OPcache serves the old scripts until revalidate_freq has passed: nothing is decided, the page says so.
        self::assertNull($this->updater()->resume());
        self::assertSame('0.9.0', $this->updater()->offer()['finishing']);
        self::assertSame([], $this->upgrades);
        self::assertSame([], $this->events);

        // Still the old code an hour later: the files were put back by hand.
        $this->clock->advance(Updater::SWAP_WAIT_S);
        $result = $this->updater()->resume();
        self::assertSame('swap_lost', $result['code'] ?? null);
        self::assertNull($this->updater()->offer()['finishing']);
    }

    public function test_a_worker_killed_between_the_journal_and_the_swap_blocks_nothing(): void
    {
        $this->updater()->check();
        // The journal was written, then the process died before the first rename.
        $this->settings->set(Updater::STATE, (string) json_encode(['step' => 'swapped', 'from' => '0.7.0', 'to' => '0.9.0', 'active' => true, 'at' => 1790772000]));
        $this->fs->seed((new Swap($this->fs, self::MODULES))->next(), ['kronixonconnect.php' => 'staged']);

        $result = $this->updater()->resume();

        self::assertSame('swap_lost', $result['code'] ?? null);
        self::assertFalse($this->fs->exists((new Swap($this->fs, self::MODULES))->next()), 'the stage is removed');
        self::assertSame('the running 0.7.0', $this->fs->read(self::LIVE.'/kronixonconnect.php'));
        // The administrator can try again at once.
        $this->updater()->install('0.9.0');
        self::assertSame('updated', $this->updater('0.9.0')->resume()['outcome'] ?? null);
    }

    public function test_a_tampered_zip_is_refused_before_anything_is_written(): void
    {
        $this->updater()->check();
        $this->served = substr_replace(self::$zip, 'X', 5000, 1);

        $this->expectFailure(UpdateError::REFUSED, 'sha256_mismatch', function (): void {
            $this->updater()->install('0.9.0');
        });

        self::assertSame('the running 0.7.0', $this->fs->read(self::LIVE.'/kronixonconnect.php'));
        self::assertFalse($this->fs->exists((new Swap($this->fs, self::MODULES))->next()));
        self::assertNull($this->settings->get(Updater::STATE));
        self::assertSame('module.update_failed', $this->events[0][0]);
        self::assertSame(['from' => '0.7.0', 'to' => '0.9.0', 'error' => 'sha256_mismatch'], $this->events[0][1]);
        self::assertSame(['outcome' => 'failed', 'kind' => UpdateError::REFUSED, 'code' => 'sha256_mismatch', 'from' => '0.7.0', 'to' => '0.9.0'], array_diff_key((array) $this->updater()->offer()['result'], ['at' => true]));
    }

    public function test_only_the_offered_version_installs(): void
    {
        $this->expectFailure(UpdateError::REFUSED, 'stale_offer', function (): void {
            $this->updater()->install('0.9.0');
        });
        $this->updater()->check();
        $this->expectFailure(UpdateError::REFUSED, 'stale_offer', function (): void {
            $this->updater()->install('0.9.1');
        });
        self::assertSame([], $this->downloads);
    }

    public function test_a_read_only_module_folder_downloads_nothing_and_points_to_the_zip(): void
    {
        $this->updater()->check();
        $this->fs->readOnly = [self::LIVE];

        $this->expectFailure(UpdateError::SERVER, 'read_only', function (): void {
            $this->updater()->install('0.9.0');
        });
        self::assertSame([], $this->downloads);
        self::assertSame('https://connect-staging.kronixon.com/dl/kronixonconnect-0.9.0.zip', $this->updater()->offer()['url']);
    }

    public function test_one_update_at_a_time(): void
    {
        $this->updater()->check();
        $this->locked = true;

        $this->expectFailure(UpdateError::BUSY, 'busy', function (): void {
            $this->updater()->install('0.9.0');
        });
        self::assertSame([], $this->events, 'a busy click is no failed update');
        self::assertNull($this->updater('0.9.0')->resume());
    }

    public function test_a_development_checkout_offers_the_zip_but_installs_nothing(): void
    {
        $updater = $this->updater(Protocol::MODULE_VERSION, '');
        $updater->check();

        self::assertArrayNotHasKey('channel', $this->asked[0]);
        self::assertFalse($updater->offer()['installable']);
        $this->expectFailure(UpdateError::SERVER, 'no_release_keys', function () use ($updater): void {
            $updater->install('0.9.0');
        });
    }

    private function updater(string $running = Protocol::MODULE_VERSION, string $keys = 'staging'): Updater
    {
        return new Updater(
            $this->settings,
            $keys === '' ? new ReleaseKeys('', '') : Releases::keys($keys),
            new Swap($this->fs, self::MODULES),
            function (array $query): array {
                $this->asked[] = $query;
                if ($this->answer instanceof PlatformError) {
                    throw $this->answer;
                }

                return $this->answer;
            },
            function (Release $release): string {
                $this->downloads[] = $release->url;

                return (string) $this->served;
            },
            function (string $from, string $to): ?string {
                $this->upgrades[] = [$from, $to];

                return $this->upgradeFails;
            },
            function (string $version, bool $active): void {
                $this->restored[] = [$version, $active];
            },
            function (string $type, array $payload): void {
                $this->events[] = [$type, $payload];
            },
            function (): bool {
                if ($this->locked) {
                    return false;
                }
                $this->locked = true;

                return true;
            },
            function (): void {
                $this->locked = false;
            },
            $this->clock,
            new Log(static function (): void {
            }),
            ['version' => $running, 'ps' => '9.1.5', 'php' => '8.4.0', 'api_host' => 'https://connect-staging.kronixon.com', 'active' => true]
        );
    }

    private function expectFailure(string $kind, string $code, callable $action): void
    {
        try {
            $action();
            self::fail('no error, expected '.$code);
        } catch (UpdateError $error) {
            self::assertSame($kind, $error->kind());
            self::assertSame($code, $error->code());
        }
    }
}

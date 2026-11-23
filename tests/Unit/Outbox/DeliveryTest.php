<?php

declare(strict_types=1);

namespace Kronixon\Connect\Tests\Unit\Outbox;

use Kronixon\Connect\Auth\SecretStore;
use Kronixon\Connect\Outbox\Delivery;
use Kronixon\Connect\Outbox\Flusher;
use Kronixon\Connect\Outbox\Heartbeat;
use Kronixon\Connect\Tests\Support\MemorySettings;
use PHPUnit\Framework\TestCase;

/**
 * A page view flushes only when the server can send the answer first; otherwise nothing is registered
 * and the page never waits for the platform. The delivery mode the settings page and the heartbeat show.
 */
final class DeliveryTest extends TestCase
{
    private const NOW = 1790772000;

    /** @var MemorySettings */
    private $settings;

    /** @var list<callable> shutdown functions registered */
    private $registered = [];

    /** @var list<string> finish functions called */
    private $finished = [];

    /** @var int flushes run */
    private $flushes = 0;

    protected function setUp(): void
    {
        $this->settings = new MemorySettings();
        $this->settings->set(SecretStore::STORE_ID, '0192b2e4-9b3c-7d4e-9f50-6b7c8d9e0f1a');
    }

    public function test_without_a_finish_function_no_flush_is_registered(): void
    {
        $delivery = $this->delivery([]);

        self::assertNull($delivery->finisher());
        self::assertFalse($delivery->onPageView($this->settings, self::NOW, $this->flush()));
        self::assertSame([], $this->registered, 'mod_php: nothing runs after the page');
    }

    public function test_under_fpm_the_flush_runs_after_the_answer_is_finished(): void
    {
        $delivery = $this->delivery(['fastcgi_finish_request']);

        self::assertTrue($delivery->onPageView($this->settings, self::NOW, $this->flush()));
        self::assertCount(1, $this->registered);
        self::assertSame(0, $this->flushes, 'not before the shutdown');
        ($this->registered[0])();
        self::assertSame(['fastcgi_finish_request'], $this->finished);
        self::assertSame(1, $this->flushes);
    }

    public function test_under_litespeed_its_finish_function_is_used(): void
    {
        $delivery = $this->delivery(['litespeed_finish_request']);

        self::assertSame('litespeed_finish_request', $delivery->finisher());
        self::assertTrue($delivery->onPageView($this->settings, self::NOW, $this->flush()));
        ($this->registered[0])();
        self::assertSame(['litespeed_finish_request'], $this->finished);
    }

    public function test_when_the_answer_cannot_be_finished_the_flush_does_not_run(): void
    {
        $delivery = $this->delivery(['fastcgi_finish_request'], false);

        self::assertTrue($delivery->onPageView($this->settings, self::NOW, $this->flush()));
        ($this->registered[0])();
        self::assertSame(0, $this->flushes);
    }

    public function test_a_page_view_does_not_flush_when_unpaired_recent_waiting_or_a_cron_runs(): void
    {
        $delivery = $this->delivery(['fastcgi_finish_request']);
        $cases = [
            'unpaired' => [SecretStore::STORE_ID, null],
            'flushed 59 s ago' => [Flusher::LAST_FLUSH, (string) (self::NOW - 59)],
            'waiting for Retry-After' => [Flusher::RETRY_AT, (string) (self::NOW + 1)],
            'a cron called 299 s ago' => [Heartbeat::CRON_AT, (string) (self::NOW - 299)],
        ];
        foreach ($cases as $name => [$key, $value]) {
            $this->setUp();
            if ($value === null) {
                $this->settings->delete($key);
            } else {
                $this->settings->set($key, $value);
            }
            self::assertFalse($delivery->onPageView($this->settings, self::NOW, $this->flush()), $name);
        }
        self::assertSame([], $this->registered);
    }

    public function test_the_mode_is_cron_then_page_views_then_pull_only(): void
    {
        $this->settings->set(Heartbeat::CRON_AT, (string) (self::NOW - Heartbeat::CRON_FRESH_S));
        self::assertSame(Delivery::CRON, $this->delivery([])->mode($this->settings, self::NOW));

        $this->settings->set(Heartbeat::CRON_AT, (string) (self::NOW - Heartbeat::CRON_FRESH_S - 1));
        self::assertSame(Delivery::PAGE_VIEWS, $this->delivery(['litespeed_finish_request'])->mode($this->settings, self::NOW));
        self::assertSame(Delivery::PULL_ONLY, $this->delivery([])->mode($this->settings, self::NOW));
    }

    /**
     * @param  list<string>  $available  the finish functions this "server" has
     */
    private function delivery(array $available, bool $finishes = true): Delivery
    {
        return new Delivery(static function (string $function) use ($available): bool {
            return in_array($function, $available, true);
        }, function (callable $shutdown): void {
            $this->registered[] = $shutdown;
        }, function (string $function) use ($finishes): bool {
            $this->finished[] = $function;

            return $finishes;
        });
    }

    /**
     * @return callable(): void
     */
    private function flush(): callable
    {
        return function (): void {
            $this->flushes++;
        };
    }
}

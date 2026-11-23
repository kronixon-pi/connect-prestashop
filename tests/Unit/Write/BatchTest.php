<?php

declare(strict_types=1);

namespace Kronixon\Connect\Tests\Unit\Write;

use Kronixon\Connect\Api\ApiError;
use Kronixon\Connect\Api\Budget;
use Kronixon\Connect\Api\Request;
use Kronixon\Connect\Api\Response;
use Kronixon\Connect\Support\Log;
use Kronixon\Connect\Tests\Support\CatalogDb;
use Kronixon\Connect\Tests\Support\FakeWriter;
use Kronixon\Connect\Tests\Support\FixedClock;
use Kronixon\Connect\Tests\Support\ProtocolSchemas;
use Kronixon\Connect\Write\Batch;
use Kronixon\Connect\Write\Item;
use Kronixon\Connect\Write\ItemError;
use Kronixon\Connect\Write\Warnings;
use Kronixon\Connect\Write\Writer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once dirname(__DIR__, 2).'/Support/prestashop-stubs.php';

/**
 * The batch around every write endpoint: the envelope, one transaction per item, the time budget and
 * the answer.
 */
final class BatchTest extends TestCase
{
    /** @var CatalogDb */
    private $db;

    /** @var FixedClock */
    private $clock;

    /** @var FakeWriter|null */
    private $writer;

    /** @var list<array<string, string|null>> */
    private $logged = [];

    protected function setUp(): void
    {
        $this->db = new CatalogDb();
        $this->clock = new FixedClock(1790772000.0);
    }

    public function test_items_are_written_in_order_and_the_answer_matches_the_schema(): void
    {
        $data = $this->run_batch([['key' => 'a', 'kx_id' => '0192B2E4-2f7a-7c5b-8a1d-3e4f5a6b7c8d'], ['key' => '2']], static function (Item $item, Warnings $warnings): array {
            $warnings->add('meta_title_cut');

            return ['external_id' => '5', 'action' => 'created'];
        });

        self::assertSame(['start', 'prepare a', 'write a', 'seal a', 'committed a', 'prepare 2', 'write 2', 'seal 2', 'committed 2', 'finish', 'hashes'], $this->steps());
        self::assertSame(['a', '2'], array_column($data['results'], 'key'));
        self::assertSame('0192b2e4-2f7a-7c5b-8a1d-3e4f5a6b7c8d', $data['results'][0]['kx_id']);
        self::assertSame([['meta_title_cut'], ['meta_title_cut']], array_column($data['results'], 'warnings'));
        self::assertSame(str_repeat('a', 64), $data['results'][1]['content_hash']);
        self::assertSame(['ok' => 2, 'error' => 0, 'skipped' => 0, 'time_ms' => 0], $data['summary']);
        self::assertFalse($data['continue']);
        self::assertSame([], ProtocolSchemas::errors('tags.json', $data));
    }

    public function test_a_refused_item_is_rolled_back_alone(): void
    {
        $data = $this->run_batch([['key' => 'a'], ['key' => 'b'], ['key' => 'c']], function (Item $item, Warnings $warnings): array {
            $this->db->execute("INSERT INTO ps_tag (id_lang, name) VALUES (1, '".$item->key()."')");
            if ($item->key() === 'b') {
                $warnings->add('lost');
                throw new ItemError('match_ambiguous', 'More than one', 'match_by', ['candidates' => ['1', '2']]);
            }

            return ['external_id' => '5', 'action' => 'created'];
        });

        self::assertSame(['a', 'c'], array_column($this->db->rows('SELECT name FROM ps_tag ORDER BY name'), 'name'));
        self::assertSame(['key' => 'b', 'status' => 'error', 'code' => 'match_ambiguous', 'message' => 'More than one', 'field' => 'match_by', 'candidates' => ['1', '2'], 'warnings' => ['lost']], $data['results'][1]);
        self::assertSame([], $data['results'][2]['warnings'], 'a warning belongs to its item');
        self::assertContains('rolledBack b', $this->steps());
        self::assertNotContains('committed b', $this->steps());
        self::assertSame(['ok' => 2, 'error' => 1, 'skipped' => 0, 'time_ms' => 0], $data['summary']);
    }

    /**
     * @return iterable<string, array{callable(): bool, list<string>}>
     */
    public function failedCleanUps(): iterable
    {
        yield 'something is left' => [static function (): bool {
            return false;
        }, ['TypeError']];
        yield 'the clean-up throws' => [static function (): bool {
            throw new RuntimeException('hook');
        }, ['RuntimeException', 'TypeError']];
    }

    /**
     * @dataProvider failedCleanUps
     *
     * @param  callable(): bool  $undo
     * @param  list<string>  $logged
     */
    public function test_a_clean_up_that_fails_is_the_items_error_and_the_batch_goes_on(callable $undo, array $logged): void
    {
        $data = $this->run_batch([['key' => '1'], ['key' => '2'], ['key' => '3'], ['key' => '4'], ['key' => '5']], function (Item $item) use ($undo): array {
            if ($this->writer !== null) {
                $this->writer->undo = $undo;
            }
            if ($item->key() === '3') {
                throw new \TypeError('hook');
            }

            return ['external_id' => '5', 'action' => 'created'];
        });

        self::assertSame(['ok', 'ok', 'error', 'ok', 'ok'], array_column($data['results'], 'status'));
        self::assertSame('cleanup_failed', $data['results'][2]['code']);
        self::assertSame(['ok' => 4, 'error' => 1, 'skipped' => 0, 'time_ms' => 0], $data['summary']);
        self::assertCount(4, array_column($data['results'], 'content_hash'), 'the written items are reported in full');
        self::assertSame($logged, array_map(static function (array $row): string {
            return json_decode((string) $row['context'], true)['exception'];
        }, $this->logged));
        self::assertSame([], ProtocolSchemas::errors('products.json', $data));
    }

    public function test_an_option_of_the_wrong_value_refuses_the_request(): void
    {
        foreach (['reindex' => 'later', 'strict_languages' => 'yes'] as $option => $value) {
            $request = Request::fromServer(['REQUEST_METHOD' => 'POST', 'QUERY_STRING' => 'path=products'], (string) json_encode(['items' => [['key' => 'a']], 'options' => [$option => $value]]));
            try {
                (new Batch($this->db, new Log(static function (): void {
                })))->run($request, new Budget($this->clock, 25), static function (Item $options): Writer {
                    $options->choice('reindex', ['deferred', 'inline', 'none']);
                    $options->bool('strict_languages');
                    self::fail('accepted');
                });
                self::fail('the options were accepted');
            } catch (ApiError $error) {
                self::assertSame([422, 'validation_failed', ['errors' => ['options.'.$option]]], [$error->status(), $error->errorCode(), $error->details()]);
            }
        }
    }

    public function test_an_unexpected_error_is_logged_by_class_and_never_quoted(): void
    {
        $data = $this->run_batch([['key' => 'a'], ['key' => 'b'], ['key' => 'c']], static function (Item $item): array {
            if ($item->key() === 'a') {
                throw new RuntimeException('SELECT secret FROM ps_x');
            }
            if ($item->key() === 'b') {
                throw new \PrestaShopException('Property Product->ean13 is not valid');
            }
            throw new \PrestaShopDatabaseException('You have an error in your SQL: Product->x');
        });

        self::assertSame(['module_error', 'validation_failed', 'module_error'], array_column($data['results'], 'code'));
        self::assertSame('ean13', $data['results'][1]['field']);
        self::assertStringNotContainsString('SELECT', (string) json_encode($data));
        self::assertStringNotContainsString('SQL', (string) json_encode($data));
        self::assertSame(['RuntimeException', 'PrestaShopException', 'PrestaShopDatabaseException'], array_map(static function (array $row): string {
            return json_decode((string) $row['context'], true)['exception'];
        }, $this->logged));
        self::assertNotContains('finish', $this->steps(), 'nothing was written');
    }

    public function test_what_the_time_budget_does_not_cover_is_skipped_in_order(): void
    {
        $data = $this->run_batch([['key' => 'a'], ['key' => 'b'], ['key' => 'c']], function (): array {
            $this->clock->advance(12.0);

            return ['external_id' => '5', 'action' => 'updated'];
        });

        self::assertSame(['ok', 'ok', 'skipped'], array_column($data['results'], 'status'));
        self::assertSame('time_budget', $data['results'][2]['code']);
        self::assertTrue($data['continue']);
        self::assertSame(['ok' => 2, 'error' => 0, 'skipped' => 1, 'time_ms' => 24000], $data['summary']);
        self::assertSame([], ProtocolSchemas::errors('products.json', $data));
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public function brokenEnvelopes(): iterable
    {
        yield 'no JSON' => ['{', 400, 'bad_request'];
        yield 'no items' => ['{}', 422, 'validation_failed'];
        yield 'empty items' => ['{"items":[]}', 422, 'validation_failed'];
        yield 'items is a map' => ['{"items":{"a":{"key":"a"}}}', 422, 'validation_failed'];
        yield 'an item is no object' => ['{"items":["a"]}', 422, 'validation_failed'];
        yield 'no key' => ['{"items":[{"name":"x"}]}', 422, 'validation_failed'];
        yield 'empty key' => ['{"items":[{"key":""}]}', 422, 'validation_failed'];
        yield 'a key of 65' => ['{"items":[{"key":"'.str_repeat('k', 65).'"}]}', 422, 'validation_failed'];
        yield 'a key twice' => ['{"items":[{"key":"1"},{"key":"1"}]}', 422, 'validation_failed'];
        yield 'options is no object' => ['{"options":1,"items":[{"key":"1"}]}', 422, 'validation_failed'];
        yield '101 items' => [(string) json_encode(['items' => array_map(static function (int $i): array {
            return ['key' => "k$i"];
        }, range(1, 101))]), 413, 'batch_too_large'];
    }

    /**
     * @dataProvider brokenEnvelopes
     */
    public function test_a_broken_envelope_writes_nothing(string $body, int $status, string $code): void
    {
        try {
            $this->send($body, static function (): array {
                self::fail('nothing may be written');
            });
            self::fail('the envelope was accepted');
        } catch (ApiError $error) {
            self::assertSame([$status, $code], [$error->status(), $error->errorCode()]);
        }
    }

    public function test_a_hundred_items_are_one_batch(): void
    {
        $data = $this->run_batch(array_map(static function (int $i): array {
            return ['key' => "k$i"];
        }, range(1, 100)), static function (): array {
            return ['external_id' => '1', 'action' => 'unchanged'];
        });

        self::assertSame(100, $data['summary']['ok']);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  callable(Item, Warnings): array<string, mixed>  $write
     * @return array<string, mixed>
     */
    private function run_batch(array $items, callable $write): array
    {
        $response = $this->send((string) json_encode(['items' => $items]), $write);
        self::assertSame(200, $response->status());

        return (array) json_decode($response->body(), true);
    }

    /**
     * @param  callable(Item, Warnings): array<string, mixed>  $write
     */
    private function send(string $body, callable $write): Response
    {
        $request = Request::fromServer(['REQUEST_METHOD' => 'POST', 'QUERY_STRING' => 'path=products'], $body);
        $log = new Log(function (array $row): void {
            $this->logged[] = $row;
        });

        return (new Batch($this->db, $log))->run($request, new Budget($this->clock, 25), function (Item $options, Warnings $warnings) use ($write): Writer {
            return $this->writer = new FakeWriter($write, $warnings);
        });
    }

    /**
     * @return list<string>
     */
    private function steps(): array
    {
        return $this->writer === null ? [] : $this->writer->steps;
    }
}

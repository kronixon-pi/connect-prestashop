<?php

declare(strict_types=1);

namespace Kronixon\Connect\Tests\Unit\Api;

use Kronixon\Connect\Api\ApiError;
use Kronixon\Connect\Api\Request;
use Kronixon\Connect\Api\Response;
use Kronixon\Connect\Support\Uuid;
use Kronixon\Connect\Tests\Support\MemoryIdempotency;
use Kronixon\Connect\Tests\Support\TestModule;
use PHPUnit\Framework\TestCase;

/**
 * A write with an Idempotency-Key is processed once (README §3), under the write lock.
 */
final class IdempotencyTest extends TestCase
{
    private const KEY = '0192b2e4-7c1a-7d3e-9f4b-2a6c8e0d1f35';

    /** @var TestModule */
    private $module;

    /** @var MemoryIdempotency */
    private $store;

    /** @var int */
    private $handled = 0;

    protected function setUp(): void
    {
        $this->module = new TestModule();
        $this->module->pair();
        $this->store = new MemoryIdempotency();
        foreach (['/v1/products', '/v1/brands'] as $path) {
            $this->module->router->add('POST', $path, function (Request $request): Response {
                $this->handled++;
                if ($request->body() === '{"bad":1}') {
                    throw ApiError::validationFailed(['items']);
                }

                return Response::json(200, ['call' => $this->handled, 'name' => 'Обувки 🙂']);
            });
        }
    }

    public function test_the_same_request_again_gets_the_stored_answer_signed_anew(): void
    {
        $first = $this->send('products', '{"a":1}', self::KEY);
        $nonce = Uuid::v4();
        $replay = $this->send('products', '{"a":1}', strtoupper(self::KEY), $nonce);

        self::assertSame(1, $this->handled);
        self::assertSame($first->body(), $replay->body());
        self::assertNull($first->header('X-KX-Idempotent-Replay'));
        self::assertSame('true', $replay->header('X-KX-Idempotent-Replay'));
        self::assertTrue(TestModule::signedWith($replay, $nonce, (string) $this->module->platform->secret));
    }

    public function test_another_body_or_endpoint_with_the_key_is_a_conflict(): void
    {
        $this->send('products', '{"a":1}', self::KEY);
        foreach ([['products', '{"a":2}'], ['brands', '{"a":1}']] as [$endpoint, $body]) {
            $answer = $this->send($endpoint, $body, self::KEY);
            self::assertSame(409, $answer->status());
            self::assertSame('idempotency_conflict', json_decode($answer->body(), true)['error']['code']);
        }
        self::assertSame(1, $this->handled);
    }

    public function test_without_a_key_every_request_is_processed(): void
    {
        $this->send('products', '{"a":1}', null);
        $this->send('products', '{"a":1}', null);

        self::assertSame(2, $this->handled);
        self::assertSame([], $this->store->answers);
    }

    public function test_a_refused_request_is_not_stored(): void
    {
        self::assertSame(422, $this->send('products', '{"bad":1}', self::KEY)->status());
        self::assertSame([], $this->store->answers);
        self::assertSame(200, $this->send('products', '{"a":1}', self::KEY)->status(), 'the key is still free');
    }

    public function test_a_key_is_a_uuid(): void
    {
        $answer = $this->send('products', '{"a":1}', hash('sha256', 'x'));

        self::assertSame(400, $answer->status());
        self::assertSame(0, $this->handled);
    }

    public function test_an_answer_lives_24_hours(): void
    {
        $this->send('products', '{"a":1}', self::KEY);
        $this->module->clock->advance(86400.0);
        self::assertSame('true', $this->send('products', '{"a":1}', self::KEY)->header('X-KX-Idempotent-Replay'));
        $this->module->clock->advance(1.0);
        self::assertNull($this->send('products', '{"a":1}', self::KEY)->header('X-KX-Idempotent-Replay'));
        self::assertSame(2, $this->handled);
    }

    public function test_a_store_that_fails_does_not_fail_the_write(): void
    {
        $this->store->broken = true;
        self::assertSame(200, $this->send('products', '{"a":1}', self::KEY)->status());
        self::assertSame('An answer could not be kept for replay', $this->module->logRows[count($this->module->logRows) - 1]['message']);
    }

    public function test_the_store_is_asked_under_the_lock_and_a_busy_shop_replays_nothing(): void
    {
        $this->send('products', '{"a":1}', self::KEY);
        self::assertSame(['acquire', 'release'], array_slice($this->module->guards->lockLog, -2));
        $this->module->guards->heldElsewhere = true;
        $busy = $this->send('products', '{"a":1}', self::KEY);

        self::assertSame(409, $busy->status());
        self::assertSame('busy', json_decode($busy->body(), true)['error']['code']);
        self::assertSame('5', $busy->header('Retry-After'));
    }

    private function send(string $endpoint, string $body, ?string $key, ?string $nonce = null): Response
    {
        $server = $this->module->signedServer((string) $this->module->platform->secret, 'POST', $endpoint, [], $body, $nonce);
        if ($key !== null) {
            $server['HTTP_IDEMPOTENCY_KEY'] = $key;
        }

        return $this->module->kernel($this->store)->handle($server, $body);
    }
}

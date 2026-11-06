<?php

declare(strict_types=1);

namespace Kronixon\Connect\Tests\Unit\Api;

use Kronixon\Connect\Api\Kernel;
use Kronixon\Connect\Api\Response;
use Kronixon\Connect\Api\Router;
use Kronixon\Connect\Auth\SecretStore;
use Kronixon\Connect\Tests\Support\FixedClock;
use Kronixon\Connect\Tests\Support\MemoryGuards;
use Kronixon\Connect\Tests\Support\MemorySettings;
use Kronixon\Connect\Tests\Support\TestCipher;
use Kronixon\Connect\Tests\Support\TestModule;
use Kronixon\Connect\Tests\Support\Vectors;
use PHPUnit\Framework\TestCase;

/**
 * The README §4.4 pipeline of the real Kernel, fed with the protocol's signature vectors and with
 * requests signed like the platform's ModuleClient signs them.
 */
final class SignedKernelTest extends TestCase
{
    private const STORE = '0192b2e4-2f7a-7c5b-8a1d-3e4f5a6b7c8d';

    /** @var MemoryGuards */
    private $guards;

    /** @var list<string> */
    private $handled = [];

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public function requestVectors(): iterable
    {
        foreach (Vectors::byKind('request') as $id => $vector) {
            yield $id => [$vector];
        }
    }

    /**
     * @dataProvider requestVectors
     *
     * @param  array<string, mixed>  $v
     */
    public function test_each_vector_passes_the_real_middleware_and_gets_a_signed_answer(array $v): void
    {
        $response = $this->vectorKernel((float) $v['timestamp'])->handle(Vectors::server($v), Vectors::wireBody($v));

        self::assertSame(200, $response->status(), $v['id'].': '.$response->body());
        self::assertSame([$v['method'].' '.$v['path']], $this->handled);
        $secret = Vectors::document()['secrets'][$v['key_id']];
        self::assertTrue(TestModule::signedWith($response, $v['nonce'], $secret), 'signed with the key that verified the request');
        self::assertSame($v['key_id'], $response->header('X-KX-Key-Id'));
        self::assertSame('0.2.0', $response->header('X-KX-Module-Version'));
        self::assertNotNull($response->header('X-KX-Time-Used'));
    }

    /**
     * @dataProvider requestVectors
     *
     * @param  array<string, mixed>  $v
     */
    public function test_a_replayed_vector_is_refused_as_nonce_reused_and_still_signed(array $v): void
    {
        $kernel = $this->vectorKernel((float) $v['timestamp']);
        $kernel->handle(Vectors::server($v), Vectors::wireBody($v));
        $replay = $kernel->handle(Vectors::server($v), Vectors::wireBody($v));

        self::assertSame(401, $replay->status());
        self::assertSame('nonce_reused', self::code($replay));
        self::assertTrue(TestModule::signedWith($replay, $v['nonce'], Vectors::document()['secrets'][$v['key_id']]), 'an answer after a verified signature is signed');
        self::assertCount(1, $this->handled);
    }

    public function test_the_kernel_reproduces_the_signed_response_vector(): void
    {
        $vectors = Vectors::document()['vectors'];
        $request = $vectors[0];
        $answer = Vectors::byKind('response')['signed-response'];
        self::assertSame($request['nonce'], $answer['request_nonce']);

        $router = (new Router())->add('POST', '/v1/stock/bulk', static function () use ($answer): Response {
            return Response::json($answer['status'], (array) json_decode($answer['body'], true));
        });
        $clock = new FixedClock((float) $answer['timestamp']);
        $kernel = new Kernel($router, $this->vectorSecrets(), $clock, new MemoryGuards(), new MemoryGuards());
        $response = $kernel->handle(Vectors::server($request), Vectors::wireBody($request));

        self::assertSame($answer['body'], $response->body());
        self::assertSame((string) $answer['timestamp'], $response->header('X-KX-Timestamp'));
        self::assertSame($answer['expected']['signature'], $response->header('X-KX-Signature'));
    }

    /**
     * @return iterable<string, array{callable(array<string, string>, string): array{0: array<string, string>, 1: string}, string}>
     */
    public function tamperings(): iterable
    {
        yield 'body' => [static function (array $s, string $b): array {
            return [$s, $b.' '];
        }, 'signature_invalid'];
        yield 'path' => [static function (array $s, string $b): array {
            $s['QUERY_STRING'] = str_replace('path=stock/bulk', 'path=prices/bulk', $s['QUERY_STRING']);

            return [$s, $b];
        }, 'signature_invalid'];
        yield 'query' => [static function (array $s, string $b): array {
            $s['QUERY_STRING'] .= '&limit=1';

            return [$s, $b];
        }, 'signature_invalid'];
        yield 'timestamp' => [static function (array $s, string $b): array {
            $s['HTTP_X_KX_TIMESTAMP'] = (string) ((int) $s['HTTP_X_KX_TIMESTAMP'] + 1);

            return [$s, $b];
        }, 'signature_invalid'];
        yield 'nonce' => [static function (array $s, string $b): array {
            $s['HTTP_X_KX_NONCE'] = '0192b2e4-7c1a-7d3e-9f4b-2a6c8e0d1f36';

            return [$s, $b];
        }, 'signature_invalid'];
        yield 'method' => [static function (array $s, string $b): array {
            $s['REQUEST_METHOD'] = 'POST';
            $s['HTTP_X_HTTP_METHOD_OVERRIDE'] = 'DELETE';

            return [$s, $b];
        }, 'signature_invalid'];
        yield 'key id prev' => [static function (array $s, string $b): array {
            $s['HTTP_X_KX_KEY_ID'] = 'prev';

            return [$s, $b];
        }, 'signature_invalid'];
        yield 'unknown key id' => [static function (array $s, string $b): array {
            $s['HTTP_X_KX_KEY_ID'] = 'next';

            return [$s, $b];
        }, 'signature_invalid'];
        yield 'another store' => [static function (array $s, string $b): array {
            $s['HTTP_X_KX_STORE'] = '0192b2e4-2f7a-7c5b-8a1d-3e4f5a6b7c8e';

            return [$s, $b];
        }, 'signature_invalid'];
        yield 'signature missing' => [static function (array $s, string $b): array {
            unset($s['HTTP_X_KX_SIGNATURE']);

            return [$s, $b];
        }, 'signature_invalid'];
    }

    /**
     * @dataProvider tamperings
     *
     * @param  callable(array<string, string>, string): array{0: array<string, string>, 1: string}  $tamper
     */
    public function test_a_tampered_request_is_refused_unsigned_and_burns_no_nonce(callable $tamper, string $code): void
    {
        $v = Vectors::document()['vectors'][0];
        [$server, $body] = $tamper(Vectors::server($v), Vectors::wireBody($v));
        $kernel = $this->vectorKernel((float) $v['timestamp']);
        $response = $kernel->handle($server, $body);

        self::assertSame(401, $response->status());
        self::assertSame($code, self::code($response));
        self::assertNull($response->header('X-KX-Signature'), 'refused before a verified signature: unsigned');
        self::assertNull($response->header('X-KX-Module-Version'));
        self::assertSame([], $this->guards->nonces, 'a forged request cannot burn a nonce');
        self::assertSame([], $this->handled);

        $genuine = $kernel->handle(Vectors::server($v), Vectors::wireBody($v));
        self::assertSame(200, $genuine->status(), 'the genuine request still passes afterwards');
    }

    public function test_the_clock_window_is_three_hundred_seconds_and_the_error_carries_server_time(): void
    {
        $v = Vectors::document()['vectors'][0];
        foreach ([300 => 200, -300 => 200, 301 => 401, -301 => 401] as $skew => $status) {
            $this->handled = [];
            $response = $this->vectorKernel((float) ($v['timestamp'] + $skew))->handle(Vectors::server($v), Vectors::wireBody($v));
            self::assertSame($status, $response->status(), 'skew '.$skew);
            if ($status === 401) {
                self::assertSame('timestamp_out_of_window', self::code($response));
                self::assertSame($v['timestamp'] + $skew, self::error($response)['server_time']);
            }
        }
    }

    public function test_prev_verifies_until_its_expiry_and_never_after(): void
    {
        $until = (int) Vectors::document()['prev_valid_until'];
        // The prev vector's own timestamp is a day before the expiry: sign the same kind of request at it.
        $module = $this->pairedModule();
        $module->clock->advance($until - $module->clock->now());
        $atExpiry = $module->kernel()->handle($module->signedServer('kxs_P7wD2qLz9XkR4mVb8NcT1yHs6GjF3aUe', 'GET', 'health', [], '', null, null, 'prev'), '');
        self::assertSame(200, $atExpiry->status());

        $module->clock->advance(1);
        $after = $module->kernel()->handle($module->signedServer('kxs_P7wD2qLz9XkR4mVb8NcT1yHs6GjF3aUe', 'GET', 'health', [], '', null, null, 'prev'), '');
        self::assertSame(401, $after->status());
        self::assertSame('signature_invalid', self::code($after));
        $withoutKeyId = $module->kernel()->handle($module->signedServer('kxs_P7wD2qLz9XkR4mVb8NcT1yHs6GjF3aUe', 'GET', 'health'), '');
        self::assertSame(401, $withoutKeyId->status(), 'cur then prev: prev is no candidate any more');
    }

    public function test_the_rate_limit_counts_verified_requests_of_the_last_minute(): void
    {
        $module = $this->pairedModule();
        $module->rateLimit = 3;
        $secret = (string) $module->secrets->current();
        $statuses = [];
        for ($i = 0; $i < 4; $i++) {
            $statuses[] = $module->kernel()->handle($module->signedServer($secret, 'GET', 'health'), '')->status();
        }
        $limited = $module->kernel()->handle($module->signedServer($secret, 'GET', 'health'), '');

        self::assertSame([200, 200, 200, 429], $statuses);
        self::assertSame('rate_limited', self::code($limited));
        self::assertSame('60', $limited->header('Retry-After'));

        $module->clock->advance(60);
        self::assertSame(200, $module->kernel()->handle($module->signedServer($secret, 'GET', 'health'), '')->status(), 'a minute later the window is free');
    }

    public function test_writes_run_under_the_write_lock_and_a_held_lock_answers_busy(): void
    {
        $module = $this->pairedModule();
        $secret = (string) $module->secrets->current();
        $module->guards->heldElsewhere = true;
        $busy = $module->kernel()->handle($module->signedServer($secret, 'POST', 'unpair', [], '{"reason":"user"}'), '{"reason":"user"}');

        self::assertSame(409, $busy->status());
        self::assertSame('busy', self::code($busy));
        self::assertSame('5', $busy->header('Retry-After'));
        self::assertTrue($module->secrets->isPaired(), 'nothing ran');
        self::assertNotNull($busy->header('X-KX-Signature'));

        $module->guards->heldElsewhere = false;
        $module->guards->lockLog = [];
        $module->kernel()->handle($module->signedServer($secret, 'GET', 'health'), '');
        self::assertSame([], $module->guards->lockLog, 'reads take no lock');
        $module->kernel()->handle($module->signedServer($secret, 'POST', 'unpair', [], '{"reason":"user"}'), '{"reason":"user"}');
        self::assertSame(['acquire', 'release'], $module->guards->lockLog);
    }

    public function test_unknown_endpoints_are_revealed_only_after_the_signature(): void
    {
        $module = $this->pairedModule();
        $secret = (string) $module->secrets->current();

        $unsigned = $module->kernel()->handle(['REQUEST_METHOD' => 'GET', 'QUERY_STRING' => 'path=nope', 'HTTP_X_KX_PROTOCOL' => 'v1'], '');
        self::assertSame(401, $unsigned->status());
        $signed = $module->kernel()->handle($module->signedServer($secret, 'GET', 'nope'), '');
        self::assertSame(404, $signed->status());
        self::assertSame('not_found', self::code($signed));
        $wrongMethod = $module->kernel()->handle($module->signedServer($secret, 'POST', 'capabilities', [], '{}'), '{}');
        self::assertSame(405, $wrongMethod->status());
        self::assertNotNull($wrongMethod->header('X-KX-Signature'));
    }

    private function vectorKernel(float $now): Kernel
    {
        $this->guards = new MemoryGuards();
        $this->handled = [];
        $router = new Router();
        foreach (Vectors::byKind('request') as $v) {
            $router->add($v['method'], $v['path'], function () use ($v): Response {
                $this->handled[] = $v['method'].' '.$v['path'];

                return Response::json(200, ['ok' => true]);
            });
        }

        return new Kernel($router, $this->vectorSecrets(), new FixedClock($now), $this->guards, $this->guards);
    }

    private function vectorSecrets(): SecretStore
    {
        $doc = Vectors::document();
        $cipher = new TestCipher();
        $settings = new MemorySettings();
        $settings->set(SecretStore::STORE_ID, self::STORE);
        $settings->set(SecretStore::SECRET, $cipher->encrypt($doc['secrets']['cur']));
        $settings->set(SecretStore::SECRET_PREV, $cipher->encrypt($doc['secrets']['prev']));
        $settings->set(SecretStore::SECRET_PREV_UNTIL, (string) $doc['prev_valid_until']);

        return new SecretStore($settings, $cipher);
    }

    private function pairedModule(): TestModule
    {
        $doc = Vectors::document();
        $module = new TestModule();
        $cipher = new TestCipher();
        $module->settings->set(SecretStore::STORE_ID, self::STORE);
        $module->settings->set(SecretStore::SECRET, $cipher->encrypt($doc['secrets']['cur']));
        $module->settings->set(SecretStore::SECRET_PREV, $cipher->encrypt($doc['secrets']['prev']));
        $module->settings->set(SecretStore::SECRET_PREV_UNTIL, (string) $doc['prev_valid_until']);

        return $module;
    }

    private static function code(Response $response): string
    {
        return (string) self::error($response)['code'];
    }

    /**
     * @return array<string, mixed>
     */
    private static function error(Response $response): array
    {
        $data = json_decode($response->body(), true);

        return is_array($data) && is_array($data['error'] ?? null) ? $data['error'] : [];
    }
}

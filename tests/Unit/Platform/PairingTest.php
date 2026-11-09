<?php

declare(strict_types=1);

namespace Kronixon\Connect\Tests\Unit\Platform;

use Kronixon\Connect\Auth\SecretStore;
use Kronixon\Connect\Platform\Pairing;
use Kronixon\Connect\Tests\Support\FakePlatform;
use Kronixon\Connect\Tests\Support\TestModule;
use PHPUnit\Framework\TestCase;

/**
 * Pairing from the settings page against a platform that answers like the merged B2 flow.
 */
final class PairingTest extends TestCase
{
    public function test_a_code_pairs_opens_the_sealed_secret_and_pings_signed(): void
    {
        $module = new TestModule();
        $outcome = $module->pairing()->pair(' k7pm 3xq9 ');

        self::assertSame('paired', $outcome['state']);
        self::assertArrayNotHasKey('warning', $outcome, 'the signed ping passed');
        self::assertSame(FakePlatform::STORE_ID, $module->secrets->storeId());
        self::assertSame($module->platform->secret, $module->secrets->current(), 'the module holds the secret the platform sealed');
        self::assertSame('Example Ltd', $module->secrets->setting(SecretStore::TENANT_NAME));

        [$pair, $ping] = $module->platform->requests;
        self::assertSame('https://connect.example.test/v1/pair', $pair['url']);
        self::assertArrayNotHasKey('X-KX-Signature', $pair['headers'], 'pair is unsigned: the code is the secret');
        $request = json_decode($pair['body'], true);
        self::assertSame('K7PM-3XQ9', $request['code']);
        self::assertSame('https://shop.example.com/', $request['store_url']);
        self::assertSame('Example Shop', $request['shop_name'], 'control characters are dropped');
        self::assertSame($module->secrets->setting(SecretStore::PUBLIC_KEY), $request['public_key']);
        self::assertSame(hash('sha256', (string) json_encode($module->endpoints()->capabilities(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)), $request['capabilities_hash']);
        self::assertSame('https://connect.example.test/v1/stores/'.FakePlatform::STORE_ID.'/ping', $ping['url']);
        self::assertSame('cur', $ping['headers']['X-KX-Key-Id']);
        self::assertStringStartsWith('KronixonConnect/0.3.1 (prestashop ', $ping['headers']['User-Agent']);
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public function codes(): iterable
    {
        yield 'as shown' => ['K7PM-3XQ9', 'K7PM-3XQ9'];
        yield 'lower case, no dash' => ['k7pm3xq9', 'K7PM-3XQ9'];
        yield 'spaces and an en dash' => [" K7PM \u{2013} 3XQ9\u{00A0}", 'K7PM-3XQ9'];
        yield 'a minus sign' => ["K7PM\u{2212}3XQ9", 'K7PM-3XQ9'];
        yield 'too short' => ['K7PM-3XQ', null];
        yield 'O and 0 are not in the alphabet' => ['K7PM-3XO0', null];
        yield 'I and 1 are not in the alphabet' => ['K7PM-3XI1', null];
        yield 'empty' => ['', null];
    }

    /**
     * @dataProvider codes
     */
    public function test_codes_are_normalised_like_the_platform(string $typed, ?string $expected): void
    {
        self::assertSame($expected, Pairing::normaliseCode($typed));
    }

    public function test_an_invalid_code_or_url_never_reaches_the_network(): void
    {
        $module = new TestModule();
        $pairing = $module->pairing();

        self::assertSame('pair_code_invalid', $pairing->pair('NOPE')['code']);
        self::assertFalse($pairing->setPlatformUrl('http://connect.example.test'), 'http needs the development switch');
        $module->settings->set(Pairing::PLATFORM_URL, 'ftp://connect.example.test');
        self::assertSame('platform_url_invalid', $pairing->pair(FakePlatform::CODE)['code']);
        self::assertSame([], $module->platform->requests);

        $module->settings->set(Pairing::ALLOW_HTTP, '1');
        self::assertTrue($pairing->setPlatformUrl('http://connect.example.test:8080/v1/'));
        self::assertSame('http://connect.example.test:8080', $pairing->platformUrl());
    }

    /**
     * @return iterable<string, array{array{0: int, 1: string, 2: array<string, mixed>, 3: int|null}, array<string, mixed>}>
     */
    public function refusals(): iterable
    {
        yield 'expired code' => [[410, 'pair_code_expired', [], null], ['code' => 'pair_code_expired']];
        yield 'another workspace' => [[409, 'store_already_paired', [], null], ['code' => 'store_already_paired']];
        yield '24 h back-off' => [[409, 'store_already_paired', ['reason' => 'verification_backoff'], 86000], ['code' => 'store_already_paired', 'reason' => 'verification_backoff', 'retry_until' => 1790772000 + 86000]];
        yield 'url mismatch' => [[409, 'store_already_paired', ['reason' => 'store_url_mismatch'], null], ['code' => 'store_already_paired', 'reason' => 'store_url_mismatch']];
        yield 'workspace blocked' => [[429, 'rate_limited', [], 3600], ['code' => 'rate_limited', 'retry_until' => 1790772000 + 3600]];
        yield 'module too old' => [[426, 'module_too_old', ['min_module_version' => '1.0.0'], null], ['code' => 'module_too_old', 'min_module_version' => '1.0.0']];
        yield 'validation' => [[422, 'validation_failed', ['errors' => ['store_url', 'Bad Member']], null], ['code' => 'validation_failed', 'errors' => ['store_url']]];
        yield 'no release key yet' => [[503, 'maintenance', [], null], ['code' => 'maintenance']];
        yield 'http shop' => [[422, 'store_url_insecure', [], null], ['code' => 'store_url_insecure']];
    }

    /**
     * @dataProvider refusals
     *
     * @param  array{0: int, 1: string, 2: array<string, mixed>, 3: int|null}  $refusal
     * @param  array<string, mixed>  $expected
     */
    public function test_a_refusal_keeps_the_platforms_error_key_and_retry_time(array $refusal, array $expected): void
    {
        $module = new TestModule();
        $module->platform->refusePair = $refusal;
        $pairing = $module->pairing();
        $outcome = $pairing->pair(FakePlatform::CODE);

        self::assertSame(['state' => 'error'] + $expected + ['at' => 1790772000], $outcome);
        self::assertSame($outcome, $pairing->state(), 'kept for the settings page');
        self::assertFalse($module->secrets->isPaired());
    }

    public function test_an_unreachable_platform_and_a_forged_or_unsigned_ping(): void
    {
        $module = new TestModule();
        $module->platform->mode = 'network';
        self::assertSame('network_error', $module->pairing()->pair(FakePlatform::CODE)['code']);

        $module = new TestModule();
        $module->pair();
        $module->platform->mode = 'unsigned';
        self::assertSame('response_unsigned', $module->pairing()->ping());
        $module->platform->mode = 'forged';
        self::assertSame('response_signature_invalid', $module->pairing()->ping());
        $module->platform->mode = 'normal';
        self::assertNull($module->pairing()->ping());
    }

    public function test_a_pinned_platform_key_refuses_another_one(): void
    {
        $module = new TestModule();
        $outcome = $module->pairing('Bw4FBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8gISI=')->pair(FakePlatform::CODE);

        self::assertSame('platform_key_mismatch', $outcome['code']);
        self::assertFalse($module->secrets->isPaired());
        self::assertSame('paired', (new TestModule())->pairing(FakePlatform::PLATFORM_KEY)->pair(FakePlatform::CODE)['state']);
    }

    public function test_a_clock_off_by_more_than_the_window_is_reported(): void
    {
        $module = new TestModule();
        $module->platform->now = 1790772000 + 301;

        $outcome = $module->pairing()->pair(FakePlatform::CODE);

        self::assertSame('paired', $outcome['state']);
        self::assertSame('clock_skew', $outcome['warning']);
    }

    public function test_unpair_from_the_shop_tells_the_platform_and_forgets_the_secrets_whatever_it_answers(): void
    {
        $module = new TestModule();
        $module->pair();
        $publicKey = $module->secrets->setting(SecretStore::PUBLIC_KEY);
        $module->pairing()->unpair('user');

        $last = end($module->platform->requests);
        self::assertIsArray($last);
        self::assertSame('POST', $last['method']);
        self::assertSame('https://connect.example.test/v1/stores/'.FakePlatform::STORE_ID.'/unpair', $last['url']);
        self::assertSame('{"reason":"user"}', $last['body']);
        self::assertFalse($module->secrets->isPaired());
        self::assertNull($module->secrets->current());
        self::assertSame($publicKey, $module->secrets->setting(SecretStore::PUBLIC_KEY), 'the key pair stays for a re-pair');

        $module = new TestModule();
        $module->pair();
        $module->platform->mode = 'network';
        $module->pairing()->unpair('uninstall', 5);
        self::assertFalse($module->secrets->isPaired(), 'an unreachable platform does not keep the secrets');
    }

    public function test_secrets_are_stored_encrypted_and_never_logged(): void
    {
        $module = new TestModule();
        $module->pair();
        $first = (string) $module->platform->secret;
        $module->pairing()->ping();
        $next = 'kxs_NEXTnextNEXTnextNEXTnextNEXTnext';
        $body = $module->rotateBody($next, 1790772000 + 86400);
        $module->kernel()->handle($module->signedServer($first, 'POST', 'rotate', [], $body), $body);
        $module->pairing()->unpair('user');
        $module->pair();

        $stored = (string) json_encode($module->settings->values);
        $logged = (string) json_encode($module->logRows);
        foreach ([$first, $next, (string) $module->platform->secret] as $secret) {
            self::assertStringNotContainsString($secret, $stored);
            self::assertStringNotContainsString($secret, $logged);
            self::assertStringNotContainsString(substr($secret, 4), $logged);
        }
        self::assertNotEmpty($module->logRows);
        self::assertStringNotContainsString('PRIVATE', strtoupper((string) json_encode(array_column($module->logRows, 'context'))));
    }
}

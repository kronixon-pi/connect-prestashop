<?php

declare(strict_types=1);

namespace Kronixon\Connect\Tests\Unit\Api;

use Kronixon\Connect\Api\Response;
use Kronixon\Connect\Auth\SecretStore;
use Kronixon\Connect\Platform\Pairing;
use Kronixon\Connect\Support\Log;
use Kronixon\Connect\Support\Uuid;
use Kronixon\Connect\Tests\Support\TestModule;
use PHPUnit\Framework\TestCase;

/**
 * capabilities, profile, health, rotate and unpair after a real pairing (the platform's side is
 * FakePlatform): the reverse check the platform makes is `GET capabilities` signed with the secret
 * it issued.
 */
final class SessionEndpointsTest extends TestCase
{
    public function test_the_reverse_check_passes_with_the_secret_the_platform_issued(): void
    {
        $module = new TestModule();
        $module->pair();
        $nonce = Uuid::v4();
        $response = $module->kernel()->handle($module->signedServer((string) $module->platform->secret, 'GET', 'capabilities', [], '', $nonce), '');

        self::assertSame(200, $response->status(), $response->body());
        self::assertTrue(TestModule::signedWith($response, $nonce, (string) $module->platform->secret));
        $data = self::json($response);
        self::assertSame('v1', $data['protocol']);
        self::assertFalse($data['capabilities']['products.write'], 'operations of later versions are false');
        self::assertTrue($data['capabilities']['suppliers'], 'GET catalog/suppliers is served');
        self::assertSame(['jpg', 'webp'], $data['capabilities']['image_formats']);
        self::assertSame(25, $data['limits']['time_budget_s']);
    }

    public function test_profile_and_health(): void
    {
        $module = new TestModule();
        $module->pair();
        $module->settings->set('KX_INSTANCE_ID', '0192b2e4-2f7a-4c5b-8a1d-3e4f5a6b7c8d');
        $secret = (string) $module->platform->secret;

        $profile = self::json($module->kernel()->handle($module->signedServer($secret, 'GET', 'profile'), ''));
        self::assertSame('0192b2e4-2f7a-4c5b-8a1d-3e4f5a6b7c8d', $profile['instance_id']);
        self::assertSame('2026-09-30T12:40:00Z', $profile['server_time']);
        self::assertSame('https://shop.example.com/module/kronixonconnect/api', $profile['urls']['api']);

        $health = self::json($module->kernel()->handle($module->signedServer($secret, 'GET', 'health'), ''));
        self::assertTrue($health['paired']);
        self::assertSame('0192b2e4-2f7a-7c5b-8a1d-3e4f5a6b7c8d', $health['store_id']);
        self::assertSame(1790772000, $health['server_time']);
    }

    public function test_rotate_makes_the_new_secret_cur_and_keeps_prev_for_its_window(): void
    {
        $module = new TestModule();
        $module->pair();
        $old = (string) $module->platform->secret;
        $new = 'kxs_9Qm3vT7bLp2XcR8fWn4zHd6KyE1sAu5G';
        $body = $module->rotateBody($new, 1790772000 + 86400);
        $nonce = Uuid::v4();
        $response = $module->kernel()->handle($module->signedServer($old, 'POST', 'rotate', [], $body, $nonce), $body);

        self::assertSame(200, $response->status(), $response->body());
        self::assertSame('{"ok":true}', $response->body());
        self::assertTrue(TestModule::signedWith($response, $nonce, $old), 'the answer is signed with the secret the request came with');
        self::assertSame($new, $module->secrets->current());

        self::assertSame(200, $module->kernel()->handle($module->signedServer($new, 'GET', 'health'), '')->status(), 'cur');
        self::assertSame(200, $module->kernel()->handle($module->signedServer($old, 'GET', 'health', [], '', null, null, 'prev'), '')->status(), 'prev within 24 h');
        $module->clock->advance(86401);
        $module->platform->now += 86401;
        self::assertSame(401, $module->kernel()->handle($module->signedServer($old, 'GET', 'health', [], '', null, null, 'prev'), '')->status(), 'prev after 24 h');

        $again = $module->kernel()->handle($module->signedServer($new, 'POST', 'rotate', [], $body), $body);
        self::assertSame(200, $again->status(), 'a repeated rotation with the same secret is harmless');
        self::assertSame($new, $module->secrets->current());
    }

    public function test_a_suspected_leak_rotation_drops_prev_at_once_and_prev_until_is_capped(): void
    {
        $module = new TestModule();
        $module->pair();
        $old = (string) $module->platform->secret;
        $body = $module->rotateBody('kxs_9Qm3vT7bLp2XcR8fWn4zHd6KyE1sAu5G', 1790772000 + 400 * 86400);
        $module->kernel()->handle($module->signedServer($old, 'POST', 'rotate', [], $body), $body);
        self::assertSame((string) (1790772000 + 86400), $module->settings->get('KX_SECRET_PREV_UNTIL'));

        $module = new TestModule();
        $module->pair();
        $old = (string) $module->platform->secret;
        $body = $module->rotateBody('kxs_9Qm3vT7bLp2XcR8fWn4zHd6KyE1sAu5G', 1790772000);
        $module->kernel()->handle($module->signedServer($old, 'POST', 'rotate', [], $body), $body);
        $module->clock->advance(1);
        self::assertSame(401, $module->kernel()->handle($module->signedServer($old, 'GET', 'health'), '')->status());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public function badRotations(): iterable
    {
        yield 'not sealed to this module' => ['{"secret_sealed":"'.base64_encode(str_repeat('x', 80)).'","prev_valid_until":1790858400}'];
        yield 'no prev_valid_until' => ['{"secret_sealed":"c2VhbGVk"}'];
        yield 'not base64' => ['{"secret_sealed":"%%%","prev_valid_until":1790858400}'];
    }

    /**
     * @dataProvider badRotations
     */
    public function test_a_bad_rotation_is_refused_and_changes_nothing(string $body): void
    {
        $module = new TestModule();
        $module->pair();
        $old = (string) $module->platform->secret;
        $response = $module->kernel()->handle($module->signedServer($old, 'POST', 'rotate', [], $body), $body);

        self::assertSame(422, $response->status());
        self::assertSame('validation_failed', self::json($response)['error']['code']);
        self::assertSame($old, $module->secrets->current());
    }

    public function test_a_secret_sealed_but_not_of_the_protocol_form_is_refused(): void
    {
        $module = new TestModule();
        $module->pair();
        $old = (string) $module->platform->secret;
        $body = $module->rotateBody('not-a-kxs-secret', 1790858400);

        self::assertSame(422, $module->kernel()->handle($module->signedServer($old, 'POST', 'rotate', [], $body), $body)->status());
        self::assertSame($old, $module->secrets->current());
    }

    public function test_unpair_forgets_the_secrets_and_every_later_call_is_refused_unsigned(): void
    {
        $module = new TestModule();
        $module->pair();
        $secret = (string) $module->platform->secret;
        $nonce = Uuid::v4();
        $response = $module->kernel()->handle($module->signedServer($secret, 'POST', 'unpair', [], '{"reason":"revoked"}', $nonce), '{"reason":"revoked"}');

        self::assertSame(200, $response->status());
        self::assertTrue(TestModule::signedWith($response, $nonce, $secret), 'signed with the secret it just forgot');
        self::assertFalse($module->secrets->isPaired());

        $later = $module->kernel()->handle($module->signedServer($secret, 'GET', 'capabilities'), '');
        self::assertSame(403, $later->status());
        self::assertSame('store_unpaired', self::json($later)['error']['code']);
        self::assertNull($later->header('X-KX-Signature'));
        self::assertNull($later->header('X-KX-Module-Version'));

        $bad = new TestModule();
        $bad->pair();
        $body = '{"reason":"because"}';
        self::assertSame(422, $bad->kernel()->handle($bad->signedServer((string) $bad->platform->secret, 'POST', 'unpair', [], $body), $body)->status());
        self::assertTrue($bad->secrets->isPaired());
    }

    public function test_an_unpair_by_the_platform_leaves_no_pairing_setting_behind(): void
    {
        $module = new TestModule();
        $module->settings->set('KX_INSTANCE_ID', '0192b2e4-2f7a-4c5b-8a1d-3e4f5a6b7c8d');
        $module->pair();
        $module->settings->set(SecretStore::LAST_REQUEST_AT, '1790772000'); // written by the front controller's onVerified
        $new = 'kxs_9Qm3vT7bLp2XcR8fWn4zHd6KyE1sAu5G';
        $rotate = $module->rotateBody($new, 1790772000 + 86400);
        self::assertSame(200, $module->kernel()->handle($module->signedServer((string) $module->platform->secret, 'POST', 'rotate', [], $rotate), $rotate)->status());
        $installation = ['KX_INSTANCE_ID', SecretStore::KEY_PAIR, SecretStore::PUBLIC_KEY, Pairing::PLATFORM_URL];
        self::assertSame('paired', $module->pairing()->state()['state'] ?? null);
        foreach (array_merge([SecretStore::STORE_ID], SecretStore::PAIRING_KEYS) as $key) {
            self::assertNotNull($module->settings->get($key), $key.' is written by a pairing or a rotation');
        }

        $body = '{"reason":"revoked"}';
        $response = $module->kernel()->handle($module->signedServer($new, 'POST', 'unpair', [], $body), $body);

        self::assertSame(200, $response->status());
        $left = array_keys($module->settings->values);
        sort($left);
        sort($installation);
        self::assertSame($installation, $left, 'only the installation settings stay');
        self::assertSame([], $module->pairing()->state(), 'the settings page shows no pairing outcome');
    }

    public function test_the_log_masks_secret_shaped_values_and_sensitive_names(): void
    {
        $rows = [];
        $log = new Log(static function (array $row) use (&$rows): void {
            $rows[] = $row;
        });
        $log->warning('test', 'got kxs_9Qm3vT7bLp2XcR8fWn4zHd6KyE1sAu5G back', [
            'shared_secret' => 'anything',
            'signature' => 'abc',
            'note' => 'sig '.str_repeat('ab', 32),
            'sealed_box' => 'c2VhbGVk',
            'error_code' => 'nonce_reused',
        ]);

        self::assertSame('got kxs_*** back', $rows[0]['message']);
        self::assertSame('{"shared_secret":"***","signature":"***","note":"sig ***","sealed_box":"***","error_code":"nonce_reused"}', $rows[0]['context']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(Response $response): array
    {
        $data = json_decode($response->body(), true);
        self::assertIsArray($data);

        return $data;
    }
}

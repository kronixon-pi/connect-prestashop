<?php

declare(strict_types=1);

namespace Kronixon\Connect\Tests\Support;

use Kronixon\Connect\Api\Endpoints;
use Kronixon\Connect\Api\Kernel;
use Kronixon\Connect\Api\Response;
use Kronixon\Connect\Api\Router;
use Kronixon\Connect\Auth\SecretStore;
use Kronixon\Connect\Auth\Signer;
use Kronixon\Connect\Compat\Ps9;
use Kronixon\Connect\Platform\Pairing;
use Kronixon\Connect\Platform\PlatformClient;
use Kronixon\Connect\Support\Log;
use Kronixon\Connect\Support\Uuid;

/**
 * The module wired as Services wires it, with in-memory settings, guards, log and a fake platform:
 * everything but PrestaShop itself is the real code.
 */
final class TestModule
{
    /** @var MemorySettings */
    public $settings;

    /** @var SecretStore */
    public $secrets;

    /** @var MemoryGuards */
    public $guards;

    /** @var FixedClock */
    public $clock;

    /** @var FakePlatform */
    public $platform;

    /** @var list<array<string, string|null>> */
    public $logRows = [];

    /** @var Router */
    public $router;

    /** @var int */
    public $rateLimit = 120;

    public function __construct(float $now = 1790772000.0)
    {
        $this->settings = new MemorySettings();
        $this->secrets = new SecretStore($this->settings, new TestCipher());
        $this->guards = new MemoryGuards();
        $this->clock = new FixedClock($now);
        $this->platform = new FakePlatform();
        $this->platform->now = (int) $now;
        $this->router = $this->endpoints()->register(new Router());
    }

    public function log(): Log
    {
        return new Log(function (array $row): void {
            $this->logRows[] = $row;
        });
    }

    public function endpoints(): Endpoints
    {
        return new Endpoints($this->secrets, new FakeShop(), new Ps9(), $this->clock, $this->log());
    }

    public function kernel(): Kernel
    {
        return new Kernel($this->router, $this->secrets, $this->clock, $this->guards, $this->guards, $this->log(), 25, $this->rateLimit);
    }

    public function pairing(string $pinnedKey = ''): Pairing
    {
        $this->settings->set(Pairing::PLATFORM_URL, 'https://connect.example.test');

        return new Pairing($this->secrets, new PlatformClient($this->platform, $this->secrets, $this->clock), $this->endpoints(), new FakeShop(), $this->settings, $this->clock, $this->log(), $pinnedKey);
    }

    /** Pairs through the fake platform (the real pairing code). */
    public function pair(): void
    {
        $outcome = $this->pairing()->pair(FakePlatform::CODE);
        if (($outcome['state'] ?? null) !== 'paired') {
            throw new \RuntimeException('pairing failed: '.json_encode($outcome));
        }
    }

    /**
     * $_SERVER of a request the platform signs with $secret (ModuleClient's form: the front controller
     * URL without friendly URLs, `path=` first).
     *
     * @param  array<string, string>  $query
     * @return array<string, string>
     */
    public function signedServer(string $secret, string $method, string $endpoint, array $query = [], string $body = '', ?string $nonce = null, ?int $timestamp = null, string $keyId = 'cur'): array
    {
        $nonce = $nonce ?? Uuid::v4();
        $timestamp = $timestamp ?? (int) $this->clock->now();
        $raw = 'fc=module&module=kronixonconnect&controller=api&path='.rawurlencode(ltrim($endpoint, '/'));
        foreach ($query as $name => $value) {
            $raw .= '&'.rawurlencode($name).'='.rawurlencode($value);
        }
        $headers = (new Signer())->requestHeaders((string) $this->secrets->storeId(), $keyId, $secret, $method, '/v1/'.ltrim($endpoint, '/'), $raw, $timestamp, $nonce, $body);
        $server = ['REQUEST_METHOD' => $method, 'QUERY_STRING' => $raw];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $server;
    }

    /** Whether $response carries a valid response signature for $nonce under $secret. */
    public static function signedWith(Response $response, string $nonce, string $secret): bool
    {
        $signature = $response->header('X-KX-Signature');
        $timestamp = $response->header('X-KX-Timestamp');

        return $signature !== null && $timestamp !== null
            && hash_equals((new Signer())->signResponse($secret, $nonce, (int) $timestamp, $response->status(), $response->body()), $signature);
    }

    /**
     * The `$defs.request` body of module/rotate.json for $secret, sealed to the module's key.
     */
    public function rotateBody(string $secret, int $prevValidUntil): string
    {
        $public = (string) base64_decode((string) $this->secrets->setting(SecretStore::PUBLIC_KEY), true);

        return (string) json_encode(['secret_sealed' => base64_encode(sodium_crypto_box_seal($secret, $public)), 'prev_valid_until' => $prevValidUntil]);
    }
}

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

namespace Kronixon\Connect\Api;

use Kronixon\Connect\Auth\NonceStore;
use Kronixon\Connect\Auth\PairingState;
use Kronixon\Connect\Auth\Signer;
use Kronixon\Connect\Auth\Verifier;
use Kronixon\Connect\Auth\WriteLock;
use Kronixon\Connect\Protocol;
use Kronixon\Connect\Support\Clock;
use Kronixon\Connect\Support\Log;
use Kronixon\Connect\Support\Uuid;
use Throwable;

/**
 * The single entry of the API front controller. A request passes README §4.4 in order:
 *
 * 1. parse (400 / 405 / 413, the gzip guard in Request) and `X-KX-Protocol` (400);
 * 2. paired? (`403 store_unpaired`) — nothing about any endpoint is revealed before this;
 * 3. (the optional IP allowlist is not implemented yet);
 * 4–6. `X-KX-Store` names this pairing, header formats, the ±300 s clock window, the HMAC against
 *    `cur`, then a valid `prev` (`401 signature_invalid` / `timestamp_out_of_window`);
 * 7. the nonce, stored only now (`401 nonce_reused`), then the rate limit (`429 rate_limited`);
 * 8. the route (`404` / `405`), POST/DELETE under the write lock (`409 busy`).
 *
 * Every answer after step 6 is signed with the key that verified the request (README §4.3) and
 * carries the module version and the time used; every earlier answer is unsigned and carries
 * neither. PHP notices never reach the body, exceptions become `500 module_error` (their class is
 * logged, never their message), and a fatal error still answers JSON through the shutdown guard.
 */
final class Kernel
{
    private const FATAL = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];

    /** @var Router */
    private $router;

    /** @var PairingState */
    private $pairing;

    /** @var Clock */
    private $clock;

    /** @var NonceStore */
    private $nonces;

    /** @var WriteLock */
    private $lock;

    /** @var Log|null */
    private $log;

    /** @var int */
    private $budgetSeconds;

    /** @var int */
    private $rateLimit;

    /** @var (callable(int): void)|null */
    private $onVerified;

    /** @var string */
    private $requestId = '';

    /** @var Budget|null */
    private $budget;

    /** @var array{0: string, 1: string, 2: string}|null key id, secret, request nonce of a verified request */
    private $signing;

    /** @var bool */
    private $responded = false;

    /** @var int */
    private $suppressedErrors = 0;

    /**
     * @param  (callable(int): void)|null  $onVerified  called with the time of each verified request
     */
    public function __construct(
        Router $router,
        PairingState $pairing,
        Clock $clock,
        NonceStore $nonces,
        WriteLock $lock,
        ?Log $log = null,
        int $budgetSeconds = Protocol::TIME_BUDGET_S,
        int $rateLimit = Protocol::RATE_LIMIT_PER_MINUTE,
        ?callable $onVerified = null
    ) {
        $this->router = $router;
        $this->pairing = $pairing;
        $this->clock = $clock;
        $this->nonces = $nonces;
        $this->lock = $lock;
        $this->log = $log;
        $this->budgetSeconds = $budgetSeconds;
        $this->rateLimit = max(1, $rateLimit);
        $this->onVerified = $onVerified;
    }

    /**
     * @param  array<string, mixed>  $server
     */
    public function handle(array $server, string $rawBody): Response
    {
        $this->budget = new Budget($this->clock, $this->budgetSeconds);
        $this->signing = null;
        $this->requestId = self::requestIdFrom($server);
        $level = ob_get_level();
        ob_start();
        set_error_handler(function (): bool {
            $this->suppressedErrors++;

            return true;
        });
        try {
            $response = $this->process($server, $rawBody, $this->budget);
        } catch (ApiError $error) {
            $response = Response::fromError($error, $this->requestId, $this->serverTime());
        } catch (Throwable $unexpected) {
            if ($this->log !== null) {
                $this->log->warning('api', 'Unexpected error', ['exception' => get_class($unexpected)], $this->requestId);
            }
            $response = Response::fromError(ApiError::moduleError(), $this->requestId, $this->serverTime());
        } finally {
            restore_error_handler();
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }

        return $this->decorate($response);
    }

    /**
     * Sends the response once; the shutdown guard stays silent afterwards.
     */
    public function send(Response $response, string $acceptEncoding, bool $hostCompresses): void
    {
        $this->responded = true;
        $response->send($acceptEncoding, $hostCompresses);
    }

    /**
     * A fatal error (memory, timeout, parse error in a hook of another module) ends the request
     * without reaching handle()'s catch: answer JSON 500 from the shutdown function instead of an
     * HTML error page.
     */
    public function guardFatalErrors(): void
    {
        register_shutdown_function(function (): void {
            if ($this->responded) {
                return;
            }
            $last = error_get_last();
            if ($last === null || ! in_array($last['type'], self::FATAL, true)) {
                return;
            }
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            $this->send($this->fatalResponse(), '', true);
        });
    }

    public function fatalResponse(): Response
    {
        return $this->decorate(Response::fromError(ApiError::moduleError(), $this->requestId, $this->serverTime()));
    }

    public function suppressedErrors(): int
    {
        return $this->suppressedErrors;
    }

    public function router(): Router
    {
        return $this->router;
    }

    /**
     * @param  array<string, mixed>  $server
     *
     * @throws ApiError
     */
    private function process(array $server, string $rawBody, Budget $budget): Response
    {
        $request = Request::fromServer($server, $rawBody);
        if ($request->header(Protocol::HEADER_PROTOCOL) !== Protocol::VERSION) {
            throw ApiError::protocolUnsupported();
        }
        $keys = $this->pairing->isPaired() ? $this->pairing->keys() : null;
        $storeId = $this->pairing->storeId();
        if ($keys === null || $storeId === null) {
            throw ApiError::unpaired();
        }

        $store = $request->header(Protocol::HEADER_STORE);
        if ($store !== null && strtolower($store) !== strtolower($storeId)) {
            throw ApiError::signatureInvalid();
        }
        $now = $this->serverTime();
        $nonce = (string) $request->header(Protocol::HEADER_NONCE);
        $keyId = (new Verifier())->verifyRequest(
            $keys,
            $request->method(),
            $request->path(),
            $request->rawQuery(),
            (string) $request->header(Protocol::HEADER_TIMESTAMP),
            $nonce,
            $request->body(),
            (string) $request->header(Protocol::HEADER_SIGNATURE),
            $request->header(Protocol::HEADER_KEY_ID),
            $now
        );
        // From here every answer is signed, with the key that verified the request: a rotation or an
        // unpair changes the stored secrets, but its answer must verify with the secret it was sent with.
        $this->signing = [$keyId, $keys->candidates($keyId, $now)[$keyId], $nonce];
        $request = $request->withVerifiedKeyId($keyId);

        if (! $this->nonces->claim($nonce, $now)) {
            throw ApiError::nonceReused();
        }
        if ($this->nonces->countSince($now - 59) > $this->rateLimit) {
            throw ApiError::rateLimited(60);
        }
        if ($this->onVerified !== null) {
            ($this->onVerified)($now);
        }

        $handler = $this->router->resolve($request);
        if ($request->method() === 'GET') {
            return $handler($request, $budget);
        }
        if (! $this->lock->acquire()) {
            throw ApiError::busy(Protocol::BUSY_RETRY_S);
        }
        try {
            return $handler($request, $budget);
        } finally {
            $this->lock->release();
        }
    }

    /**
     * The one place that adds X-KX-* headers. An unsigned answer carries only X-KX-Protocol and
     * X-KX-Request-Id (Response::wire() adds Cache-Control, X-Content-Type-Options and X-Robots-Tag):
     * no module version an unauthenticated caller could use to fingerprint a release.
     */
    private function decorate(Response $response): Response
    {
        $response = $response
            ->withHeader(Protocol::HEADER_PROTOCOL, Protocol::VERSION)
            ->withHeader(Protocol::HEADER_REQUEST_ID, $this->requestId);
        if ($this->signing === null) {
            return $response;
        }
        [$keyId, $secret, $nonce] = $this->signing;
        $headers = (new Signer())->responseHeaders($keyId, $secret, $nonce, $this->serverTime(), $response->status(), $response->body());
        $headers[Protocol::HEADER_MODULE_VERSION] = Protocol::MODULE_VERSION;
        $headers[Protocol::HEADER_TIME_USED] = (string) ($this->budget === null ? 0 : $this->budget->elapsedMs());
        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    private function serverTime(): int
    {
        return (int) floor($this->clock->now());
    }

    /**
     * Echoes a well-formed X-KX-Request-Id, otherwise issues a UUID.
     *
     * @param  array<string, mixed>  $server
     */
    private static function requestIdFrom(array $server): string
    {
        $given = $server['HTTP_X_KX_REQUEST_ID'] ?? null;
        if (is_string($given) && preg_match('/\A[A-Za-z0-9._-]{1,64}\z/', $given) === 1) {
            return $given;
        }

        return Uuid::v4();
    }
}

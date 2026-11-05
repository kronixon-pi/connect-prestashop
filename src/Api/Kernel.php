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

use Kronixon\Connect\Auth\PairingState;
use Kronixon\Connect\Protocol;
use Kronixon\Connect\Support\Clock;
use Kronixon\Connect\Support\Uuid;
use Throwable;

/**
 * The single entry of the API front controller: parses the request, enforces the protocol version
 * and the pairing, dispatches, and turns every failure into the JSON error envelope. PHP notices and
 * warnings never reach the body (they are counted, the output buffer is discarded), exceptions
 * become `500 module_error` without class, message or trace, and a fatal error during the request
 * still answers JSON through the shutdown guard.
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

    /** @var int */
    private $budgetSeconds;

    /** @var string */
    private $requestId = '';

    /** @var bool */
    private $responded = false;

    /** @var int */
    private $suppressedErrors = 0;

    public function __construct(Router $router, PairingState $pairing, Clock $clock, int $budgetSeconds = Protocol::TIME_BUDGET_S)
    {
        $this->router = $router;
        $this->pairing = $pairing;
        $this->clock = $clock;
        $this->budgetSeconds = $budgetSeconds;
    }

    /**
     * @param  array<string, mixed>  $server
     */
    public function handle(array $server, string $rawBody): Response
    {
        $budget = new Budget($this->clock, $this->budgetSeconds);
        $this->requestId = self::requestIdFrom($server);
        $level = ob_get_level();
        ob_start();
        set_error_handler(function (): bool {
            $this->suppressedErrors++;

            return true;
        });
        try {
            $response = $this->process($server, $rawBody, $budget);
        } catch (ApiError $error) {
            $response = Response::fromError($error, $this->requestId, $this->serverTime());
        } catch (Throwable $unexpected) {
            // M1 writes the exception class and the request id to kx_log (never the message: it may carry data).
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
        if (! $this->pairing->isPaired()) {
            throw ApiError::unpaired();
        }

        // M1 verifies the signature (Verifier), consumes the nonce (NonceStore) and signs the response here;
        // until then nothing is dispatched without a verified signature.
        throw ApiError::signatureInvalid();
    }

    /**
     * The one place that adds X-KX-* headers. Every M0 answer is unsigned, and an unsigned answer
     * carries only X-KX-Protocol and X-KX-Request-Id (Response::wire() adds Cache-Control,
     * X-Content-Type-Options and X-Robots-Tag): no module version an unauthenticated caller could
     * use to fingerprint a release.
     */
    private function decorate(Response $response): Response
    {
        // M1: a signed answer may add X-KX-Module-Version and X-KX-Time-Used, only after verification.
        return $response
            ->withHeader(Protocol::HEADER_PROTOCOL, Protocol::VERSION)
            ->withHeader(Protocol::HEADER_REQUEST_ID, $this->requestId);
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

    public function router(): Router
    {
        return $this->router;
    }
}

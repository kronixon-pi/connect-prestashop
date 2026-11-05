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

/**
 * A JSON response. The body is final before send(): a signature covers exactly these bytes, and
 * compression (when the client accepts gzip) happens after signing.
 */
final class Response
{
    public const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    private const GZIP_MIN_BYTES = 1024;

    /** @var int */
    private $status;

    /** @var array<string, string> */
    private $headers;

    /** @var string */
    private $body;

    /**
     * @param  array<string, string>  $headers
     */
    private function __construct(int $status, string $body, array $headers)
    {
        $this->status = $status;
        $this->body = $body;
        $this->headers = $headers;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     */
    public static function json(int $status, array $data, array $headers = []): self
    {
        $body = json_encode($data, self::JSON_FLAGS);
        if ($body === false) {
            return self::error(500, 'module_error', 'Unexpected module error.', '', time());
        }

        return new self($status, $body, $headers);
    }

    /**
     * The error envelope of Spec §5.6.
     *
     * @param  array<string, mixed>  $details
     */
    public static function error(int $status, string $code, string $message, string $requestId, int $serverTime, array $details = [], ?int $retryAfter = null): self
    {
        $error = ['code' => $code, 'message' => $message];
        if ($details !== []) {
            $error['details'] = $details;
        }
        $error['request_id'] = $requestId;
        $error['server_time'] = $serverTime;
        $headers = [];
        if ($retryAfter !== null) {
            $error['retry_after'] = $retryAfter;
            $headers['Retry-After'] = (string) $retryAfter;
        }
        $body = json_encode(['error' => $error], self::JSON_FLAGS);

        return new self($status, $body === false ? '{"error":{"code":"module_error"}}' : $body, $headers);
    }

    public static function fromError(ApiError $error, string $requestId, int $serverTime): self
    {
        return self::error($error->status(), $error->errorCode(), $error->getMessage(), $requestId, $serverTime, $error->details(), $error->retryAfter());
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;

        return $clone;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * The bytes and headers as they go on the wire (gzip only when accepted, worth it, and the host
     * does not already compress output).
     *
     * @return array{0: array<string, string>, 1: string}
     */
    public function wire(string $acceptEncoding, bool $hostCompresses): array
    {
        $headers = [
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow',
        ] + $this->headers;
        $body = $this->body;
        if (! $hostCompresses && strlen($body) >= self::GZIP_MIN_BYTES && function_exists('gzencode')
            && self::acceptsGzip($acceptEncoding)) {
            $compressed = gzencode($body, 6);
            if ($compressed !== false) {
                $body = $compressed;
                $headers['Content-Encoding'] = 'gzip';
                $headers['Vary'] = 'Accept-Encoding';
            }
        }
        $headers['Content-Length'] = (string) strlen($body);

        return [$headers, $body];
    }

    /** `gzip` (or `*`) listed in Accept-Encoding with a q-value above zero. */
    public static function acceptsGzip(string $acceptEncoding): bool
    {
        foreach (explode(',', $acceptEncoding) as $token) {
            $parts = array_map('trim', explode(';', $token));
            $name = strtolower($parts[0]);
            if ($name !== 'gzip' && $name !== '*') {
                continue;
            }
            $q = 1.0;
            foreach (array_slice($parts, 1) as $param) {
                if (strncasecmp($param, 'q=', 2) === 0) {
                    $q = (float) substr($param, 2);
                }
            }

            return $q > 0.0;
        }

        return false;
    }

    public function send(string $acceptEncoding = '', bool $hostCompresses = false): void
    {
        [$headers, $body] = $this->wire($acceptEncoding, $hostCompresses);
        if (! headers_sent()) {
            http_response_code($this->status);
            header_remove('X-Powered-By');
            header_remove('Set-Cookie');
            foreach ($headers as $name => $value) {
                header($name.': '.$value, true);
            }
        }
        echo $body;
    }
}

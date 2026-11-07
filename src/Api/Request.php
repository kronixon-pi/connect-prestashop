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

use Kronixon\Connect\Auth\Canonical;
use Kronixon\Connect\Protocol;

/**
 * One API request as the protocol sees it: the effective method (after X-HTTP-Method-Override),
 * the logical path `/v1/<endpoint>` from the `path` parameter, the raw query string (signed
 * canonically) and the body after gzip decoding (signatures cover the uncompressed bytes).
 */
final class Request
{
    private const TRANSPORT_METHODS = ['GET', 'POST', 'DELETE'];

    private const INFLATE_STEP = 4096;

    /** @var string */
    private $method;

    /** @var string */
    private $transportMethod;

    /** @var string */
    private $path;

    /** @var string */
    private $rawQuery;

    /** @var array<string, string> lower-case name => value */
    private $headers;

    /** @var string */
    private $body;

    /** @var string|null `cur` or `prev`: the key the Kernel verified the request with, null before that */
    private $verifiedKeyId;

    /**
     * @param  array<string, string>  $headers
     */
    private function __construct(string $method, string $transportMethod, string $path, string $rawQuery, array $headers, string $body)
    {
        $this->method = $method;
        $this->transportMethod = $transportMethod;
        $this->path = $path;
        $this->rawQuery = $rawQuery;
        $this->headers = $headers;
        $this->body = $body;
    }

    /**
     * @param  array<string, mixed>  $server  $_SERVER or an equivalent
     * @param  string  $rawBody  the body as received, at most $maxBodyBytes + 1 bytes
     *
     * @throws ApiError 400 bad_request | protocol_version_unsupported, 405, 413
     */
    public static function fromServer(array $server, string $rawBody, int $maxBodyBytes = Protocol::MAX_BODY_BYTES): self
    {
        $headers = self::headersFrom($server);
        $transportMethod = strtoupper(self::stringOf($server, 'REQUEST_METHOD'));
        if (! in_array($transportMethod, self::TRANSPORT_METHODS, true)) {
            throw ApiError::methodNotAllowed(self::TRANSPORT_METHODS);
        }
        $method = $transportMethod;
        $override = $headers[strtolower(Protocol::HEADER_METHOD_OVERRIDE)] ?? '';
        if ($override !== '') {
            if ($transportMethod !== 'POST' || strtoupper($override) !== 'DELETE') {
                throw ApiError::methodNotAllowed(self::TRANSPORT_METHODS);
            }
            $method = 'DELETE';
        }

        $rawQuery = self::stringOf($server, 'QUERY_STRING');
        $path = self::logicalPath($rawQuery);
        $body = self::decodeBody($rawBody, $headers['content-encoding'] ?? '', $maxBodyBytes);

        return new self($method, $transportMethod, $path, $rawQuery, $headers, $body);
    }

    /**
     * `path=status`, `path=/v1/status` and `path=v1/status` all name `/v1/status`.
     *
     * @throws ApiError
     */
    public static function logicalPath(string $rawQuery): string
    {
        $value = null;
        foreach (Canonical::parseQuery($rawQuery) as $pair) {
            if ($pair[0] === 'path') {
                $value = $pair[1];
                break;
            }
        }
        if ($value === null || trim($value, '/') === '') {
            throw ApiError::badRequest('The path parameter is missing.');
        }
        $value = ltrim($value, '/');
        if (preg_match('#\Av([0-9]+)(?:/|\z)#', $value, $m) === 1) {
            if ($m[1] !== '1') {
                throw ApiError::protocolUnsupported();
            }
            $value = (string) substr($value, strlen($m[0]));
        }
        $value = rtrim($value, '/');
        if (preg_match('#\A[a-z0-9]+(?:[/_-][a-z0-9]+)*\z#', $value) !== 1) {
            throw ApiError::badRequest('The path parameter is not a protocol endpoint.');
        }

        return '/v1/'.$value;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function transportMethod(): string
    {
        return $this->transportMethod;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function rawQuery(): string
    {
        return $this->rawQuery;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function body(): string
    {
        return $this->body;
    }

    /** A copy that records the key id the signature verified with (KeyRing::CURRENT or ::PREVIOUS). */
    public function withVerifiedKeyId(string $keyId): self
    {
        $copy = clone $this;
        $copy->verifiedKeyId = $keyId;

        return $copy;
    }

    /** The key id the request verified with; null when it has not been verified. */
    public function verifiedKeyId(): ?string
    {
        return $this->verifiedKeyId;
    }

    /** The first value of an endpoint query parameter (transport parameters are not visible here). */
    public function query(string $name): ?string
    {
        if (in_array($name, Canonical::TRANSPORT_PARAMS, true)) {
            return null;
        }
        foreach (Canonical::parseQuery($this->rawQuery) as $pair) {
            if ($pair[0] === $name) {
                return $pair[1];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $server
     * @return array<string, string>
     */
    private static function headersFrom(array $server): array
    {
        $headers = [];
        foreach ($server as $key => $value) {
            if (! is_string($key) || ! is_scalar($value)) {
                continue;
            }
            if (strncmp($key, 'HTTP_', 5) === 0) {
                $name = substr($key, 5);
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_ENCODING'], true)) {
                $name = $key;
            } else {
                continue;
            }
            $headers[strtolower(str_replace('_', '-', $name))] = trim((string) $value);
        }

        return $headers;
    }

    /**
     * @param  array<string, mixed>  $server
     */
    private static function stringOf(array $server, string $key): string
    {
        $value = $server[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @throws ApiError
     */
    private static function decodeBody(string $raw, string $encoding, int $max): string
    {
        if (strlen($raw) > $max) {
            throw ApiError::payloadTooLarge($max);
        }
        $encoding = strtolower($encoding);
        if ($encoding === '' || $encoding === 'identity') {
            return $raw;
        }
        if ($encoding !== 'gzip') {
            throw ApiError::badRequest('Unsupported Content-Encoding.');
        }
        if (! function_exists('inflate_init')) {
            throw ApiError::badRequest('gzip is not available on this host; send the body uncompressed.');
        }
        $context = inflate_init(ZLIB_ENCODING_GZIP);
        if ($context === false) {
            throw ApiError::badRequest('The gzip body cannot be decoded.');
        }
        // Small input steps bound the output of one step (deflate expands at most ~1032:1), so a gzip
        // bomb is stopped at the limit instead of exhausting memory_limit.
        $out = '';
        $offset = 0;
        $length = strlen($raw);
        while ($offset < $length) {
            $piece = (string) substr($raw, $offset, self::INFLATE_STEP);
            $offset += self::INFLATE_STEP;
            $chunk = @inflate_add($context, $piece, $offset >= $length ? ZLIB_FINISH : ZLIB_SYNC_FLUSH);
            if ($chunk === false) {
                throw ApiError::badRequest('The gzip body cannot be decoded.');
            }
            $out .= $chunk;
            if (strlen($out) > $max) {
                throw ApiError::payloadTooLarge($max);
            }
        }
        if (inflate_get_status($context) !== ZLIB_STREAM_END) {
            throw ApiError::badRequest('The gzip body cannot be decoded.');
        }

        return $out;
    }
}

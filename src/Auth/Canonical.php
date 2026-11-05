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

namespace Kronixon\Connect\Auth;

/**
 * Canonical strings of protocol v1 (protocol/v1/README.md §4, Spec §4.1).
 *
 * Request:  METHOD \n PATH[?QUERY] \n TIMESTAMP \n NONCE \n sha256hex(BODY)
 * Response: "RESPONSE" \n REQUEST_NONCE \n TIMESTAMP \n STATUS \n sha256hex(BODY)
 *
 * BODY is always the uncompressed bytes. The query is the endpoint's parameters only: transport
 * parameters are dropped, each key and value is decoded as application/x-www-form-urlencoded
 * (`+` is a space) and re-encoded with RFC 3986 (rawurlencode), pairs are sorted byte-wise by the
 * encoded key, then by the encoded value.
 */
final class Canonical
{
    /** Query parameters that belong to the transport (front controller, PrestaShop dispatcher), never signed. */
    public const TRANSPORT_PARAMS = ['fc', 'module', 'controller', 'path', 'id_lang', 'isolang', 'id_shop'];

    public const RESPONSE_PREFIX = 'RESPONSE';

    private function __construct()
    {
    }

    /**
     * Splits a raw query string into decoded pairs, in wire order.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function parseQuery(string $rawQuery): array
    {
        $pairs = [];
        if ($rawQuery === '') {
            return $pairs;
        }
        foreach (explode('&', $rawQuery) as $part) {
            if ($part === '') {
                continue;
            }
            $eq = strpos($part, '=');
            $key = $eq === false ? $part : substr($part, 0, $eq);
            $value = $eq === false ? '' : substr($part, $eq + 1);
            $pairs[] = [urldecode($key), urldecode($value)];
        }

        return $pairs;
    }

    public static function query(string $rawQuery): string
    {
        $encoded = [];
        foreach (self::parseQuery($rawQuery) as $pair) {
            if (in_array($pair[0], self::TRANSPORT_PARAMS, true)) {
                continue;
            }
            $encoded[] = [rawurlencode($pair[0]), rawurlencode($pair[1])];
        }
        usort($encoded, static function (array $a, array $b): int {
            $byKey = strcmp($a[0], $b[0]);

            return $byKey !== 0 ? $byKey : strcmp($a[1], $b[1]);
        });

        return implode('&', array_map(static function (array $pair): string {
            return $pair[0].'='.$pair[1];
        }, $encoded));
    }

    /** The PATH line: the logical path plus `?` and the canonical query when there is one. */
    public static function pathLine(string $logicalPath, string $rawQuery): string
    {
        $query = self::query($rawQuery);

        return $query === '' ? $logicalPath : $logicalPath.'?'.$query;
    }

    public static function request(string $method, string $logicalPath, string $rawQuery, int $timestamp, string $nonce, string $body): string
    {
        return implode("\n", [
            strtoupper($method),
            self::pathLine($logicalPath, $rawQuery),
            (string) $timestamp,
            $nonce,
            hash('sha256', $body),
        ]);
    }

    public static function response(string $requestNonce, int $timestamp, int $status, string $body): string
    {
        return implode("\n", [
            self::RESPONSE_PREFIX,
            $requestNonce,
            (string) $timestamp,
            (string) $status,
            hash('sha256', $body),
        ]);
    }
}

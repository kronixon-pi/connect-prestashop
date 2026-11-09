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

namespace Kronixon\Connect\Catalog;

use Kronixon\Connect\Api\ApiError;

/**
 * The `cursor` of `GET catalog/*` (README.md, "Catalog reads"): base64url without padding of the JSON
 * `{"id":<last id>}`, or `{"id":<product id>,"v":<variant id>}` for the rows keyed by product and
 * variant (prices, stock). A page starts after that position; `{"id":N}` without `v` starts after
 * every row of id N, so a client may build `{"id":<after>}` itself to start an id range.
 *
 * Only the exact form this class writes is accepted: anything else (padding, other members, a
 * number written another way, a negative id) is `400 bad_request`. Its integrity needs no secret of
 * its own: every request that carries it is signed by the platform.
 */
final class Cursor
{
    private const MAX_LENGTH = 512;

    private function __construct()
    {
    }

    public static function encode(int $id, ?int $variant = null): string
    {
        $data = $variant === null ? ['id' => $id] : ['id' => $id, 'v' => $variant];

        return rtrim(strtr(base64_encode((string) json_encode($data)), '+/', '-_'), '=');
    }

    /**
     * @return array{0: int, 1: int|null} the id and the variant id (null: after every row of the id)
     *
     * @throws ApiError 400 bad_request
     */
    public static function decode(string $cursor): array
    {
        $invalid = ApiError::badQuery(['cursor']);
        if ($cursor === '' || strlen($cursor) > self::MAX_LENGTH || preg_match('/\A[A-Za-z0-9_-]+\z/', $cursor) !== 1) {
            throw $invalid;
        }
        $json = base64_decode(strtr($cursor, '-_', '+/'), true);
        $data = is_string($json) ? json_decode($json, true) : null;
        if (! is_array($data) || ! is_int($data['id'] ?? null) || $data['id'] < 0) {
            throw $invalid;
        }
        $variant = $data['v'] ?? null;
        if ($variant !== null && (! is_int($variant) || $variant < 0)) {
            throw $invalid;
        }
        if (self::encode($data['id'], $variant) !== $cursor) {
            throw $invalid;
        }

        return [$data['id'], $variant];
    }
}

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

use Kronixon\Connect\Api\Response;

/**
 * The answer of a `GET catalog/*` (common.json `page_meta`): `data` in id order and `meta` with
 * `count`, `next_cursor` (null on the last page), `has_more`, `max_id` and `generated_at`.
 * An answer stays under 8 MB (README §9): items past that size move to the next page; a page keeps at
 * least one item, so a walk always advances.
 */
final class Page
{
    /** 8 MB less room for `meta` and the envelope. */
    public const MAX_BYTES = 8323072;

    /** @var list<array<string, mixed>> */
    public $items;

    /** @var bool */
    public $hasMore;

    /** @var string|null */
    public $nextCursor;

    /**
     * @param  list<array<string, mixed>>  $items  at most the page's limit, in cursor order
     * @param  bool  $more  rows exist past the last item (in the range)
     * @param  callable(array<string, mixed>): string  $cursorOf  the cursor that starts after an item
     */
    public function __construct(array $items, bool $more, callable $cursorOf, int $maxBytes = self::MAX_BYTES)
    {
        $bytes = 0;
        $kept = [];
        foreach ($items as $item) {
            $bytes += strlen((string) json_encode($item, Response::JSON_FLAGS)) + 1;
            if ($kept !== [] && $bytes > $maxBytes) {
                $more = true;
                break;
            }
            $kept[] = $item;
        }
        $this->items = $kept;
        $this->hasMore = $more && $kept !== [];
        $this->nextCursor = $this->hasMore ? $cursorOf($kept[count($kept) - 1]) : null;
    }

    public static function none(): self
    {
        return new self([], false, static function (): string {
            return '';
        });
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function answer(string $maxId, string $generatedAt): array
    {
        return [
            'data' => $this->items,
            'meta' => [
                'count' => count($this->items),
                'next_cursor' => $this->nextCursor,
                'has_more' => $this->hasMore,
                'max_id' => $maxId,
                'generated_at' => $generatedAt,
            ],
        ];
    }
}

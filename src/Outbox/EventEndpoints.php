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

namespace Kronixon\Connect\Outbox;

use Kronixon\Connect\Api\ApiError;
use Kronixon\Connect\Api\Request;
use Kronixon\Connect\Api\Response;
use Kronixon\Connect\Api\Router;
use Kronixon\Connect\Catalog\Cursor;
use Kronixon\Connect\Support\Clock;
use Kronixon\Connect\Support\Uuid;

/**
 * The pull side of the outbox (Spec §6.34): `GET events` lists pending and failed rows, one event per
 * row and oldest first, and `POST events/ack` marks rows sent by event id. The platform uses it when
 * the pushes do not arrive; a row it acknowledges is not pushed again.
 */
final class EventEndpoints
{
    public const MAX_LIMIT = 200;

    /** @var callable(): array{0: OutboxStore, 1: Events} */
    private $outbox;

    /** @var Clock */
    private $clock;

    /**
     * @param  callable(): array{0: OutboxStore, 1: Events}  $outbox  built when an endpoint runs
     */
    public function __construct(callable $outbox, Clock $clock)
    {
        $this->outbox = $outbox;
        $this->clock = $clock;
    }

    public function register(Router $router): Router
    {
        return $router
            ->add('GET', '/v1/events', function (Request $request): Response {
                return Response::json(200, $this->pull($request));
            })
            ->add('POST', '/v1/events/ack', function (Request $request): Response {
                return Response::json(200, ['acknowledged' => $this->ack($request)]);
            });
    }

    /**
     * @return array<string, mixed>
     */
    private function pull(Request $request): array
    {
        $limit = $request->query('limit');
        if ($limit !== null && preg_match('/\A[1-9][0-9]{0,2}\z/', $limit) !== 1) {
            throw ApiError::badQuery(['limit']);
        }
        $limit = min(self::MAX_LIMIT, $limit === null ? self::MAX_LIMIT : (int) $limit);
        $cursor = $request->query('cursor');
        $after = $cursor === null ? 0 : Cursor::decode($cursor)[0];

        [$store, $events] = ($this->outbox)();
        $rows = $store->pull($after, $limit + 1);
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $data = [];
        foreach ($events->build($rows, false) as $entry) {
            if ($entry['event'] !== null) {
                $data[] = $entry['event'];
            }
        }
        $last = $rows === [] ? null : (int) $rows[count($rows) - 1]['id_outbox'];

        return [
            'data' => $data,
            'meta' => [
                'count' => count($data),
                'next_cursor' => $more && $last !== null ? Cursor::encode($last) : null,
                'has_more' => $more,
                'generated_at' => gmdate('Y-m-d\TH:i:s\Z', (int) floor($this->clock->now())),
            ],
        ];
    }

    private function ack(Request $request): int
    {
        $body = json_decode($request->body(), true);
        $ids = is_array($body) ? ($body['ids'] ?? null) : null;
        if (! is_array($ids) || $ids === [] || count($ids) > self::MAX_LIMIT || array_keys($ids) !== range(0, count($ids) - 1)) {
            throw ApiError::validationFailed(['ids']);
        }
        foreach ($ids as $id) {
            if (! is_string($id) || ! Uuid::isValid($id)) {
                throw ApiError::validationFailed(['ids']);
            }
        }
        if (count(array_unique(array_map('strtolower', $ids))) !== count($ids)) {
            throw ApiError::validationFailed(['ids']);
        }
        [$store] = ($this->outbox)();

        return $store->ack(array_values($ids), gmdate('Y-m-d H:i:s', (int) floor($this->clock->now())));
    }
}

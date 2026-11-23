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

/**
 * Outbox rows as events of schemas/events.json. Coalesced, the rows of one entity_key are one event:
 * the newest row's event_id, type and time; a stock event keeps the oldest row's previous quantity.
 * Events follow the order of their newest rows, so the events of one entity keep their order.
 *
 * What the hook did not know is read when the event is built (Enricher): the product's content_hash,
 * the variant's price, the entity's kx_id. An event whose entity is gone by then is dropped (null): a
 * `*.deleted` event follows it.
 */
final class Events
{
    /** kx_entity_map types by the start of an event type. */
    private const ENTITIES = [
        'product' => 'product',
        'price' => 'product',
        'stock' => 'product',
        'combination' => 'product_attribute',
        'category' => 'category',
        'brand' => 'manufacturer',
    ];

    /** @var Enricher */
    private $enricher;

    public function __construct(Enricher $enricher)
    {
        $this->enricher = $enricher;
    }

    /**
     * @param  list<array<string, string|null>>  $rows  of kx_outbox, by id_outbox
     * @return list<array{event: array<string, mixed>|null, ids: list<int>}>
     */
    public function build(array $rows, bool $coalesce): array
    {
        /** @var array<string, list<array<string, string|null>>> $groups */
        $groups = [];
        foreach ($rows as $row) {
            $key = $coalesce ? (string) $row['entity_key'] : (string) $row['id_outbox'];
            // A key goes to the end when it gets a newer row: groups end in the order of their newest rows.
            $group = $groups[$key] ?? [];
            unset($groups[$key]);
            $group[] = $row;
            $groups[$key] = $group;
        }

        $events = [];
        foreach ($groups as $group) {
            $events[] = ['event' => $this->event($group), 'ids' => array_map(static function (array $row): int {
                return (int) $row['id_outbox'];
            }, $group)];
        }

        $events = $this->enrich($events);
        foreach ($events as $i => $entry) {
            if ($entry['event'] !== null && $entry['event']['payload'] === []) {
                // `{}` on the wire, not `[]`.
                $events[$i]['event']['payload'] = new \stdClass();
            }
        }

        return $events;
    }

    /** `2026-11-23 10:00:00.123` (kx_outbox) as `2026-11-23T10:00:00.123Z`. */
    public static function iso(string $stored): string
    {
        $time = str_replace(' ', 'T', trim($stored));
        if (preg_match('/\.[0-9]+\z/', $time) === 1) {
            $time = rtrim(rtrim($time, '0'), '.');
        }

        return $time.'Z';
    }

    /**
     * @param  non-empty-list<array<string, string|null>>  $group
     * @return array<string, mixed>
     */
    private function event(array $group): array
    {
        $newest = $group[count($group) - 1];
        $payload = self::payload($newest);
        if ($newest['type'] === 'module.outbox_overflow' && count($group) > 1) {
            // Everything since the first gap, whatever was merged in between.
            $payload['since'] = self::payload($group[0])['since'] ?? ($payload['since'] ?? null);
            $payload['merged'] = array_sum(array_map(static function (array $row): int {
                return (int) (self::payload($row)['merged'] ?? 0);
            }, $group));
        }
        if ($newest['type'] === 'stock.updated' && count($group) > 1) {
            $oldest = self::payload($group[0]);
            if (array_key_exists('previous_quantity', $oldest)) {
                $payload['previous_quantity'] = $oldest['previous_quantity'];
            } else {
                unset($payload['previous_quantity']);
            }
        }

        return [
            'event_id' => strtolower((string) $newest['event_id']),
            'type' => (string) $newest['type'],
            'external_id' => $newest['external_id'],
            'occurred_at' => self::iso((string) $newest['occurred_at']),
            'dedupe_key' => substr((string) $newest['entity_key'], 0, 170).':'.(int) $newest['id_outbox'],
            'origin' => 'shop',
            'payload' => $payload,
        ];
    }

    /**
     * @param  list<array{event: array<string, mixed>|null, ids: list<int>}>  $events
     * @return list<array{event: array<string, mixed>|null, ids: list<int>}>
     */
    private function enrich(array $events): array
    {
        $hashed = [];
        $priced = [];
        $linked = [];
        foreach ($events as $entry) {
            $event = $entry['event'];
            if ($event === null || $event['external_id'] === null) {
                continue;
            }
            $id = (int) $event['external_id'];
            if ($event['type'] === 'product.created' || $event['type'] === 'product.updated') {
                $hashed[$id] = $id;
            } elseif ($event['type'] === 'price.updated') {
                $priced[$id] = $id;
            }
            $entity = self::entity($event);
            if ($entity !== null) {
                $linked[$entity][$id] = $id;
            }
        }
        $hashes = $hashed === [] ? [] : $this->enricher->contentHashes(array_values($hashed));
        $prices = $priced === [] ? [] : $this->enricher->prices(array_values($priced));
        $kx = [];
        foreach ($linked as $entity => $ids) {
            $kx[$entity] = $this->enricher->kxIds($entity, array_values($ids));
        }

        foreach ($events as $i => $entry) {
            $event = $entry['event'];
            if ($event === null || $event['external_id'] === null) {
                continue;
            }
            $id = (int) $event['external_id'];
            if ($event['type'] === 'product.created' || $event['type'] === 'product.updated') {
                if (! isset($hashes[$id])) {
                    $events[$i]['event'] = null;
                    continue;
                }
                $event['payload']['content_hash'] = $hashes[$id];
            } elseif ($event['type'] === 'price.updated') {
                $price = $prices[$id.':'.($event['payload']['variant_id'] ?? '0')] ?? null;
                if ($price === null) {
                    $events[$i]['event'] = null;
                    continue;
                }
                $event['payload']['price'] = $price;
            }
            $entity = self::entity($event);
            if ($entity !== null && isset($kx[$entity][$id])) {
                $event['kx_id'] = $kx[$entity][$id];
            }
            $events[$i]['event'] = $event;
        }

        return $events;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private static function entity(array $event): ?string
    {
        $prefix = strstr((string) $event['type'], '.', true);
        if ($prefix === 'attribute_value') {
            return ($event['payload']['kind'] ?? null) === 'feature' ? 'feature_value' : 'attribute';
        }

        return self::ENTITIES[(string) $prefix] ?? null;
    }

    /**
     * @param  array<string, string|null>  $row
     * @return array<string, mixed>
     */
    private static function payload(array $row): array
    {
        $payload = $row['payload'] === null ? [] : json_decode((string) $row['payload'], true);

        return is_array($payload) ? $payload : [];
    }
}

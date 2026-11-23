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
 * One change of the shop as a hook sees it: an event type of schemas/events.json, the entity's id and
 * what the hook knows for free. Changes with the same key are one event when they are sent (coalesced:
 * the platform re-reads the entity anyway).
 */
final class Change
{
    /** @var string */
    public $type;

    /** @var string|null */
    public $externalId;

    /** @var array<string, mixed> payload members known at the hook */
    public $payload;

    /** @var string what makes two changes the same: type, entity and, for prices and stock, the variant */
    public $key;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(string $type, ?string $externalId, array $payload = [], string $variant = '')
    {
        $this->type = $type;
        $this->externalId = $externalId;
        $this->payload = $payload;
        $this->key = $type.':'.($externalId ?? '-').($variant === '' ? '' : ':'.$variant);
    }
}

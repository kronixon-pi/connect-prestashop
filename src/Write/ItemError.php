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

namespace Kronixon\Connect\Write;

use RuntimeException;

/**
 * Why one item of a write batch was refused (common.json `item_result`: `code`, `field`, `message`).
 * The item's transaction is rolled back; the batch goes on. The message is short English.
 */
final class ItemError extends RuntimeException
{
    /** @var string */
    private $itemCode;

    /** @var string|null */
    private $field;

    /** @var array<string, mixed> further members of the result (`candidates`) */
    private $extra;

    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(string $itemCode, string $message, ?string $field = null, array $extra = [])
    {
        parent::__construct($message);
        $this->itemCode = $itemCode;
        $this->field = $field;
        $this->extra = $extra;
    }

    public static function invalid(string $field, string $why = 'is missing or invalid'): self
    {
        return new self('validation_failed', $field.' '.$why, $field);
    }

    /** An entity the item names does not exist in the paired shop: `not_found`, `brand_not_found`, … */
    public static function missing(string $field, string $code = 'not_found'): self
    {
        return new self($code, $field.' names nothing of this shop', $field);
    }

    /** PrestaShop's own validation (`Property Product->ean13 is not valid`): the field, never the message. */
    public static function refused(string $message): self
    {
        // 8.x escapes the arrow of its message for HTML.
        return preg_match('/-(?:>|&gt;)(\w+)/', $message, $m) === 1
            ? self::invalid($m[1], 'is refused by PrestaShop')
            : new self('validation_failed', 'PrestaShop refused the item');
    }

    /** PrestaShop refused to store the object (a save returned false). */
    public static function notSaved(string $what): self
    {
        return new self('save_failed', 'PrestaShop could not store the '.$what);
    }

    public function itemCode(): string
    {
        return $this->itemCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function result(): array
    {
        $result = ['code' => $this->itemCode, 'message' => mb_substr($this->getMessage(), 0, 500, 'UTF-8')];
        if ($this->field !== null) {
            $result['field'] = substr($this->field, 0, 128);
        }

        return $result + $this->extra;
    }
}

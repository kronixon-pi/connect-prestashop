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
use Throwable;

/**
 * Database::lockRows() did not get the row locks, or a statement of Database::bulk() waited for one in
 * vain: another session held a row longer than the wait (MySQL 1205) or the two waited for each other
 * (1213, a deadlock). The chunk is rolled back; nothing of it is written.
 */
final class RowsLocked extends RuntimeException
{
    /** @var bool */
    private $deadlock;

    public function __construct(string $message, bool $deadlock = false, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->deadlock = $deadlock;
    }

    /** The server chose this transaction to roll back (1213): the other session goes on at once. */
    public function deadlock(): bool
    {
        return $this->deadlock;
    }
}

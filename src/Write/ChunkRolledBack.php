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
 * Database::commitChunk() found the chunk's marker row gone: the server rolled the chunk's transaction
 * back while it ran, and whatever ran after that is in a transaction of its own, which the caller rolls
 * back. Nothing of the chunk is written.
 */
final class ChunkRolledBack extends RuntimeException
{
}

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

namespace Kronixon\Connect\Install;

use Throwable;

/**
 * All-or-nothing install: runs each step in order; when one fails (returns false or throws), undoes
 * that step and every earlier one in reverse order, so a failed install leaves no module row, no
 * table and no configuration key behind. An undo must be safe on a half-done step.
 */
final class Steps
{
    private function __construct()
    {
    }

    /**
     * @param  array<string, array{0: callable(): bool, 1: callable(): bool}>  $steps  name => [do, undo]
     * @return string|null the name of the step that failed, null when all passed
     */
    public static function run(array $steps): ?string
    {
        $started = [];
        foreach ($steps as $name => [$do, $undo]) {
            $started[] = $undo;
            try {
                $ok = $do();
            } catch (Throwable $e) {
                $ok = false;
            }
            if (! $ok) {
                foreach (array_reverse($started) as $rollback) {
                    try {
                        $rollback();
                    } catch (Throwable $e) {
                        // keep undoing the remaining steps
                    }
                }

                return (string) $name;
            }
        }

        return null;
    }
}

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

namespace Kronixon\Connect\Update;

use RuntimeException;

/**
 * Why an update was not installed. The code is shown to the administrator and sent in `module.update_failed`; its
 * kind decides the message of the settings page:
 *
 * - `refused`: the release failed a check (signature, sha256, size, key, channel, version, package);
 * - `download`: the zip could not be fetched;
 * - `server`: this server does not let the module replace its own files (read-only folder, disk space, a missing
 *   PHP extension or function) — the administrator installs the zip by hand;
 * - `busy`: another update runs;
 * - `failed`: the upgrade failed and the previous version was restored.
 */
final class UpdateError extends RuntimeException
{
    public const REFUSED = 'refused';

    public const DOWNLOAD = 'download';

    public const SERVER = 'server';

    public const BUSY = 'busy';

    public const FAILED = 'failed';

    /** @var string */
    private $kind;

    /** @var string */
    private $errorCode;

    public function __construct(string $kind, string $code, string $detail = '')
    {
        parent::__construct($detail === '' ? $code : $code.': '.$detail);
        $this->kind = $kind;
        $this->errorCode = $code;
    }

    public static function refused(string $code, string $detail = ''): self
    {
        return new self(self::REFUSED, $code, $detail);
    }

    public static function server(string $code, string $detail = ''): self
    {
        return new self(self::SERVER, $code, $detail);
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function code(): string
    {
        return $this->errorCode;
    }
}

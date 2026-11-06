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

namespace Kronixon\Connect\Support;

use Db;
use Throwable;

/**
 * The module log (table `kx_log`, kept 14 days). It never holds a secret: context values under a
 * sensitive name are replaced, and anything shaped like a shared secret, a signature or a sealed
 * box is masked in every string before it is written. Logging never fails a request.
 */
final class Log
{
    public const RETENTION_DAYS = 14;

    private const SENSITIVE_NAME = '/secret|sealed|private|signature|passw|token|code|key/i';

    /** @var callable(array<string, string|null>): void */
    private $sink;

    /**
     * @param  callable(array<string, string|null>): void  $sink  receives one row: level, channel, message, context, request_id
     */
    public function __construct(callable $sink)
    {
        $this->sink = $sink;
    }

    public static function forShop(Db $db, string $prefix): self
    {
        return new self(static function (array $row) use ($db, $prefix): void {
            $db->execute('DELETE FROM `'.$prefix.'kx_log` WHERE `created_at` < DATE_SUB(NOW(), INTERVAL '.self::RETENTION_DAYS.' DAY)');
            $values = [];
            foreach ($row as $column => $value) {
                $values[] = $value === null ? 'NULL' : '\''.$db->escape($value).'\'';
            }
            $db->execute('INSERT INTO `'.$prefix.'kx_log` (`'.implode('`, `', array_keys($row)).'`, `created_at`) VALUES ('.implode(', ', $values).', NOW())');
        });
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function info(string $channel, string $message, array $context = [], ?string $requestId = null): void
    {
        $this->write('info', $channel, $message, $context, $requestId);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function warning(string $channel, string $message, array $context = [], ?string $requestId = null): void
    {
        $this->write('warning', $channel, $message, $context, $requestId);
    }

    /** Masks shared secrets, hex signatures/hashes and long base64 blobs. */
    public static function scrub(string $text): string
    {
        return (string) preg_replace(
            ['/kxs_[0-9A-Za-z]{8,}/', '/\b[0-9a-fA-F]{64}\b/', '#[A-Za-z0-9+/]{40,}={0,2}#'],
            ['kxs_***', '***', '***'],
            $text
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function write(string $level, string $channel, string $message, array $context, ?string $requestId): void
    {
        $clean = [];
        foreach ($context as $name => $value) {
            if (preg_match(self::SENSITIVE_NAME, (string) $name) === 1 && $name !== 'error_code') {
                $clean[$name] = '***';
            } elseif (is_scalar($value) || $value === null) {
                $clean[$name] = is_string($value) ? self::scrub($value) : $value;
            } else {
                $clean[$name] = self::scrub((string) json_encode($value, JSON_UNESCAPED_SLASHES));
            }
        }
        $json = $clean === [] ? null : json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        try {
            ($this->sink)([
                'level' => $level,
                'channel' => substr($channel, 0, 32),
                'message' => substr(self::scrub($message), 0, 255),
                'context' => $json === false || $json === null ? null : substr($json, 0, 4000),
                'request_id' => $requestId === null ? null : substr($requestId, 0, 64),
            ]);
        } catch (Throwable $e) {
            // The log is best effort; a request never fails because of it.
        }
    }
}

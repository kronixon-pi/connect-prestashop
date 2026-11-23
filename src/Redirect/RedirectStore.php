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

namespace Kronixon\Connect\Redirect;

use Kronixon\Connect\Support\Settings;
use Kronixon\Connect\Write\Database;

/**
 * `kx_redirect`: old paths of one shop and where they lead (a product, a category, or nowhere: 410).
 * KX_REDIRECTS is `1` while the table may hold any (sync()), so a shop without redirects pays no query
 * for them. `path_from` is VARBINARY: one row is one path byte for byte, as for PrestaShop's router (no
 * collation folds case or ignores a trailing space).
 */
final class RedirectStore
{
    public const FLAG = 'KX_REDIRECTS';

    /** `kx_redirect.path_from`, in characters (764 bytes). */
    private const LENGTH = 191;

    /** @var Database */
    private $db;

    /** @var Settings */
    private $settings;

    /** @var int */
    private $shopId;

    public function __construct(Database $db, Settings $settings, int $shopId)
    {
        $this->db = $db;
        $this->settings = $settings;
        $this->shopId = $shopId;
    }

    /**
     * The path of a URL or request URI as it is kept and compared: decoded, without query and fragment;
     * null for one that cannot be kept (too long, not UTF-8, not absolute).
     */
    public static function path(string $url): ?string
    {
        $path = (string) preg_replace('#\A[a-z][a-z0-9+.-]*://[^/?\#]*#i', '', $url);
        $path = rawurldecode(explode('?', explode('#', $path, 2)[0], 2)[0]);

        return $path !== '' && $path[0] === '/' && mb_check_encoding($path, 'UTF-8') && preg_match('/[\x00-\x1F]/', $path) !== 1 && mb_strlen($path, 'UTF-8') <= self::LENGTH ? $path : null;
    }

    /** @return string `created`, `updated` or `unchanged` */
    public function put(string $path, string $targetType, int $targetId, int $code): string
    {
        $row = $this->find($path);
        if ($row !== null && $row === ['target_type' => $targetType, 'target_id' => $targetId, 'code' => $code]) {
            return 'unchanged';
        }
        $this->remove([$path]);
        $this->db->execute('INSERT INTO '.$this->db->table('kx_redirect').' (id_shop, path_from, target_type, target_id, code, date_add) VALUES ('
            .$this->shopId.', '.$this->db->quote($path).', '.$this->db->quote($targetType).', '.$targetId.', '.$code.', '.$this->db->quote(date('Y-m-d H:i:s')).')');
        if ($this->settings->get(self::FLAG) !== '1') {
            $this->settings->set(self::FLAG, '1');
        }

        return $row === null ? 'created' : 'updated';
    }

    /** An address that changed keeps leading to its entity; nothing when the path did not change. */
    public function keep(string $oldUrl, string $newUrl, string $targetType, int $targetId): void
    {
        $old = self::path($oldUrl);
        $new = self::path($newUrl);
        if ($old !== null && $new !== null && $old !== $new) {
            $this->remove([$new]);
            $this->put($old, $targetType, $targetId, 301);
        }
    }

    /**
     * @param  list<string>  $paths
     * @return bool whether any was there
     */
    public function remove(array $paths): bool
    {
        $there = array_values(array_filter($paths, function (string $path): bool {
            return $this->find($path) !== null;
        }));
        foreach ($there as $path) {
            $this->db->execute('DELETE FROM '.$this->db->table('kx_redirect').' WHERE id_shop = '.$this->shopId.' AND path_from = '.$this->db->quote($path));
        }

        return $there !== [];
    }

    /**
     * @return array{target_type: string, target_id: int, code: int}|null
     */
    public function find(string $path): ?array
    {
        $rows = $this->db->rows('SELECT path_from, target_type, target_id, code FROM '.$this->db->table('kx_redirect')
            .' WHERE id_shop = '.$this->shopId.' AND path_from = '.$this->db->quote($path));

        return $rows === [] ? null : ['target_type' => (string) $rows[0]['target_type'], 'target_id' => (int) $rows[0]['target_id'], 'code' => (int) $rows[0]['code']];
    }

    public function any(): bool
    {
        return $this->settings->get(self::FLAG) === '1';
    }

    /**
     * Once after a write request, under its lock: the flag goes with the last row of any shop. Nobody else
     * adds rows, so none can appear unnoticed; a row removed in the back office is noticed by the next write.
     */
    public function sync(): void
    {
        if ($this->any() && $this->db->rows('SELECT 1 FROM '.$this->db->table('kx_redirect').' LIMIT 1') === []) {
            $this->settings->set(self::FLAG, '0');
        }
    }
}

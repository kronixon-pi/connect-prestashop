<?php

declare(strict_types=1);
/**
 * Kronixon Connect for PrestaShop — test tooling, not part of the module zip.
 *
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 *
 * Helpers of catalog-writes.php and product-writes.php, after the shop's config.inc.php.
 */

/**
 * @return array<string, callable>
 */
function kxShopHelpers(Db $db, string $p): array
{
    return [
        'value' => static function (string $sql) use ($db): int {
            return (int) $db->getValue($sql, false);
        },
        'text' => static function (string $sql) use ($db): string {
            return (string) $db->getValue($sql, false);
        },
        // The shop's second language, as tests/fixtures/generate.php adds it.
        'bulgarian' => static function (): int {
            $id = (int) Language::getIdByIso('bg');
            if ($id > 0) {
                return $id;
            }
            define('_PS_ADMIN_DIR_', _PS_ROOT_DIR_.'/admin-dev');
            $language = new Language();
            $language->name = 'Bulgarian';
            $language->iso_code = 'bg';
            $language->locale = 'bg-BG';
            $language->language_code = 'bg-bg';
            $language->date_format_lite = 'd.m.Y';
            $language->date_format_full = 'd.m.Y H:i:s';
            $language->is_rtl = false;
            $language->active = true;
            if (! $language->add(true, false, true)) {
                throw new RuntimeException('Could not add the bg language.');
            }
            Language::copyLanguageData((int) Configuration::get('PS_LANG_DEFAULT'), (int) $language->id);
            Language::resetCache();

            return (int) $language->id;
        },
        // Every item of a GET catalog/* by external id.
        'readAll' => static function (SignedClient $kx, string $endpoint, array $query = []): array {
            $items = [];
            do {
                $page = $kx->get($endpoint, $query + ['limit' => '500']);
                foreach ($page['data']['data'] ?? [] as $item) {
                    $items[(int) $item['external_id']] = $item;
                }
                $query['cursor'] = (string) ($page['data']['meta']['next_cursor'] ?? '');
            } while ($query['cursor'] !== '');

            return $items;
        },
    ];
}

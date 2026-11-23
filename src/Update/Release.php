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

/**
 * One release as the platform's signed `GET /v1/module/latest` names it (`latest`, schemas/platform/module-latest.json).
 * What it says is only a pointer: the zip it names is installed only when its bytes carry a signature of a key built
 * into this module (Package).
 */
final class Release
{
    public const VERSION = '/\A[0-9]+\.[0-9]+\.[0-9]+\z/';

    /** @var string */
    public $version;

    /** @var string */
    public $channel;

    /** @var string */
    public $url;

    /** @var int */
    public $size;

    /** @var string */
    public $sha256;

    /** @var string 64 bytes */
    public $signature;

    /** @var string */
    public $keyId;

    /** @var bool */
    public $mandatory;

    /** @var string|null */
    public $changelogUrl;

    /**
     * @param  mixed  $latest  the answer's `latest`
     *
     * @throws UpdateError `refused` when a field is missing or malformed
     */
    public static function fromAnswer($latest): self
    {
        if (! is_array($latest)) {
            throw UpdateError::refused('bad_answer', 'latest');
        }
        $release = new self();
        $release->version = self::text($latest, 'version', self::VERSION);
        $release->channel = self::text($latest, 'channel', '/\A(stable|staging)\z/');
        $release->url = self::text($latest, 'url', '#\Ahttps?://[^\s]{1,2040}\z#');
        $release->sha256 = self::text($latest, 'sha256', '/\A[0-9a-f]{64}\z/');
        $release->keyId = self::text($latest, 'signature_key_id', '/\A[a-z0-9][a-z0-9-]{0,31}\z/');
        $signature = base64_decode(self::text($latest, 'signature', '#\A[A-Za-z0-9+/]{86}==\z#'), true);
        $size = $latest['size'] ?? null;
        if (! is_int($size) || $size < 1 || $size > Package::MAX_ZIP_BYTES || ! is_string($signature) || strlen($signature) !== 64) {
            throw UpdateError::refused('bad_answer', 'size');
        }
        $release->size = $size;
        // `latest.protocol` is not read: the signed manifest's `protocol` is what Package::open() checks.
        $release->signature = $signature;
        $release->mandatory = ($latest['mandatory'] ?? false) === true;
        $changelog = $latest['changelog_url'] ?? null;
        $release->changelogUrl = is_string($changelog) && preg_match('#\Ahttps://[^\s"<>]{1,500}\z#', $changelog) === 1 ? $changelog : null;

        return $release;
    }

    /**
     * @param  array<mixed>  $latest
     */
    private static function text(array $latest, string $field, string $pattern): string
    {
        $value = $latest[$field] ?? null;
        if (! is_string($value) || preg_match($pattern, $value) !== 1) {
            throw UpdateError::refused('bad_answer', $field);
        }

        return $value;
    }
}

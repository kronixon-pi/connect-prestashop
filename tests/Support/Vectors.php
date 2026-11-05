<?php

declare(strict_types=1);

namespace Kronixon\Connect\Tests\Support;

use Kronixon\Connect\Auth\KeyRing;
use RuntimeException;

/**
 * Reads protocol/v1/vectors.json and turns a request vector into the $_SERVER/body pair the
 * front controller would see.
 */
final class Vectors
{
    public const FILE = __DIR__.'/../../protocol/v1/vectors.json';

    /**
     * @return array<string, mixed>
     */
    public static function document(): array
    {
        $json = file_get_contents(self::FILE);
        if ($json === false) {
            throw new RuntimeException('vectors.json is missing');
        }
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($data)) {
            throw new RuntimeException('vectors.json is not an object');
        }

        return $data;
    }

    /**
     * @return array<string, array<string, mixed>> id => vector
     */
    public static function byKind(string $kind): array
    {
        $out = [];
        foreach (self::document()['vectors'] as $vector) {
            if ($vector['kind'] === $kind) {
                $out[$vector['id']] = $vector;
            }
        }

        return $out;
    }

    public static function keyRing(): KeyRing
    {
        $doc = self::document();

        return new KeyRing($doc['secrets']['cur'], $doc['secrets']['prev'], (int) $doc['prev_valid_until']);
    }

    /**
     * @param  array<string, mixed>  $vector
     * @return array<string, string>
     */
    public static function server(array $vector): array
    {
        $server = [
            'REQUEST_METHOD' => $vector['transport']['method'],
            'QUERY_STRING' => $vector['transport']['query'],
            'HTTP_X_KX_PROTOCOL' => 'v1',
            'HTTP_X_KX_TIMESTAMP' => (string) $vector['timestamp'],
            'HTTP_X_KX_NONCE' => $vector['nonce'],
            'HTTP_X_KX_SIGNATURE' => $vector['expected']['signature'],
        ];
        foreach ($vector['transport']['headers'] as $name => $value) {
            $key = strtoupper(str_replace('-', '_', $name));
            $server[$key === 'CONTENT_ENCODING' ? 'HTTP_CONTENT_ENCODING' : 'HTTP_'.$key] = $value;
        }

        return $server;
    }

    /**
     * @param  array<string, mixed>  $vector
     */
    public static function wireBody(array $vector): string
    {
        if (isset($vector['transport']['body_base64'])) {
            $raw = base64_decode($vector['transport']['body_base64'], true);
            if ($raw === false) {
                throw new RuntimeException('bad base64 in '.$vector['id']);
            }

            return $raw;
        }

        return $vector['body'];
    }
}

<?php

declare(strict_types=1);

namespace Kronixon\Connect\Tests\Support;

use Kronixon\Connect\Api\Response;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * Validates answers against protocol/v1/schemas (the vendored source of truth), as JSON decoded the way
 * the platform decodes it. Counts what it validated, so a suite can report its coverage.
 */
final class ProtocolSchemas
{
    private const PREFIX = 'https://connect.kronixon.com/protocol/v1/schemas/';

    /** @var array<string, int> schema file => answers validated */
    public static $validated = [];

    /** @var Validator|null */
    private static $validator;

    /**
     * The errors of $data against `$defs.response` of `module/<file>`; [] when it is valid.
     *
     * @param  array<string, mixed>|string  $data  an answer array or its JSON
     * @return list<string>
     */
    public static function errors(string $file, $data): array
    {
        $json = is_string($data) ? $data : (string) json_encode($data, Response::JSON_FLAGS);
        $result = self::validator()->validate(json_decode($json, false), self::PREFIX.'module/'.$file.'#/$defs/response');
        self::$validated[$file] = (self::$validated[$file] ?? 0) + 1;
        if ($result->isValid()) {
            return [];
        }
        $errors = [];
        foreach ((new ErrorFormatter())->format($result->error()) as $path => $messages) {
            $errors[] = $path.': '.implode('; ', (array) $messages);
        }

        return $errors;
    }

    private static function validator(): Validator
    {
        if (self::$validator === null) {
            $dir = dirname(__DIR__, 2).'/protocol/v1/schemas';
            self::$validator = new Validator();
            self::$validator->resolver()->registerPrefix(self::PREFIX, realpath($dir) ?: $dir);
            self::$validator->setMaxErrors(10);
        }

        return self::$validator;
    }
}

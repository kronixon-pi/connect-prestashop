<?php

declare(strict_types=1);

// Builds a release zip from HEAD (docs/RELEASING.md):
//
//   php scripts/release/build.php <version> <staging|stable> <public keys: key_id:base64[,…]> <output zip>
//
// The same commit, version, channel and keys give the same bytes on any machine. Prints the sha256.

use Kronixon\Connect\Release\ReleaseBuilder;

require __DIR__.'/ReleaseBuilder.php';

if ($argc !== 5) {
    fwrite(STDERR, "usage: build.php <version> <staging|stable> <key_id:base64[,...]> <output zip>\n");
    exit(2);
}

try {
    $root = dirname(__DIR__, 2);
    $zip = ReleaseBuilder::build(
        ReleaseBuilder::filesFromGit($root),
        ReleaseBuilder::minPhp(ReleaseBuilder::composerFromGit($root)),
        $argv[1],
        $argv[2],
        $argv[3]
    );
    if (file_put_contents($argv[4], $zip) !== strlen($zip)) {
        throw new RuntimeException('The zip could not be written.');
    }
    echo hash('sha256', $zip), '  ', basename($argv[4]), PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'build: '.$e->getMessage().PHP_EOL);
    exit(1);
}

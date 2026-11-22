<?php

declare(strict_types=1);

// Signs a release zip (docs/RELEASING.md):
//
//   php scripts/release/sign.php <zip> <file with the secret key> <public keys: key_id:base64[,…]>
//
// Writes <zip>.sig: base64 of the detached Ed25519 signature over the zip's bytes. The secret comes from a file, never
// from the command line or the output, and must be the half of the first public key (what the build pinned).

use Kronixon\Connect\Release\ReleaseBuilder;

require __DIR__.'/ReleaseBuilder.php';

if ($argc !== 4) {
    fwrite(STDERR, "usage: sign.php <zip> <secret key file> <key_id:base64[,...]>\n");
    exit(2);
}

try {
    $zip = (string) file_get_contents($argv[1]);
    if ($zip === '') {
        throw new RuntimeException('The zip is empty or not there.');
    }
    $signature = ReleaseBuilder::sign($zip, ReleaseBuilder::secretKey((string) file_get_contents($argv[2])), $argv[3]);
    if (file_put_contents($argv[1].'.sig', $signature) !== strlen($signature)) {
        throw new RuntimeException('The signature could not be written.');
    }
    echo 'signed ', hash('sha256', $zip), '  ', basename($argv[1]), PHP_EOL;
} catch (Throwable $e) {
    // The message only: a trace could name an argument of a frame.
    fwrite(STDERR, 'sign: '.$e->getMessage().PHP_EOL);
    exit(1);
}

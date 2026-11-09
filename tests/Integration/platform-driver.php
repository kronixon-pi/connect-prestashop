<?php

declare(strict_types=1);

/*
 * Plays the platform's side against a paired shop (scripts/ps-smoke.sh, inside the shop container):
 * signed calls like the platform's ModuleClient (front controller URL, `path=` first, a nonce per
 * request, Idempotency-Key on a body), every 2xx verified against its response signature, then the
 * refusals, a rotation and an unpair. One line per check; exit 1 on any failure.
 *
 *   php platform-driver.php <shop base URL> <apache address, e.g. 127.0.0.1:80> [state file]
 *
 * Test tooling: PHP 7.4+, no dependencies, never part of the module zip. It prints no secret.
 */

[$self, $base, $connectTo] = $argv + [null, null, null];
$stateFile = $argv[3] ?? (getenv('KX_STUB_STATE') ?: '/tmp/kx-stub/state.json');
if (! is_string($base) || ! is_string($connectTo)) {
    fwrite(STDERR, "usage: platform-driver.php <shop base URL> <host:port> [state file]\n");
    exit(2);
}
$state = json_decode((string) @file_get_contents($stateFile), true);
if (! is_array($state) || ! isset($state['cur'], $state['store_id'])) {
    fwrite(STDERR, "no paired state in $stateFile\n");
    exit(2);
}
$failures = 0;
$parts = parse_url($base);
$hostPort = $parts['host'].':'.($parts['port'] ?? 80);

$uuid = static function (): string {
    $h = bin2hex(random_bytes(16));

    return substr($h, 0, 8).'-'.substr($h, 8, 4).'-4'.substr($h, 13, 3).'-8'.substr($h, 17, 3).'-'.substr($h, 20, 12);
};

/**
 * @param array<string, string> $override header overrides (null-free), after signing
 * @return array{status: int, headers: array<string, string>, body: string, nonce: string}
 */
$call = static function (string $method, string $endpoint, ?string $secret, string $body = '', array $options = []) use ($base, $connectTo, $hostPort, $state, $uuid): array {
    $friendly = ! empty($options['friendly']);
    $url = rtrim($base, '/').($friendly ? '/module/kronixonconnect/api?path=' : '/index.php?fc=module&module=kronixonconnect&controller=api&path=').rawurlencode($endpoint);
    // Endpoint parameters: in the URL, and signed as the canonical query (README §4.2).
    $pairs = [];
    foreach ($options['query'] ?? [] as $name => $value) {
        $url .= '&'.rawurlencode((string) $name).'='.rawurlencode((string) $value);
        $pairs[] = rawurlencode((string) $name).'='.rawurlencode((string) $value);
    }
    sort($pairs, SORT_STRING);
    $path = '/v1/'.$endpoint.($pairs === [] ? '' : '?'.implode('&', $pairs));
    $nonce = $options['nonce'] ?? $uuid();
    $timestamp = $options['timestamp'] ?? time();
    $headers = ['X-KX-Protocol' => 'v1', 'X-KX-Store' => $state['store_id'], 'X-KX-Request-Id' => $nonce, 'Accept' => 'application/json', 'User-Agent' => 'KronixonPlatform/smoke'];
    if ($body !== '') {
        $headers['Content-Type'] = 'application/json; charset=utf-8';
        $headers['Idempotency-Key'] = hash('sha256', $method.' /v1/'.$endpoint.' '.$body);
    }
    if ($secret !== null) {
        $canonical = implode("\n", [$method, $path, (string) $timestamp, $nonce, hash('sha256', $options['signed_body'] ?? $body)]);
        $headers += ['X-KX-Timestamp' => (string) $timestamp, 'X-KX-Nonce' => $nonce, 'X-KX-Key-Id' => $options['key_id'] ?? 'cur', 'X-KX-Signature' => hash_hmac('sha256', $canonical, $secret)];
    }
    $lines = [];
    foreach ($headers as $name => $value) {
        $lines[] = $name.': '.$value;
    }
    $received = [];
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $lines,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECT_TO => [$hostPort.':'.$connectTo],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$received): int {
            $colon = strpos($line, ':');
            if ($colon !== false) {
                $received[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
            }

            return strlen($line);
        },
    ]);
    if ($method !== 'GET') {
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    }
    $answer = (string) curl_exec($curl);

    return ['status' => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'headers' => $received, 'body' => $answer, 'nonce' => $nonce];
};
$signedBy = static function (array $r, string $secret): bool {
    $sig = $r['headers']['x-kx-signature'] ?? null;
    $ts = $r['headers']['x-kx-timestamp'] ?? null;

    return $sig !== null && $ts !== null
        && hash_equals(hash_hmac('sha256', implode("\n", ['RESPONSE', $r['nonce'], $ts, (string) $r['status'], hash('sha256', $r['body'])]), $secret), $sig);
};
$check = static function (string $name, bool $ok, string $detail = '') use (&$failures): void {
    echo ($ok ? '  ok   ' : '  FAIL ').$name.($ok || $detail === '' ? '' : ' — '.substr($detail, 0, 300))."\n";
    $failures += $ok ? 0 : 1;
};
$code = static function (array $r): string {
    $data = json_decode($r['body'], true);

    return is_array($data) && isset($data['error']['code']) ? (string) $data['error']['code'] : '-';
};
/** @param list<string> $members */
$has = static function (array $data, array $members): bool {
    foreach ($members as $member) {
        $node = $data;
        foreach (explode('/', $member) as $key) {
            if (! is_array($node) || ! array_key_exists($key, $node)) {
                return false;
            }
            $node = $node[$key];
        }
    }

    return true;
};

$cur = $state['cur'];
$check('the stub received the signed ping after pairing', $state['pings'] >= 1);

// The reverse check of the platform (VerifyStore): signed GET capabilities, then GET profile.
$caps = $call('GET', 'capabilities', $cur);
$data = json_decode($caps['body'], true);
$check('GET capabilities → 200, signed with cur', $caps['status'] === 200 && $signedBy($caps, $cur), $caps['status'].' '.$caps['body']);
$check('capabilities carry what the platform parses', is_array($data) && $has($data, ['protocol', 'module_version', 'capabilities/products.write', 'capabilities/blog/enabled', 'capabilities/image_formats', 'limits/batch', 'limits/max_body_bytes']), $caps['body']);
$check('capabilities_hash of the pair request matches the answer', ($state['pair_request']['capabilities_hash'] ?? '') === hash('sha256', $caps['body']));
$profile = $call('GET', 'profile', $cur);
$data = json_decode($profile['body'], true);
$check('GET profile → 200, signed', $profile['status'] === 200 && $signedBy($profile, $cur), $profile['status'].' '.substr($profile['body'], 0, 300));
$check('profile carries what the platform parses', is_array($data) && $has($data, ['platform', 'version', 'php', 'module_version', 'instance_id', 'timezone', 'server_time', 'shop/id', 'shop/name', 'shop/url', 'shop/multistore', 'languages', 'currencies', 'tax/enabled', 'tax/display', 'stock/locations', 'counts', 'friendly_urls', 'maintenance_mode', 'urls/api']) && is_int($data['shop']['id']) && $data['languages'] !== [], $profile['body']);
$health = $call('GET', 'health', $cur, '', ['friendly' => true]);
$data = json_decode($health['body'], true);
$check('GET health (friendly URL) → 200, paired, db ok', $health['status'] === 200 && $signedBy($health, $cur) && ($data['paired'] ?? null) === true && ($data['db_ok'] ?? null) === true && ($data['store_id'] ?? null) === $state['store_id'], $health['body']);
$check('a signed answer carries the module version', isset($caps['headers']['x-kx-module-version'], $caps['headers']['x-kx-time-used']));

// Refusals (README §4.4).
$replay = $call('GET', 'capabilities', $cur, '', ['nonce' => $caps['nonce']]);
$check('a replayed nonce → 401 nonce_reused (signed)', $replay['status'] === 401 && $code($replay) === 'nonce_reused' && $signedBy($replay, $cur), $replay['status'].' '.$replay['body']);
$tampered = $call('POST', 'unpair', $cur, '{"reason":"user"}', ['signed_body' => '{"reason":"other"}']);
$check('a tampered body → 401 signature_invalid, unsigned, no version', $tampered['status'] === 401 && $code($tampered) === 'signature_invalid' && ! isset($tampered['headers']['x-kx-signature']) && ! isset($tampered['headers']['x-kx-module-version']), $tampered['status'].' '.$tampered['body']);
$stale = $call('GET', 'health', $cur, '', ['timestamp' => time() - 301]);
$staleData = json_decode($stale['body'], true);
$check('a timestamp 301 s old → 401 timestamp_out_of_window with server_time', $stale['status'] === 401 && $code($stale) === 'timestamp_out_of_window' && is_int($staleData['error']['server_time'] ?? null), $stale['body']);
$unsigned = $call('GET', 'health', null);
$check('an unsigned request → 401, unsigned', $unsigned['status'] === 401 && ! isset($unsigned['headers']['x-kx-module-version']), $unsigned['status'].' '.$unsigned['body']);
$unknown = $call('GET', 'nope', $cur);
$check('an unknown endpoint after the signature → 404 not_found (signed)', $unknown['status'] === 404 && $signedBy($unknown, $cur), $unknown['status'].' '.$unknown['body']);
$check('the shop is still paired after the refusals', $call('GET', 'health', $cur)['status'] === 200);

// Catalog reads: the first page of every GET catalog/* signed, a walk by next_cursor through the friendly
// URL, an id range from a cursor the platform builds itself, a tampered cursor. Every answer is written to
// $answers as <schema>--<name>.json for validate-answers.php.
$answers = getenv('KX_ANSWERS') ?: '/tmp/kx-answers';
if (! is_dir($answers)) {
    mkdir($answers, 0777, true);
}
$cursorFor = static function (int $after): string {
    return rtrim(strtr(base64_encode('{"id":'.$after.'}'), '+/', '-_'), '=');
};
foreach (['products', 'variants', 'images', 'prices', 'stock'] as $kind) {
    $page = $call('GET', 'catalog/'.$kind, $cur, '', ['query' => ['limit' => '20']]);
    $data = json_decode($page['body'], true);
    file_put_contents("$answers/catalog-$kind.json--signed.json", $page['body']);
    $check("GET catalog/$kind → 200 signed, data and meta.max_id", $page['status'] === 200 && $signedBy($page, $cur) && is_array($data['data'] ?? null) && isset($data['meta']['max_id']), $page['status'].' '.substr($page['body'], 0, 300));
}
$ids = [];
$query = ['limit' => '3', 'fields' => 'basic'];
for ($i = 1; $i <= 3; $i++) {
    $page = $call('GET', 'catalog/products', $cur, '', ['query' => $query, 'friendly' => true]);
    $data = json_decode($page['body'], true);
    file_put_contents("$answers/catalog-products.json--walk-$i.json", $page['body']);
    $ids = array_merge($ids, array_column($data['data'] ?? [], 'external_id'));
    $query['cursor'] = (string) ($data['meta']['next_cursor'] ?? '');
}
$ints = array_map('intval', $ids);
$sorted = array_values(array_unique($ints));
sort($sorted);
$check('three pages by next_cursor: 9 products in id order, none twice', count($ids) === 9 && $ints === $sorted, implode(',', $ids));
$range = $call('GET', 'catalog/products', $cur, '', ['query' => ['cursor' => $cursorFor((int) $ids[1]), 'until_id' => $ids[4], 'fields' => 'basic']]);
$data = json_decode($range['body'], true);
file_put_contents("$answers/catalog-products.json--range.json", $range['body']);
$check('a range from a built cursor: exactly the ids in (after, until_id]', $range['status'] === 200 && array_column($data['data'] ?? [], 'external_id') === array_slice($ids, 2, 3) && ($data['meta']['has_more'] ?? null) === false, $range['body']);
$bad = $call('GET', 'catalog/products', $cur, '', ['query' => ['cursor' => $cursorFor(1).'x']]);
$check('a tampered cursor → 400 bad_request (signed)', $bad['status'] === 400 && $code($bad) === 'bad_request' && $signedBy($bad, $cur), $bad['status'].' '.$bad['body']);

// Rotation (README §4.5): sealed to the module's key, prev valid 24 h.
$alphabet = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
$next = 'kxs_';
for ($i = 0; $i < 32; $i++) {
    $next .= $alphabet[random_int(0, 61)];
}
$rotateBody = json_encode(['secret_sealed' => base64_encode(sodium_crypto_box_seal($next, (string) base64_decode($state['module_public_key'], true))), 'prev_valid_until' => time() + 86400]);
$rotate = $call('POST', 'rotate', $cur, (string) $rotateBody);
$check('POST rotate → 200 signed with the old secret', $rotate['status'] === 200 && $signedBy($rotate, $cur), $rotate['status'].' '.$rotate['body']);
$withNew = $call('GET', 'health', $next);
$check('the new secret verifies as cur', $withNew['status'] === 200 && $signedBy($withNew, $next), $withNew['status'].' '.$withNew['body']);
$withPrev = $call('GET', 'health', $cur, '', ['key_id' => 'prev']);
$check('the old secret verifies as prev', $withPrev['status'] === 200 && ($withPrev['headers']['x-kx-key-id'] ?? '') === 'prev' && $signedBy($withPrev, $cur), $withPrev['status'].' '.$withPrev['body']);

// Unpair from the platform: every later call refused, unsigned.
$unpair = $call('POST', 'unpair', $next, '{"reason":"user"}');
$check('POST unpair → 200 signed', $unpair['status'] === 200 && $signedBy($unpair, $next), $unpair['status'].' '.$unpair['body']);
$after = $call('GET', 'capabilities', $next);
$check('after unpair: 403 store_unpaired, unsigned, no version', $after['status'] === 403 && $code($after) === 'store_unpaired' && ! isset($after['headers']['x-kx-signature']) && ! isset($after['headers']['x-kx-module-version']), $after['status'].' '.$after['body']);
$check('no answer set a cookie', ! isset($caps['headers']['set-cookie'], $after['headers']['set-cookie']));

echo "DRIVER failures=$failures\n";
exit($failures === 0 ? 0 : 1);

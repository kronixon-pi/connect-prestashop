<?php

declare(strict_types=1);

/*
 * A minimal Connect service for the shop smoke test (`php -S 127.0.0.1:8099 platform-stub.php`
 * inside the shop container). It follows the merged platform flow: POST /v1/pair (PairStore: the code,
 * `kxs_` + 32 base62 from random_int, sealed with crypto_box_seal to the module's key, the 201 body in
 * the platform's member order and JSON encoding) and the signed store routes ping / unpair /
 * rotate-request (VerifyConnectSignature: protocol, store, format, ±300 s, HMAC with cur then prev,
 * nonce, then the route; the answer signed with cur). State lives in a JSON file that
 * platform-driver.php reads, so the driver can then play the platform's calls to the module.
 * Test tooling: PHP 7.4+, no dependencies, never part of the module zip.
 */

$stateFile = getenv('KX_STUB_STATE') ?: '/tmp/kx-stub/state.json';
$code = getenv('KX_STUB_CODE') ?: 'K7PM-3XQ9';
$now = time();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$body = (string) file_get_contents('php://input');
$requestId = bin2hex(random_bytes(8));

/** @param array<string, mixed> $data @param array<string, string> $headers */
$answer = static function (int $status, array $data, array $headers = []) use ($requestId): void {
    http_response_code($status);
    header('Content-Type: application/json');
    header('X-KX-Protocol: v1');
    header('X-KX-Request-Id: '.$requestId);
    header('Cache-Control: no-store');
    foreach ($headers as $name => $value) {
        header($name.': '.$value);
    }
    echo json_encode($data);
};
/** @param array<string, mixed> $details */
$error = static function (int $status, string $errorCode, array $details = []) use ($answer, $now, $requestId): void {
    $error = ['code' => $errorCode, 'message' => $errorCode, 'request_id' => $requestId, 'server_time' => $now];
    if ($details !== []) {
        $error['details'] = $details;
    }
    $answer($status, ['error' => $error]);
};
$load = static function () use ($stateFile): array {
    $data = is_file($stateFile) ? json_decode((string) file_get_contents($stateFile), true) : null;

    return is_array($data) ? $data : [];
};
$save = static function (array $state) use ($stateFile): void {
    if (! is_dir(dirname($stateFile))) {
        mkdir(dirname($stateFile), 0700, true);
    }
    file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
};

if (($_SERVER['HTTP_X_KX_PROTOCOL'] ?? '') !== 'v1') {
    $error(400, 'protocol_version_unsupported');

    return true;
}
if ($method === 'GET' && $path === '/v1/status') {
    $answer(200, ['protocol' => 'v1', 'server_time' => $now]);

    return true;
}

if ($method === 'POST' && $path === '/v1/pair') {
    $input = json_decode($body, true);
    $typed = is_array($input) && is_string($input['code'] ?? null) ? strtoupper((string) preg_replace('/[\s-]+/', '', $input['code'])) : '';
    if ($typed !== str_replace('-', '', $code)) {
        $error(400, 'pair_code_invalid');

        return true;
    }
    $key = base64_decode((string) ($input['public_key'] ?? ''), true);
    $missing = array_diff(['store_url', 'platform', 'version', 'php', 'module_version', 'protocol', 'public_key', 'shop_id', 'capabilities_hash'], array_keys($input));
    if ($key === false || strlen($key) !== 32 || $missing !== [] || ! is_int($input['shop_id'])) {
        $error(422, 'validation_failed', ['errors' => array_values($missing)]);

        return true;
    }
    $alphabet = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    $secret = 'kxs_';
    for ($i = 0; $i < 32; $i++) {
        $secret .= $alphabet[random_int(0, 61)];
    }
    $hex = bin2hex(random_bytes(16));
    $storeId = substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-7'.substr($hex, 13, 3).'-a'.substr($hex, 17, 3).'-'.substr($hex, 20, 12);
    $save(['store_id' => $storeId, 'cur' => $secret, 'prev' => null, 'prev_until' => null, 'module_public_key' => $input['public_key'],
        'pair_request' => $input, 'unpaired' => null, 'pings' => 0, 'nonces' => [], ]);
    $apiBase = 'https://connect.example.test/v1';
    $answer(201, [
        'store_id' => $storeId,
        'shared_secret_sealed' => base64_encode(sodium_crypto_box_seal($secret, $key)),
        'platform_public_key' => base64_encode(str_repeat("\x01", 32)),
        'api_base' => $apiBase,
        'events_url' => $apiBase.'/stores/'.$storeId.'/events',
        'server_time' => $now,
        'tenant_name' => 'Smoke Test Workspace',
        'heartbeat_interval' => 86400,
    ]);

    return true;
}

if (preg_match('#\A/v1/stores/([0-9a-f-]{36})/(ping|unpair|rotate-request)\z#', $path, $m) !== 1) {
    $error(404, 'not_found');

    return true;
}
$state = $load();
$store = $_SERVER['HTTP_X_KX_STORE'] ?? '';
if ($state === [] || $store !== ($state['store_id'] ?? null) || $state['unpaired'] !== null) {
    $error(403, 'store_unpaired');

    return true;
}
$timestamp = $_SERVER['HTTP_X_KX_TIMESTAMP'] ?? '';
$nonce = $_SERVER['HTTP_X_KX_NONCE'] ?? '';
$signature = strtolower($_SERVER['HTTP_X_KX_SIGNATURE'] ?? '');
if (preg_match('/\A[0-9]{1,12}\z/', $timestamp) !== 1 || preg_match('/\A[0-9a-fA-F-]{36}\z/', $nonce) !== 1 || preg_match('/\A[0-9a-f]{64}\z/', $signature) !== 1) {
    $error(401, 'signature_invalid');

    return true;
}
if (abs($now - (int) $timestamp) > 300) {
    $error(401, 'timestamp_out_of_window');

    return true;
}
$canonical = implode("\n", [$method, $path, $timestamp, $nonce, hash('sha256', $body)]);
$keys = [$state['cur']];
if ($state['prev'] !== null && $now <= (int) $state['prev_until']) {
    $keys[] = $state['prev'];
}
$matched = false;
foreach ($keys as $key) {
    $matched = $matched || hash_equals(hash_hmac('sha256', $canonical, $key), $signature);
}
if (! $matched) {
    $error(401, 'signature_invalid');

    return true;
}
if (in_array($nonce, $state['nonces'], true)) {
    $error(401, 'nonce_reused');

    return true;
}
$state['nonces'][] = $nonce;
$input = json_decode($body, true);
if ($m[2] === 'ping') {
    $state['pings']++;
    $data = ['ok' => true, 'server_time' => $now];
} elseif ($m[2] === 'unpair') {
    $state['unpaired'] = is_array($input) ? ($input['reason'] ?? 'missing') : 'missing';
    $data = ['ok' => true];
} else {
    $data = ['ok' => true, 'rotation_started' => false];
}
$save($state);
$json = json_encode($data);
$answer(200, $data, [
    'X-KX-Timestamp' => (string) $now,
    'X-KX-Signature' => hash_hmac('sha256', implode("\n", ['RESPONSE', $nonce, (string) $now, '200', hash('sha256', (string) $json)]), $state['cur']),
    'X-KX-Key-Id' => 'cur',
]);

return true;

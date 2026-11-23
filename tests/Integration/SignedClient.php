<?php

declare(strict_types=1);

/**
 * Signed calls to a paired shop as the platform's ModuleClient makes them (catalog-writes.php,
 * product-writes.php): a nonce per request, an Idempotency-Key on every write, the response signature
 * checked, every 2xx kept for validate-answers.php. Test tooling, never part of the module zip.
 */
final class SignedClient
{
    /** @var string */
    private $base;

    /** @var string */
    private $connectTo;

    /** @var array<string, mixed> */
    private $state;

    /** @var int */
    private $kept = 0;

    /** @var int */
    private $failures = 0;

    /** @var bool */
    private $losing = false;

    public function __construct(string $base, string $connectTo, string $stateFile = '/tmp/kx-stub/state.json')
    {
        $state = json_decode((string) @file_get_contents($stateFile), true);
        if (! is_array($state) || ! isset($state['cur'], $state['store_id'])) {
            throw new RuntimeException("no paired state in $stateFile");
        }
        $this->base = rtrim($base, '/');
        $this->connectTo = $connectTo;
        $this->state = $state;
    }

    public static function uuid(): string
    {
        $h = bin2hex(random_bytes(16));

        return substr($h, 0, 8).'-'.substr($h, 8, 4).'-4'.substr($h, 13, 3).'-8'.substr($h, 17, 3).'-'.substr($h, 20, 12);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{status: int, replay: bool, data: array<string, mixed>, raw: string}
     */
    public function post(string $endpoint, array $body, ?string $key = null): array
    {
        return $this->call('POST', $endpoint, [], (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $key ?? self::uuid());
    }

    /**
     * A write whose answer never comes (the request dies in the shop).
     *
     * @param array<string, mixed> $body
     */
    public function lose(string $endpoint, array $body, string $key): void
    {
        $this->losing = true;
        $this->post($endpoint, $body, $key);
        $this->losing = false;
    }

    /**
     * @param array<string, string> $query
     * @return array{status: int, replay: bool, data: array<string, mixed>, raw: string}
     */
    public function get(string $endpoint, array $query = []): array
    {
        return $this->call('GET', $endpoint, $query, '', null);
    }

    /**
     * @param array<string, string> $query
     * @return array{status: int, replay: bool, data: array<string, mixed>, raw: string}
     */
    public function call(string $method, string $endpoint, array $query, string $body, ?string $key): array
    {
        $url = $this->base.'/index.php?fc=module&module=kronixonconnect&controller=api&path='.rawurlencode($endpoint);
        $pairs = [];
        foreach ($query as $name => $value) {
            $pairs[] = rawurlencode($name).'='.rawurlencode($value);
        }
        sort($pairs, SORT_STRING);
        $url .= $pairs === [] ? '' : '&'.implode('&', $pairs);
        $nonce = self::uuid();
        $time = (string) time();
        $canonical = implode("\n", [$method, '/v1/'.$endpoint.($pairs === [] ? '' : '?'.implode('&', $pairs)), $time, $nonce, hash('sha256', $body)]);
        $headers = ['X-KX-Protocol: v1', 'X-KX-Store: '.$this->state['store_id'], 'X-KX-Timestamp: '.$time, 'X-KX-Nonce: '.$nonce, 'X-KX-Key-Id: cur',
            'X-KX-Signature: '.hash_hmac('sha256', $canonical, $this->state['cur']), 'Content-Type: application/json; charset=utf-8', ];
        if ($key !== null) {
            $headers[] = 'Idempotency-Key: '.$key;
        }
        $received = [];
        $curl = curl_init($url);
        $parts = parse_url($this->base);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECT_TO => [$parts['host'].':'.($parts['port'] ?? 80).':'.$this->connectTo],
            CURLOPT_TIMEOUT => 60,
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
        $raw = (string) curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $signature = hash_hmac('sha256', implode("\n", ['RESPONSE', $nonce, $received['x-kx-timestamp'] ?? '', (string) $status, hash('sha256', $raw)]), $this->state['cur']);
        if ($this->losing) {
            return ['status' => $status, 'replay' => false, 'data' => [], 'raw' => $raw];
        }
        if (! hash_equals($signature, $received['x-kx-signature'] ?? '')) {
            $this->check("$method $endpoint is signed", false, "$status $raw");
        }
        $data = json_decode($raw, true);
        if ($status === 200) {
            $dir = getenv('KX_ANSWERS') ?: '/tmp/kx-answers';
            if (! is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            file_put_contents($dir.'/'.str_replace('/', '-', $endpoint).'.json--write-'.(++$this->kept).'.json', $raw);
        }

        return ['status' => $status, 'replay' => ($received['x-kx-idempotent-replay'] ?? '') === 'true', 'data' => is_array($data) ? $data : [], 'raw' => $raw];
    }

    public function check(string $name, bool $ok, string $detail = ''): void
    {
        echo ($ok ? '  ok   ' : '  FAIL ').$name.($ok || $detail === '' ? '' : ' — '.substr($detail, 0, 700))."\n";
        $this->failures += $ok ? 0 : 1;
    }

    public function failures(): int
    {
        return $this->failures;
    }
}

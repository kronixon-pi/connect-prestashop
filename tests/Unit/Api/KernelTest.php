<?php

declare(strict_types=1);

namespace Kronixon\Connect\Tests\Unit\Api;

use Kronixon\Connect\Api\Kernel;
use Kronixon\Connect\Api\Response;
use Kronixon\Connect\Api\Router;
use Kronixon\Connect\Auth\KeyRing;
use Kronixon\Connect\Auth\PairingState;
use Kronixon\Connect\Auth\UnpairedState;
use Kronixon\Connect\Tests\Support\FixedClock;
use LogicException;
use PHPUnit\Framework\TestCase;

final class KernelTest extends TestCase
{
    private const NOW = 1790772000.25;

    public function test_every_v1_path_answers_store_unpaired_while_not_paired(): void
    {
        foreach (['/v1/status', 'capabilities', 'catalog/products', 'unknown/endpoint'] as $path) {
            $response = $this->kernel()->handle($this->server('GET', 'path='.rawurlencode($path)), '');

            self::assertSame(403, $response->status(), $path);
            $error = $this->error($response);
            self::assertSame('store_unpaired', $error['code']);
            self::assertSame(1790772000, $error['server_time']);
            self::assertNull($response->header('X-KX-Signature'), 'an unpaired module has no secret to sign with');
        }
    }

    public function test_decoration_headers(): void
    {
        $response = $this->kernel()->handle($this->server('GET', 'path=status') + ['HTTP_X_KX_REQUEST_ID' => 'req-123'], '');

        self::assertSame('v1', $response->header('X-KX-Protocol'));
        self::assertSame('req-123', $response->header('X-KX-Request-Id'));
        self::assertSame('req-123', $this->error($response)['request_id']);
    }

    public function test_an_unsigned_answer_does_not_reveal_the_module_version_or_timing(): void
    {
        $response = $this->kernel()->handle($this->server('GET', 'path=/v1/status') + ['HTTP_X_KX_REQUEST_ID' => 'req-1'], '');
        [$headers] = $response->wire('', false);

        self::assertSame(403, $response->status());
        self::assertSame('store_unpaired', $this->error($response)['code']);
        self::assertNull($response->header('X-KX-Module-Version'));
        self::assertNull($response->header('X-KX-Time-Used'));
        $names = array_keys($headers);
        sort($names);
        self::assertSame(['Cache-Control', 'Content-Length', 'Content-Type', 'X-Content-Type-Options', 'X-KX-Protocol', 'X-KX-Request-Id', 'X-Robots-Tag'], $names);
        self::assertSame('no-store', $headers['Cache-Control']);
    }

    public function test_the_fatal_answer_is_unsigned_too(): void
    {
        $fatal = $this->kernel()->fatalResponse();

        self::assertNull($fatal->header('X-KX-Module-Version'));
        self::assertNull($fatal->header('X-KX-Time-Used'));
        self::assertSame('v1', $fatal->header('X-KX-Protocol'));
    }

    public function test_a_malformed_request_id_is_replaced_by_a_uuid(): void
    {
        $response = $this->kernel()->handle($this->server('GET', 'path=status') + ['HTTP_X_KX_REQUEST_ID' => "bad id\n"], '');

        self::assertMatchesRegularExpression('/\A[0-9a-f-]{36}\z/', (string) $response->header('X-KX-Request-Id'));
    }

    public function test_the_protocol_header_is_required(): void
    {
        $server = $this->server('GET', 'path=status');
        unset($server['HTTP_X_KX_PROTOCOL']);
        $missing = $this->kernel()->handle($server, '');
        $wrong = $this->kernel()->handle(['HTTP_X_KX_PROTOCOL' => 'v2'] + $server, '');

        foreach ([$missing, $wrong] as $response) {
            self::assertSame(400, $response->status());
            self::assertSame('protocol_version_unsupported', $this->error($response)['code']);
            self::assertSame(['supported' => ['v1']], $this->error($response)['details']);
        }
    }

    public function test_parsing_errors_come_before_the_pairing_gate(): void
    {
        self::assertSame('bad_request', $this->error($this->kernel()->handle($this->server('GET', ''), ''))['code']);
        self::assertSame('method_not_allowed', $this->error($this->kernel()->handle($this->server('PUT', 'path=status'), ''))['code']);
        self::assertSame('payload_too_large', $this->error($this->kernel()->handle($this->server('POST', 'path=products'), str_repeat('x', 4194305)))['code']);
    }

    public function test_a_paired_kernel_still_dispatches_nothing_without_a_verified_signature(): void
    {
        $router = (new Router())->add('GET', '/v1/status', static function (): Response {
            return Response::json(200, ['reached' => true]);
        });
        $paired = new class () implements PairingState {
            public function isPaired(): bool
            {
                return true;
            }

            public function storeId(): ?string
            {
                return '0192b2e4-2f7a-7c5b-8a1d-3e4f5a6b7c8d';
            }

            public function keys(): ?KeyRing
            {
                return new KeyRing('kxs_test');
            }
        };
        $response = (new Kernel($router, $paired, new FixedClock(self::NOW)))->handle($this->server('GET', 'path=status'), '');

        self::assertSame(401, $response->status());
        self::assertSame('signature_invalid', $this->error($response)['code']);
    }

    public function test_stray_output_and_php_warnings_never_reach_the_body(): void
    {
        $pairing = new class () implements PairingState {
            public function isPaired(): bool
            {
                echo '<b>Notice</b> from a hook of another module';
                trigger_error('a warning inside the request', E_USER_WARNING);

                return false;
            }

            public function storeId(): ?string
            {
                return null;
            }

            public function keys(): ?KeyRing
            {
                return null;
            }
        };
        $level = ob_get_level();
        $kernel = new Kernel(new Router(), $pairing, new FixedClock(self::NOW));
        $response = $kernel->handle($this->server('GET', 'path=status'), '');

        self::assertSame($level, ob_get_level());
        self::assertSame('store_unpaired', $this->error($response)['code']);
        self::assertStringNotContainsString('Notice', $response->body());
        self::assertSame(1, $kernel->suppressedErrors());
    }

    public function test_an_exception_becomes_module_error_without_its_message_or_trace(): void
    {
        $pairing = new class () implements PairingState {
            public function isPaired(): bool
            {
                throw new LogicException('secret kxs_leak in /var/www/html/modules/x.php');
            }

            public function storeId(): ?string
            {
                return null;
            }

            public function keys(): ?KeyRing
            {
                return null;
            }
        };
        $response = (new Kernel(new Router(), $pairing, new FixedClock(self::NOW)))->handle($this->server('GET', 'path=status'), '');

        self::assertSame(500, $response->status());
        self::assertSame('module_error', $this->error($response)['code']);
        self::assertStringNotContainsString('kxs_leak', $response->body());
        self::assertStringNotContainsString('/var/www', $response->body());
        self::assertStringNotContainsString('LogicException', $response->body());
    }

    public function test_the_fatal_response_is_the_json_envelope(): void
    {
        $kernel = $this->kernel();
        $kernel->handle($this->server('GET', 'path=status') + ['HTTP_X_KX_REQUEST_ID' => 'req-9'], '');
        $fatal = $kernel->fatalResponse();

        self::assertSame(500, $fatal->status());
        self::assertSame('module_error', $this->error($fatal)['code']);
        self::assertSame('req-9', $this->error($fatal)['request_id']);
    }

    private function kernel(): Kernel
    {
        return new Kernel(new Router(), new UnpairedState(), new FixedClock(self::NOW));
    }

    /**
     * @return array<string, string>
     */
    private function server(string $method, string $query): array
    {
        return ['REQUEST_METHOD' => $method, 'QUERY_STRING' => $query, 'HTTP_X_KX_PROTOCOL' => 'v1'];
    }

    /**
     * @return array<string, mixed>
     */
    private function error(Response $response): array
    {
        $data = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertSame(['error'], array_keys($data));

        return $data['error'];
    }
}

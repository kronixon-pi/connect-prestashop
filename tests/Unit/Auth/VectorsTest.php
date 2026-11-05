<?php

declare(strict_types=1);

namespace Kronixon\Connect\Tests\Unit\Auth;

use Kronixon\Connect\Api\ApiError;
use Kronixon\Connect\Api\Request;
use Kronixon\Connect\Auth\Canonical;
use Kronixon\Connect\Auth\KeyRing;
use Kronixon\Connect\Auth\Signer;
use Kronixon\Connect\Auth\Verifier;
use Kronixon\Connect\Tests\Support\Vectors;
use PHPUnit\Framework\TestCase;

/**
 * The 8 vectors of protocol/v1/vectors.json, end to end: the wire request ($_SERVER + body) is
 * parsed like the front controller does, canonicalised, signed and verified.
 */
final class VectorsTest extends TestCase
{
    public function test_the_file_holds_the_eight_vectors_of_the_plan(): void
    {
        $ids = array_map(static function (array $v): string {
            return $v['id'];
        }, Vectors::document()['vectors']);

        self::assertSame([
            'spec-4.2-post-stock-bulk',
            'spec-4.2-get-query',
            'query-sorting-encoding',
            'empty-body-no-query',
            'gzip-body',
            'method-override-delete',
            'prev-key-after-rotation',
            'signed-response',
        ], $ids);
    }

    public function test_the_spec_vectors_keep_the_spec_values(): void
    {
        $vectors = Vectors::byKind('request');

        self::assertSame('46330005e9cf0a630b73b288ad7b8c7aaa2a124d10cb9d704f967854eae2f262', $vectors['spec-4.2-post-stock-bulk']['expected']['signature']);
        self::assertSame('142cbfb1fbcb6e319ac89ebbb22bcd6d651ff0cba117e97f8c13338afa1f8738', $vectors['spec-4.2-post-stock-bulk']['expected']['body_sha256']);
        self::assertSame('596eaa3c8056b7d8198d997f36e39afe33765eb89ec35ee573ee3efcab7c3c49', $vectors['spec-4.2-get-query']['expected']['signature']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public function requestVectors(): iterable
    {
        foreach (Vectors::byKind('request') as $id => $vector) {
            yield $id => [$vector];
        }
    }

    /**
     * @dataProvider requestVectors
     *
     * @param  array<string, mixed>  $v
     */
    public function test_the_wire_request_parses_to_the_vectors_logical_request(array $v): void
    {
        $request = Request::fromServer(Vectors::server($v), Vectors::wireBody($v));

        self::assertSame($v['method'], $request->method());
        self::assertSame($v['transport']['method'], $request->transportMethod());
        self::assertSame($v['path'], $request->path());
        self::assertSame($v['body'], $request->body(), 'the body is compared after gzip decoding');
        self::assertSame($v['expected']['body_sha256'], hash('sha256', $request->body()));
    }

    /**
     * @dataProvider requestVectors
     *
     * @param  array<string, mixed>  $v
     */
    public function test_canonical_string_and_signature_match(array $v): void
    {
        $secret = Vectors::document()['secrets'][$v['key_id']];

        self::assertSame($v['expected']['canonical_query'], Canonical::query($v['transport']['query']));
        self::assertSame($v['expected']['canonical'], Canonical::request($v['method'], $v['path'], $v['transport']['query'], $v['timestamp'], $v['nonce'], $v['body']));
        self::assertSame($v['expected']['signature'], (new Signer())->signRequest($secret, $v['method'], $v['path'], $v['transport']['query'], $v['timestamp'], $v['nonce'], $v['body']));
    }

    /**
     * @dataProvider requestVectors
     *
     * @param  array<string, mixed>  $v
     */
    public function test_the_verifier_accepts_the_vector_with_its_key(array $v): void
    {
        $request = Request::fromServer(Vectors::server($v), Vectors::wireBody($v));

        $keyId = $this->verify(Vectors::keyRing(), $request, $v['expected']['signature'], $v['key_id'], $v['timestamp']);

        self::assertSame($v['key_id'], $keyId);
    }

    /**
     * @dataProvider requestVectors
     *
     * @param  array<string, mixed>  $v
     */
    public function test_without_a_key_id_the_verifier_tries_cur_then_prev(array $v): void
    {
        $request = Request::fromServer(Vectors::server($v), Vectors::wireBody($v));

        self::assertSame($v['key_id'], $this->verify(Vectors::keyRing(), $request, $v['expected']['signature'], null, $v['timestamp']));
    }

    /**
     * @dataProvider requestVectors
     *
     * @param  array<string, mixed>  $v
     */
    public function test_a_tampered_body_fails(array $v): void
    {
        $server = Vectors::server($v);
        unset($server['HTTP_CONTENT_ENCODING']);
        $request = Request::fromServer($server, $v['body'].' ');

        $this->assertRejected('signature_invalid', function () use ($request, $v): void {
            $this->verify(Vectors::keyRing(), $request, $v['expected']['signature'], $v['key_id'], $v['timestamp']);
        });
    }

    /**
     * @dataProvider requestVectors
     *
     * @param  array<string, mixed>  $v
     */
    public function test_a_tampered_path_fails(array $v): void
    {
        $this->assertRejected('signature_invalid', function () use ($v): void {
            (new Verifier())->verifyRequest(Vectors::keyRing(), $v['method'], $v['path'].'x', $v['transport']['query'], (string) $v['timestamp'], $v['nonce'], $v['body'], $v['expected']['signature'], $v['key_id'], $v['timestamp']);
        });
        $this->assertRejected('signature_invalid', function () use ($v): void {
            (new Verifier())->verifyRequest(Vectors::keyRing(), $v['method'], '/v2'.substr($v['path'], 3), $v['transport']['query'], (string) $v['timestamp'], $v['nonce'], $v['body'], $v['expected']['signature'], $v['key_id'], $v['timestamp']);
        });
    }

    /**
     * @dataProvider requestVectors
     *
     * @param  array<string, mixed>  $v
     */
    public function test_a_tampered_timestamp_or_nonce_fails(array $v): void
    {
        $this->assertRejected('signature_invalid', function () use ($v): void {
            (new Verifier())->verifyRequest(Vectors::keyRing(), $v['method'], $v['path'], $v['transport']['query'], (string) ($v['timestamp'] + 1), $v['nonce'], $v['body'], $v['expected']['signature'], $v['key_id'], $v['timestamp']);
        });
        $nonce = substr($v['nonce'], 0, -1).($v['nonce'][35] === '0' ? '1' : '0');
        $this->assertRejected('signature_invalid', function () use ($v, $nonce): void {
            (new Verifier())->verifyRequest(Vectors::keyRing(), $v['method'], $v['path'], $v['transport']['query'], (string) $v['timestamp'], $nonce, $v['body'], $v['expected']['signature'], $v['key_id'], $v['timestamp']);
        });
    }

    public function test_a_signature_over_the_wire_order_query_fails(): void
    {
        $v = Vectors::byKind('request')['query-sorting-encoding'];
        $secret = Vectors::document()['secrets']['cur'];
        // A client that skips canonicalisation (wire order, no transport filtering, no re-encoding) signs another string.
        $naive = implode("\n", ['GET', $v['path'].'?'.$v['transport']['query'], (string) $v['timestamp'], $v['nonce'], hash('sha256', '')]);
        $signature = Signer::hmac($secret, $naive);

        self::assertNotSame($v['expected']['signature'], $signature);
        $this->assertRejected('signature_invalid', function () use ($v, $signature): void {
            (new Verifier())->verifyRequest(Vectors::keyRing(), 'GET', $v['path'], $v['transport']['query'], (string) $v['timestamp'], $v['nonce'], '', $signature, 'cur', $v['timestamp']);
        });
    }

    public function test_reordering_the_wire_query_keeps_the_signature_but_changing_a_value_breaks_it(): void
    {
        $v = Vectors::byKind('request')['query-sorting-encoding'];
        $parts = explode('&', $v['transport']['query']);
        $reordered = implode('&', array_reverse($parts));
        $verifier = new Verifier();

        self::assertSame('cur', $verifier->verifyRequest(Vectors::keyRing(), 'GET', $v['path'], $reordered, (string) $v['timestamp'], $v['nonce'], '', $v['expected']['signature'], 'cur', $v['timestamp']));

        $swappedValues = str_replace(['tag=ssd', 'tag=nvme'], ['tag=TMP', 'tag=ssd'], $v['transport']['query']);
        $swappedValues = str_replace('tag=TMP', 'tag=nvmx', $swappedValues);
        $this->assertRejected('signature_invalid', function () use ($verifier, $v, $swappedValues): void {
            $verifier->verifyRequest(Vectors::keyRing(), 'GET', $v['path'], $swappedValues, (string) $v['timestamp'], $v['nonce'], '', $v['expected']['signature'], 'cur', $v['timestamp']);
        });
        $this->assertRejected('signature_invalid', function () use ($verifier, $v): void {
            $verifier->verifyRequest(Vectors::keyRing(), 'GET', $v['path'], str_replace('limit=50', 'limit=51', $v['transport']['query']), (string) $v['timestamp'], $v['nonce'], '', $v['expected']['signature'], 'cur', $v['timestamp']);
        });
    }

    public function test_the_overridden_delete_does_not_verify_as_post(): void
    {
        $v = Vectors::byKind('request')['method-override-delete'];

        $this->assertRejected('signature_invalid', function () use ($v): void {
            (new Verifier())->verifyRequest(Vectors::keyRing(), 'POST', $v['path'], $v['transport']['query'], (string) $v['timestamp'], $v['nonce'], $v['body'], $v['expected']['signature'], 'cur', $v['timestamp']);
        });
    }

    public function test_the_gzip_vector_does_not_verify_over_the_compressed_bytes(): void
    {
        $v = Vectors::byKind('request')['gzip-body'];
        $compressed = Vectors::wireBody($v);

        self::assertNotSame($v['body'], $compressed);
        $this->assertRejected('signature_invalid', function () use ($v, $compressed): void {
            (new Verifier())->verifyRequest(Vectors::keyRing(), $v['method'], $v['path'], $v['transport']['query'], (string) $v['timestamp'], $v['nonce'], $compressed, $v['expected']['signature'], 'cur', $v['timestamp']);
        });
    }

    public function test_the_prev_vector_fails_with_cur_after_expiry_and_when_revoked(): void
    {
        $v = Vectors::byKind('request')['prev-key-after-rotation'];
        $doc = Vectors::document();
        $verify = function (KeyRing $keys, ?string $keyId, int $now) use ($v): string {
            return (new Verifier(PHP_INT_MAX))->verifyRequest($keys, $v['method'], $v['path'], $v['transport']['query'], (string) $v['timestamp'], $v['nonce'], $v['body'], $v['expected']['signature'], $keyId, $now);
        };

        $this->assertRejected('signature_invalid', function () use ($verify, $v): void {
            $verify(Vectors::keyRing(), 'cur', $v['timestamp']);
        });
        self::assertSame('prev', $verify(Vectors::keyRing(), 'prev', (int) $doc['prev_valid_until']));
        $this->assertRejected('signature_invalid', function () use ($verify, $doc): void {
            $verify(Vectors::keyRing(), 'prev', (int) $doc['prev_valid_until'] + 1);
        });
        $this->assertRejected('signature_invalid', function () use ($verify, $doc, $v): void {
            $verify(new KeyRing($doc['secrets']['cur']), null, $v['timestamp']);
        });
        $this->assertRejected('signature_invalid', function () use ($verify, $v): void {
            $verify(Vectors::keyRing(), 'next', $v['timestamp']);
        });
    }

    public function test_the_clock_window_is_three_hundred_seconds_either_side(): void
    {
        $v = Vectors::byKind('request')['spec-4.2-post-stock-bulk'];
        $verify = function (int $now) use ($v): string {
            return (new Verifier())->verifyRequest(Vectors::keyRing(), $v['method'], $v['path'], $v['transport']['query'], (string) $v['timestamp'], $v['nonce'], $v['body'], $v['expected']['signature'], 'cur', $now);
        };

        self::assertSame('cur', $verify($v['timestamp'] + 300));
        self::assertSame('cur', $verify($v['timestamp'] - 300));
        $this->assertRejected('timestamp_out_of_window', function () use ($verify, $v): void {
            $verify($v['timestamp'] + 301);
        });
        $this->assertRejected('timestamp_out_of_window', function () use ($verify, $v): void {
            $verify($v['timestamp'] - 301);
        });
    }

    public function test_malformed_envelope_values_are_rejected_before_the_hmac(): void
    {
        $v = Vectors::byKind('request')['spec-4.2-post-stock-bulk'];
        $cases = [
            ['timestamp' => '1790772000.5', 'nonce' => $v['nonce'], 'signature' => $v['expected']['signature']],
            ['timestamp' => (string) $v['timestamp'], 'nonce' => 'not-a-uuid', 'signature' => $v['expected']['signature']],
            ['timestamp' => (string) $v['timestamp'], 'nonce' => $v['nonce'], 'signature' => substr($v['expected']['signature'], 0, 63)],
        ];
        foreach ($cases as $case) {
            $this->assertRejected('signature_invalid', function () use ($v, $case): void {
                (new Verifier())->verifyRequest(Vectors::keyRing(), $v['method'], $v['path'], '', $case['timestamp'], $case['nonce'], $v['body'], $case['signature'], 'cur', $v['timestamp']);
            });
        }
    }

    public function test_an_upper_case_signature_is_accepted(): void
    {
        $v = Vectors::byKind('request')['spec-4.2-post-stock-bulk'];

        self::assertSame('cur', (new Verifier())->verifyRequest(Vectors::keyRing(), $v['method'], $v['path'], $v['transport']['query'], (string) $v['timestamp'], $v['nonce'], $v['body'], strtoupper($v['expected']['signature']), 'cur', $v['timestamp']));
    }

    public function test_the_signed_response_vector(): void
    {
        $v = Vectors::byKind('response')['signed-response'];
        $secret = Vectors::document()['secrets']['cur'];
        $signer = new Signer();

        self::assertSame($v['expected']['canonical'], Canonical::response($v['request_nonce'], $v['timestamp'], $v['status'], $v['body']));
        self::assertSame($v['expected']['signature'], $signer->signResponse($secret, $v['request_nonce'], $v['timestamp'], $v['status'], $v['body']));
        self::assertSame('cur', (new Verifier())->verifyResponse(Vectors::keyRing(), $v['request_nonce'], (string) $v['timestamp'], $v['status'], $v['body'], $v['expected']['signature'], 'cur', $v['timestamp']));

        $headers = $signer->responseHeaders('cur', $secret, $v['request_nonce'], $v['timestamp'], $v['status'], $v['body']);
        self::assertSame($v['expected']['signature'], $headers['X-KX-Signature']);
    }

    public function test_a_tampered_response_fails(): void
    {
        $v = Vectors::byKind('response')['signed-response'];
        $verify = function (string $nonce, int $status, string $body) use ($v): string {
            return (new Verifier())->verifyResponse(Vectors::keyRing(), $nonce, (string) $v['timestamp'], $status, $body, $v['expected']['signature'], 'cur', $v['timestamp']);
        };

        $this->assertRejected('signature_invalid', function () use ($verify, $v): void {
            $verify($v['request_nonce'], 201, $v['body']);
        });
        $this->assertRejected('signature_invalid', function () use ($verify, $v): void {
            $verify($v['request_nonce'], $v['status'], str_replace('"ok":1', '"ok":2', $v['body']));
        });
        $this->assertRejected('signature_invalid', function () use ($verify, $v): void {
            $verify('0192b2e4-8a2b-7c3d-8e4f-5a6b7c8d9e0f', $v['status'], $v['body']);
        });
    }

    public function test_a_request_signature_is_not_a_valid_response_signature(): void
    {
        $request = Vectors::byKind('request')['spec-4.2-post-stock-bulk'];

        $this->assertRejected('signature_invalid', function () use ($request): void {
            (new Verifier())->verifyResponse(Vectors::keyRing(), $request['nonce'], (string) $request['timestamp'], 200, $request['body'], $request['expected']['signature'], 'cur', $request['timestamp']);
        });
    }

    public function test_request_headers_carry_the_protocol_fields(): void
    {
        $v = Vectors::byKind('request')['spec-4.2-post-stock-bulk'];
        $secret = Vectors::document()['secrets']['cur'];
        $headers = (new Signer())->requestHeaders('0192b2e4-2f7a-7c5b-8a1d-3e4f5a6b7c8d', 'cur', $secret, 'POST', $v['path'], '', $v['timestamp'], $v['nonce'], $v['body']);

        self::assertSame([
            'X-KX-Store' => '0192b2e4-2f7a-7c5b-8a1d-3e4f5a6b7c8d',
            'X-KX-Timestamp' => '1790772000',
            'X-KX-Nonce' => $v['nonce'],
            'X-KX-Signature' => $v['expected']['signature'],
            'X-KX-Protocol' => 'v1',
            'X-KX-Key-Id' => 'cur',
        ], $headers);
    }

    private function verify(KeyRing $keys, Request $request, string $signature, ?string $keyId, int $now): string
    {
        return (new Verifier())->verifyRequest(
            $keys,
            $request->method(),
            $request->path(),
            $request->rawQuery(),
            (string) $request->header('X-KX-Timestamp'),
            (string) $request->header('X-KX-Nonce'),
            $request->body(),
            $signature,
            $keyId,
            $now
        );
    }

    private function assertRejected(string $code, callable $call): void
    {
        try {
            $call();
        } catch (ApiError $error) {
            self::assertSame($code, $error->errorCode());
            self::assertSame(401, $error->status());

            return;
        }
        self::fail('Expected ApiError '.$code);
    }
}

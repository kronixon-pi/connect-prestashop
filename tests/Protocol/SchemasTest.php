<?php

declare(strict_types=1);

namespace Kronixon\Connect\Tests\Protocol;

use FilesystemIterator;
use Kronixon\Connect\Api\Kernel;
use Kronixon\Connect\Api\Router;
use Kronixon\Connect\Auth\UnpairedState;
use Kronixon\Connect\Tests\Support\FixedClock;
use Kronixon\Connect\Tests\Support\MemoryGuards;
use Kronixon\Connect\Tests\Support\TestModule;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use stdClass;

/**
 * protocol/v1/schemas: every file is a draft 2020-12 schema whose $id matches its path, compiles,
 * accepts its own examples; vectors.json matches its schema; the module's real error answers match
 * the error schema; the endpoint list of README §6 and the schema files agree.
 */
final class SchemasTest extends TestCase
{
    private const PREFIX = 'https://connect.kronixon.com/protocol/v1/schemas/';

    private const DIR = __DIR__.'/../../protocol/v1/schemas/';

    private const README = __DIR__.'/../../protocol/v1/README.md';

    /**
     * @return iterable<string, array{string}>
     */
    public function schemaFiles(): iterable
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::DIR, FilesystemIterator::SKIP_DOTS));
        $files = [];
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'json') {
                $files[] = substr($file->getPathname(), strlen(self::DIR));
            }
        }
        sort($files);
        foreach ($files as $relative) {
            yield $relative => [$relative];
        }
    }

    /**
     * @dataProvider schemaFiles
     */
    public function test_header_and_id(string $relative): void
    {
        $schema = $this->decode(self::DIR.$relative);

        self::assertSame('https://json-schema.org/draft/2020-12/schema', $schema->{'$schema'} ?? null);
        self::assertSame(self::PREFIX.$relative, $schema->{'$id'} ?? null);
        self::assertNotEmpty($schema->title ?? '');
    }

    /**
     * @dataProvider schemaFiles
     */
    public function test_every_definition_compiles_and_accepts_its_examples(string $relative): void
    {
        $schema = $this->decode(self::DIR.$relative);
        $validator = $this->validator();
        $checked = 0;
        foreach ((array) ($schema->{'$defs'} ?? []) as $name => $definition) {
            $uri = self::PREFIX.$relative.'#/$defs/'.$name;
            $validator->validate(null, $uri);
            foreach ($definition->examples ?? [] as $i => $example) {
                $result = $validator->validate($example, $uri);
                self::assertTrue($result->isValid(), "$uri example $i: ".$this->explain($result->error()));
                $checked++;
            }
        }
        $this->addToAssertionCount(1);
        if (strpos($relative, 'module/') === 0 || strpos($relative, 'platform/') === 0) {
            self::assertGreaterThan(0, $checked, $relative.' has no examples');
        }
    }

    /**
     * @dataProvider schemaFiles
     */
    public function test_endpoint_files_declare_their_endpoint(string $relative): void
    {
        $schema = $this->decode(self::DIR.$relative);
        if (strpos($relative, 'module/') !== 0 && strpos($relative, 'platform/') !== 0) {
            $this->addToAssertionCount(1);

            return;
        }
        $endpoint = $schema->{'x-kx-endpoint'} ?? null;
        self::assertIsObject($endpoint, $relative);
        self::assertContains($endpoint->method, ['GET', 'POST', 'DELETE']);
        self::assertMatchesRegularExpression('#^/v1/[a-z0-9{}/_-]+$#', $endpoint->path);
        self::assertIsBool($endpoint->signed);
        $defs = array_keys((array) $schema->{'$defs'});
        self::assertContains('response', $defs, $relative);
        if ($endpoint->method === 'GET') {
            self::assertNotContains('request', $defs, $relative.': a GET has a query, not a body');
        }
    }

    public function test_the_readme_lists_exactly_the_schema_endpoints(): void
    {
        $readme = (string) file_get_contents(self::README);
        preg_match_all('/\| `(GET|POST|DELETE) (\/v1\/[^`]+)` \| `((?:module|platform)\/[a-z0-9-]+\.json)` \|/', $readme, $m, PREG_SET_ORDER);
        $listed = [];
        foreach ($m as [, $method, $path, $file]) {
            $listed[$file] = $method.' '.$path;
        }
        $declared = [];
        foreach (array_merge(glob(self::DIR.'module/*.json') ?: [], glob(self::DIR.'platform/*.json') ?: []) as $path) {
            $endpoint = $this->decode($path)->{'x-kx-endpoint'};
            $declared[substr($path, strlen(self::DIR))] = $endpoint->method.' '.$endpoint->path;
        }
        ksort($listed);
        ksort($declared);

        self::assertSame($declared, $listed);
        self::assertCount(33, array_filter(array_keys($declared), static function (string $f): bool {
            return strpos($f, 'module/') === 0;
        }));
    }

    public function test_vectors_match_their_schema(): void
    {
        $result = $this->validator()->validate($this->decode(__DIR__.'/../../protocol/v1/vectors.json'), self::PREFIX.'vectors.json');

        self::assertTrue($result->isValid(), $this->explain($result->error()));
    }

    public function test_every_event_type_has_a_payload_schema(): void
    {
        $events = $this->decode(self::DIR.'events.json');
        $types = $events->{'$defs'}->event->properties->type->enum;
        $validator = $this->validator();

        foreach ($types as $type) {
            $event = (object) ['event_id' => '0192b2e4-7c1a-7d3e-9f4b-2a6c8e0d1f35', 'type' => $type, 'occurred_at' => '2026-09-30T12:40:00Z', 'dedupe_key' => $type.':1', 'origin' => 'shop', 'payload' => (object) ['unexpected' => 1]];
            $result = $validator->validate($event, self::PREFIX.'events.json#/$defs/event');
            self::assertFalse($result->isValid(), $type.' accepts any payload');
        }
        self::assertCount(20, $types);
    }

    /**
     * Proof that the examples test can fail: each instance breaks one rule of its schema.
     *
     * @return iterable<string, array{string, string}>
     */
    public function brokenInstances(): iterable
    {
        yield 'error without code' => ['common.json#/$defs/error_response', '{"error":{"message":"x","request_id":"r","server_time":1}}'];
        yield 'unknown error code' => ['common.json#/$defs/error_response', '{"error":{"code":"teapot","message":"x","request_id":"r","server_time":1}}'];
        yield 'money as a JSON number' => ['common.json#/$defs/money', '99.99'];
        yield 'money with 7 decimals' => ['common.json#/$defs/money', '"1.1234567"'];
        yield 'datetime without Z' => ['common.json#/$defs/datetime', '"2026-09-30T12:40:00+02:00"'];
        yield 'external id as a number' => ['common.json#/$defs/external_id', '123'];
        yield 'ok result without action' => ['common.json#/$defs/item_result', '{"key":"p-1","status":"ok"}'];
        yield 'capabilities for protocol v2' => ['module/capabilities.json#/$defs/response', '{"protocol":"v2","module_version":"1.0.0","min_platform_protocol":"v1","capabilities":{},"limits":{}}'];
        yield '101 products' => ['module/products.json#/$defs/request', '{"items":['.implode(',', array_fill(0, 101, '{"key":"p","groups":["content"]}')).']}'];
        yield 'unknown product group' => ['module/products.json#/$defs/request', '{"items":[{"key":"p","groups":["everything"]}]}'];
        yield '1001 price rows' => ['module/prices-bulk.json#/$defs/request', '{"rows":['.implode(',', array_fill(0, 1001, '{"product_id":"1","variant_id":"0","price":"1.00"}')).']}'];
        yield 'image over http' => ['module/images.json#/$defs/item', '{"key":"i","kx_key":"k","url":"http://files.example.com/1.jpg","sha256":"142cbfb1fbcb6e319ac89ebbb22bcd6d651ff0cba117e97f8c13338afa1f8738"}'];
        yield 'pair code with I/O/0/1' => ['platform/pair.json#/$defs/request', '{"code":"IO01-ABCD","store_url":"https://s.example/","platform":"prestashop","version":"9.1.5","php":"8.4","module_version":"1.0.0","protocol":"v1","public_key":"AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=","shop_id":1,"capabilities_hash":"142cbfb1fbcb6e319ac89ebbb22bcd6d651ff0cba117e97f8c13338afa1f8738"}'];
        yield 'vector without a signature' => ['vectors.json#/$defs/response_vector', '{"id":"x","kind":"response","description":"","key_id":"cur","request_nonce":"0192b2e4-7c1a-7d3e-9f4b-2a6c8e0d1f35","status":200,"timestamp":1,"body":"","expected":{"body_sha256":"e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855","canonical":"RESPONSE\n"}}'];
    }

    /**
     * @dataProvider brokenInstances
     */
    public function test_the_validator_rejects_broken_instances(string $schema, string $json): void
    {
        $result = $this->validator()->validate(json_decode($json, false, 512, JSON_THROW_ON_ERROR), self::PREFIX.$schema);

        self::assertFalse($result->isValid());
    }

    public function test_the_modules_unpaired_answer_matches_the_error_schema(): void
    {
        $kernel = new Kernel(new Router(), new UnpairedState(), new FixedClock(1790772000.0), new MemoryGuards(), new MemoryGuards());
        $validator = $this->validator();
        $cases = [
            ['REQUEST_METHOD' => 'GET', 'QUERY_STRING' => 'path=/v1/status', 'HTTP_X_KX_PROTOCOL' => 'v1'],
            ['REQUEST_METHOD' => 'GET', 'QUERY_STRING' => 'path=/v1/status'],
            ['REQUEST_METHOD' => 'PUT', 'QUERY_STRING' => 'path=/v1/status', 'HTTP_X_KX_PROTOCOL' => 'v1'],
        ];
        foreach ($cases as $server) {
            $body = json_decode($kernel->handle($server, '')->body(), false, 512, JSON_THROW_ON_ERROR);
            $result = $validator->validate($body, self::PREFIX.'common.json#/$defs/error_response');
            self::assertTrue($result->isValid(), $this->explain($result->error()));
        }
    }

    public function test_the_modules_answers_and_its_pair_request_match_their_schemas(): void
    {
        $module = new TestModule();
        $module->pair();
        $module->settings->set('KX_INSTANCE_ID', '0192b2e4-2f7a-4c5b-8a1d-3e4f5a6b7c8d');
        $secret = (string) $module->platform->secret;
        $validator = $this->validator();
        $check = function (string $schema, string $json) use ($validator): void {
            $result = $validator->validate(json_decode($json, false, 512, JSON_THROW_ON_ERROR), self::PREFIX.$schema);
            self::assertTrue($result->isValid(), $schema.': '.$this->explain($result->error()));
        };

        $check('platform/pair.json#/$defs/request', $module->platform->requests[0]['body']);
        foreach (['capabilities', 'profile', 'health'] as $endpoint) {
            $response = $module->kernel()->handle($module->signedServer($secret, 'GET', $endpoint), '');
            self::assertSame(200, $response->status(), $endpoint);
            $check('module/'.$endpoint.'.json#/$defs/response', $response->body());
        }
        $rotate = $module->rotateBody('kxs_9Qm3vT7bLp2XcR8fWn4zHd6KyE1sAu5G', 1790858400);
        $check('module/rotate.json#/$defs/request', $rotate);
        $check('module/rotate.json#/$defs/response', $module->kernel()->handle($module->signedServer($secret, 'POST', 'rotate', [], $rotate), $rotate)->body());
        $secret = 'kxs_9Qm3vT7bLp2XcR8fWn4zHd6KyE1sAu5G';

        $nonce = '0192b2e4-7c1a-7d3e-9f4b-2a6c8e0d1f35';
        $module->kernel()->handle($module->signedServer($secret, 'GET', 'health', [], '', $nonce), '');
        $errors = [
            $module->kernel()->handle($module->signedServer($secret, 'GET', 'health', [], '', $nonce), ''),
            $module->kernel()->handle($module->signedServer($secret, 'GET', 'nope'), ''),
            $module->kernel()->handle($module->signedServer($secret, 'POST', 'unpair', [], '{}'), '{}'),
        ];
        $module->guards->heldElsewhere = true;
        $errors[] = $module->kernel()->handle($module->signedServer($secret, 'POST', 'unpair', [], '{"reason":"user"}'), '{"reason":"user"}');
        $module->guards->heldElsewhere = false;
        foreach ($errors as $error) {
            $check('common.json#/$defs/error_response', $error->body());
        }

        $check('module/unpair.json#/$defs/response', $module->kernel()->handle($module->signedServer($secret, 'POST', 'unpair', [], '{"reason":"user"}'), '{"reason":"user"}')->body());
    }

    private function validator(): Validator
    {
        $validator = new Validator();
        $validator->resolver()->registerPrefix(self::PREFIX, realpath(self::DIR) ?: self::DIR);
        $validator->setMaxErrors(5);

        return $validator;
    }

    /**
     * @return stdClass
     */
    private function decode(string $path)
    {
        $data = json_decode((string) file_get_contents($path), false, 512, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(stdClass::class, $data, $path);

        return $data;
    }

    /**
     * @param  \Opis\JsonSchema\Errors\ValidationError|null  $error
     */
    private function explain($error): string
    {
        if ($error === null) {
            return '';
        }
        $json = json_encode((new ErrorFormatter())->format($error), JSON_UNESCAPED_SLASHES);

        return $json === false ? '' : $json;
    }
}

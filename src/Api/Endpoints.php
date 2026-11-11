<?php

/**
 * Kronixon Connect for PrestaShop
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License version 3.0
 * that is bundled with this package in the file LICENSE.
 * It is also available at https://opensource.org/licenses/AFL-3.0
 *
 * @author    Kronixon
 * @copyright 2026 Kronixon
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

declare(strict_types=1);

namespace Kronixon\Connect\Api;

use Kronixon\Connect\Auth\KeyRing;
use Kronixon\Connect\Auth\SecretStore;
use Kronixon\Connect\Compat\CompatInterface;
use Kronixon\Connect\Protocol;
use Kronixon\Connect\Shop\ShopInfo;
use Kronixon\Connect\Support\Clock;
use Kronixon\Connect\Support\Log;

/**
 * The endpoints of module 0.2.0 (README §6.1): capabilities, profile, health, rotate, unpair.
 * They run only behind the Kernel's verification; rotate and unpair under the write lock.
 */
final class Endpoints
{
    public const LAST_REQUEST_AT = SecretStore::LAST_REQUEST_AT;

    /** module/unpair.json `reason`. */
    private const UNPAIR_REASONS = ['user', 'store_deleted', 'revoked', 'tenant_deleted', 'other'];

    /** Capabilities whose operations arrive with later module versions: false until then (README §10). */
    private const NOT_YET = ['products.write', 'variants', 'images', 'image_alt', 'categories', 'attributes',
        'attribute_values_custom', 'stock', 'stock_locations', 'prices.bulk', 'specific_prices', 'tags', 'seo',
        'redirects', 'webhooks', 'search_reindex', 'cache_flush', 'orders.read', 'facets', 'events.pull', ];

    /** @var SecretStore */
    private $secrets;

    /** @var ShopInfo */
    private $shop;

    /** @var CompatInterface */
    private $compat;

    /** @var Clock */
    private $clock;

    /** @var Log */
    private $log;

    /** @var int */
    private $budgetSeconds;

    public function __construct(SecretStore $secrets, ShopInfo $shop, CompatInterface $compat, Clock $clock, Log $log, int $budgetSeconds = Protocol::TIME_BUDGET_S)
    {
        $this->secrets = $secrets;
        $this->shop = $shop;
        $this->compat = $compat;
        $this->clock = $clock;
        $this->log = $log;
        $this->budgetSeconds = $budgetSeconds;
    }

    public function register(Router $router): Router
    {
        return $router
            ->add('GET', '/v1/capabilities', function (): Response {
                return Response::json(200, $this->capabilities());
            })
            ->add('GET', '/v1/profile', function (): Response {
                return Response::json(200, $this->profile());
            })
            ->add('GET', '/v1/health', function (): Response {
                return Response::json(200, $this->health());
            })
            ->add('POST', '/v1/rotate', function (Request $request): Response {
                return $this->rotate($request);
            })
            ->add('POST', '/v1/unpair', function (Request $request): Response {
                return $this->unpair($request);
            });
    }

    /**
     * GET capabilities (module/capabilities.json); its sha256 is the pair request's `capabilities_hash`.
     *
     * @return array<string, mixed>
     */
    public function capabilities(): array
    {
        $flags = array_fill_keys(self::NOT_YET, false);
        $flags['multilang'] = $this->shop->multilang();
        $flags['multistore'] = $this->shop->multistore();
        $flags['blog'] = ['enabled' => false, 'adapters' => [], 'import_sources' => []];
        $flags['image_formats'] = $this->shop->imageFormats();
        // GET catalog/suppliers (module/catalog-suppliers.json: "with the suppliers capability").
        $flags['suppliers'] = true;
        // GET catalog/variants?product_id_in= (module 0.3.2).
        $flags['catalog'] = ['variants_product_id_in' => true];
        ksort($flags);

        return [
            'protocol' => Protocol::VERSION,
            'module_version' => Protocol::MODULE_VERSION,
            'min_platform_protocol' => Protocol::VERSION,
            'capabilities' => $flags,
            'limits' => [
                'batch' => 100,
                'bulk_rows' => 1000,
                'images_per_request' => 50,
                'time_budget_s' => max(1, min(Protocol::MAX_TIME_BUDGET_S, $this->budgetSeconds)),
                'max_body_bytes' => Protocol::MAX_BODY_BYTES,
            ],
            'features' => ['redirect_types' => $this->compat->redirectTypes()],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function profile(): array
    {
        return [
            'module_version' => Protocol::MODULE_VERSION,
            'instance_id' => (string) $this->secrets->setting('KX_INSTANCE_ID'),
            'timezone' => $this->shop->timezone(),
            'server_time' => gmdate('Y-m-d\TH:i:s\Z', $this->now()),
            'urls' => ['api' => $this->shop->apiUrl()],
        ] + $this->shop->profile();
    }

    /**
     * Cheap: no COUNT(*), no network (module/health.json).
     *
     * @return array<string, mixed>
     */
    public function health(): array
    {
        $dbOk = $this->shop->databaseOk();
        $last = $this->secrets->setting(self::LAST_REQUEST_AT);

        return [
            'ok' => $dbOk,
            'server_time' => $this->now(),
            'module_version' => Protocol::MODULE_VERSION,
            'paired' => $this->secrets->isPaired(),
            'store_id' => $this->secrets->storeId(),
            'last_platform_request_at' => $last === null ? null : gmdate('Y-m-d\TH:i:s\Z', (int) $last),
            'db_ok' => $dbOk,
            'php' => ['version' => PHP_VERSION, 'memory_limit' => (string) ini_get('memory_limit')],
            'warnings' => [],
        ];
    }

    /**
     * POST rotate (README §4.5): the new secret sealed to this module's key becomes `cur`, the old one
     * `prev` until `prev_valid_until` (at most 24 h from now). The answer is still signed with the old
     * secret, the one the request was verified with (Kernel). Repeating it is harmless.
     *
     * A rotation verified with `prev` is a retry after a lost answer: the platform still holds the
     * secret that is `prev` here, so only `cur` is replaced and `prev` keeps its expiry.
     */
    private function rotate(Request $request): Response
    {
        $body = self::object($request);
        $sealed = $body['secret_sealed'] ?? null;
        $until = $body['prev_valid_until'] ?? null;
        $errors = [];
        if (! is_string($sealed) || strlen($sealed) > 256 || preg_match('#\A[A-Za-z0-9+/]+={0,2}\z#', $sealed) !== 1) {
            $errors[] = 'secret_sealed';
        }
        if (! is_int($until) || $until < 0) {
            $errors[] = 'prev_valid_until';
        }
        $secret = $errors === [] && is_string($sealed) ? $this->secrets->openSecret($sealed) : null;
        if ($errors === [] && $secret === null) {
            $errors[] = 'secret_sealed';
        }
        if ($errors !== [] || $secret === null || ! is_int($until)) {
            throw ApiError::validationFailed($errors);
        }
        $keyId = $request->verifiedKeyId() ?? KeyRing::CURRENT;
        if (! $this->secrets->rotated($secret, min($until, $this->now() + 86400), $keyId)) {
            throw ApiError::moduleError();
        }
        $this->log->info('auth', 'Shared secret rotated', ['prev_valid_until' => $until]);

        return Response::json(200, ['ok' => true]);
    }

    /** POST unpair (README §3.4): the secrets go; every later request answers `403 store_unpaired`. */
    private function unpair(Request $request): Response
    {
        $reason = self::object($request)['reason'] ?? null;
        if (! is_string($reason) || ! in_array($reason, self::UNPAIR_REASONS, true)) {
            throw ApiError::validationFailed(['reason']);
        }
        $this->secrets->forget();
        $this->log->info('auth', 'Unpaired by the platform', ['reason' => $reason]);

        return Response::json(200, ['ok' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function object(Request $request): array
    {
        $data = json_decode($request->body(), true);
        if (! is_array($data) || ($data !== [] && array_keys($data) === range(0, count($data) - 1))) {
            throw ApiError::badRequest('The body is not a JSON object.');
        }

        return $data;
    }

    private function now(): int
    {
        return (int) floor($this->clock->now());
    }
}

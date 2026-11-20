<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Autopilot\ApiTokens;
use App\Services\Pixels\PixelConfig;
use App\Services\Pixels\PlatformClient;
use App\Services\Pixels\ServerEvents;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Growth & Marketing -> Ads Autopilot. (Lane AP-API)
 *
 *   GET  admin-api/ads-autopilot                    the screen          autopilot.view
 *   POST admin-api/ads-autopilot/keys               create a key        autopilot.manage
 *   POST admin-api/ads-autopilot/keys/{id}/revoke   revoke it           autopilot.manage
 *   POST admin-api/ads-autopilot/measurement        Meta switches       autopilot.manage
 *   GET  admin-api/ads-autopilot/costs              cost prices, paged  autopilot.view
 *   POST admin-api/ads-autopilot/costs              save cost prices    autopilot.manage
 *
 * The key is in ONE answer only: the create. After that the screen knows its
 * hint ("kbbap_…a1b2") and nothing else; the database knows its SHA-256.
 */
final class AdsAutopilotApiController extends Controller
{
    private const COST_PAGE = 25;

    public function __construct(private ApiTokens $tokens, private PixelConfig $config) {}

    public function index(): JsonResponse
    {
        return new JsonResponse(['ok' => true] + $this->state());
    }

    public function createKey(Request $request): JsonResponse
    {
        $scopes = $request->input('scopes');
        $scopes = is_array($scopes) ? array_values(array_filter($scopes, 'is_string')) : array_keys(ApiTokens::SCOPES);
        $scopes = array_values(array_intersect(array_keys(ApiTokens::SCOPES), $scopes));

        if ($scopes === []) {
            return new JsonResponse(['ok' => false, 'message' => 'Tick at least one thing the key may read.'], 422);
        }

        $made = $this->tokens->create((string) (is_string($request->input('name')) ? $request->input('name') : ''), $scopes, auth('admin')->id() ? (int) auth('admin')->id() : null);

        if ($made === null) {
            return new JsonResponse(['ok' => false, 'message' => 'There are already ' . ApiTokens::MAX_LIVE . ' live keys. Revoke one you no longer use first.'], 422);
        }

        return new JsonResponse(['ok' => true, 'token' => $made['token'], 'hint' => $made['hint']] + $this->state());
    }

    public function revokeKey(int $id): JsonResponse
    {
        if (! $this->tokens->revoke($id)) {
            return new JsonResponse(['ok' => false, 'message' => 'That key is already revoked, or does not exist.'], 404);
        }

        return new JsonResponse(['ok' => true] + $this->state());
    }

    public function saveMeasurement(Request $request): JsonResponse
    {
        $values = [];

        $version = $request->input('graph_version');
        if (is_string($version)) {
            if (! in_array($version, PlatformClient::GRAPH_VERSIONS, true)) {
                return new JsonResponse(['ok' => false, 'message' => 'Pick one of the listed Graph API versions.'], 422);
            }
            $values['meta_graph_version'] = $version === PlatformClient::GRAPH_VERSION ? '' : $version;
        }

        foreach (['refund_events' => 'meta_refund_events', 'capi_retry' => 'meta_capi_retry', 'purchase_onhold' => 'meta_purchase_onhold'] as $in => $key) {
            if ($request->has($in)) {
                $values[$key] = $request->boolean($in) ? '1' : '0';
            }
        }

        $refused = $this->config->save($values);

        if ($refused !== []) {
            return new JsonResponse(['ok' => false, 'message' => 'Not saved: ' . implode(', ', $refused) . '.'], 422);
        }

        app()->forgetInstance(ServerEvents::class);

        return new JsonResponse(['ok' => true] + $this->state());
    }

    public function costs(Request $request): JsonResponse
    {
        $q = trim(str_replace(['%', '_', '\\'], '', mb_substr((string) $request->query('q', ''), 0, 80)));
        $page = max(1, min(10000, (int) $request->query('page', 1)));
        $missing = $request->boolean('missing');

        $base = DB::table('products')->whereNull('deleted_at')->whereIn('status', ['publish', 'private', 'draft']);
        if ($q !== '') {
            $base->where(fn ($w) => $w->where('name', 'like', '%' . $q . '%')->orWhere('sku', 'like', '%' . $q . '%'));
        }
        if ($missing) {
            $base->whereNull('cost_price');
        }

        $total = (clone $base)->count();
        $rows = $base->orderBy('name')->orderBy('id')->forPage($page, self::COST_PAGE)
            ->get(['id', 'name', 'sku', 'price', 'sale_price', 'cost_price'])
            ->map(static fn ($p) => [
                'id' => (int) $p->id,
                'name' => (string) $p->name,
                'sku' => $p->sku !== null ? (string) $p->sku : '',
                'price' => $p->price !== null ? round((int) $p->price / 100, 2) : null,
                'sale_price' => $p->sale_price !== null ? round((int) $p->sale_price / 100, 2) : null,
                'cost' => $p->cost_price !== null ? round((int) $p->cost_price / 100, 2) : null,
            ])->all();

        $withCost = (int) DB::table('products')->whereNotNull('cost_price')->count();

        return new JsonResponse(['ok' => true, 'rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => self::COST_PAGE, 'with_cost' => $withCost]);
    }

    public function saveCosts(Request $request): JsonResponse
    {
        $rows = $request->input('rows');

        if (! is_array($rows) || $rows === [] || count($rows) > 200) {
            return new JsonResponse(['ok' => false, 'message' => 'Send between 1 and 200 rows.'], 422);
        }

        $writes = [];
        foreach ($rows as $row) {
            $id = is_array($row) ? (int) ($row['id'] ?? 0) : 0;
            $raw = is_array($row) ? ($row['cost'] ?? null) : null;
            $raw = is_scalar($raw) ? trim((string) $raw) : ($raw === null ? '' : 'x');

            if ($id <= 0) {
                return new JsonResponse(['ok' => false, 'message' => 'A row has no product.'], 422);
            }

            if ($raw === '') {
                $writes[$id] = null;

                continue;
            }

            if (preg_match('/^\d{1,7}(\.\d{1,2})?$/', $raw) !== 1) {
                return new JsonResponse(['ok' => false, 'message' => 'Cost prices are amounts in AED, like 42 or 42.50. Nothing was saved.'], 422);
            }

            $writes[$id] = (int) round(((float) $raw) * 100);
        }

        $known = DB::table('products')->whereIn('id', array_keys($writes))->pluck('id')->map(fn ($v) => (int) $v)->all();
        $saved = 0;

        DB::transaction(function () use ($writes, $known, &$saved): void {
            foreach ($writes as $id => $fils) {
                if (in_array($id, $known, true)) {
                    $saved += DB::table('products')->where('id', $id)->update(['cost_price' => $fils]);
                }
            }
        });

        return new JsonResponse(['ok' => true, 'saved' => $saved]);
    }

    /** @return array<string, mixed> */
    private function state(): array
    {
        $names = DB::table('autopilot_tokens')->pluck('name', 'id');
        $calls = DB::table('autopilot_api_log')->orderByDesc('id')->limit(20)->get(['token_id', 'path', 'status', 'rows', 'created_at'])
            ->map(static fn ($r) => [
                'key' => $r->token_id !== null ? (string) ($names[$r->token_id] ?? '#' . $r->token_id) : null,
                'path' => (string) $r->path,
                'status' => (int) $r->status,
                'rows' => (int) $r->rows,
                'at' => $r->created_at,
            ])->all();

        $events = app(ServerEvents::class);

        return [
            'api_url' => url('/autopilot-api/v1'),
            'scopes' => ApiTokens::SCOPES,
            'keys' => $this->tokens->all(),
            'max_keys' => ApiTokens::MAX_LIVE,
            'calls' => $calls,
            'measurement' => [
                'meta_server_events' => $events->metaOn(),
                'graph_version' => PlatformClient::graphVersion(),
                'graph_versions' => PlatformClient::GRAPH_VERSIONS,
                'graph_default' => PlatformClient::GRAPH_VERSION,
                'refund_events' => $this->config->on('meta_refund_events'),
                'capi_retry' => $this->config->on('meta_capi_retry'),
                'purchase_onhold' => $this->config->on('meta_purchase_onhold'),
                'retry_queue' => ServerEvents::retryQueue(),
                // The shop has no cookie or consent banner (see Pixels\GoogleAds), so
                // there is no consent signal to gate server events on. Said, not hidden.
                'consent_banner' => false,
            ],
        ];
    }
}

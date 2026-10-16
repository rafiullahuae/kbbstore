<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ConcernSync\ClaudeConcernSuggester;
use App\Services\ConcernSync\ConcernRules;
use App\Services\ConcernSync\ConcernSync;
use App\Services\ConcernSync\ConcernSyncCredentials;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Catalog → Shop by Concern. (Lane SC)
 *
 *   GET  admin-api/concern-sync           the screen: concerns, key saved?, model, how many are in no concern
 *   POST admin-api/concern-sync/settings  save the Claude API key (or clear it) and the model
 *   POST admin-api/concern-sync/scan      Sync: a page of products in no concern, each with suggestions
 *   POST admin-api/concern-sync/apply     add the ticked memberships -- ONLY ADDS
 *
 * Behind `concerns.sync` (owner, manager) through AdminCapabilities, which
 * fails closed; CSRF and auth:admin from the admin-api group; the three POSTs
 * share the named throttle bucket `concern-sync` (10 a minute). The key is
 * never sent back: the screen is told `has_key` and nothing else.
 */
final class ConcernSyncApiController extends Controller
{
    public function show(): JsonResponse
    {
        $concerns = ConcernSync::concerns();
        $creds = ConcernSyncCredentials::read();

        return response()->json([
            'concerns' => self::concernList($concerns),
            'pending' => $concerns === [] ? 0 : ConcernSync::pendingCount($concerns),
            'has_key' => $creds['key'] !== null,
            'model' => $creds['model'],
            'models' => ConcernSyncCredentials::MODELS,
        ]);
    }

    public function settings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'api_key' => ['nullable', 'string', 'max:400', 'regex:/^[A-Za-z0-9_\-]*$/'],
            'clear_key' => ['nullable', 'boolean'],
            'model' => ['required', 'string', 'in:'.implode(',', array_keys(ConcernSyncCredentials::MODELS))],
        ]);

        ConcernSyncCredentials::saveModel($data['model']);

        if (! empty($data['clear_key'])) {
            ConcernSyncCredentials::saveKey(null);
        } elseif (isset($data['api_key']) && trim($data['api_key']) !== '') {
            ConcernSyncCredentials::saveKey($data['api_key']);
        }

        $creds = ConcernSyncCredentials::read();

        return response()->json(['ok' => true, 'has_key' => $creds['key'] !== null, 'model' => $creds['model']]);
    }

    public function scan(Request $request): JsonResponse
    {
        $data = $request->validate([
            'engine' => ['nullable', 'string', 'in:auto,rules'],
            'before' => ['nullable', 'integer', 'min:1'],
        ]);

        $concerns = ConcernSync::concerns();
        if ($concerns === []) {
            return response()->json(['message' => 'This shop has none of the Shop by Concern collections yet.'], 422);
        }

        $products = ConcernSync::unassigned($concerns, isset($data['before']) ? (int) $data['before'] : null);
        $creds = ConcernSyncCredentials::read();

        $engine = 'rules';
        $note = $creds['key'] === null ? 'No Claude API key saved, so these are rule-based suggestions.' : null;
        $byClaude = [];
        $failed = [];

        if ($products !== [] && $creds['key'] !== null && ($data['engine'] ?? 'auto') === 'auto') {
            @set_time_limit(200);
            $got = ClaudeConcernSuggester::suggest($products, $concerns, $creds['key'], $creds['model']);
            $byClaude = $got['suggestions'];
            $failed = array_flip($got['failed']);
            $engine = $byClaude === [] ? 'rules' : 'claude';
            if ($got['error'] !== null) {
                $note = 'Claude could not answer ('.$got['error'].'), so '.($byClaude === [] ? 'these are' : 'some are').' rule-based suggestions.';
            }
        } elseif (($data['engine'] ?? 'auto') === 'rules') {
            $note = 'Rule-based suggestions, as asked.';
        }

        $rows = [];
        foreach ($products as $p) {
            $fromClaude = isset($byClaude[$p['id']]) && ! isset($failed[$p['id']]);
            $rows[] = [
                'id' => $p['id'],
                'name' => $p['name'],
                'brand' => $p['brand'],
                'image' => $p['image'],
                'srcset' => $p['image'] !== '' && class_exists(\App\Support\ImageVariants::class) ? \App\Support\ImageVariants::srcsetFor($p['image']) : '',
                'categories' => $p['categories'],
                'source' => $fromClaude ? 'claude' : 'rules',
                'suggestions' => $fromClaude ? $byClaude[$p['id']] : ConcernRules::suggest($p, $concerns),
            ];
        }

        $last = $rows === [] ? null : end($rows)['id'];

        return response()->json([
            'engine' => $engine,
            'note' => $note,
            'concerns' => self::concernList($concerns),
            'products' => $rows,
            'pending' => ConcernSync::pendingCount($concerns),
            'next_before' => count($rows) >= ConcernSync::PAGE ? $last : null,
        ]);
    }

    public function apply(Request $request): JsonResponse
    {
        $concerns = ConcernSync::concerns();

        $data = $request->validate([
            'links' => ['required', 'array', 'min:1', 'max:'.ConcernSync::PAGE],
            'links.*.product_id' => ['required', 'integer', 'min:1'],
            'links.*.concerns' => ['required', 'array', 'min:1', 'max:'.count(ConcernSync::CONCERNS)],
            'links.*.concerns.*' => ['required', 'string', 'in:'.implode(',', array_keys($concerns) ?: ['-'])],
        ]);

        $links = [];
        foreach ($data['links'] as $l) {
            $links[(int) $l['product_id']] = array_values(array_unique(array_merge($links[(int) $l['product_id']] ?? [], $l['concerns'])));
        }

        $result = ConcernSync::apply($concerns, $links);

        return response()->json(['ok' => true] + $result + ['pending' => ConcernSync::pendingCount($concerns)]);
    }

    /** @return list<array{slug:string, name:string}> */
    private static function concernList(array $concerns): array
    {
        $out = [];
        foreach ($concerns as $slug => $c) {
            $out[] = ['slug' => $slug, 'name' => $c['name']];
        }

        return $out;
    }
}

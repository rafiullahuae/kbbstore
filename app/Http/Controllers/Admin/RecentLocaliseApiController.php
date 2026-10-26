<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ConcernSync\ConcernSync;
use App\Services\ConcernSync\ConcernSyncCredentials;
use App\Services\RecentLocalise\RecentConcerns;
use App\Services\RecentLocalise\RecentLocalise;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Catalog -> Translate & categorise. (Lane LT)
 *
 *   GET  admin-api/recent-localise                  key saved?, model, concerns, defaults
 *   GET  admin-api/recent-localise/scan             the scope: what each product lacks, and the estimate
 *   POST admin-api/recent-localise/review           side by side, one page of the scope
 *   POST admin-api/recent-localise/run              one step of the translation run (drafts only)
 *   POST admin-api/recent-localise/retry            forget the rejected list
 *   POST admin-api/recent-localise/save             the owner's inline edit of one field
 *   POST admin-api/recent-localise/approve          publish the drafts of exactly these products
 *   POST admin-api/recent-localise/concerns/suggest skin-concern suggestions for a page of products
 *   POST admin-api/recent-localise/concerns/apply   add the ticked memberships (only adds)
 *
 * Capability `catalog.localise` (owner, manager) for every path under the
 * prefix through AdminCapabilities, which fails closed. The Claude key is the
 * one Catalog -> Shop by Concern stores; it is never sent to the browser.
 */
final class RecentLocaliseApiController extends Controller
{
    public function show(): JsonResponse
    {
        $creds = ConcernSyncCredentials::read();

        return response()->json([
            'has_key' => $creds['key'] !== null,
            'model' => $creds['model'],
            'models' => ConcernSyncCredentials::MODELS,
            'concerns' => self::concernList(ConcernSync::concerns()),
            'defaults' => ['count' => RecentLocalise::DEFAULT_COUNT, 'max' => RecentLocalise::MAX_COUNT],
            'fields' => RecentLocalise::FIELDS,
        ]);
    }

    public function scan(Request $request): JsonResponse
    {
        $ids = self::scopeFrom($request);
        $creds = ConcernSyncCredentials::read();
        $report = RecentLocalise::report($ids);

        return response()->json($report + [
            'ids' => $ids,
            'estimate' => RecentLocalise::estimate(RecentLocalise::pending($ids), $creds['model']),
            'rejected' => count(RecentLocalise::rejected()),
            'has_key' => $creds['key'] !== null,
        ]);
    }

    public function review(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:20'],
            'ids.*' => ['integer', 'min:1'],
        ]);

        return response()->json(['products' => RecentLocalise::review(array_map('intval', $data['ids']))]);
    }

    public function run(Request $request): JsonResponse
    {
        $creds = ConcernSyncCredentials::read();
        if ($creds['key'] === null) {
            return response()->json(['message' => 'No Claude API key is saved. Add it under Catalog → Shop by Concern → Claude API key & model.'], 422);
        }

        @set_time_limit(240);

        return response()->json(RecentLocalise::step(self::scopeFrom($request), $creds['key'], $creds['model']));
    }

    public function retry(): JsonResponse
    {
        RecentLocalise::forgetRejected();

        return response()->json(['ok' => true]);
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'min:1'],
            'field' => ['required', 'string', 'in:'.implode(',', RecentLocalise::FIELDS)],
            'value' => ['present', 'nullable', 'string', 'max:65000'],
        ]);

        $row = RecentLocalise::saveEdit((int) $data['product_id'], $data['field'], (string) ($data['value'] ?? ''));
        if ($row === null) {
            return response()->json(['message' => 'That product no longer exists.'], 404);
        }

        return response()->json(['ok' => true, 'status' => $row->status, 'value' => $row->value]);
    }

    public function approve(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_ids' => ['required', 'array', 'min:1', 'max:'.RecentLocalise::MAX_COUNT],
            'product_ids.*' => ['integer', 'min:1'],
        ]);

        return response()->json(['ok' => true] + RecentLocalise::approve(array_values(array_unique(array_map('intval', $data['product_ids'])))));
    }

    public function suggest(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_ids' => ['required', 'array', 'min:1', 'max:'.RecentConcerns::PAGE],
            'product_ids.*' => ['integer', 'min:1'],
            'rules_only' => ['nullable', 'boolean'],
        ]);

        $concerns = ConcernSync::concerns();
        if ($concerns === []) {
            return response()->json(['message' => 'This shop has none of the Shop by Concern collections yet.'], 422);
        }

        $creds = ConcernSyncCredentials::read();

        return response()->json(RecentConcerns::suggest($data['product_ids'], $concerns, $creds['key'], $creds['model'], ! empty($data['rules_only']))
            + ['concerns' => self::concernList($concerns)]);
    }

    public function applyConcerns(Request $request): JsonResponse
    {
        $concerns = ConcernSync::concerns();

        $data = $request->validate([
            'links' => ['required', 'array', 'min:1', 'max:'.RecentLocalise::MAX_COUNT],
            'links.*.product_id' => ['required', 'integer', 'min:1'],
            'links.*.concerns' => ['required', 'array', 'min:1', 'max:'.count(ConcernSync::CONCERNS)],
            'links.*.concerns.*' => ['required', 'string', 'in:'.implode(',', array_keys($concerns) ?: ['-'])],
        ]);

        $links = [];
        foreach ($data['links'] as $l) {
            $links[(int) $l['product_id']] = array_values(array_unique(array_merge($links[(int) $l['product_id']] ?? [], $l['concerns'])));
        }

        return response()->json(['ok' => true] + RecentConcerns::apply($concerns, $links));
    }

    /** @return list<int> */
    private static function scopeFrom(Request $request): array
    {
        $data = $request->validate([
            'count' => ['nullable', 'integer', 'min:1', 'max:'.RecentLocalise::MAX_COUNT],
            'days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);

        return RecentLocalise::scope((int) ($data['count'] ?? RecentLocalise::DEFAULT_COUNT), isset($data['days']) ? (int) $data['days'] : null);
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

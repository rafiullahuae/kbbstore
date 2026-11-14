<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Translation\ArabicStructureRepair;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Catalog -> Translate & categorise -> Arabic spacing. (Lane ARDX)
 *
 *   GET  admin-api/ar-structure          dry run: what would change, what needs a person, recent batches
 *   POST admin-api/ar-structure/apply    write the repairs (backup first)
 *   POST admin-api/ar-structure/undo     put a batch back
 *
 * Capability `catalog.ar_structure` (owner, manager) for every path under the
 * prefix through AdminCapabilities, which fails closed. Blank lines and list
 * breaks only; no word changes (App\Support\BlockStructure checks it).
 */
final class ArabicStructureApiController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $scan = ArabicStructureRepair::scan(self::scope($request));

        return response()->json([
            'rows' => $scan['rows'],
            'aligned' => $scan['aligned'],
            'untranslated' => $scan['untranslated'],
            // Shapes and reasons only: the copy itself stays on the server.
            'fixable' => array_map(static fn (array $f): array => [
                'product_id' => $f['product_id'], 'slug' => $f['slug'], 'field' => $f['field'],
                'before' => $f['before'], 'after' => $f['after'], 'en' => $f['en'], 'changes' => $f['changes'],
            ], array_slice($scan['fixable'], 0, 300)),
            'fixable_count' => count($scan['fixable']),
            'human' => array_map(static fn (array $h): array => [
                'product_id' => $h['product_id'], 'slug' => $h['slug'], 'field' => $h['field'], 'why' => $h['why'],
            ], array_slice($scan['human'], 0, 300)),
            'human_count' => count($scan['human']),
            'batches' => ArabicStructureRepair::batches(),
        ]);
    }

    public function apply(Request $request): JsonResponse
    {
        try {
            $r = ArabicStructureRepair::apply(self::scope($request));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($r + ['batches' => ArabicStructureRepair::batches()]);
    }

    public function undo(Request $request): JsonResponse
    {
        $data = $request->validate(['batch' => ['required', 'string', 'max:40', 'regex:/^[0-9a-z-]+$/']]);

        return response()->json(ArabicStructureRepair::undo($data['batch']) + ['batches' => ArabicStructureRepair::batches()]);
    }

    /** @return list<int>|null */
    private static function scope(Request $request): ?array
    {
        $recent = (int) $request->input('recent', 0);

        return $recent > 0 ? ArabicStructureRepair::recentIds(min(5000, $recent)) : null;
    }
}

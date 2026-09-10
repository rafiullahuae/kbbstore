<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Store\ShopController;
use App\Services\HomepageLayouts;
use App\Services\HomepageSections;
use App\Support\GridSkins;
use App\Support\Shortcodes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/** Homepage section visibility, order and grid skins. */
class HomepageApiController extends Controller
{
    public function __construct(
        private HomepageSections $sections,
        private HomepageLayouts $layouts,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json([
            'sections' => array_values($this->sections->all()),
            'skins' => collect(GridSkins::ALL)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'layouts' => $this->layouts->summaries(),
            'layout' => $this->layouts->current(),
        ]);
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sections' => ['required', 'array', 'min:1'],
            'sections.*.key' => ['required', 'string', 'max:40'],
            'sections.*.desktop' => ['required', 'boolean'],
            'sections.*.mobile' => ['required', 'boolean'],
            'sections.*.skin' => ['nullable', 'string', 'max:40'],
        ]);

        $payload = [];
        $order = 0;

        foreach ($data['sections'] as $row) {
            if (! isset(HomepageSections::REGISTRY[$row['key']])) {
                return response()->json(['ok' => false, 'error' => "Unknown section: {$row['key']}."], 422);
            }

            $payload[$row['key']] = [
                'desktop' => $row['desktop'],
                'mobile' => $row['mobile'],
                'skin' => $row['skin'] ?? null,
                'order' => $order++,
            ];
        }

        $this->sections->save($payload);

        // The homepage and its rails are cached; without this a change appears
        // only after the TTL and looks as though it did not save.
        Cache::forget('kbb.home.rails');
        Cache::forget('kbb.home.brands');
        Shortcodes::flush();
        ShopController::flushSidebarCache();

        return response()->json([
            'ok' => true,
            'saved' => count($payload),
            'sections' => array_values($this->sections->all()),
        ]);
    }

    /** Apply a layout preset, then hand back the resulting sections. */
    public function applyLayout(Request $request): JsonResponse
    {
        $data = $request->validate(['layout' => ['required', 'string', 'max:40']]);

        if (! $this->layouts->exists($data['layout'])) {
            return response()->json(['ok' => false, 'error' => 'Unknown layout.'], 422);
        }

        $this->layouts->apply($data['layout'], $this->sections);

        Cache::forget('kbb.home.rails');
        Cache::forget('kbb.home.brands');
        Shortcodes::flush();
        ShopController::flushSidebarCache();

        return response()->json([
            'ok' => true,
            'layout' => $this->layouts->current(),
            'sections' => array_values($this->sections->all()),
        ]);
    }
}

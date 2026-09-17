<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Store\ShopController;
use App\Services\HomepageContent;
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
        private HomepageContent $content,
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

    /**
     * Appearance → Homepage content: the words, as opposed to the switches.
     *
     * A sibling endpoint under the SAME prefix rather than a new top-level one,
     * so `['*', 'admin-api/homepage/**', 'content.manage']` — already in
     * AdminCapabilities::RULES — governs it. A new prefix would need a new rule
     * and that map fails closed, which is a screen that 403s on a host with no
     * shell to fix it from.
     */
    public function content(): JsonResponse
    {
        return response()->json($this->content->payload());
    }

    public function saveContent(Request $request): JsonResponse
    {
        $data = $request->validate([
            'slides' => ['present', 'array', 'max:' . HomepageContent::MAX_SLIDES],
            'slides.*' => ['array'],
            'copy' => ['sometimes', 'array'],
        ]);

        $rejected = $this->content->saveSlides($data['slides']);

        $copy = $this->content->saveCopy($data['copy'] ?? []);

        foreach ($copy['rejected'] as $label) {
            $rejected[$label] = 'not a valid value';
        }

        /*
         * The homepage is cached, and so are its rails. Without this the owner
         * saves, reloads the shop, sees the old hero and concludes the screen
         * does not work — which is what the section endpoint above already
         * learned, in the comment beside its own forget() calls.
         */
        Cache::forget('kbb.home.rails');
        Cache::forget('kbb.home.brands');
        Shortcodes::flush();
        ShopController::flushSidebarCache();

        return response()->json([
            'ok' => true,
            'rejected' => $rejected,
            'saved' => count($data['slides']),
        ] + $this->content->payload());
    }
}

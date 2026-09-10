<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Demo content on/off.
 *
 * Demo content is rendered, never stored. This endpoint flips a single setting;
 * it creates and deletes nothing, so a real catalogue cannot be affected either
 * way. That is worth stating plainly in the admin, which the confirmation does.
 */
class DemoApiController extends Controller
{
    public function __construct(private SettingsService $settings) {}

    public function show(): JsonResponse
    {
        return response()->json([
            'enabled' => (bool) $this->settings->get('demo_content', false),
            'note' => 'Demo content is only ever displayed, never saved. Real products, categories, brands, reviews and posts are untouched, and always take priority over demo items.',
        ]);
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);

        $this->settings->set('demo_content', $data['enabled']);

        // The homepage caches its rails; without this the change appears only
        // after the TTL and looks as though it did nothing.
        foreach (['kbb.home.rails', 'kbb.home.brands', 'kbb.home.cats', 'kbb.home.posts',
                  'kbb.home.reviews', 'kbb.home.routine', 'kbb.home.count', 'kbb.home.brandcount'] as $key) {
            Cache::forget($key);
        }

        return response()->json(['ok' => true, 'enabled' => $data['enabled']]);
    }
}

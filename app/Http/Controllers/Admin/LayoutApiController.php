<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Store\ShopController;
use App\Services\SettingsService;
use App\Support\GridSkins;
use App\Support\Shortcodes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Product-grid skin and column settings, plus the shortcode reference. */
class LayoutApiController extends Controller
{
    public function __construct(private SettingsService $settings) {}

    public function show(): JsonResponse
    {
        return response()->json([
            'skins' => collect(GridSkins::ALL)->map(fn ($label, $key) => [
                'key' => $key,
                'label' => $label,
            ])->values(),
            'current' => GridSkins::resolve(),
            'columns' => (int) $this->settings->get('grid_columns', 4),
            'shortcodes' => [
                ['code' => '[kbb_products]', 'desc' => 'Latest 8 products, store skin'],
                ['code' => '[kbb_products limit="4" columns="4"]', 'desc' => 'Set how many and how wide'],
                ['code' => '[kbb_products skin="luxe"]', 'desc' => 'Override the skin for this grid only'],
                ['code' => '[kbb_products category="serums"]', 'desc' => 'One category (slug), comma-separate for more'],
                ['code' => '[kbb_products brand="cosrx"]', 'desc' => 'One brand (slug)'],
                ['code' => '[kbb_products featured="1"]', 'desc' => 'Featured products only'],
                ['code' => '[kbb_products on_sale="1"]', 'desc' => 'Discounted products only'],
                ['code' => '[kbb_products orderby="popularity"]', 'desc' => 'date · popularity · rating · price · price-desc · name · random'],
                ['code' => '[kbb_products ids="12,44,91"]', 'desc' => 'Exactly these products, in that order'],
            ],
        ]);
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate([
            'skin' => ['required', 'string', 'max:40'],
            'columns' => ['required', 'integer', 'between:1,6'],
        ]);

        if (! GridSkins::exists($data['skin'])) {
            return response()->json(['ok' => false, 'error' => 'Unknown skin.'], 422);
        }

        $this->settings->set('grid_skin', $data['skin']);
        $this->settings->set('grid_columns', $data['columns']);

        // Grids are cached; without this the change appears only after the TTL.
        Shortcodes::flush();
        ShopController::flushSidebarCache();

        return response()->json(['ok' => true, 'skin' => $data['skin'], 'columns' => $data['columns']]);
    }
}

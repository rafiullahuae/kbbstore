<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;

class SettingController extends Controller
{
    /**
     * GET /api/settings — public, unauthenticated.
     *
     * This returned Setting::map() in full: every row in the settings table,
     * to anyone who asked. That included `admin_path`, which is the whole
     * point of the admin living at a secret URL, and `indexnow_key`, which
     * lets a third party submit URLs to search engines as this site.
     *
     * Allowlisted rather than denylisted. A denylist protects only the keys
     * someone remembered to add, and this table gains keys whenever a feature
     * ships — gift_enabled and gift_fee arrived while this was being written.
     * An allowlist fails closed: a new setting is private until it is
     * deliberately published.
     *
     * Values below are presentational and already visible on the storefront
     * to anyone who loads a page.
     */
    private const PUBLIC_KEYS = [
        'store_name',
        'currency',
        'vat_rate',
        'products_per_page',
        'grid_skin',
        'grid_columns',
        'free_ship',
        'delivery_flat',
        'cod_fee',
        'gift_enabled',
        'gift_fee',
    ];

    public function index()
    {
        $all = Setting::map();

        $out = [];

        foreach (self::PUBLIC_KEYS as $key) {
            if (array_key_exists($key, $all)) {
                $out[$key] = $all[$key];
            }
        }

        return response()->json($out);
    }
}

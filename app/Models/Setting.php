<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /**
     * All settings as a flat key => value array.
     *
     * Restored. The original model carried this helper and eight places still
     * call it — Seo, PageController, SeoFilesController, CheckoutController,
     * SettingController and AdminController. My Phase 0 rewrite of this model
     * dropped it, so every one of those calls fell through to Eloquent's
     * __callStatic and threw "Call to undefined method Setting::map()". That is
     * what took the storefront down.
     *
     * Two changes from the original, both deliberate:
     *
     *   - The table is `settings`, not `store_settings`. Phase 0 renamed it, and
     *     the model's default table name now resolves correctly.
     *   - Cached for the request. Seo::render() and PageController both call
     *     this on the same page load, and it was two full table reads. (Rule 27)
     */
    public static function map(): array
    {
        static $memo = null;

        if ($memo !== null) {
            return $memo;
        }

        $memo = Cache::remember('kbb.settings.map', 300, static function (): array {
            $out = [];

            foreach (self::query()->get(['key', 'value']) as $row) {
                $out[(string) $row->key] = $row->value;
            }

            return $out;
        });

        return is_array($memo) ? $memo : ($memo = []);
    }

    /** Call after writing a setting, or readers keep the old value for 5 minutes. */
    public static function flushMap(): void
    {
        Cache::forget('kbb.settings.map');
    }
}

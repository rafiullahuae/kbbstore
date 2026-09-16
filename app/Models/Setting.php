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
    /**
     * The per-process half of the memo.
     *
     * A class property rather than a `static` inside map(), so flushMap() can
     * actually reach it. It could not before: flushMap() forgot the cache key
     * and the function-local static kept answering with the value from before
     * the write, for the life of the process. Harmless under PHP-FPM, where a
     * request is a process — but a queue worker that had read a setting once
     * never saw another change to it, and in tests a save followed by a page
     * render returned the pre-save value. That is how ReviewBadgeParityTest
     * found this: it switched the badge heart off, re-rendered, and the heart
     * was still there.
     */
    private static ?array $memo = null;

    public static function map(): array
    {
        if (self::$memo !== null) {
            return self::$memo;
        }

        $loaded = Cache::remember('kbb.settings.map', 300, static function (): array {
            $out = [];

            foreach (self::query()->get(['key', 'value']) as $row) {
                $out[(string) $row->key] = $row->value;
            }

            return $out;
        });

        return self::$memo = is_array($loaded) ? $loaded : [];
    }

    /** Call after writing a setting, or readers keep the old value for 5 minutes. */
    public static function flushMap(): void
    {
        self::$memo = null;

        Cache::forget('kbb.settings.map');
    }
}

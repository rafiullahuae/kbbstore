<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Setting;
use App\Models\Translation;
use App\Services\SettingsService;
use App\Services\Translation\TranslationStore;
use App\Support\Locale;

/**
 * Switch the shop into Arabic, and publish a string into it — Lane FB.
 *
 * ── WHY A CLASS AND NOT TWO MORE GLOBAL TEST FUNCTIONS ──────────────────────
 *
 * Two test files already declare their own copy of this pair as plain
 * functions (ArabicInterfaceStringsTest's `arabicOn`, ShopPhpLabelsAreKeyedTest's
 * `fbArabicOn`), and a third would be the one that breaks: a global declared in
 * one Pest file is only there for another file when the whole suite is loaded,
 * so `vendor/bin/pest tests/Feature/OneFile.php` fatals on an undefined
 * function while the full run is green. That is the worst shape a test helper
 * can have — it works exactly until somebody debugs a single file.
 *
 * A class under Tests\Support is autoloaded, so it is there whichever files the
 * runner was asked for.
 *
 * ── WHAT `on()` HAS TO FLUSH, AND WHY ALL FOUR ──────────────────────────────
 *
 * Arabic is a settings row, and this application memoises settings in three
 * places that do not know about each other: Setting::map()'s process-level
 * static (the trap CLAUDE.md records), SettingsService' own memo and its cache,
 * and TranslationStore's. A test that writes the row and flushes only the cache
 * gets a request that still believes the shop is English, and the failure looks
 * like a missing translation rather than a stale memo.
 */
final class ArabicShop
{
    /** Turn Arabic on, and make every memo notice. */
    public static function on(): void
    {
        Setting::query()->updateOrCreate(
            ['key' => Locale::SETTING_ENABLED],
            ['value' => '1', 'autoload' => true]
        );

        Setting::flushMap();
        SettingsService::forgetMemo();
        app(SettingsService::class)->flush();
        TranslationStore::flush();
    }

    /** One published Arabic string, written the way the admin screen writes it. */
    public static function string(string $key, string $value): void
    {
        TranslationStore::put(
            'ar',
            'ui',
            0,
            $key,
            $value,
            Translation::STATUS_PUBLISHED,
            Translation::SOURCE_MANUAL,
        );
    }
}

<?php

declare(strict_types=1);

use App\Services\PageBanners;
use App\Services\SettingsService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The trust strips. (Lane TS)
 *
 * The owner, choosing H1 and F1 off docs/trust-strip-options: the strip under
 * the homepage banner, the same strip with no box under the Super Sale header
 * and above the footer everywhere else, the thin delivery line at the top of
 * Super Sale — "and remove the previous strip from the super sale page which
 * is below header".
 *
 * TWO DATA CHANGES, BECAUSE HE ASKED FOR THEM (CLAUDE.md rule 1):
 *
 *  1. /super-sale/'s Page banners strip ("100% Authentic Products · Express
 *     Delivery all over UAE …") is switched off on that ONE page: its key
 *     leaves the assignment. The banner itself, every other page's assignment
 *     and every line, colour and size stay; Pages → Page banners or the
 *     page's "Edit header" panel switches it back on.
 *  2. The homepage's old Trust row (Appearance → Homepage → Trust row) is
 *     switched off in a saved layout, because the new strip under the banner
 *     replaces it. It ships off already; this only reaches a shop that turned
 *     it on. Its row, position and words are kept.
 *
 * Then the caches: storefront views changed, so the compiled views go, and the
 * settings caches go so both writes are what the next request reads. No route
 * is added; the route cache goes by the same convention every package follows.
 * Running this twice changes nothing the second time.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sale = $this->saleStripOff();
        $row = $this->oldTrustRowOff();
        $cleared = 0;

        foreach ([
            storage_path('framework/views/*.php'),
            base_path('bootstrap/cache/config.php'),
            base_path('bootstrap/cache/routes-*.php'),
            base_path('bootstrap/cache/services.php'),
            base_path('bootstrap/cache/packages.php'),
        ] as $pattern) {
            foreach (glob($pattern) ?: [] as $file) {
                if (is_file($file) && @unlink($file)) {
                    $cleared++;
                }
            }
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        app(SettingsService::class)->flush();
        SettingsService::forgetMemo();

        if (app()->runningInConsole()) {
            echo "Cleared {$cleared} compiled files.\n";
            echo $sale ? "The Page banners strip is off on /super-sale/; the banner is kept.\n" : "The Page banners strip was already off on /super-sale/.\n";
            echo $row ? "The old homepage Trust row is off; the new trust strip replaces it.\n" : "The old homepage Trust row was already off.\n";
            echo "The trust strips are on: Appearance → Homepage content → Trust strip.\n";
        }
    }

    /** Was /super-sale/'s strip on? */
    private function saleStripOff(): bool
    {
        $raw = DB::table('settings')->where('key', PageBanners::KEY)->value('value');
        $all = is_string($raw) ? json_decode($raw, true) : null;

        if (! is_array($all) || ! isset($all['assign']['collection:super-sale'])) {
            return false;
        }

        unset($all['assign']['collection:super-sale']);
        app(SettingsService::class)->set(PageBanners::KEY, $all);

        return true;
    }

    /** Was the old Trust row on in a saved homepage layout? */
    private function oldTrustRowOff(): bool
    {
        $raw = DB::table('settings')->where('key', 'homepage_sections')->value('value');
        $saved = is_string($raw) ? json_decode($raw, true) : null;
        $row = is_array($saved) ? ($saved['trust'] ?? null) : null;

        if (! is_array($row) || (empty($row['desktop']) && empty($row['mobile']))) {
            return false;
        }

        $saved['trust']['desktop'] = false;
        $saved['trust']['mobile'] = false;
        app(SettingsService::class)->set('homepage_sections', $saved);

        return true;
    }

    public function down(): void {}
};

<?php

declare(strict_types=1);

use App\Services\PageBanners;
use App\Services\SettingsService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The strip off on every page, and the top of a custom page ordered and
 * spaced from the "Edit header" panel. (Lane SP3)
 *
 * The owner: "turned off the strip by default on all pages, and allow to turn
 * ON on any page from the edit panel on the front-end, also give facilty to
 * sort header area and strip. by drag n drop up down. also remove any space
 * between header area and main site header. and give option to control to
 * spacings."
 *
 * ONE DATA CHANGE, BECAUSE HE ASKED FOR IT (CLAUDE.md rule 1, 30 September).
 * Pages → Page banners keeps a library of banners and an assignment of page →
 * banner; the assignment is each page's "show the strip". A shop that has
 * SAVED its banners carries its own assignment — /super-sale/ on, as Lane SS
 * shipped it — which the new empty default cannot reach. So the stored
 * assignment is emptied. EVERY BANNER STAYS: its lines, colours, sizes and
 * picture are untouched, ready for the panel's "Show the strip" to put back
 * on any page. A shop that never saved has nothing stored and gets the new
 * default from the code. Running this twice changes nothing the second time.
 *
 * Then the caches: three storefront views changed, so the compiled views go,
 * and the settings caches go so the emptied assignment is what the next
 * request reads. No route is added (the panel saves through the existing
 * /admin-api/page-header/apply), but the route cache goes too, by the same
 * convention every package here follows.
 */
return new class extends Migration
{
    public function up(): void
    {
        $off = $this->stripOff();
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

        SettingsService::forgetMemo();

        if (app()->runningInConsole()) {
            echo "Cleared {$cleared} compiled files.\n";
            echo $off > 0 ? "The strip is off on {$off} page(s); every banner is kept.\n" : "The strip was already off everywhere.\n";
        }
    }

    /** How many pages had the strip on. */
    private function stripOff(): int
    {
        $raw = DB::table('settings')->where('key', PageBanners::KEY)->value('value');
        $all = is_string($raw) ? json_decode($raw, true) : null;

        if (! is_array($all) || ! is_array($all['assign'] ?? null) || $all['assign'] === []) {
            return 0;
        }

        $n = count($all['assign']);
        $all['assign'] = [];
        app(SettingsService::class)->set(PageBanners::KEY, $all);

        return $n;
    }

    public function down(): void {}
};

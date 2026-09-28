<?php

declare(strict_types=1);

use App\Support\UrlScheme;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Point the stored navigation at the addresses the scheme serves.
 *
 * =============================================================================
 * WHY A MIGRATION AND NOT A CODE CHANGE
 * =============================================================================
 *
 * The header and the mobile drawer are `menu_items` ROWS, seeded by
 * 2026_09_09_040000_seed_kbeautybliss_menu and
 * ..._070000_fix_kbeautybliss_menu_structure, and both seed the brand node as
 * `/korean-skincare-brands/`. Moving the route without moving the row leaves
 * the shop's own primary navigation pointing at an address it 301s away from —
 * every visitor who clicks "Brands" pays a redirect, on every page, for ever,
 * and the shop advertises the retired address to every crawler that reads the
 * header.
 *
 * 2026_11_07_000000_repoint_menu_category_urls is the precedent: the same table,
 * the same reasoning, the last time a menu URL shape moved.
 *
 * =============================================================================
 * MATCHED ON THE EXACT STORED STRING, AND ON A PREFIX ONLY WHERE IT IS SAFE
 * =============================================================================
 *
 * A row the owner has edited in Store -> Mega Menu does not hold the seeded
 * value any more and is left alone — the same rule the category migration
 * states. The prefix rewrites below are exact about their prefix and keep
 * everything after it, so `/product-category/skincare/toners/` becomes
 * `/collections/skincare/toners/` and a hand-written query string survives.
 *
 * NOTHING ELSE IN THE ROW IS TOUCHED. Labels, ordering, parents and the
 * display flags are the owner's.
 *
 * =============================================================================
 * IDEMPOTENT
 * =============================================================================
 *
 * Every match is against the OLD spelling, so a second run finds nothing. The
 * new spellings share no prefix with the old ones, which is what makes that
 * true rather than merely likely.
 *
 * down() is empty: the reverse is a navigation full of redirects.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('menu_items')) {
            return;
        }

        $moved = 0;

        // Exact addresses first. The brand directory is the one the seeds wrote.
        foreach ([
            UrlScheme::LEGACY_BRAND_INDEX => UrlScheme::BRAND_BASE,
            UrlScheme::LEGACY_BLOG_INDEX => UrlScheme::BLOG_BASE,
        ] as $from => $to) {
            foreach ([$from, rtrim($from, '/')] as $spelling) {
                $moved += DB::table('menu_items')->where('url', $spelling)->update(['url' => $to]);
            }
        }

        // Then the two prefixes, keeping whatever follows them.
        $moved += $this->movePrefix(UrlScheme::LEGACY_COLLECTION_BASE, UrlScheme::COLLECTION_BASE);
        $moved += $this->movePrefix(UrlScheme::LEGACY_BRAND_INDEX, UrlScheme::BRAND_BASE);
        $moved += $this->movePrefix(UrlScheme::LEGACY_BRAND_BASE, UrlScheme::BRAND_BASE);

        // The menu tree is cached per slot; without this the header would go on
        // serving the old URLs after the update finishes.
        try {
            app(\App\Services\NavigationService::class)->flush();
        } catch (\Throwable) {
            // Never block the data fix on a cache driver problem.
        }

        if (app()->runningInConsole()) {
            echo "Address scheme: repointed {$moved} menu item(s) onto /collections/, /brands/ and /blog/.\n";
        }
    }

    public function down(): void {}

    /**
     * Per row rather than a SQL string function: REPLACE and SUBSTRING are
     * spelled differently on SQLite and MySQL and this shop runs both, and a
     * menu is tens of rows.
     */
    private function movePrefix(string $from, string $to): int
    {
        $moved = 0;

        foreach (DB::table('menu_items')->where('url', 'like', $from.'%')->get(['id', 'url']) as $row) {
            $url = $to.substr((string) $row->url, strlen($from));

            $moved += DB::table('menu_items')->where('id', $row->id)->update(['url' => $url]);
        }

        return $moved;
    }
};

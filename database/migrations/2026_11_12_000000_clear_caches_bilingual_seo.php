<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Drop the compiled views, because this package moves the hreflang block out of
 * a Blade template (Lane EY).
 *
 * NO NEW ROUTES. /sitemap.xml, /robots.txt and /llms.txt already exist and
 * their behaviour changed inside the controller, so the compiled route table is
 * correct as it stands and nothing here needs it gone. The rule in CLAUDE.md
 * is about routes that did not exist before; this is the other half of the same
 * problem and it is worse, not milder.
 *
 * WHY THE COMPILED VIEWS MUST GO, SPECIFICALLY. Blade names a compiled file by
 * a hash of the view's PATH, never its contents, and the compiled copy is
 * served until something deletes it. layouts/store.blade.php used to emit the
 * hreflang links itself; App\Support\Seo now emits them. If the stale compiled
 * layout survives this update, the two run together and every page that uses
 * that layout publishes EACH hreflang TWICE — and the duplicate pair is not
 * harmless, because the layout's copy was built from the request path while
 * Seo's is built from the canonical, so on a paginated listing and on any page
 * with a `seo.canonical` override the two disagree. Google is then handed two
 * contradictory answers to "what is the Arabic version of this page", which is
 * a worse state than the one this package fixes.
 *
 * The shop is unaffected by any of it while Arabic is off, which is how it
 * ships: Locale::alternatePaths() returns an empty set with one language live,
 * so neither copy emits anything.
 *
 * NO SCHEMA CHANGE AND NOTHING SEEDED. This package changes what is said about
 * URLs that already exist; it creates no table, no column and no setting.
 *
 * bootstrap/cache/config.php goes with them only because a package that lands
 * new code while a compiled container describes the old one is the failure this
 * whole convention exists for, and deleting it costs one rebuild on the next
 * request.
 *
 * Best-effort throughout, like every other clear_caches migration in this set:
 * a file that cannot be unlinked mid-update must not fail the package and
 * strand the site half-updated. A stale cache is a visible bug; a failed
 * migration is an outage.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cleared = 0;

        foreach ([
            storage_path('framework/views/*.php'),
            base_path('bootstrap/cache/config.php'),
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

        if (app()->runningInConsole()) {
            echo "Cleared {$cleared} compiled files; hreflang is emitted by App\\Support\\Seo now,\n";
            echo "so a stale compiled layout would print every alternate twice.\n";
        }
    }

    public function down(): void {}
};

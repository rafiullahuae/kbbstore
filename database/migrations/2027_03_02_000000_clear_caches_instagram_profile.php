<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Drop the compiled routes and views for Instagram Profile (Lane IG, Phase 21).
 *
 * NOT OPTIONAL, and this package needs BOTH halves of the clear rather than the
 * views-only one that most admin changes ship:
 *
 *   THE ROUTE TABLE. `routes/instagram-admin.php` registers seven new paths under
 *   `admin-api`, and a route added to the source does not exist until the compiled
 *   route table is rebuilt (CLAUDE.md). Without this, Content → Instagram draws
 *   perfectly and every one of its calls answers 404 — including the callback
 *   Instagram redirects to, which fails AFTER the owner has granted access, so he
 *   would have authorised a shop that cannot receive the authorisation. The screen
 *   names this case by hand if it sees a 404 from its own endpoint, because the
 *   symptom otherwise reads as "the feature does not work".
 *
 *   THE COMPILED VIEWS. Three storefront templates and one admin partial change:
 *   resources/views/store/home.blade.php grows the two new homepage sections,
 *   resources/views/instagram/{section,assets}.blade.php are new, and
 *   resources/views/admin/partials/instagram-screen.blade.php is the screen itself.
 *   `storage/framework/views` caches a compiled view by the PATH of its source and
 *   decides staleness by comparing file times — and an unzip's timestamps are not
 *   reliably newer than what is already on disk, which is how a shop ends up holding
 *   a compiled copy of a template the package replaced.
 *
 * ── AND IT MOVES NOTHING A SHOPPER SEES ─────────────────────────────────────
 *
 * Worth saying because clearing the config cache reads as though it might. Every
 * new setting this package adds ships at the value the page already has:
 * `instagram_profile` is OFF in ModuleRegistry, the video rail's `home_section`
 * ships as the empty string, and both new homepage sections render NO BYTES until
 * the owner configures them — the `<section>` element itself is inside the content
 * check, not around it. StorefrontEnglishUnchangedTest is the instrument and it does
 * not move on this package.
 *
 * `config.php` is cleared with the rest because `.env` is not read at all while a
 * config cache exists, and an owner who has just been told to check a credential
 * would otherwise be editing a file nothing reads.
 */
return new class extends Migration
{
    public function up(): void
    {
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

        if (app()->runningInConsole()) {
            echo "Cleared {$cleared} compiled files. Content -> Instagram is reachable now, and the\n"
                ."homepage has two new rows on Appearance -> Homepage: Video rail and Instagram\n"
                ."Profile. BOTH DRAW NOTHING YET -- the video rail until a section is picked on\n"
                ."Content -> Shoppable video -> Appearance -> Homepage, and Instagram until the\n"
                ."account is connected on Content -> Instagram and the module is switched on at\n"
                ."Store -> Modules -> Instagram Profile. Nothing on the storefront moved.\n";
        }
    }

    /** Clearing a cache is not a state change; the next request rebuilds it. */
    public function down(): void {}
};

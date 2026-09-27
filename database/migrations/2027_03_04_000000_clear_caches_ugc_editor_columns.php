<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Drop the compiled views for the two-column clip editor (Lane V5).
 *
 * ── WHY THE VIEW CACHE MUST GO, AND WHY THE ROUTE TABLE NEED NOT ────────────
 *
 * THE COMPILED VIEWS. One file changes, and it is a Blade partial:
 * resources/views/admin/partials/ugc-library-screen.blade.php — the whole of
 * Content → Shoppable video → All clips, including the add-a-clip flow.
 * `storage/framework/views` caches a compiled view by the PATH of its source and
 * decides staleness by comparing file times, and an unzip's timestamps are not
 * reliably newer than what is already on disk. That is how a shop ends up serving
 * a compiled copy of a partial the package replaced — here, the OLD single-column
 * editor with the old upload bar, from a package that shipped the new one.
 *
 * This partial is pulled into resources/views/admin/app.blade.php, so the stale
 * copy that matters is app.blade.php's: a compiled parent holds the compiled
 * child's output. Clearing the whole directory is the only reliable answer, and
 * is what every other admin-screen package here does.
 *
 * NO ROUTE IS ADDED BY THIS PACKAGE, so the route table is not the reason for the
 * clear — but `bootstrap/cache/routes-*.php` goes with the rest anyway, because a
 * stale route cache costs a rebuild and nothing else, and leaving one behind is
 * how the next package's route quietly does not exist. The endpoint the new
 * "Cut the cover and teaser from the video" button posts to,
 * `POST admin-api/ugc-videos/{id}/derive`, has been in routes/ugc-admin.php since
 * Lane V2 and is already live; this package only moves the button that calls it.
 *
 * ── AND IT MOVES NOTHING, ON THE SHOP OR IN THE CONSOLE ─────────────────────
 *
 * Worth saying plainly because clearing the config cache reads as though it might.
 * This package adds NO setting, changes no default and writes to no table. It is a
 * layout change to one admin screen:
 *
 *   - every step panel is two columns from 900px and one below it,
 *   - each group is a titled section instead of an uppercase label,
 *   - the upload bar gained the bytes, the stage and both endings,
 *   - the cut button moved out of the cover box into its own section under the
 *     two file boxes, where an upload leaves the owner's eye.
 *
 * Every field keeps its name, its id and its data-ugs-field attribute, so what the
 * screen sends is byte-identical to what it sent before.
 * StorefrontEnglishUnchangedTest is the instrument and it does not move on this
 * package — nothing on the storefront is touched at all.
 *
 * `config.php` is cleared with the rest for the reason the Instagram migration
 * beside this one gives: `.env` is not read at all while a config cache exists, so
 * an owner told to check `KBB_FFMPEG` would otherwise be editing a file nothing
 * reads. That matters on exactly this package, because whether the new cut section
 * offers a button or explains what to do instead is read off that answer.
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
            echo "Cleared {$cleared} compiled files. Content -> Shoppable video -> All clips now\n"
                ."lays every step of the add-a-clip flow out in TWO COLUMNS on a desktop and one\n"
                ."on a phone, with a titled section over each group instead of a small uppercase\n"
                ."label. The upload bar shows the megabytes sent as well as the percentage, says\n"
                ."when the file has all arrived and the server is working on it, and STAYS on\n"
                ."screen afterwards -- with a tick, or with the reason it was refused. And the\n"
                ."'Cut the cover and teaser from the video' button has moved out of the bottom of\n"
                ."the cover box into its own section directly under the two file boxes, so\n"
                ."finishing an upload offers the cut instead of leaving it to be found. On a\n"
                ."server with no ffmpeg that same section says what to do instead. No setting\n"
                ."changed, no default moved, and nothing on the storefront was touched.\n";
        }
    }

    /** Clearing a cache is not a state change; the next request rebuilds it. */
    public function down(): void {}
};

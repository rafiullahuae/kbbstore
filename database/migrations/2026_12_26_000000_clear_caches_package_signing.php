<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for Ed25519 package signing, and for the fallback
 * update page that has never worked.
 *
 * CONFIG, WHICH IS CACHED. config/kbb.php gains `update_signing` and
 * `update_public_keys`, and bootstrap/cache/config.php is read in preference to
 * the source. Without this clear the shop keeps the config it compiled before
 * the package landed, which means the signing mode the Core Updates screen
 * prints is not the one the verifier is using -- and a screen that disagrees
 * with the code is worse than no screen.
 *
 * A CHANGED ROUTE CLOSURE. routes/web.php's /updates closure now passes
 * $request into UpdateController::index(). The router dispatches against
 * bootstrap/cache/routes-*.php, so without this clear the old closure keeps
 * running and the fix does not exist on the server.
 *
 * ▲ THAT FIX IS THE POINT OF THIS PARAGRAPH. `index()` has been typed
 * `index(Request $request)` since the baseline commit of the updater, and the
 * closure has called it with no argument for just as long. `/admin/updates
 * ?fallback=1` is the standalone page that exists precisely FOR the case where
 * the admin bundle will not load -- and it has answered 500 with an
 * ArgumentCountError its entire life. Nobody found it because nobody loads a
 * fallback page until the day they need it, and on that day they conclude the
 * server is dead rather than the page. It was found by a lane photographing a
 * banner on it.
 *
 * NOTHING IS ENFORCED BY THIS PACKAGE. Signing ships in `permissive` mode with
 * an EMPTY trusted-key list: a signature that is present is checked, a package
 * with no signature is accepted exactly as today, and every package this
 * project has ever built carries `"signature": ""`. The Core Updates header
 * still reads "unsigned packages accepted", byte for byte.
 *
 * The one behaviour change for a package that exists today: one carrying a
 * signature this server cannot check is refused rather than ignored. No real
 * package carries one.
 *
 * TURNING IT ON IS A SIX-STEP SEQUENCE AND THE STEPS MAY NOT BE MERGED --
 * docs/PACKAGE-SIGNING.md §1. The package that teaches a shop to REQUIRE a
 * signature is applied by the verifier that came before it, so the shop must be
 * able to check a signature before it can be told to demand one, and must have
 * been SEEN to check one before that demand is safe. That is the same
 * new-code/old-data/no-way-in shape that bricked this shop's updater on
 * 24 September; the sequence exists so it cannot happen again.
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
            echo "Cleared {$cleared} compiled files; packages can now carry an Ed25519 signature\n"
                ."and this shop can check one. NOTHING IS ENFORCED -- unsigned packages are still\n"
                ."accepted exactly as before. docs/PACKAGE-SIGNING.md has the six-step sequence for\n"
                ."turning it on. Also: /admin/updates?fallback=1 renders instead of 500ing, which\n"
                ."it had done since the updater was built.\n";
        }
    }

    public function down(): void {}
};

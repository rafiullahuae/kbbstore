<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Clear compiled caches for the indexable storefront images and the new <h1>s.
 *
 * WHAT CHANGED AND WHY EACH CACHE MATTERS HERE.
 *
 * Five Blade templates changed — store/home, store/blog, store/post,
 * store/collection and store/skin-quiz. Compiled Blade is the cache that
 * actually decides what this package does: every <img> and every <h1> added by
 * this change lives in a template, and until storage/framework/views is
 * cleared the server keeps rendering the compiled copy it already has. The
 * package would land, change the files, and change nothing on the site.
 *
 * OPcache for the usual reason recorded in CLAUDE.md: this host cannot be
 * shelled into or restarted, so a written .php file is not the PHP the server
 * runs until OPcache lets go of the old bytecode. App\Support\CoverImage is a
 * NEW class, and Store\PageController now calls it; a stale controller paired
 * with a present-but-unloaded helper is the shape that 500s a page.
 *
 * The home page's own data caches are flushed too, and that one is not
 * defensive. The journal rail on the home page used to read `$post->image`, a
 * column that does not exist on the posts table, and now reads `cover`. The
 * rail's posts are cached for fifteen minutes under kbb.home.posts as
 * serialised models. Those cached models were built by a `get()` that selects
 * every column, so they do carry `cover` and the rail would recover on its
 * own — but "would recover on its own within fifteen minutes" is the kind of
 * thing that is true until the day the query gains a select(), and a cache
 * forget costs nothing.
 *
 * There is no route change in this package, so the route cache is correct
 * either way; it is cleared with the rest because a half-cleared bootstrap
 * cache is harder to reason about than an empty one.
 *
 * No schema change, and nothing here positions a column with an AFTER clause.
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

        /*
         * Wrapped: on a fresh install this runs before the cache table exists,
         * and a migration that dies here takes the rest of the set with it.
         */
        try {
            foreach (['kbb.home.posts', 'kbb.home.rails', 'kbb.home.cats'] as $key) {
                Cache::forget($key);
            }
        } catch (\Throwable $e) {
            // A cache that cannot be reached holds nothing worth clearing.
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        if (app()->runningInConsole()) {
            echo "Cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void {}
};

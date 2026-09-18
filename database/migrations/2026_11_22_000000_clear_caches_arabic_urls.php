<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * Clear the compiled caches so /ar starts working.
 *
 * WHAT CHANGED IS A PROVIDER, NOT A ROUTE, and that is exactly why this is
 * needed rather than exactly why it is not. AppServiceProvider now prepends
 * SetLocaleFromPath to the global middleware stack, which is the registration
 * that makes /ar resolve at all. Three compiled artefacts can hide it:
 *
 *   bootstrap/cache/services.php   the resolved provider manifest
 *   bootstrap/cache/packages.php   its discovery half
 *   opcache                        the class file itself, still cached from the
 *                                  version without the prepend
 *
 * An unzip does not reliably land a newer mtime than the file it replaces, so
 * on a host with no shell the owner can apply this package, switch Arabic on,
 * and still get 404 on /ar -- with the fix apparently applied. That is the
 * failure this migration exists to prevent, and it is the second time this
 * feature has presented as "I enabled Arabic and /ar still 404s".
 *
 * The compiled views go too: the storefront templates read Locale::current()
 * to build every link, so a view compiled while the shop was English-only
 * would keep printing English URLs on an Arabic page.
 *
 * Best-effort, like every clear_caches migration here: a file that cannot be
 * unlinked mid-update must not fail the package and strand the site
 * half-updated. A stale cache is a visible bug; a failed migration is an
 * outage.
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
            echo "Cleared {$cleared} compiled files; /ar now resolves without the\n";
            echo "hand-edit to bootstrap/app.php that no package could ever make.\n";
        }
    }

    public function down(): void {}
};

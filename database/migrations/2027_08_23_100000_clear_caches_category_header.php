<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * The category page's "Edit header" panel and its custom header area. (Lane CH)
 *
 * Adds admin routes (routes/category-header-admin.php), so the compiled route
 * cache has to go, as CLAUDE.md requires of every package that adds one, and
 * changes two storefront views (store/shop, components/kbb-title-header) and
 * adds a third, so the compiled views go too.
 *
 * WRITES NO DATA. Every category page draws what it drew before until the
 * owner edits one: the custom header area is off on every category, and the
 * new per-category numbers, phone picture and laptop crop are absent, which
 * means "follow the shop setting".
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
            echo "Cleared {$cleared} compiled files.\n";
            echo "The category page's Edit header panel is ready.\n";
        }
    }

    public function down(): void {}
};

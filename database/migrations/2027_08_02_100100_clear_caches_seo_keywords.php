<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * SEO → Keywords (Lane KW) adds routes/seo-keywords-admin.php, which does
 * nothing until the compiled route table is dropped, and changes three
 * storefront templates (shop, brands) and App\Support\Seo, so the compiled
 * views go too. Same list every route-adding package ships.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cleared = 0;

        foreach ([
            base_path('bootstrap/cache/routes-v7.php'),
            base_path('bootstrap/cache/routes.php'),
            base_path('bootstrap/cache/config.php'),
            storage_path('framework/views/*.php'),
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
            echo "Cleared {$cleared} compiled files; Store → SEO Keywords is now reachable.\n";
        }
    }

    public function down(): void {}
};

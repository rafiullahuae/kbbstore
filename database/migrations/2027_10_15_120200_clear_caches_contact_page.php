<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * Lane CT: two route files reach routes/web.php with this package —
 * routes/contact-form.php (POST /contact-us/send) and
 * routes/contact-inquiries-admin.php (Store → Inquiries) — and a route does
 * nothing until the compiled route cache is dropped. The compiled views go too,
 * so the contact page and the console draw from the new templates, and the
 * cached role table, so the two new capabilities reach every role on the first
 * request.
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
            base_path('bootstrap/cache/events.php'),
            base_path('bootstrap/cache/services.php'),
            base_path('bootstrap/cache/packages.php'),
        ] as $pattern) {
            foreach (glob($pattern) ?: [] as $file) {
                if (is_file($file) && @unlink($file)) {
                    $cleared++;
                }
            }
        }

        try {
            \Illuminate\Support\Facades\Cache::forget(\App\Support\AdminRoles::CACHE_KEY);
        } catch (\Throwable) {
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

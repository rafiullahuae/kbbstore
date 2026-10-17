<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * Lane BIO adds five routes to routes/owner-app.php (Face ID / fingerprint).
 * A compiled route table keeps serving the old shape until it is cleared
 * (CLAUDE.md, "How this ships"), so the compiled files go, as with every
 * package that adds a route. Literal only: no application class is loaded.
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
        }
    }

    public function down(): void {}
};

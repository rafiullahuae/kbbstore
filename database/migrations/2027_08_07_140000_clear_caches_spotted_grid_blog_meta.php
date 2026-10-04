<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled views for Lane HS: the homepage Blog cards lose the reading
 * time and the category tag (Appearance → Homepage content → Blog → Reading
 * time / Category tag, both off at the owner's request), and the homepage
 * #KBeautyBliss Spotted section draws a static grid of six pictures
 * (Appearance → #KBeautyBliss Spotted → Homepage grid). Both are template
 * changes, which a stale compiled view would hide. No setting is written: the
 * new defaults are the schemas' own.
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
            echo "Homepage: Blog cards without reading time and tag; #KBeautyBliss Spotted as a six-picture grid.\n";
        }
    }

    public function down(): void {}
};

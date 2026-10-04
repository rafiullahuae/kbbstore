<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear the caches for the compact brand header and the logo ring. (Lane BH)
 *
 * The owner: "logo + name in one row, then description in another, that's it
 * ... same less heighted banner in mobile as like on desktop", and a logo
 * "auto circled with outer brand color border" taken from the logo itself.
 * Both ship ON because he asked (Appearance → Site layout → Brand page →
 * Brand header style = Compact, Ring round the brand logo = on); the brand
 * page template changed, so compiled Blade and opcache go. No route is added.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cleared = 0;

        foreach ([
            storage_path('framework/views/*.php'),
            base_path('bootstrap/cache/config.php'),
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
            echo "Brand pages: the logo and the name in one row, the description under them, on phones too; the logo ringed in the brand's colour (Appearance -> Site layout -> Brand page).\n";
        }
    }

    public function down(): void {}
};

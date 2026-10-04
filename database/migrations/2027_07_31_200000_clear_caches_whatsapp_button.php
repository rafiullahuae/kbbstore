<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for Appearance → WhatsApp button.               (Lane WA)
 *
 * ── WHY THIS IS OWED, THREE TIMES OVER ──────────────────────────────────────
 *
 * 1. A NEW ROUTE FILE. routes/whatsapp-button-admin.php is required into the
 *    `admin-api` group by routes/web.php in the same package. A route added by
 *    a package does nothing until the compiled route table is gone: the screen
 *    would draw and every control on it would 404.
 *
 * 2. CHANGED BLADES. layouts/store.blade.php and the four standalone
 *    storefront documents (store/blog, store/post, store/review-wall,
 *    store/skin-quiz) each gain one @include of partials/whatsapp-button, and
 *    admin/app.blade.php gains the screen. A compiled copy is keyed by PATH
 *    and freshness is a filemtime compare an unzip does not reliably win, so a
 *    stale compiled layout would leave the button off the shop with nothing
 *    logged.
 *
 * 3. THE CONFIG CACHE, because App\Support\AdminCapabilities gains
 *    `wabutton.manage` and a RULES row for `admin-api/whatsapp-button`. That
 *    map fails closed, so a stale compiled bootstrap answers 403 on the new
 *    screen.
 *
 * ── WHAT THE SHOP DOES WHEN THIS IS APPLIED ────────────────────────────────
 *
 * The button APPEARS, bottom right, design G "Orbit team" at 60px, with the
 * welcome bubble shown once per visitor — because the owner asked for it and
 * CLAUDE.md rule 1 (30 September) ships what he asked for ON. Nothing else on
 * any page moves. Every control to change or remove it is on the new screen.
 *
 * NO SETTING ROWS ARE WRITTEN and there is no schema change: the shipped
 * values are read from WhatsAppButton::SCHEMA while nothing is stored.
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
            echo "The floating WhatsApp button is on the shop: design G (Orbit team), bottom right, 60px, with the welcome bubble shown once per visitor. Change or remove it at Appearance → WhatsApp button.\n";
        }
    }

    public function down(): void {}
};

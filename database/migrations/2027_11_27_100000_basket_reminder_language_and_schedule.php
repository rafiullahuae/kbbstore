<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Basket reminders in the basket's language, and two new admin endpoints
 * (the owner, 23 October 2026: "if the cart was abandoned in eng, english
 * email will go, if in arabic, then arabic email will go").
 *
 * 1. A reminder still WAITING for an address typed at checkout was written
 *    with the language of /api/checkout-contact, which has no /ar, so it read
 *    English for everybody. Where the basket's last page was Arabic
 *    (carts.ct_seen_path under /ar), it is set to Arabic now. Sent ones are
 *    left as history.
 * 2. GET cart-tracking/reminders/preview and POST cart-tracking/reminders/
 *    schedule are new routes, so the compiled route table is cleared
 *    (CLAUDE.md, "How this ships"), with compiled views and config.
 */
return new class extends Migration
{
    public function up(): void
    {
        $fixed = 0;

        if (Schema::hasTable('cart_recoveries') && Schema::hasColumn('cart_recoveries', 'locale')
            && Schema::hasColumn('carts', 'ct_seen_path')) {
            $fixed = DB::table('cart_recoveries')
                ->where('source', 'checkout_email')
                ->where('stage', 0)
                ->whereNull('cancelled_at')
                ->where(fn ($w) => $w->whereNull('locale')->orWhere('locale', '!=', 'ar'))
                ->whereIn('cart_id', DB::table('carts')->select('id')
                    ->where(fn ($w) => $w->where('ct_seen_path', '/ar')->orWhere('ct_seen_path', 'like', '/ar/%')))
                ->update(['locale' => 'ar']);
        }

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

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            echo "Basket reminders: {$fixed} waiting reminder(s) set to Arabic; cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void {}
};

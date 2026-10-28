<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * NO "ONLY N LEFT" ON THE PRODUCT PAGE (the owner, 28 October 2026, pointing at
 * "Only 1 left · order soon": "i don't want to show the quantity, just it
 * should say as like other products. In stock, ready to ship.").
 *
 * DEFAULT MOVED, because he asked: `low_stock_at` -> 0, which the setting's own
 * help defines as "Set 0 to never show it" (product.blade.php: `$lowAt > 0`).
 * Every in-stock product then reads "In stock · ready to ship"; sold out is
 * unchanged. The control stays where it was -- Ecommerce -> General -> Store
 * basics -> Low stock threshold -- so he can bring the note back.
 *
 * Only the product page reads this key; the owner app's low-stock alerts have
 * their own setting (OwnerAppSettings) and do not move. Written whether or not
 * the row exists, because an absent row means the code default of 5.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        DB::table('settings')->updateOrInsert(
            ['key' => 'low_stock_at'],
            ['value' => '0', 'updated_at' => now(), 'created_at' => now()],
        );

        try {
            foreach (['kbb.settings', 'kbb.settings.map'] as $key) {
                Cache::forget($key);
            }
        } catch (\Throwable) {
            // A cache that cannot forget expires on its own.
        }
    }

    public function down(): void {}
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Free gifts: MAIN and BACKUP gifts per range (the owner, 23 October 2026):
 *
 *   "i should be able to add multiple masks, minis, and full size products,
 *    as primary and back-log ... the back log will only trigger if the
 *    primary products get out of stock by any reason, so the system will pick
 *    any from the back log as per the quantity available ... first the 3 qnty
 *    product from the back log"
 *
 * One column on the range's gift rows: 'primary' (every row that exists today,
 * so nothing a shopper gets moves on applying this) or 'backup'.
 * App\Services\FreeGifts::linesFor() reads it. The compiled copy the shop
 * reads is rebuilt on the next save; it is also cleared here so the shop
 * picks the column up at once.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('free_gift_range_items') && ! Schema::hasColumn('free_gift_range_items', 'role')) {
            Schema::table('free_gift_range_items', function (Blueprint $t) {
                $t->string('role', 8)->default('primary');
            });
        }

        if (Schema::hasTable('settings')) {
            \Illuminate\Support\Facades\DB::table('settings')->where('key', 'free_gifts_compiled')->delete();
            try {
                \Illuminate\Support\Facades\Cache::forget('kbb.settings');
                \Illuminate\Support\Facades\Cache::forget('kbb.settings.map');
            } catch (\Throwable) {
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('free_gift_range_items') && Schema::hasColumn('free_gift_range_items', 'role')) {
            Schema::table('free_gift_range_items', fn (Blueprint $t) => $t->dropColumn('role'));
        }
    }
};

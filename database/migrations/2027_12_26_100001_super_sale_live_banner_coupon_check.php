<?php

declare(strict_types=1);

use App\Support\SuperSaleBanner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Lane SSB: the live Super Sale banner says "COUPON CODE: GLOW" as text, and a
 * public page must never offer a code this shop has not got
 * (PublicPagesQuoteRealPricesTest). The check runs HERE and on every save in
 * Pages -> Page banners -> Super Sale banner, never on the page, so
 * /super-sale/ spends no query on it.
 *
 * Where `coupons` holds GLOW (the live shop runs it: it is printed on the
 * banner picture the page shows today), nothing is written and the banner
 * ships as approved. Where it does not, and he has saved no banner of his own,
 * the banner ships with the coupon line off (code '') -- he types it back in
 * once the coupon exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        try {
            if (! Schema::hasTable('settings') || ! Schema::hasTable('coupons')) {
                return;
            }

            if (DB::table('settings')->where('key', SuperSaleBanner::KEY)->exists()) {
                return;
            }

            $code = (string) SuperSaleBanner::defaults()['code'];

            if (DB::table('coupons')->whereRaw('LOWER(code) = ?', [mb_strtolower($code)])->exists()) {
                return;
            }

            DB::table('settings')->insert([
                'key' => SuperSaleBanner::KEY,
                'value' => json_encode(['code' => ''] + SuperSaleBanner::defaults(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'autoload' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach (['kbb.settings', 'kbb.settings.map'] as $cacheKey) {
                try {
                    Cache::forget($cacheKey);
                } catch (\Throwable) {
                    // A cache that cannot forget expires on its own.
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function down(): void {}
};

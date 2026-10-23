<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Store -> SEO & Meta -> Rich product results, set as the owner asked (23
 * October 2026): "over 199 free delivery, below 20 aed delivery fee all over
 * UAE" -- so every product page tells Google its delivery cost, the
 * "Free delivery" line a competitor's results show. Returns stay UNSTATED on
 * purpose: "we don't accept returns due to hygienic issues", and stating that
 * makes Google print "No returns" under every product.
 *
 * Writes a cost or threshold only where the box is empty, so a value he has
 * typed is his. down() takes back exactly what this wrote.
 */
return new class extends Migration
{
    private const VALUES = [
        'enable_merchant' => '1',
        'merchant_ship_cost' => '20',
        'merchant_ship_free_over' => '199',
    ];

    public function up(): void
    {
        // Only on a shop that has taken orders: these are this shop's terms,
        // not a default for a fresh install (whose demo catalogue has
        // products but has never sold anything).
        if (! Schema::hasTable('settings') || ! Schema::hasTable('orders') || ! DB::table('orders')->exists()) {
            return;
        }

        $now = now();

        foreach (self::VALUES as $key => $value) {
            $row = DB::table('settings')->where('key', $key)->first(['value']);

            if ($row === null) {
                DB::table('settings')->insert(['key' => $key, 'value' => $value, 'created_at' => $now, 'updated_at' => $now]);
            } elseif (trim((string) $row->value) === '' || ($key === 'enable_merchant' && (string) $row->value !== '1')) {
                // The switch is what he asked for; the two amounts only fill a blank.
                DB::table('settings')->where('key', $key)->update(['value' => $value, 'updated_at' => $now]);
            }
        }

        $this->forgetCaches();
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        foreach (self::VALUES as $key => $value) {
            DB::table('settings')->where('key', $key)->where('value', $value)->delete();
        }

        $this->forgetCaches();
    }

    private function forgetCaches(): void
    {
        try {
            foreach (['kbb.settings', 'kbb.settings.map'] as $key) {
                Cache::forget($key);
            }
        } catch (\Throwable) {
            // A cache that cannot forget expires on its own.
        }
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Lane FG, the owner: the checkout order summary's content background is
 * "super light greenish"; make it the free-gifts preview's summary colour,
 * #fbf7f8 (the light pinkish-white `--soft`). He asked for it, so it is the
 * shop's new state (CLAUDE.md rule 1, the 30 September reversal): a saved
 * "Opened summary background" of Almost white or Very light green moves to the
 * new option. The control stays -- Appearance -> Checkout page -> 13 October
 * layout -> Opened summary background -- and the old choice is kept beside it
 * so down() can put it back. "As 2.60.495" (classic) is left alone.
 * Literals only.
 */
return new class extends Migration
{
    private const KEY = 'checkoutpage_lo_sum_open';

    private const BEFORE = 'checkout_summary_background_before_fg';

    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        $row = DB::table('settings')->where('key', self::KEY)->first();

        if ($row !== null && in_array((string) $row->value, ['white', 'green'], true)) {
            DB::table('settings')->updateOrInsert(['key' => self::BEFORE], ['value' => (string) $row->value, 'autoload' => false, 'updated_at' => now()]);
            DB::table('settings')->where('key', self::KEY)->update(['value' => 'blush', 'updated_at' => now()]);
        }

        try {
            Cache::forget('kbb.settings');
        } catch (\Throwable) {
            // A cache that cannot forget expires on its own.
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        $before = DB::table('settings')->where('key', self::BEFORE)->value('value');

        if (is_string($before) && $before !== '') {
            DB::table('settings')->where('key', self::KEY)->where('value', 'blush')->update(['value' => $before]);
        }
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed gift_enabled and gift_fee so the value has one home.
 *
 * 2.60.87 defaulted these in PHP -- get('gift_enabled', true) -- and then had
 * the admin tab guess the same default again in JavaScript. Two defaults for
 * one setting, which is how the Gift wrapping tab ended up reading "Off, 0"
 * above a checkout that was plainly showing the tick: neither screen was
 * wrong about its own default, they simply never agreed on one.
 *
 * Writing the row makes the database the single source. Both screens then
 * read the same stored string and there is nothing left to disagree about.
 *
 * insertOrIgnore, so a merchant who has already chosen a value keeps it --
 * this seeds, it does not reset.
 */
return new class extends Migration
{
    /** value is stored as a string; gift_fee is fils, like cod_fee. */
    private const SEED = [
        'gift_enabled' => '1',
        'gift_fee' => '1500',
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::SEED as $key => $value) {
            DB::table('settings')->insertOrIgnore([
                'key' => $key,
                'value' => $value,
                'autoload' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Deliberately empty. Deleting these on rollback would take a merchant's
     * own choice with them, and their absence is harmless.
     */
    public function down(): void {}
};

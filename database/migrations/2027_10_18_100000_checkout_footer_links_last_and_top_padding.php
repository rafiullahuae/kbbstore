<?php

declare(strict_types=1);

use App\Services\SettingsService;
use App\Services\SlimFooter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/*
 * Lane CO. The owner, 9 October, on a phone screenshot of the checkout footer:
 *
 *   "in checkout footer, move the privacy etc row to the end after payments
 *    icon with a grey line seperator. and give 30px space inside the top
 *    pading of the checkout footer, i mean just above the logo as marked."
 *
 * Written as STORED values, so the live bar is in his state the moment the
 * package is applied -- and written over whatever was stored, because this is
 * a newer instruction than any value saved on that screen before it:
 *
 *   - Appearance -> Footer -> Shape & size -> "Where the policy links sit":
 *     "Last row, after the payment marks, under a thin grey line".
 *   - Appearance -> Footer -> Shape & size -> "Padding inside the top": 30.
 *   - Appearance -> Footer -> On a phone -> "Padding inside the top, on a
 *     phone": 30.
 *
 * The top padding has to be a stored row and not a moved default: each of the
 * two falls back to "Height" while it has no row of its own (SlimFooter::all()),
 * so a default of 30 would never be read on a shop that has one. "Padding inside
 * the bottom" is not touched and keeps following "Height".
 */
return new class extends Migration
{
    private const VALUES = ['links_pos' => 'end', 'pad_top' => 30, 'm_pad_top' => 30];

    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        SettingsService::forgetMemo();
        app(SlimFooter::class)->save(self::VALUES);
        SettingsService::forgetMemo();
    }

    public function down(): void
    {
        //
    }
};

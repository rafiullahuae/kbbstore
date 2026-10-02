<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The share sheet ships with Amazon's platform set. (Lane QB)
 *
 * The owner asked for the share sheet "same as attached fro mamazon with same
 * product image carry, title row, and sharing platforms" — and Amazon's sheet
 * has no Facebook, X or LinkedIn tile. Those three now default to OFF in
 * App\Services\ProductTrustShare::SCHEMA, but a default only applies where no
 * row is stored, and Lane PW's screen posts EVERY field on Save: a shop where
 * the old Trust · Share bar tab was ever saved holds `true` for all three, and
 * would keep showing them in a sheet he asked to look like Amazon's.
 *
 * So the three rows are removed WHEN THEY HOLD THE OLD SHIPPED `true` — the
 * value the bar's defaults put there, which he never chose for the sheet
 * (CLAUDE.md: "a thing he asked for is the shop's new state"). A row that
 * reads off is his choice and is left alone. Each switch is still on
 * Appearance → Product page → Share, one click away.
 *
 * The bar's retired settings (label, style, shape, size and its four spacing
 * sliders) are NOT deleted: nothing reads them any more, and leaving a dead
 * row costs nothing while deleting one can never be taken back.
 */
return new class extends Migration
{
    private const KEYS = ['pdpts_share_facebook', 'pdpts_share_x', 'pdpts_share_linkedin'];

    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        $removed = DB::table('settings')
            ->whereIn('key', self::KEYS)
            ->whereIn('value', ['1', 'true', 'on', 'yes'])
            ->delete();

        try {
            app(\App\Services\SettingsService::class)->flush();
        } catch (\Throwable) {
            // A cold or missing cache store is not a failed migration.
        }

        if (app()->runningInConsole()) {
            echo "Share sheet: {$removed} old share-bar platform rows returned to the sheet's defaults.\n";
        }
    }

    public function down(): void {}
};

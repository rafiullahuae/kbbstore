<?php

declare(strict_types=1);

use App\Services\SettingsService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The product-page free-gifts strip's words, as the owner wants them (14 Nov 2026):
 * "keep this text only: Free gifts with every order. Limited time offer! as it is.
 * also in arabic."
 *
 * 2.60.664 shipped "Limited time only!" / "لفترة محدودة فقط!". This moves the second
 * part to "Limited time offer!" / "عرض لفترة محدودة!" -- but only where it still holds
 * text a package wrote. Words the owner typed himself are left exactly as they are:
 *   - the Arabic interface row: replaced only while it holds a shipped wording;
 *     inserted if missing;
 *   - his saved English (setting pgs_line2): replaced only while it equals the
 *     2.60.664 default (saving the tab stores the default verbatim).
 * Safe to run again.
 */
return new class extends Migration
{
    private const FIELD = 'store.product.gift_strip_2';

    private const ENGLISH = 'Limited time offer!';

    private const ARABIC = 'عرض لفترة محدودة!';

    /** Arabic the earlier packages published for this field. */
    private const SHIPPED_ARABIC = ['لفترة محدودة فقط!', 'عرض لفترة محدودة. لا تفوّت الفرصة!'];

    private const SHIPPED_ENGLISH = 'Limited time only!';

    public function up(): void
    {
        if (Schema::hasTable('translations')) {
            $now = now();
            $row = DB::table('translations')
                ->where('locale', 'ar')->where('group', 'ui')->where('item_id', 0)
                ->where('field', self::FIELD);

            if (! (clone $row)->exists()) {
                DB::table('translations')->insert([
                    'locale' => 'ar', 'group' => 'ui', 'item_id' => 0, 'field' => self::FIELD, 'value' => self::ARABIC,
                    'status' => 'published', 'source' => 'manual', 'source_hash' => sha1(self::ENGLISH),
                    'reviewed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                ]);
            } else {
                (clone $row)->whereIn('value', self::SHIPPED_ARABIC)->update([
                    'value' => self::ARABIC, 'source_hash' => sha1(self::ENGLISH), 'updated_at' => $now,
                ]);
            }
        }

        if (Schema::hasTable('settings')) {
            $settings = app(SettingsService::class);

            if ($settings->get('pgs_line2', null) === self::SHIPPED_ENGLISH) {
                $settings->set('pgs_line2', self::ENGLISH);
            }
        }

        try {
            foreach (['kbb.translations.ar', 'kbb.translations.en'] as $key) {
                Cache::forget($key);
            }
        } catch (\Throwable) {
            // The loader re-reads on the next request either way.
        }
    }

    public function down(): void
    {
        // Words only; nothing to undo safely without knowing which ones the owner kept.
    }
};

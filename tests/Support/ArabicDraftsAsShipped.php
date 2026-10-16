<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Translation\TranslationStore;
use Illuminate\Support\Facades\DB;

/**
 * Put the shop's Arabic back the way 2.60.519-522 shipped it: interface
 * drafts written, nothing approved -- Lane ARF.
 *
 * The suite's database is built by running every migration, and since Lane
 * ARF the last of them (2027_11_06_100000_lane_arf_arabic_by_default) does
 * what the owner asked for: "i need the full translation by default". It
 * publishes every Arabic draft and adds the rows that never had one. So a
 * freshly migrated test database no longer HAS a draft.
 *
 * The draft/approve machinery is unchanged and still the owner's -- a machine
 * translation still lands as a draft, Approve all still approves, an
 * unapproved string still falls back to English, the review switch still
 * draws ⟪brackets⟫ -- and the tests that prove THAT need the state it works
 * on. They call this first. It is not a statement about what the shop
 * serves; ArabicByDefaultTest pins that.
 */
final class ArabicDraftsAsShipped
{
    /** The rows 2027_11_06_100000 adds, which did not exist before it. */
    private const ARF_ROWS = [
        'store.wording.site_name_uae',
        'store.concern.intro_acne', 'store.concern.intro_hydration', 'store.concern.intro_dark_spots', 'store.concern.intro_ageing',
        'store.concern.intro_sensitivity', 'store.concern.intro_pores', 'store.concern.intro_dullness', 'store.concern.intro_sun',
    ];

    public static function restore(): void
    {
        // Every shipped draft is a machine row; the migration published them all.
        DB::table('translations')
            ->where('locale', 'ar')->where('source', 'machine')->where('status', 'published')
            ->update(['status' => 'draft', 'reviewed_at' => null]);

        DB::table('translations')
            ->where('locale', 'ar')->where('group', 'ui')->where('item_id', 0)
            ->whereIn('field', self::ARF_ROWS)
            ->delete();

        // Lane AL (2027_11_08_1000xx) publishes Arabic descriptions by slug, and
        // the demo product 1025-dokdo-toner shares a live slug, so the test
        // database carries one published products row these tests never made;
        // its seven content pages match the test database's pages by slug too.
        DB::table('translations')
            ->where('locale', 'ar')->whereIn('group', ['products', 'pages', 'posts'])
            ->where('source', 'manual')->where('status', 'published')
            ->delete();

        TranslationStore::flush();
    }
}

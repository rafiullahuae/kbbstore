<?php

declare(strict_types=1);

use App\Models\Translation;
use App\Services\Translation\ArabicInterfaceDrafts;
use App\Services\Translation\InterfaceStrings;
use App\Services\Translation\TranslationStore;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Arabic DRAFTS for the new site footer and #KBeautyBliss Spotted. (Lane HB)
 *
 * A copy of 2027_07_23_000200_seed_share_card_arabic_drafts with this lane's
 * keys: drafts are never served (TranslationStore serves approved rows only),
 * so this changes no page; it puts the strings on the owner's review list.
 * Until he approves them the Arabic shop prints the English.
 */
return new class extends Migration
{
    /** The keys this migration is for, so it cannot drift into seeding the whole file. */
    private const KEYS = [
        'store.footer.help_headline',
        'store.footer.help_chip',
        'store.footer.help_sub',
        'store.footer.help_heading',
        'store.footer.discover_heading',
        'store.footer.visit_heading',
        'store.footer.visit_dubai',
        'store.footer.visit_korea',
        'store.footer.link_brands',
        'store.footer.link_about',
        'store.footer.link_journal',
        'store.footer.link_spotted',
        'store.footer.link_privacy',
        'store.footer.link_terms',
        'store.footer.follow_label',
        'store.footer.news_placeholder',
        'store.footer.news_label',
        'store.footer.news_button',
        'store.spotted.home_heading',
        'store.spotted.home_sub',
        'store.spotted.button',
        'store.spotted.prev',
        'store.spotted.next',
        'store.spotted.likes',
        'store.spotted.new_tab',
        'store.spotted.page_h1',
        'store.spotted.page_intro',
        'store.spotted.seo_title',
        'store.spotted.seo_desc',
        'store.spotted.breadcrumb_label',
        'store.spotted.empty',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('translations')) {
            return;
        }

        $drafts = ArabicInterfaceDrafts::all();

        $existing = array_flip(array_map(
            static fn ($f): string => TranslationStore::normaliseKey((string) $f),
            DB::table('translations')
                ->where('locale', 'ar')
                ->where('group', Translation::GROUP_UI)
                ->where('item_id', 0)
                ->whereIn('field', self::KEYS)
                ->pluck('field')
                ->all()
        ));

        $now = now();
        $insert = [];

        foreach (self::KEYS as $key) {
            $field = TranslationStore::normaliseKey($key);

            if (isset($existing[$field]) || ! isset($drafts[$key])) {
                continue;
            }

            $english = InterfaceStrings::english($field);

            $insert[] = [
                'locale' => 'ar',
                'group' => Translation::GROUP_UI,
                'item_id' => 0,
                'field' => $field,
                'value' => $drafts[$key],
                'status' => Translation::STATUS_DRAFT,
                'source' => Translation::SOURCE_MACHINE,
                'source_hash' => $english === null ? null : sha1($english),
                'reviewed_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($insert !== []) {
            DB::table('translations')->insert($insert);
        }

        try {
            TranslationStore::flush();
        } catch (\Throwable) {
            // Drafts are not served, so a stale map here cannot even be wrong —
            // and an update that dies half-applied over a cache is the shape
            // CLAUDE.md records against UpdateRunner::recordManifest().
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('translations')) {
            return;
        }

        /*
         * Only rows this migration could have written: a draft from a machine.
         * A string the owner has typed or approved is his, and rolling a
         * package back is not a reason to delete it.
         */
        DB::table('translations')
            ->where('locale', 'ar')
            ->where('group', Translation::GROUP_UI)
            ->where('item_id', 0)
            ->whereIn('field', self::KEYS)
            ->where('status', Translation::STATUS_DRAFT)
            ->where('source', Translation::SOURCE_MACHINE)
            ->delete();
    }
};

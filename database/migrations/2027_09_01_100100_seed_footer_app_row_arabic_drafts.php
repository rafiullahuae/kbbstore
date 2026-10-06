<?php

declare(strict_types=1);

/*
 * Lane FB: Arabic DRAFTS for the footer's app row (store.footer.app_*) — its
 * headline, its button, the three icons' names and the install sheets — from
 * ArabicInterfaceDrafts. Drafts: served only once approved under Translation
 * -> Strings, so the Arabic shop prints the English until then. The typing
 * lines are not here: they are Appearance -> Footer -> App row settings and
 * ship in Arabic already. A key the owner already typed is left as he typed it.
 */

use App\Models\Translation;
use App\Services\Translation\ArabicInterfaceDrafts;
use App\Services\Translation\InterfaceStrings;
use App\Services\Translation\TranslationStore;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Modelled on 2027_08_29_100100_seed_push_message_arabic_drafts, keys only changed. */
return new class extends Migration
{
    /** The keys this migration is for, so it cannot drift into seeding the whole file. */
    private const KEYS = [
        'store.footer.app_title',
        'store.footer.app_button',
        'store.footer.app_ic_apple',
        'store.footer.app_ic_android',
        'store.footer.app_ic_ipad',
        'store.footer.app_close',
        'store.footer.app_ios_title',
        'store.footer.app_ios_1',
        'store.footer.app_ios_2',
        'store.footer.app_and_title',
        'store.footer.app_and_1',
        'store.footer.app_and_2',
        'store.footer.app_inapp_title',
        'store.footer.app_inapp_1',
        'store.footer.app_inapp_2',
        'store.footer.app_qr_title',
        'store.footer.app_qr_1',
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

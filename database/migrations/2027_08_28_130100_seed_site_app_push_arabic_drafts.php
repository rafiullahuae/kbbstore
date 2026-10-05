<?php

declare(strict_types=1);

/*
 * Lane NT: Arabic DRAFTS for the installed shop app's "Allow notifications"
 * sheet (store.site_app.push_*), from ArabicInterfaceDrafts::laneNtSiteAppPush().
 * Drafts, as every machine-written string arrives: not served until approved
 * under Translation -> Progress / Strings, so /ar shows the English until then.
 * A key the owner already typed is left exactly as he typed it.
 */

use App\Models\Translation;
use App\Services\Translation\ArabicInterfaceDrafts;
use App\Services\Translation\InterfaceStrings;
use App\Services\Translation\TranslationStore;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Modelled on 2027_08_04_100200_seed_top_strip_arabic_drafts: a migration that
 * has run does not run again, so new keys in ArabicInterfaceDrafts reach the
 * database only through a migration of their own. Guarded on the row not
 * already existing, keyed on (locale, group, item_id, field), so the owner's
 * own typing is never overwritten and applying the package twice writes
 * nothing the second time.
 */
return new class extends Migration
{
    /** The keys this migration is for, so it cannot drift into seeding the whole file. */
    private const KEYS = [
        'store.site_app.push_title',
        'store.site_app.push_body',
        'store.site_app.push_allow',
        'store.site_app.push_later',
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

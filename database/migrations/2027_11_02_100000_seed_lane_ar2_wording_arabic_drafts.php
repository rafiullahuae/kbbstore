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
 * Lane AR round 2: the Arabic drafts for App\Support\ArabicWording -- the
 * shipped English of settings (bundle tiers, trust lines, the delivery line,
 * shipping method names, the journal's title, the SEO descriptions built in
 * code) as `store.wording.*` interface strings.
 *
 * A migration rather than an edit to an earlier seed, because a migration that
 * has run does not run again (Lane SW's note, the pattern of
 * 2027_10_11_100000_seed_card_confirming_arabic_draft). Rows are `draft`, so
 * nothing on /ar changes until the owner approves them on Translation -> Strings.
 */
return new class extends Migration
{
    /** @return list<string> every `store.wording.*` key App\Support\ArabicWording ships. */
    private static function keys(): array
    {
        return array_map(static fn (string $k): string => 'store.'.$k, array_keys(\App\Support\ArabicWording::ENGLISH));
    }

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
                ->whereIn('field', self::keys())
                ->pluck('field')
                ->all()
        ));

        $now = now();
        $insert = [];

        foreach (self::keys() as $key) {
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
            ->whereIn('field', self::keys())
            ->where('status', Translation::STATUS_DRAFT)
            ->where('source', Translation::SOURCE_MACHINE)
            ->delete();
    }
};

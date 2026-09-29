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
 * The Place-order overlay's nineteen Arabic drafts. (Lane PLC)
 *
 * ── WHY ANOTHER MIGRATION AND NOT AN EDIT TO AN EARLIER ONE ───────────────
 *
 * 2027_04_28_000000_seed_arabic_interface_drafts wrote 1,023 drafts and
 * 2027_05_10_000100 wrote three more, and both have ALREADY RUN on this shop.
 * Adding nineteen keys to ArabicInterfaceDrafts makes them appear in that
 * file's source and in no database anywhere: a migration that has run does not
 * run again, so the nineteen would sit in the class for ever and /ar would go
 * on rendering the English with nothing to show for it. That is the silent half
 * of the shape — the console's Drafts figure would not move and nobody would
 * have anything to click.
 *
 * ── WHAT IT CHANGES ON THE SHOP: NOTHING ──────────────────────────────────
 *
 * All nineteen rows are `status = draft`, and TranslationStore::uiMap() filters
 * on `published` before the map is built, so a draft cannot reach a page. /ar
 * renders the same English it renders today; the only thing that moves is
 * Translation → Progress, where Drafts awaiting approval goes up by nineteen.
 *
 * Guarded on the row not already existing, keyed on the same
 * (locale, group, item_id, field) identity the table is unique on. So the
 * owner's own typing is never overwritten, and an update package applied twice
 * writes nothing the second time — the table's unique index makes the guard
 * load-bearing rather than tidy.
 */
return new class extends Migration
{
    /** The keys this migration is for, so it cannot drift into seeding the whole file. */
    private const KEYS = [
        'store.checkout.placing_label',
        'store.checkout.placing_title',
        'store.checkout.placing_note',
        'store.checkout.placing_leaving',
        'store.checkout.placing_leaving_note',
        'store.checkout.placing_done',
        'store.checkout.placing_failed',
        'store.checkout.placing_offline',
        'store.checkout.placing_expired',
        'store.checkout.placing_no_answer',
        'store.checkout.placing_redirect_stuck',
        'store.checkout.placing_redirect_link',
        'store.checkout.return_not_completed',
        'store.checkout.return_not_completed_at',
        'store.order_received.placed_label',
        'store.order_received.placed_title',
        'store.order_received.confirming_title',
        'store.order_received.confirming_note',
        'store.order_received.confirming_slow',
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

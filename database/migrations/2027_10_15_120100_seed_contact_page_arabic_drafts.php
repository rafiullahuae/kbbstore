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
 * The Arabic drafts for the contact page's cards, icons and inquiry form
 * (Lane CT; the pattern is Lane SW's
 * 2027_10_11_100000_seed_card_confirming_arabic_draft).
 *
 * A migration rather than an edit to an earlier seed, because a migration that
 * has run does not run again: keys added only to ArabicInterfaceDrafts would
 * reach no database. Rows are `status = draft` (TranslationStore::uiMap() serves
 * only `published`), so /ar/contact-us/ shows these words once the owner
 * approves them on Translation -> Strings, and English until then.
 */
return new class extends Migration
{
    /** The keys this migration is for, so it cannot drift into seeding the whole file. */
    private const KEYS = [
        'store.contact.reach_heading',
        'store.contact.wa_title',
        'store.contact.wa_note',
        'store.contact.wa_action',
        'store.contact.ig_title',
        'store.contact.ig_note',
        'store.contact.ig_action',
        'store.contact.phone_title',
        'store.contact.phone_note',
        'store.contact.phone_action',
        'store.contact.email_title',
        'store.contact.email_note',
        'store.contact.email_action',
        'store.contact.hours_title',
        'store.contact.follow_title',
        'store.contact.follow_note',
        'store.contact.form_title',
        'store.contact.form_intro',
        'store.contact.label_name',
        'store.contact.label_email',
        'store.contact.label_phone',
        'store.contact.optional',
        'store.contact.label_topic',
        'store.contact.label_message',
        'store.contact.message_placeholder',
        'store.contact.submit',
        'store.contact.privacy',
        'store.contact.hp_label',
        'store.contact.sent_title',
        'store.contact.sent',
        'store.contact.err_summary',
        'store.contact.err_name',
        'store.contact.err_email',
        'store.contact.err_phone',
        'store.contact.err_topic',
        'store.contact.err_message',
        'store.contact.err_message_long',
        'store.contact.err_fast',
        'store.contact.err_limit',
        'store.contact.topic_order',
        'store.contact.topic_advice',
        'store.contact.topic_wholesale',
        'store.contact.topic_other',
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

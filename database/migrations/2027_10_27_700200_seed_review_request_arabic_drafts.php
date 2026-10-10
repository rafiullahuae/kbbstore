<?php

declare(strict_types=1);

use App\Models\Translation;
use App\Services\Translation\ArabicInterfaceDrafts;
use App\Services\Translation\InterfaceStrings;
use App\Services\Translation\TranslationStore;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Lane RV — the Arabic for the review request email and its page, offered as
 * DRAFTS (ArabicInterfaceDrafts' header: a machine's Arabic is never published
 * by a package). /ar and the Arabic email render the English until the owner
 * approves them in Translation → Progress, or writes his own words in Emails →
 * Customer emails → Review request → Wording · العربية. Only keys that have no
 * Arabic row yet are written, so nothing he typed is touched.
 */
return new class extends Migration
{
    /** The keys this migration is for, so it cannot drift into seeding the whole file. */
    private const KEYS = [
        'email.review_request.subject_named',
        'email.review_request.subject',
        'email.review_request.preheader',
        'email.review_request.heading',
        'email.review_request.body',
        'email.review_request.there',
        'email.review_request.eyebrow',
        'email.review_request.tap_hint',
        'email.review_request.button',
        'email.review_request.note',
        'email.review_request.closing',
        'email.review_request.signoff_thanks',
        'email.review_request.signoff_team',
        'email.review_request.why',
        'email.review_request.unsubscribe',
        'email.review_request.ago_today',
        'email.review_request.ago_yesterday',
        'email.review_request.ago_days',
        'store.review_request.title',
        'store.review_request.heading',
        'store.review_request.heading_plain',
        'store.review_request.lead',
        'store.review_request.verified',
        'store.review_request.rating_label',
        'store.review_request.rate_1',
        'store.review_request.rate_2',
        'store.review_request.rate_3',
        'store.review_request.rate_4',
        'store.review_request.rate_5',
        'store.review_request.title_label',
        'store.review_request.title_ph',
        'store.review_request.content_label',
        'store.review_request.content_ph',
        'store.review_request.photos_label',
        'store.review_request.photos_note',
        'store.review_request.name_label',
        'store.review_request.name_note',
        'store.review_request.submit',
        'store.review_request.skip_note',
        'store.review_request.published',
        'store.review_request.pending',
        'store.review_request.thanks_title',
        'store.review_request.thanks_lead',
        'store.review_request.thanks_shop',
        'store.review_request.invalid_title',
        'store.review_request.invalid_lead',
        'store.review_request.closed_lead',
        'store.review_request.err_none',
        'store.review_request.err_text',
        'store.review_request.too_many',
        'store.review_request.stop_title',
        'store.review_request.stop_lead',
        'store.review_request.stop_button',
        'store.review_request.stop_note',
        'store.review_request.stop_done_title',
        'store.review_request.stop_done_lead',
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

        // Only rows this migration could have written: a draft from a machine.
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

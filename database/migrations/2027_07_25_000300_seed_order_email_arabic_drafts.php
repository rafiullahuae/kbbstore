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
 * Seed the Arabic drafts for Lane RL's order emails (Lane RL): the new status
 * emails, the tracking line, the "Complete your order" reminders and page, and
 * the feedback request. Drafts only -- the owner reviews them under
 * Translation -> Strings; a key he has already written is left alone. Same
 * shape as 2027_07_23_000200_seed_share_card_arabic_drafts.
 */
return new class extends Migration
{
    /** The keys this migration is for, so it cannot drift into seeding the whole file. */
    private const KEYS = [
        'email.confirmation.subject',
        'email.order_status.processing_subject',
        'email.order_status.processing_heading',
        'email.order_status.processing_body',
        'email.order_status.onhold_subject',
        'email.order_status.onhold_heading',
        'email.order_status.onhold_body',
        'email.order_status.onhold_need',
        'email.order_status.onhold_reply',
        'email.confirmation.lead_paid',
        'email.order_status.completed_subject',
        'email.order_status.completed_heading',
        'email.order_status.completed_body',
        'email.order_status.refunded_subject',
        'email.order_status.refunded_heading',
        'email.order_status.refunded_body',
        'email.order_status.failed_subject',
        'email.order_status.failed_heading',
        'email.order_status.failed_body',
        'email.order_status.tracking_number',
        'email.order_status.tracking_where',
        'email.order_status.track_button',
        'email.order_status.track_note',
        'email.reminder.first_subject',
        'email.reminder.first_heading',
        'email.reminder.first_body',
        'email.reminder.first_closing',
        'email.reminder.second_subject',
        'email.reminder.second_heading',
        'email.reminder.second_body',
        'email.reminder.second_closing',
        'email.reminder.not_paid',
        'email.reminder.button',
        'email.reminder.button_note',
        'email.reminder.why_fast',
        'email.reminder.why_fast_note',
        'email.reminder.why_original',
        'email.reminder.why_original_note',
        'email.reminder.why_samples',
        'email.reminder.why_samples_note',
        'email.feedback.subject',
        'email.feedback.subject_named',
        'email.feedback.heading',
        'email.feedback.heading_named',
        'email.feedback.body',
        'email.feedback.items_heading',
        'email.feedback.button',
        'email.feedback.button_note',
        'email.feedback.closing',
        'store.order_pay.page_title',
        'store.order_pay.heading',
        'store.order_pay.lead',
        'store.order_pay.not_found',
        'store.order_pay.find_order',
        'store.order_pay.your_items',
        'store.order_pay.qty',
        'store.order_pay.total',
        'store.order_pay.how_to_pay',
        'store.order_pay.pay_button',
        'store.order_pay.working',
        'store.order_pay.no_methods',
        'store.order_pay.method_unavailable',
        'store.order_pay.cannot_reopen',
        'store.order_pay.start_failed',
        'store.order_pay.needs_javascript',
        'store.order_pay.generic_error',
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

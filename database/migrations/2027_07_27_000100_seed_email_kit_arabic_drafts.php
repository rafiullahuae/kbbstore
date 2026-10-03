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
 * Lane EM: the Arabic drafts for the approved email design's own words
 * (InterfaceStrings::emailKit() — the kit's header, help box, tracker,
 * footer, and each email's eyebrow, preheader and "why" line). Seeded as
 * DRAFTS, never published: the owner reviews them under Translation →
 * Strings, exactly as every earlier lane's drafts. Idempotent: a key that
 * already has an Arabic row (typed by the owner, or seeded before) is left
 * as it is.
 */
return new class extends Migration
{
    /** The keys this migration is for, so it cannot drift into seeding the whole file. */
    private const KEYS = [
        'email.kit.topbar',
        'email.kit.nav_shop',
        'email.kit.nav_track',
        'email.kit.nav_account',
        'email.kit.footer_terms',
        'email.kit.footer_privacy',
        'email.kit.footer_tagline',
        'email.kit.preferences',
        'email.kit.copyright',
        'email.kit.view_in_browser',
        'email.kit.help_heading',
        'email.kit.help_body',
        'email.kit.chip_order',
        'email.kit.chip_placed',
        'email.kit.chip_total',
        'email.kit.qty',
        'email.kit.coupon_label',
        'email.kit.stars',
        'email.kit.step_placed',
        'email.kit.step_confirmed',
        'email.kit.step_shipped',
        'email.kit.step_delivered',
        'email.kit.step_cancelled',
        'email.kit.step_not_paid',
        'email.kit.your_items',
        'email.kit.free',
        'email.kit.total_to_pay',
        'email.kit.paid_with',
        'email.kit.delivering_to',
        'email.kit.payment',
        'email.kit.ship_to',
        'email.kit.customer',
        'email.kit.item_count',
        'email.kit.eyebrow_confirmed',
        'email.kit.eyebrow_processing',
        'email.kit.eyebrow_onhold',
        'email.kit.eyebrow_shipped',
        'email.kit.eyebrow_delivered',
        'email.kit.eyebrow_cancelled',
        'email.kit.eyebrow_refunded',
        'email.kit.eyebrow_failed',
        'email.kit.eyebrow_reminder_first',
        'email.kit.eyebrow_reminder_second',
        'email.kit.eyebrow_refund_sent',
        'email.kit.eyebrow_alert',
        'email.kit.eyebrow_feedback',
        'email.kit.eyebrow_stock',
        'email.kit.eyebrow_basket',
        'email.kit.eyebrow_invite',
        'email.kit.eyebrow_newsletter',
        'email.kit.eyebrow_quiz',
        'email.kit.eyebrow_security',
        'email.kit.eyebrow_verify',
        'email.kit.shop_again',
        'email.kit.reply_whatsapp',
        'email.kit.signoff_thanks',
        'email.kit.pre_confirmed',
        'email.kit.pre_status',
        'email.kit.pre_reminder_first',
        'email.kit.pre_reminder_second',
        'email.kit.pre_feedback',
        'email.kit.pre_refunded',
        'email.kit.pre_alert',
        'email.kit.pre_stock',
        'email.kit.pre_basket',
        'email.kit.pre_invite',
        'email.kit.pre_newsletter',
        'email.kit.pre_quiz',
        'email.kit.pre_reset',
        'email.kit.pre_verify',
        'email.kit.why_order',
        'email.kit.why_alert',
        'email.kit.why_stock',
        'email.kit.why_basket',
        'email.kit.why_newsletter',
        'email.kit.why_quiz',
        'email.kit.why_reset',
        'email.kit.why_verify',
        'email.kit.invoice_title',
        'email.kit.invoice_lead',
        'email.kit.invoice_lead_noref',
        'email.kit.stock_title',
        'email.kit.stock_cta',
        'email.kit.stock_once',
        'email.kit.basket_title',
        'email.kit.basket_button',
        'email.kit.invite_title',
        'email.kit.newsletter_title',
        'email.kit.quiz_title',
        'email.kit.reset_title',
        'email.kit.verify_title',
        'email.feedback.stars_note',
        'email.feedback.share_heading',
        'email.feedback.share_body',
        'email.kit.howto_heading',
        'email.kit.howto_tail',
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

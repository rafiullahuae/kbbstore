<?php

declare(strict_types=1);

use App\Models\PaymentProvider;
use Illuminate\Database\Migrations\Migration;

/**
 * TABBY'S AUTO-CAPTURE SWITCH, TURNED ON — A DEFAULT THE OWNER ASKED FOR IN AS
 * MANY WORDS.
 *
 * "the tabby orders must be captured automatically. before in wordpress website
 * the orders were auto capturing. it's a serious issue, bcz until capture tabby
 * is not counting those orders and not count in payout."
 *
 * `CLAUDE.md` rule 1 says a thing the owner asked for ships at the value he
 * asked for, called out in the commit. This is that. It is the exact sibling of
 * 2027_03_21_000000_tamara_auto_capture_on_by_default, and it is a migration
 * rather than a code default for the same reason that one is:
 *
 * ── WHY A MIGRATION AND NOT A CHANGED DEFAULT IN autoCapture() ─────────────
 *
 * The payments screen stores a `bool` field as the string '1' for On and the
 * EMPTY STRING for Off (resources/views/admin/app.blade.php, the `f.type ===
 * 'bool'` branch). GatewayCredentials::get() returns '' for a key no config
 * blob carries. So "never set" and "deliberately switched Off" are the same
 * string, and a code default of "on unless '0'" would read an owner's explicit
 * Off as On and take a customer's money after he had said not to.
 *
 * Writing the '1' once, here, keeps the two states apart: after this runs the
 * key EXISTS and says On, and the moment the owner sets it to Off the screen
 * writes '' over it and nothing ever puts it back. Only when the key is ABSENT,
 * checked with array_key_exists rather than a truthiness test.
 *
 * ── WHAT IT MEANS ON THE SHOP ──────────────────────────────────────────────
 *
 * TabbyCaptureSweep captures any authorised, live (CAPTURABLE), uncaptured,
 * unvoided Tabby order inside the capture window — capture on authorisation,
 * not on fulfilment, which is the WooCommerce behaviour the owner described.
 * The scheduler runs it every few minutes, so an order is captured within
 * minutes of checkout. Nothing cancelled, refunded, failed or draft is ever a
 * candidate.
 */
return new class extends Migration
{
    public function up(): void
    {
        $row = PaymentProvider::query()->find('tabby');

        if ($row === null) {
            return;
        }

        try {
            $config = $row->config;
        } catch (\Throwable) {
            // The posture GatewayCredentials::all() takes: a config blob this
            // install cannot decrypt is not a migration failure, and rewriting
            // it here would destroy the owner's keys.
            return;
        }

        $config = is_array($config) ? $config : [];

        if (array_key_exists('auto_capture', $config)) {
            return;
        }

        $config['auto_capture'] = '1';

        $row->config = $config;
        $row->save();
    }

    public function down(): void
    {
        // Deliberately not reversed, as the Tamara sibling is not. Removing the
        // key would put the shop back where "never set" and "switched Off" are
        // the same string, and a later re-run of up() would switch it On again
        // over an owner who had turned it Off.
    }
};

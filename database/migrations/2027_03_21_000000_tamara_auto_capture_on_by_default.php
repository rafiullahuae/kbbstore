<?php

declare(strict_types=1);

use App\Models\PaymentProvider;
use Illuminate\Database\Migrations\Migration;

/**
 * TAMARA'S AUTO-CAPTURE SWITCH, TURNED ON — AND THIS IS A DEFAULT THE OWNER
 * ASKED FOR IN AS MANY WORDS.
 *
 * `CLAUDE.md` rule 1 says any NEW setting ships at the value the page already
 * has, so applying a package moves nothing until somebody moves a slider. The
 * exception it names is "a default the owner asked for in as many words, and
 * those get called out in the commit rather than buried". This is that, and it
 * is written here rather than in TamaraGateway::autoCapture() for a reason
 * worth keeping.
 *
 * ── WHY A MIGRATION AND NOT A CHANGE OF DEFAULT IN THE CODE ────────────────
 *
 * The payments screen stores a `bool` field as the string '1' for On and the
 * EMPTY STRING for Off (resources/views/admin/app.blade.php, the `f.type ===
 * 'bool'` branch: two options, value="1" and value=""). GatewayCredentials::
 * get() returns '' for a key no config blob carries. So "never set" and
 * "deliberately switched Off" are the same string, and a code default of
 * `!== '0'` would read an explicit Off as On and take a customer's money after
 * the owner had said not to.
 *
 * Writing the '1' once, here, keeps those two states apart: after this runs the
 * key EXISTS and says On, and the moment the owner sets it to Off the screen
 * writes '' over it and nothing ever puts it back.
 *
 * Only when the key is ABSENT, checked with array_key_exists rather than a
 * truthiness test, for exactly the reason above.
 *
 * ── WHAT IT MEANS ON THE SHOP ──────────────────────────────────────────────
 *
 * TamaraCaptureSweep only ever captures an order that has reached `shipped` or
 * `completed` (its FULFILLED constant) and is authorised, uncaptured, unvoided
 * and inside the capture window. The goods have gone; the money has not been
 * taken; Tamara voids an uncaptured authorisation after about 180 days and the
 * shop is never paid for the parcel it sent. That is the whole case for it.
 *
 * It does NOT capture a `processing` order, and nothing here changes that.
 * PaymentConfirmer already moves a Tamara order to `processing` the moment the
 * authorisation is confirmed (`PaymentConfirmer.php:131`), which is the other
 * half of what the owner asked for and was already true.
 */
return new class extends Migration
{
    public function up(): void
    {
        $row = PaymentProvider::query()->find('tamara');

        if ($row === null) {
            return;
        }

        try {
            $config = $row->config;
        } catch (\Throwable) {
            // The same posture GatewayCredentials::all() takes: a config blob
            // this install cannot decrypt is not a migration failure, and
            // rewriting it here would destroy the owner's keys.
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
        // Deliberately not reversed. Removing the key would put the shop back
        // into the state where "never set" and "switched Off" are the same
        // string, and a later re-run of up() would then switch it On again
        // over an owner who had turned it Off.
    }
};

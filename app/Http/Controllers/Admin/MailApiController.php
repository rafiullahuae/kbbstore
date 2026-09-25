<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Mail\MailConfigurator;
use App\Services\Mail\MailSettings;
use App\Services\Mail\MailTester;
use App\Services\ModuleSchema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Store → Mail.
 *
 * Follows PaymentsApiController's shape, including the one rule that file
 * exists to enforce, restated here because it is enforced separately:
 *
 *   THE SMTP PASSWORD IS NEVER RETURNED. Not by show(), not in the result of
 *   save(), not inside a test-send error string. It comes back as an empty
 *   string with `has_value` saying whether one is stored. Empty, not masked --
 *   a row of asterisks still discloses the length.
 *
 * That matters more here than it looks, because `GET /admin-api/settings`
 * returns `Setting::map()` -- the whole settings table, with no allowlist. Any
 * secret put in `settings` is therefore handed to the admin bundle on every
 * load of the settings screen. The password is in the encrypted
 * `mail_credentials` row instead, so that endpoint cannot carry it whatever it
 * is later rewritten to return.
 *
 * test() is the point of the whole package: the owner types an address, presses
 * a button, and finds out whether this host can send. It sends synchronously
 * and reports the transport's own error. It is rate limited at the route,
 * because a button that makes a server send mail to an address a caller chose
 * is an open relay if it is ever left unguarded.
 */
class MailApiController extends Controller
{
    public function __construct(
        private MailSettings $settings,
        private MailConfigurator $configurator,
        private MailTester $tester,
    ) {}

    /**
     * ── THE DISPLAY BOUNDARY — Lane M4 ──────────────────────────────────────
     *
     * This method is where a stored KEY becomes a sentence the owner reads, and
     * it is the only place in the application that does so. `MailSettings::all()`
     * used to do it instead, which is what round 3 §5 named as the blocker to
     * migrating this screen: a reader that hands back a label cannot be
     * compared against TRANSPORT_KEYS, against transport(), or against what any
     * other module's reader answers.
     *
     * Three things are derived here and nowhere else, all three from the one
     * schema declaration rather than from a second list:
     *
     *   type     `select` is what the rest of the application calls a control
     *            that picks from a set; `choice` is what this screen's renderer
     *            has always been sent, and the console's mailControl() branches
     *            on `f.options` before it looks at the type at all.
     *   options  the option LABELS, in declared order — `array_values()` over
     *            the schema's `value => label` map, which is TRANSPORTS and
     *            ENCRYPTIONS exactly, because those constants are what the
     *            schema is built from.
     *   value    for a select, the label of the stored key. The console's
     *            mailField() prints each option string as BOTH the value and
     *            the visible text, so the sentence is what has to come back or
     *            the wrong option is preselected. That requirement is real and
     *            it is met HERE, three lines from where the sentence is drawn.
     *
     * A `secret` never has a `value` to map: ModuleSchema::fields() emits it
     * with no `value` key at all, so the empty string below is written by this
     * method and cannot be a stored credential that leaked through.
     */
    public function show(): JsonResponse
    {
        $schema = MailSettings::schema();

        $rendered = ModuleSchema::fields(
            $schema,
            $this->settings->all(),
            [],
            [],
            $this->settings->secretsPresent(),
        );

        $fields = [];

        foreach ($rendered as $key => $f) {
            $options = $schema[$key]['options'] ?? null;
            $secret = $f['type'] === 'secret';

            $fields[] = [
                'key' => $key,
                'type' => $f['type'] === 'select' ? 'choice' : $f['type'],
                'label' => $f['label'],
                'help' => $f['help'],
                /*
                 * A secret is NEVER sent back, and `$f` does not even carry a
                 * `value` key for one — see ModuleSchema::fields(). Empty, not
                 * masked: a row of asterisks still discloses the length.
                 */
                'value' => $secret
                    ? ''
                    : (is_array($options) ? (string) ($options[$f['value']] ?? '') : $f['value']),
                'has_value' => $secret ? $f['has_value'] : ($f['value'] !== ''),
                'options' => is_array($options) ? array_values($options) : null,
            ];
        }

        return response()->json([
            'fields' => $fields,
            'configured' => $this->settings->configured(),
            'missing' => $this->settings->missing(),
            // What the mailer would really do right now, so the screen can say
            // "this will write to the log" instead of implying delivery.
            'transport' => $this->configurator->usesRealTransport() ? 'smtp' : 'log',
            'last_test' => $this->settings->lastTest(),
        ]);
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate([
            'settings' => ['required', 'array'],
        ]);

        $values = $data['settings'];

        // Fail closed, the same way PayShipRulesApiController and
        // PaymentsApiController do: an unknown key is an error, never something
        // quietly written into the settings table.
        $unknown = array_diff(array_keys($values), array_keys(MailSettings::SCHEMA));

        if ($unknown !== []) {
            return response()->json([
                'ok' => false,
                'error' => 'Unknown setting: ' . implode(', ', $unknown),
            ], 422);
        }

        $checks = [
            'mail_transport' => ['string', 'in:' . implode(',', MailSettings::TRANSPORTS)],
            'mail_encryption' => ['string', 'in:' . implode(',', MailSettings::ENCRYPTIONS)],
            'mail_host' => ['string', 'max:255'],
            'mail_port' => ['integer', 'min:1', 'max:65535'],
            'mail_username' => ['string', 'max:255'],
            'mail_password' => ['string', 'max:255'],
            'mail_from_address' => ['string', 'email', 'max:255'],
            'mail_from_name' => ['string', 'max:120'],
            // Where the new-order alert goes. MailSettings::save() also checks
            // it, but only this list makes the SCREEN report a bad value rather
            // than silently keeping the previous one.
            'mail_merchant_address' => ['string', 'email', 'max:255'],
            'mail_timeout' => ['integer', 'min:1', 'max:120'],
        ];

        /*
         * Validate only the boxes that came back with something in them.
         *
         * Every field on this screen is optional and a blank one means "clear
         * it" (or, for the password, "unchanged"). Applying `email` or
         * `integer` to a blank string would reject a perfectly ordinary save --
         * clearing the port to fall back to the default, for one.
         *
         * NULL IS WHAT A BLANK BOX ACTUALLY ARRIVES AS, and that is the half
         * this skip was missing. Laravel's global ConvertEmptyStringsToNull
         * runs before the controller, so the '' this test was written for
         * never reaches it -- `is_string(null)` is false, the skip did not
         * fire, and the `string` and `email` rules were applied to a null.
         * Measured: {"settings":{"mail_host":""}} answered 422 "The
         * settings.mail host field must be a string." Eight of this screen's
         * fifteen boxes refused to be cleared, which on a fresh store is every
         * box under the SMTP option. Same trap as
         * EcommerceApiController::castText() and the `nullable` note in
         * ReviewSettingsApiController.
         */
        $rules = [];

        foreach ($values as $key => $value) {
            if ($value === null || (is_string($value) && trim($value) === '')) {
                continue;
            }

            if (isset($checks[$key])) {
                $rules['settings.' . $key] = $checks[$key];
            }
        }

        if ($rules !== []) {
            $request->validate($rules);
        }

        $rejected = $this->settings->save($values);

        // Refreshed either way: a refusal is per-field and the rest of the
        // payload WAS written, so the transport this request may have changed
        // has to be rebuilt whether or not one box was refused.
        $this->configurator->refresh();

        /*
         * ── A REFUSED VALUE IS REPORTED, NOT SWALLOWED — Lane Q11 ───────────
         *
         * `$checks` above runs BEFORE anything is written and is unchanged.
         * This is the second half, and it is the half that was missing: what
         * MailSettings::save() actually REFUSED to store, reported with the
         * label the owner is looking at.
         *
         * The measured defect it closes: POST {"mail_reply_to":"not an
         * address"} answered 200 `{"ok":true,"configured":true,"missing":[]}`
         * and left the previous address in the row. The owner is told the
         * Reply-To moved; a customer's reply still goes wherever it went
         * before, and nobody finds out until somebody replies into a void.
         *
         * WHY NOT AN `email` ENTRY IN `$checks` INSTEAD, which is what round 4
         * §11 proposed as "one line": because `$checks` is Laravel's `email`
         * rule and the writer is `filter_var(FILTER_VALIDATE_EMAIL)`, and they
         * disagree — measured over a corpus in MailRefusalIsReportedTest. Six
         * spellings (`a@b`, `a@example`, `a@127.0.0.1`, a quoted local part,
         * two with non-ASCII) pass `email` and are refused by the writer. So
         * `mail_merchant_address` had the SAME silent drop despite being in
         * `$checks`, and the proposed line would have moved the silence from
         * one address box to the other rather than removed it. Reporting the
         * writer's own verdict cannot drift from what the writer stores,
         * because it IS what the writer stored.
         *
         * `$checks` IS LEFT EXACTLY AS IT WAS. It refuses before anything is
         * written, which is a better answer where it fires, and every rule in
         * it still fires first.
         *
         * 422 and `ok: false`, the shape PayShipRulesApiController already
         * answers with and the shape the console's own save handler already
         * renders — `toast('Could not save: ' + d.error)`. No console change.
         *
         * The other keys in the payload were written, and the sentence says so
         * rather than leaving the owner to guess whether the whole save was
         * lost.
         */
        if ($rejected !== []) {
            $labels = '“' . implode('”, “', array_values($rejected)) . '”';

            return response()->json([
                'ok' => false,
                'error' => $labels . ' ' . (count($rejected) === 1 ? 'is not a valid value and was' : 'are not valid values and were')
                    . ' NOT saved — what was stored before is unchanged. Everything else on this screen was saved.',
                // The keys, so a caller that wants to point at the box can.
                'rejected' => array_keys($rejected),
                'configured' => $this->settings->configured(),
                'missing' => $this->settings->missing(),
            ], 422);
        }

        // No echo of what was saved. show() is the read side and it is the one
        // place that decides what may be disclosed.
        return response()->json([
            'ok' => true,
            'configured' => $this->settings->configured(),
            'missing' => $this->settings->missing(),
        ]);
    }

    /**
     * POST /admin-api/mail/test — the one-click answer.
     *
     * Synchronous. The response is the transport's verdict, not a queue
     * acknowledgement, and a failure carries the real message.
     */
    public function test(Request $request): JsonResponse
    {
        $data = $request->validate([
            'to' => ['required', 'string', 'max:255', new \App\Rules\StorefrontEmail],
        ]);

        $result = $this->tester->send($data['to']);

        /*
         * 200 either way, with `ok` carrying the verdict.
         *
         * A failed SMTP handshake is not a malformed request and not a server
         * fault -- it is the answer the owner asked for, and it has to arrive
         * as a body the screen can render rather than as a status code the
         * admin bundle's fetch wrapper turns into "Request failed".
         */
        return response()->json($result);
    }

    /*
     * optionsFor() lived here and named the two option sets a third time — once
     * in TRANSPORT_LABELS, once in TRANSPORTS, once here. show() now reads them
     * off the schema, which is built from those constants, so the screen cannot
     * offer an option the cast would refuse. TRANSPORTS and ENCRYPTIONS stay
     * because $checks above validates against them by name.
     */
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Mail\MailConfigurator;
use App\Services\Mail\MailSettings;
use App\Services\Mail\MailTester;
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

    public function show(): JsonResponse
    {
        $values = $this->settings->all();
        $fields = [];

        foreach (MailSettings::SCHEMA as $key => $def) {
            [$type, $label, $help] = array_pad($def, 3, '');

            $secret = $type === 'secret';

            $fields[] = [
                'key' => $key,
                'type' => $type,
                'label' => $label,
                'help' => $help,
                // A secret is NEVER sent back.
                'value' => $secret ? '' : ($values[$key] ?? ''),
                'has_value' => $secret
                    ? $this->settings->hasPassword()
                    : (($values[$key] ?? '') !== ''),
                'options' => $this->optionsFor($key),
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

        $this->settings->save($values);
        $this->configurator->refresh();

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

    /** @return array<int, string>|null */
    private function optionsFor(string $key): ?array
    {
        return match ($key) {
            'mail_transport' => MailSettings::TRANSPORTS,
            'mail_encryption' => MailSettings::ENCRYPTIONS,
            default => null,
        };
    }
}

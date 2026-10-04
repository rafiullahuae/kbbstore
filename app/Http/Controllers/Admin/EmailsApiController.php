<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\OrderConfirmation;
use App\Models\Order;
use App\Services\Mail\DomainCheck;
use App\Services\Mail\EmailBranding;
use App\Services\Mail\EmailLook;
use App\Services\Mail\MailConfigurator;
use App\Services\Mail\MailSettings;
use App\Services\Mail\MailTester;
use App\Services\Mail\OrderMailer;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Emails → Overview, Sending & delivery, Design & branding (Lane RK, E1).
 *
 * The owner: "put all these settings etc under a new parent menu Emails".
 *
 * NOT A SECOND SETTINGS STORE. Every value here is a MailSettings key, written
 * through MailSettings::save() — the same writer Store → Mail uses, with the
 * same refusal reporting — so the two screens cannot disagree about what is
 * stored. The old screen keeps working on the same rows.
 *
 * WHAT IS DIFFERENT FROM GET /admin-api/mail is the shape, and that is the
 * point of the package:
 *
 *   - Sending offers exactly the two transports the owner asked to choose
 *     between: this server's mail and Google Workspace. A store already on the
 *     dedicated-SMTP or log option sees that option too, labelled as the
 *     current setting, so opening this screen and pressing Save can never
 *     switch a live shop's mail to something nobody chose. A transport outside
 *     the offered list is refused, not coerced (CLAUDE.md rule 5: a select
 *     stores one of its own options).
 *   - Each endpoint accepts ONLY the keys its own screen owns. A branding save
 *     cannot touch the transport, and a sending save cannot touch the footer.
 *
 * SECRETS NEVER LEAVE. The Google app password is reported as `has_value` and
 * nothing else — not masked, not its length — exactly as MailApiController
 * reports the SMTP one. A blank box means unchanged; "-" forgets it.
 *
 * Capabilities: `emails.view` for the overview, `emails.manage` for the rest,
 * both owner-only, matching store.settings which has always covered mail. See
 * AdminCapabilities.
 */
class EmailsApiController extends Controller
{
    /** The keys Emails → Sending & delivery may write. */
    public const SENDING_KEYS = [
        'mail_from_name',
        'mail_from_address',
        'mail_reply_to',
        'mail_merchant_address',
        'mail_gmail_username',
        'mail_gmail_password',
    ];

    /** The keys Emails → Design & branding → Contact details may write. */
    public const BRANDING_KEYS = [
        'mail_support_email',
        'mail_support_whatsapp',
        'mail_address_dubai',
        'mail_address_korea',
        'mail_support_instagram',
        'mail_signature',
    ];

    /** The two pre-2.60.376 keys, still accepted, mapped to the kit's own names. */
    private const ALIASES = ['order_shipped' => 'order_status_shipped', 'order_cancelled' => 'order_status_cancelled'];

    /**
     * Every email, for "Which email" (2.60.376) — the owner: "i need here all
     * the emails, pending order, and in failed order". Order emails are filled
     * with the latest order, the others with the shop's own sample data, by
     * the same App\Services\Mail\Kit\KitSamples the template editor previews.
     *
     * @return array<string, string>
     */
    public static function samples(): array
    {
        return ['plain' => 'A plain test message'] + \App\Services\Mail\CustomerEmails::testChoices();
    }

    /** The two the owner chose between, in his order. */
    public const OFFERED = [MailSettings::TRANSPORT_SERVER, MailSettings::TRANSPORT_GMAIL];

    public function __construct(
        private MailSettings $settings,
        private MailConfigurator $configurator,
        private MailTester $tester,
    ) {}

    // ------------------------------------------------------------------ overview

    public function overview(): JsonResponse
    {
        $transport = $this->settings->transport();

        return response()->json([
            'transport' => [
                'key' => $transport,
                'label' => self::label($transport),
                'configured' => $this->settings->configured(),
                'missing' => $this->settings->missing(),
                // What the mailer would really use right now. Differs from
                // `key` only when a half-filled Google or SMTP setup has fallen
                // back to server mail, which the screen then says plainly.
                'active' => $this->configurator->activeTransport(),
            ],
            'from' => $this->settings->fromAddress(),
            'last_test' => $this->settings->lastTest(),
            'dns' => app(DomainCheck::class)->last(),
            'domain' => app(DomainCheck::class)->domain(),
            'sent_7d' => $this->sentLastWeek(),
            'emails' => $this->catalogue(),
        ]);
    }

    /**
     * Every email the shop sends and whether it is on, READ-ONLY in this
     * package. Switching them is still Store → Mail → Order status emails and
     * Store → Modules; Emails → Customer emails replaces that in E2.
     *
     * @return list<array{key: string, name: string, to: string, when: string, state: string, where: string}>
     */
    private function catalogue(): array
    {
        $mailer = app(OrderMailer::class);
        $settings = app(SettingsService::class);
        $onOff = static fn (bool $on): string => $on ? 'on' : 'off';

        return [
            ['key' => 'order_confirmation', 'name' => 'Order confirmation', 'to' => 'Customer', 'when' => 'An order is placed', 'state' => $onOff($mailer->confirmationEnabled()), 'where' => 'Store → Modules'],
            ['key' => 'new_order_alert', 'name' => 'New-order alert', 'to' => 'You', 'when' => 'An order is placed', 'state' => $onOff($mailer->merchantAlertEnabled()), 'where' => 'Store → Modules'],
            ['key' => 'order_shipped', 'name' => 'Order shipped', 'to' => 'Customer', 'when' => 'Status becomes shipped', 'state' => $onOff($mailer->shippedEnabled()), 'where' => 'Emails → All mail settings → Order status emails'],
            ['key' => 'order_cancelled', 'name' => 'Order cancelled', 'to' => 'Customer', 'when' => 'Status becomes cancelled', 'state' => $onOff($mailer->cancelledEnabled()), 'where' => 'Emails → All mail settings → Order status emails'],
            ['key' => 'order_refunded', 'name' => 'Refund sent', 'to' => 'Customer', 'when' => 'A refund settles', 'state' => $onOff($mailer->refundEnabled()), 'where' => 'Store → Modules'],
            ['key' => 'order_invoice', 'name' => 'Invoice', 'to' => 'Customer', 'when' => 'You press Email invoice on an order', 'state' => 'manual', 'where' => 'Store → Orders → an order'],
            ['key' => 'back_in_stock', 'name' => 'Back in stock', 'to' => 'Shopper who asked', 'when' => 'A sold-out product returns', 'state' => $onOff($settings->moduleEnabled('back_in_stock', false)), 'where' => 'Store → Modules'],
            ['key' => 'cart_recovery', 'name' => 'Basket reminder', 'to' => 'Shopper who asked', 'when' => 'On the schedule you set', 'state' => $onOff($settings->moduleEnabled('abandoned_cart', false)), 'where' => 'Store → Modules'],
            ['key' => 'customer_invite', 'name' => 'Account invite', 'to' => 'Imported customer', 'when' => 'You press Send account invite', 'state' => 'manual', 'where' => 'Store → Customers'],
            ['key' => 'newsletter_confirm', 'name' => 'Newsletter confirmation', 'to' => 'Subscriber', 'when' => 'Someone signs up', 'state' => $onOff($settings->moduleEnabled('newsletter', false)), 'where' => 'Store → Modules'],
            ['key' => 'quiz_plan', 'name' => 'Skin quiz plan', 'to' => 'Shopper', 'when' => 'The quiz is finished with an email', 'state' => 'always', 'where' => '—'],
            ['key' => 'password_reset', 'name' => 'Password reset', 'to' => 'Customer', 'when' => 'Forgot password', 'state' => 'always', 'where' => '—'],
            ['key' => 'verify_email', 'name' => 'Verify email', 'to' => 'Customer', 'when' => 'Account sign-up', 'state' => 'always', 'where' => '—'],
        ];
    }

    /** @return array{sent: int, failed: int} */
    private function sentLastWeek(): array
    {
        try {
            if (! Schema::hasTable('mail_deliveries')) {
                return ['sent' => 0, 'failed' => 0];
            }

            // One grouped query, not two counts.
            $rows = DB::table('mail_deliveries')
                ->where('created_at', '>=', now()->subDays(7))
                ->selectRaw("SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS sent, COUNT(*) AS total")
                ->first();

            $sent = (int) ($rows->sent ?? 0);

            return ['sent' => $sent, 'failed' => max(0, (int) ($rows->total ?? 0) - $sent)];
        } catch (\Throwable) {
            return ['sent' => 0, 'failed' => 0];
        }
    }

    // ------------------------------------------------------------------- sending

    public function sending(): JsonResponse
    {
        return response()->json($this->sendingState());
    }

    public function saveSending(Request $request): JsonResponse
    {
        $data = $request->validate([
            'transport' => ['required', 'string'],
            'settings' => ['sometimes', 'array'],
        ]);

        $offered = $this->offeredKeys();

        // A select stores one of its own options, or the request is refused.
        if (! in_array($data['transport'], $offered, true)) {
            return response()->json([
                'ok' => false,
                'error' => 'That is not one of the sending options on this screen.',
            ], 422);
        }

        $values = (array) ($data['settings'] ?? []);

        if (($unknown = array_diff(array_keys($values), self::SENDING_KEYS)) !== []) {
            return response()->json([
                'ok' => false,
                'error' => 'Unknown setting: ' . implode(', ', $unknown),
            ], 422);
        }

        $rules = [];
        $checks = [
            'mail_from_name' => ['string', 'max:120'],
            'mail_from_address' => ['string', 'email', 'max:255'],
            'mail_reply_to' => ['string', 'email', 'max:255'],
            'mail_merchant_address' => ['string', 'email', 'max:255'],
            'mail_gmail_username' => ['string', 'email', 'max:255'],
            'mail_gmail_password' => ['string', 'max:255'],
        ];

        // Blank boxes are "clear it" (or, for the password, "unchanged"), and
        // arrive as null after ConvertEmptyStringsToNull — see
        // MailApiController::save() for the trap this skip avoids.
        foreach ($values as $key => $value) {
            if ($value === null || (is_string($value) && trim($value) === '')) {
                $values[$key] = '';

                continue;
            }

            $rules['settings.' . $key] = $checks[$key];
        }

        if ($rules !== []) {
            $request->validate($rules);
        }

        $rejected = $this->settings->save(['mail_transport' => $data['transport']] + $values);
        $this->configurator->refresh();

        if ($rejected !== []) {
            return response()->json([
                'ok' => false,
                'error' => '“' . implode('”, “', array_values($rejected)) . '” '
                    . (count($rejected) === 1 ? 'is not a valid value and was' : 'are not valid values and were')
                    . ' NOT saved — what was stored before is unchanged. Everything else was saved.',
                'rejected' => array_keys($rejected),
            ] + $this->sendingState(), 422);
        }

        return response()->json(['ok' => true] + $this->sendingState());
    }

    /** POST /admin-api/emails/test — through the CHOSEN (saved) transport. */
    public function test(Request $request): JsonResponse
    {
        $data = $request->validate([
            'to' => ['required', 'string', 'max:255', new \App\Rules\StorefrontEmail],
            // A select stores (here: sends) one of its own options.
            'which' => ['sometimes', 'string', 'in:' . implode(',', array_merge(array_keys(self::samples()), array_keys(self::ALIASES)))],
        ]);

        $which = (string) ($data['which'] ?? 'plain');
        $sample = null;

        if ($which !== 'plain') {
            $which = self::ALIASES[$which] ?? $which;

            try {
                $sample = \App\Services\Mail\Kit\KitSamples::mailable($which);
            } catch (\Throwable $e) {
                return response()->json(['ok' => false, 'error' => 'That email could not be filled for a test: ' . class_basename($e) . '.'], 422);
            }

            if ($sample === null) {
                return response()->json([
                    'ok' => false,
                    'error' => 'There is no order yet to fill that email with. Send the plain test message instead.',
                ], 422);
            }
        }

        // 200 either way: a refused send is the answer the owner asked for,
        // carrying the transport's own words, not a broken request.
        return response()->json($this->tester->send($data['to'], $sample, 'test.' . $which));
    }

    // ----------------------------------------------------------- domain check

    /** GET: the last answer, without looking anything up. */
    public function dns(DomainCheck $check): JsonResponse
    {
        return response()->json(['domain' => $check->domain(), 'last' => $check->last()]);
    }

    /** POST: look the three records up now (public DNS, read-only). */
    public function runDns(DomainCheck $check): JsonResponse
    {
        return response()->json(['domain' => $check->domain(), 'last' => $check->run()]);
    }

    // ---------------------------------------------------------------- preview

    /**
     * The real order confirmation, rendered with the saved settings and the
     * latest order, for the Design & branding preview. Served as a document
     * the screen puts in a sandboxed iframe. Customer data stays
     * behind the owner-only capability, like the order screens themselves.
     *
     * Lane EK: `?template=<key>` (any email of KitSections::TEMPLATES) renders
     * that email instead — the template editor's live preview — and a POST
     * carries the editor's UNSAVED sections and words, laid over the saved
     * ones for this one render and never stored. With no `template` the
     * answer is exactly what it was before (Design & branding's preview).
     */
    public function preview(?Request $request = null): \Illuminate\Http\Response
    {
        $request ??= request();
        $template = (string) $request->query('template', '');

        if ($template !== '' && isset(\App\Services\Mail\Kit\KitSections::TEMPLATES[$template])) {
            $sections = $request->isMethod('post') && is_array($request->input('sections')) ? ['sections' => $request->input('sections')] : null;
            $words = $request->isMethod('post') && is_array($request->input('words')) ? $request->input('words') : [];
            $render = static fn (): string => \App\Services\Mail\Kit\EmailWording::withDraft($template, $words, static fn (): string => \App\Services\Mail\Kit\KitSamples::html($template));
            // The editor's English | العربية switch: the same email, in Arabic.
            $locale = app()->getLocale();

            if ($request->query('locale') === 'ar') {
                app()->setLocale('ar');
            }

            try {
                $html = $sections === null ? $render() : \App\Services\Mail\Kit\KitSections::withDraft($template, $sections, $render);
            } finally {
                app()->setLocale($locale);
            }
        } else {
            $order = Order::query()->with('items')->orderByDesc('id')->first();

            $html = $order === null
                ? '<p style="font-family:sans-serif;color:#626c80;padding:24px">The preview fills itself from the shop\'s latest order, and there is no order yet.</p>'
                : (string) (new OrderConfirmation($order))->render();
        }

        return response('<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><body style="margin:0">' . $html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            // The screen frames this with sandbox="" (no script, no forms, no
            // same-origin). No enforcing content-security header: this
            // codebase ships report-only only (SecurityCspTest).
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
        ]);
    }

    /** @return list<string> */
    private function offeredKeys(): array
    {
        $current = $this->settings->transport();

        return in_array($current, self::OFFERED, true)
            ? self::OFFERED
            : [...self::OFFERED, $current];
    }

    private function sendingState(): array
    {
        $current = $this->settings->transport();
        $values = $this->settings->all();

        $options = array_map(fn (string $key): array => [
            'key' => $key,
            'label' => self::label($key) . (in_array($key, self::OFFERED, true) ? '' : ' — current setting'),
        ], $this->offeredKeys());

        return [
            'transport' => $current,
            'options' => $options,
            'values' => [
                'mail_from_name' => (string) $values['mail_from_name'],
                'mail_from_address' => (string) $values['mail_from_address'],
                'mail_reply_to' => (string) $values['mail_reply_to'],
                'mail_merchant_address' => (string) $values['mail_merchant_address'],
                'mail_gmail_username' => (string) $values['mail_gmail_username'],
            ],
            // Write-only: whether one is stored, never what it is.
            'gmail_password' => ['has_value' => $this->settings->hasGmailPassword()],
            'configured' => $this->settings->configured(),
            'missing' => $this->settings->missing(),
            'effective_from' => $this->settings->fromAddress(),
            'active' => $this->configurator->activeTransport(),
            'last_test' => $this->settings->lastTest(),
            'samples' => self::samples(),
            'has_order' => Order::query()->exists(),
            'dns' => app(DomainCheck::class)->last(),
            'domain' => app(DomainCheck::class)->domain(),
        ];
    }

    private static function label(string $key): string
    {
        return match ($key) {
            MailSettings::TRANSPORT_SERVER => "This server's mail",
            MailSettings::TRANSPORT_GMAIL => 'Google Workspace (Gmail SMTP)',
            MailSettings::TRANSPORT_SMTP => 'A dedicated SMTP server',
            MailSettings::TRANSPORT_LOG => 'Nothing — written to the log (testing only)',
            default => $key,
        };
    }

    // ------------------------------------------------------------------ branding

    public function branding(): JsonResponse
    {
        return response()->json($this->brandingState());
    }

    public function saveBranding(Request $request): JsonResponse
    {
        $data = $request->validate(['settings' => ['required', 'array']]);
        $values = $data['settings'];

        /*
         * Fonts and colours (EmailLook) travel in the same payload as the
         * contact details because they are on the same screen, and are split
         * off here: each half goes to its own writer. A font must be one of
         * EmailLook::FONTS' keys and a colour #rrggbb, or the whole save is
         * refused before anything is written.
         */
        $look = array_intersect_key($values, EmailLook::DEFAULTS);
        $values = array_diff_key($values, EmailLook::DEFAULTS);

        foreach ($look as $key => $raw) {
            if (EmailLook::clean($key, $raw) === null) {
                return response()->json([
                    'ok' => false,
                    'error' => match (true) {
                        str_contains($key, 'font') => 'That font is not one of the choices on this screen.',
                        $key === EmailLook::LOGO => 'The logo must be a picture from the Media Library or an https:// address.',
                        default => 'A colour must be written as # and six hex digits, e.g. #C13E63.',
                    },
                    'rejected' => [$key],
                ], 422);
            }
        }

        if (($unknown = array_diff(array_keys($values), self::BRANDING_KEYS)) !== []) {
            return response()->json([
                'ok' => false,
                'error' => 'Unknown setting: ' . implode(', ', $unknown),
            ], 422);
        }

        $checks = [
            'mail_support_email' => ['string', 'email', 'max:255'],
            // Digits, spaces, + ( ) and dashes only: it becomes a wa.me link.
            'mail_support_whatsapp' => ['string', 'max:60', 'regex:/^\+?[0-9][0-9 ()\-]{5,}$/'],
            'mail_address_dubai' => ['string', 'max:300'],
            'mail_address_korea' => ['string', 'max:300'],
            'mail_support_instagram' => ['string', 'max:200'],
            'mail_signature' => ['string', 'max:500'],
        ];

        $rules = [];

        foreach ($values as $key => $value) {
            if ($value === null || (is_string($value) && trim($value) === '')) {
                $values[$key] = '';

                continue;
            }

            $rules['settings.' . $key] = $checks[$key];

            /*
             * An address is typed in a box with one line per line. It is
             * stored the way the signature is, with | between lines, so that
             * EmailBranding splits both the same way and no newline ever
             * reaches a settings row or a header-adjacent string.
             */
            if (in_array($key, ['mail_address_dubai', 'mail_address_korea'], true) && is_string($value)) {
                $values[$key] = preg_replace('/\s*(?:\r\n|\r|\n)+\s*/', ' | ', trim($value)) ?? '';
            }
        }

        if ($rules !== []) {
            $request->validate($rules, [
                'settings.mail_support_whatsapp.regex' => 'The WhatsApp number may hold only digits, spaces, + ( ) and dashes.',
            ]);
        }

        $rejected = $values === [] ? [] : $this->settings->save($values);
        app(EmailLook::class)->save($look);

        if ($rejected !== []) {
            return response()->json([
                'ok' => false,
                'error' => '“' . implode('”, “', array_values($rejected)) . '” was NOT saved.',
                'rejected' => array_keys($rejected),
            ] + $this->brandingState(), 422);
        }

        return response()->json(['ok' => true] + $this->brandingState());
    }

    private function brandingState(): array
    {
        $values = $this->settings->all();
        $branding = app(EmailBranding::class);

        return [
            'values' => [
                'mail_support_email' => (string) $values['mail_support_email'],
                'mail_support_whatsapp' => (string) $values['mail_support_whatsapp'],
                'mail_address_dubai' => (string) $values['mail_address_dubai'],
                'mail_address_korea' => (string) $values['mail_address_korea'],
                'mail_support_instagram' => (string) $values['mail_support_instagram'],
                'mail_signature' => (string) $values['mail_signature'],
            ],
            // Where Design & branding's "Send test" sends: the last address
            // a test went to from Sending & delivery.
            'last_to' => (string) ($this->settings->lastTest()['to'] ?? ''),
            // Fonts and colours: the stored value, and the closed font list.
            'look' => app(EmailLook::class)->values(),
            'fonts' => array_map(static fn (array $f): string => $f[0], EmailLook::FONTS),
            'look_defaults' => EmailLook::DEFAULTS,
            // Exactly what the next order email's footer will print.
            'footer' => [
                'support' => $branding->support(),
                'addresses' => $branding->addresses(),
                'links' => $branding->footer()['links'],
                'store' => $branding->storeName(),
            ],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Services\SettingsService;

/**
 * Everything the owner fills in on Store -> Mail, read and written the way this
 * project stores operator-set values: rows in `settings` through
 * SettingsService -- except the password, which goes to MailCredentials
 * (encrypted) for the reasons the create_mail_credentials migration sets out.
 *
 * EVERY BOX ON THIS SCREEN MAY BE LEFT EMPTY, AND THE STORE STILL SENDS. That
 * is new, and it is the correction this class exists to carry. `mail_transport`
 * used to default to `smtp`; MailConfigurator fell back to the `log` transport
 * whenever the SMTP boxes were not filled in; and so the live store spent its
 * whole life writing order confirmations to storage/logs and delivering them to
 * nobody, while Store → Mail reported no error a non-technical owner would read
 * as one. The default is now TRANSPORT_SERVER -- the host's own mail -- and the
 * `log` transport is only ever reached because somebody chose it.
 */
class MailSettings
{
    /**
     * The form, declared once.
     *
     * key => [type, label, help]. `secret` is the type the admin API refuses to
     * echo back; everything else is returned with its stored value.
     *
     * @var array<string, array{0:string,1:string,2:string}>
     */
    public const SCHEMA = [
        'mail_transport' => ['choice', 'How this store sends email', 'Leave this on "Use this server\'s mail" unless your host has told you otherwise — it needs nothing filled in below. Pick the SMTP option only if you have a mailbox on a dedicated mail server, and then fill in the six boxes under it. The last option sends nothing at all and is for checking a template.'],
        'mail_host' => ['text', 'SMTP host', 'From the hosting control panel, e.g. smtp.hostinger.com'],
        'mail_port' => ['text', 'Port', '465 with SSL, or 587 with TLS. Shared hosts commonly block 25.'],
        'mail_encryption' => ['choice', 'Encryption', 'ssl for port 465, tls for port 587, none only on a local relay.'],
        'mail_username' => ['text', 'Username', 'Usually the full mailbox address, e.g. no-reply@kbeautybliss.com'],
        'mail_password' => ['secret', 'Password', 'The mailbox password. Stored encrypted and never shown again once saved.'],
        'mail_from_address' => ['text', 'From address', 'What customers see as the sender. On most shared hosts this MUST be a mailbox on this domain or the host rejects the message.'],
        'mail_from_name' => ['text', 'From name', 'e.g. K Beauty Bliss'],
        /*
         * Where the store itself is told an order has come in.
         *
         * A separate field rather than reusing the From address, because they are
         * different jobs: From is what a customer sees and on a shared host must
         * be a mailbox on this domain, while this is wherever the person who packs
         * the orders actually reads their mail — a personal Gmail, more often than
         * not. Left blank it falls back to the From address, which is the one
         * address we know exists; OrderMailer::merchantAddress() is where that
         * fallback lives.
         *
         * MailApiController::save() carries a hardcoded rule list and has no entry
         * for this key, and that controller belongs to another lane. So the format
         * check is done in save() below instead — a settings screen that accepts
         * "not an address" and then fails silently at send time is how a merchant
         * finds out weeks later that they were never told about any of it.
         */
        'mail_merchant_address' => ['text', 'New-order alerts to', 'Where you want to be told an order has come in. Leave blank to use the From address above.'],
        'mail_timeout' => ['text', 'Timeout (seconds)', 'How long to wait for the mail server before giving up. Blank uses the default. Only used by the dedicated-SMTP option.'],

        /*
         * ── What the customer sees at the bottom of an email ──
         *
         * The owner asked for the order confirmation to "focus more on support
         * by showing our WhatsApp, email, Instagram etc, so the user will feel
         * more trust and comfort". These are the fields that block feeds from,
         * and NOTHING in the templates is hardcoded: a blank box here falls
         * back to the value the storefront already uses (EmailBranding), and a
         * channel with no value anywhere is simply not printed. A support block
         * advertising a WhatsApp number nobody answers is worse than no block.
         *
         * They live on Store → Mail rather than Store → Business Details
         * because that screen's save allowlist is a hardcoded list in
         * AdminController, which belongs to another lane -- a key added there
         * would be silently rejected on save, which is precisely the "reports
         * success and writes nothing" fault that file's own comments record
         * twice. This screen renders whatever SCHEMA declares and validates
         * against it, so a field added here works the moment the package lands.
         */
        'mail_support_whatsapp' => ['text', 'Support WhatsApp number', 'Printed in every order confirmation as a "Chat on WhatsApp" link. Blank uses the number the storefront footer already shows.'],
        'mail_support_email' => ['text', 'Support email address', 'Where a customer should write for help. Blank uses the From address above.'],
        'mail_support_instagram' => ['text', 'Instagram', 'Your handle (@kbeauty.bliss) or the full profile link. Blank uses the one saved under Store → Business Details.'],
        'mail_signature' => ['text', 'Signature', 'Signed at the foot of every order email, e.g. "With love, the K Beauty Bliss team". Type a | where you want a new line. Blank prints the store name.'],

        /*
         * ── What a customer is told about money when an order is cancelled ──
         *
         * SHIPPED BLANK, AND THAT IS THE POINT. The cancellation email used to
         * carry a sentence of its own about the money coming back. Cancelling an
         * order in this application starts no refund — PaymentRefunder is a
         * separate action an operator takes deliberately — so that sentence was
         * describing something nobody had done, and the customer waited for it.
         * App\Mail\OrderStatusChanged sets the whole thing out at length.
         *
         * What the email now says is only what the order's own rows record: a
         * refund that really is on the books, or that nothing was ever taken, or
         * that money was taken and no refund has been recorded. In that last
         * case, and ONLY that case, whatever is typed here is printed after it.
         * It is the one place in this flow where a person has to say what
         * happens next, because the application cannot know: no refund policy is
         * written down anywhere in this shop, and a default invented here would
         * be the same untruth in a different hand.
         *
         * A cap, like the four above it, because MailApiController's rule list
         * is another lane's hardcoded array and has no entry for this key.
         */
        'mail_cancelled_refund_note' => ['text', 'Cancelled orders: what you tell a paid customer about their money', 'Added to the cancellation email ONLY when the order was paid and no refund has been recorded against it yet — e.g. how you send the money back and how long it takes. Leave blank and the email says nothing beyond the amount and that no refund has been recorded.'],

        /*
         * ── What the dispatch email says about how long delivery takes ──
         *
         * App\Mail\OrderStatusChanged carried this as a constant: a UAE delivery
         * window in a PHP file, while the owner's own editable delivery wording
         * lives in `delivery_default_text` and is read only by
         * App\Support\DeliveryLine. Rewrite that line and the dispatch email
         * went on saying the old thing for ever.
         *
         * IT IS A SECOND BOX AND NOT A REUSE OF THAT ONE, because the two
         * sentences are measured from different events — this from DISPATCH,
         * the storefront's from the ORDER — and on this shop the gap between
         * them is configured (`dispatch_cutoff_hour`, `dispatch_days`) rather
         * than notional. OrderStatusChanged::SHIPPED_TIMING_KEY sets out the
         * whole argument. The label says which clock it is on, because a box
         * that does not say so invites exactly the substitution that was
         * refused.
         *
         * SHIPPED BLANK: blank means the email says what it says today, to the
         * byte.
         */
        'mail_shipped_timing_note' => ['text', 'Dispatch email: how long delivery takes AFTER DISPATCH', 'Printed after "Your order has left us and is with the courier." on orders going to the UAE. Measured from the moment the parcel leaves you — not from when the order was placed, which is what the delivery line under Place order (Store → Delivery & Shipping) measures, so the two are separate boxes on purpose. Leave blank and the email keeps its current wording. Orders outside the UAE are given no window at all, because none has been measured.'],

        /*
         * ── Where a customer's reply goes ──
         *
         * Nothing in this application set a Reply-To. A reply therefore went to
         * the From address, and fromAddress() derives `no-reply@<domain>` from
         * APP_URL whenever the From box is empty — which is the shipped state of
         * this screen, deliberately, because the server transport needs nothing
         * filled in. So the footer's invitation to reply, "it reaches us", was
         * on the shipped configuration an invitation to write to a mailbox named
         * for not being read. The audit lane removed the sentence rather than
         * leave the claim standing.
         *
         * This is the other half of that repair: an address the owner can set,
         * wired into the mailer by MailConfigurator, and the invitation restored
         * in emails/layout.blade.php ONLY while this box has something in it.
         * Blank by default, and the sentence appears only when the address does.
         *
         * A SEPARATE FIELD RATHER THAN THE FROM ADDRESS, for the same reason
         * `mail_merchant_address` is separate: on a shared host the From must be
         * a mailbox on this domain or the relay rejects the message, while the
         * place a customer's reply should land is wherever the owner actually
         * reads his mail. The two are different jobs.
         *
         * Validated in save() below rather than in MailApiController's rule
         * list, which is another lane's hardcoded array — see
         * `mail_merchant_address`. An unvalidated address here is a customer
         * reply that bounces.
         */
        'mail_reply_to' => ['text', 'Reply-To address', 'Where a customer\'s reply to an order email goes. Set this and every order email invites the customer to reply and says the reply reaches you. Leave it blank and no such invitation is printed, because with the From box empty this store sends as no-reply@ and a reply would reach nobody.'],
    ];

    /*
     * ── The three ways this store can send, and why they are spelled out ──
     *
     * `server` is the host's own mail — PHP's mail() function, handed to the
     * local MTA that every other PHP application on this account already uses.
     * It is the DEFAULT, and that is the whole point of this change: the value
     * used to default to `smtp`, MailConfigurator fell back to `log` whenever
     * SMTP was not filled in, and the owner's live store was therefore writing
     * every order confirmation to storage/logs and delivering it to nobody,
     * with a green tick on the screen. An install that has never been
     * configured now sends for real.
     *
     * `smtp` is the deliberate opt-in: a dedicated mail server, credentials and
     * all, for an owner who has one.
     *
     * `log` is still reachable, because checking a template without mailing a
     * live customer is a real need — but nothing falls back to it any more. It
     * is only ever what somebody explicitly chose.
     */
    public const TRANSPORT_SERVER = 'server';

    public const TRANSPORT_SMTP = 'smtp';

    public const TRANSPORT_LOG = 'log';

    /** The canonical values. This is what is stored and what transport() returns. */
    public const TRANSPORT_KEYS = [self::TRANSPORT_SERVER, self::TRANSPORT_SMTP, self::TRANSPORT_LOG];

    public const DEFAULT_TRANSPORT = self::TRANSPORT_SERVER;

    /**
     * The same three, as the owner reads them.
     *
     * WHY THE LABEL IS THE DROPDOWN VALUE. Store → Mail renders a `choice`
     * field from MailApiController::optionsFor(), which returns this constant,
     * and the admin's mailField() prints each option string as BOTH the value
     * and the visible text (`<option value="${o}">${o}</option>`). That file is
     * another lane's and this lane may not edit it. So the only way the owner
     * sees "Use this server's mail (default)" instead of the word "server" is
     * for that sentence to be the option string.
     *
     * Nothing stores a sentence, though. save() canonicalises whatever arrives
     * -- label or key -- down to one of TRANSPORT_KEYS before it is written, and
     * all() maps the stored key back to its label so the right option comes
     * back selected. A reworded label therefore changes the screen and not a
     * single stored row.
     *
     * No commas in any label: MailApiController validates with
     * `in:` . implode(',', TRANSPORTS), and Laravel splits that on commas.
     */
    public const TRANSPORT_LABELS = [
        self::TRANSPORT_SERVER => "Use this server's mail (default)",
        self::TRANSPORT_SMTP => 'Use a dedicated SMTP server',
        self::TRANSPORT_LOG => 'Send nothing — write to the log (for testing only)',
    ];

    /** What the screen offers and what a save is validated against. */
    public const TRANSPORTS = [
        "Use this server's mail (default)",
        'Use a dedicated SMTP server',
        'Send nothing — write to the log (for testing only)',
    ];

    public const ENCRYPTIONS = ['ssl', 'tls', 'none'];

    /**
     * Caps for the fields MailApiController has no validation rule for.
     *
     * Every other key on this screen is bounded by that controller's `$checks`
     * array. These were added by later lanes and that file is not this lane's to
     * edit, so the bound lives here -- an unbounded operator string rendered
     * into every order email is a footgun whoever finds it.
     */
    public const MAX_LENGTHS = [
        'mail_signature' => 500,
        'mail_support_whatsapp' => 60,
        'mail_support_email' => 255,
        'mail_support_instagram' => 200,
        'mail_cancelled_refund_note' => 400,
        'mail_shipped_timing_note' => 400,
        'mail_reply_to' => 255,
    ];

    /** Where the last test-send outcome is kept. Not a credential; a plain setting. */
    public const LAST_TEST_KEY = 'mail_last_test';

    public function __construct(
        private SettingsService $settings,
        private MailCredentials $credentials,
    ) {}

    /**
     * Non-secret values only, defaults filled in. Safe to return from an API.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        $out = [];

        foreach (self::SCHEMA as $key => [$type]) {
            if ($type === 'secret') {
                continue;
            }

            $out[$key] = trim((string) ($this->settings->get($key, '') ?? ''));
        }

        /*
         * The stored value is a canonical key; the screen wants the label, both
         * for the visible text and so the right option comes back selected.
         * Anything unrecognised -- blank, or a value from an older release --
         * lands on the default, which is now the server's own mail rather than
         * a half-configured SMTP that silently became `log`.
         */
        $out['mail_transport'] = self::TRANSPORT_LABELS[self::canonicalTransport($out['mail_transport'])];

        if ($out['mail_encryption'] === '' || ! in_array($out['mail_encryption'], self::ENCRYPTIONS, true)) {
            $out['mail_encryption'] = 'ssl';
        }

        return $out;
    }

    public function get(string $key, string $default = ''): string
    {
        return $this->all()[$key] ?? $default;
    }

    /** The password. Only MailConfigurator has any business calling this. */
    public function password(): string
    {
        return $this->credentials->get('password');
    }

    public function hasPassword(): bool
    {
        return $this->credentials->filled('password');
    }

    /**
     * The canonical transport key: `server`, `smtp` or `log`. Never a label.
     *
     * Read straight from the settings row rather than through all(), which
     * deliberately hands back the human label for the screen.
     */
    public function transport(): string
    {
        return self::canonicalTransport((string) ($this->settings->get('mail_transport', '') ?? ''));
    }

    /**
     * Any spelling of a transport -- canonical key, screen label, or the empty
     * string an install that has never been configured carries -- reduced to one
     * of TRANSPORT_KEYS.
     *
     * The label branch is what lets an owner's choice survive: the admin posts
     * back the sentence it displayed, and this is where that sentence stops.
     */
    public static function canonicalTransport(string $value): string
    {
        $value = trim($value);

        if (in_array($value, self::TRANSPORT_KEYS, true)) {
            return $value;
        }

        foreach (self::TRANSPORT_LABELS as $key => $label) {
            if ($value === $label) {
                return $key;
            }
        }

        return self::DEFAULT_TRANSPORT;
    }

    public function usesServerMail(): bool
    {
        return $this->transport() === self::TRANSPORT_SERVER;
    }

    /**
     * The address a message actually goes out From.
     *
     * The owner's value when they have set one. When they have not -- and on the
     * server transport they need not, which is the point of it -- a no-reply
     * mailbox on this site's own domain, derived from APP_URL.
     *
     * Derived rather than left to config/mail.php's `hello@example.com`, because
     * a shared host's MTA rejects or spam-scores a From on a domain it does not
     * host, and "the emails all went to junk" is the same outcome as not sending
     * them. Nothing is written to the database: this is a computed fallback, so
     * the day the owner types a real address it takes over with no migration.
     */
    public function fromAddress(): string
    {
        $configured = trim((string) $this->get('mail_from_address'));

        if ($configured !== '') {
            return $configured;
        }

        $host = (string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: '');
        $host = preg_replace('/^www\./i', '', $host) ?? '';

        return $host !== '' && str_contains($host, '.') ? 'no-reply@' . $host : '';
    }

    /**
     * The address a customer's reply should go to, or ''.
     *
     * NOTHING IS DERIVED HERE, and that is the difference between this and
     * fromAddress(). A From address has to exist for a message to leave the
     * server at all, so one is computed from APP_URL when the owner has not
     * typed one. A Reply-To does not have to exist, and the only address that
     * could be guessed for it is the same no-reply mailbox the missing From
     * falls back to — which is precisely the address the footer's invitation to
     * reply was removed for pointing at. So a blank box means no Reply-To
     * header and no invitation, rather than a header that sends the customer
     * somewhere nobody reads.
     *
     * Re-checked on the way OUT as well as on the way in. save() refuses a
     * malformed address, but this row can also arrive from an older build, a
     * hand-edited database or a half-applied package, and what it feeds is a
     * header on every order email.
     */
    public function replyToAddress(): string
    {
        $value = trim((string) $this->get('mail_reply_to'));

        return $value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? $value : '';
    }

    /**
     * Can a message actually be attempted?
     *
     * Host, username, password and a from address. Port and encryption have
     * working defaults; those four do not, and a send without them fails in a
     * way that reads as a broken app rather than an unfilled form.
     */
    public function configured(): bool
    {
        if ($this->transport() !== self::TRANSPORT_SMTP) {
            /*
             * Nothing to configure. `log` writes to the log; `server` hands the
             * message to the host's own MTA, which needs no host, no port and no
             * password -- that is exactly why it is the default. Whether that MTA
             * is reachable is a question only a real send can answer, and the
             * Send-a-test button on this screen is where it gets answered.
             */
            return true;
        }

        $values = $this->all();

        return $values['mail_host'] !== ''
            && $values['mail_username'] !== ''
            && $values['mail_from_address'] !== ''
            && $this->hasPassword();
    }

    /** Which required fields are still blank, for an error the owner can act on. */
    public function missing(): array
    {
        if ($this->transport() !== self::TRANSPORT_SMTP) {
            return [];
        }

        $values = $this->all();
        $missing = [];

        foreach (['mail_host' => 'SMTP host', 'mail_username' => 'Username', 'mail_from_address' => 'From address'] as $key => $label) {
            if ($values[$key] === '') {
                $missing[] = $label;
            }
        }

        if (! $this->hasPassword()) {
            $missing[] = 'Password';
        }

        return $missing;
    }

    /**
     * Write the form back.
     *
     * `mail_password` is routed to the encrypted store and a blank one means
     * unchanged; the literal string "-" clears it, which is the only way the
     * screen can offer "forget the stored password" while still rendering the
     * box empty.
     *
     * @param array<string, mixed> $values
     */
    public function save(array $values): void
    {
        foreach ($values as $key => $value) {
            if (! array_key_exists($key, self::SCHEMA)) {
                continue;   // callers validate first; this is belt and braces
            }

            if ($key === 'mail_password') {
                $value = is_string($value) ? trim($value) : '';

                $this->credentials->save(['password' => $value === '-' ? null : $value]);

                continue;
            }

            $value = is_scalar($value) ? trim((string) $value) : '';

            /*
             * A length cap this class applies itself, for the same reason the
             * merchant-address format check below is here: MailApiController's
             * rule list is a hardcoded array in another lane's file and has no
             * entry for these keys, so nothing else bounds them. The signature
             * is rendered into every order email and the rest are single-line
             * facts; neither has any business being a novel.
             */
            if (isset(self::MAX_LENGTHS[$key])) {
                $value = mb_substr($value, 0, self::MAX_LENGTHS[$key]);
            }

            /*
             * The screen posts the label it displayed; a test, a console command
             * or a future caller posts the key. Both end up as the key, so the
             * settings row never holds a sentence and a reworded label does not
             * invalidate a single install's saved choice.
             */
            if ($key === 'mail_transport') {
                $this->settings->set($key, self::canonicalTransport($value));

                continue;
            }

            /*
             * The two fields this class validates itself. See their SCHEMA
             * entries: MailApiController's rule list is in another lane's file
             * and has no entry for either, and an unvalidated address here is an
             * alert nobody ever receives or a customer reply that bounces.
             * Blank is allowed in both cases and means "there is no such
             * address" — for the merchant alert, fall back to From; for
             * Reply-To, set no header and print no invitation to reply.
             *
             * The bad value is DROPPED rather than written: keeping the previous
             * address is a working mailbox, and storing "not an address" is a
             * header the transport refuses.
             */
            if (in_array($key, ['mail_merchant_address', 'mail_reply_to'], true)
                && $value !== ''
                && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }

            $this->settings->set($key, $value);
        }
    }

    /**
     * The outcome of the last test-send: when, to whom, and what happened.
     *
     * A plain setting, not a credential -- it holds no secret, and keeping it
     * in `settings` means it survives in the same place as the rest of the mail
     * configuration and shows up in a database export when somebody is trying
     * to work out why a customer never got an email.
     *
     * @return array<string, mixed>|null
     */
    public function lastTest(): ?array
    {
        $raw = $this->settings->get(self::LAST_TEST_KEY);

        if (is_array($raw)) {
            return $raw;
        }

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }

    /** @param array<string, mixed> $record */
    public function recordTest(array $record): void
    {
        // autoload false: this is diagnostics, read by one admin screen. There
        // is no reason for every storefront page to carry it in the cached
        // autoload payload.
        $this->settings->set(self::LAST_TEST_KEY, $record, false);
    }
}

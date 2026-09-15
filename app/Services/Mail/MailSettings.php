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
 * This app has never sent an email. There is no MAIL_MAILER on the server and
 * nothing has ever called the Mail facade, so every value here starts empty and
 * "not configured" is the normal state rather than an error.
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
        'mail_transport' => ['choice', 'Send using', 'smtp = the mail server below. log = write to the Laravel log and send nothing, for checking a template without mailing anyone.'],
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
        'mail_timeout' => ['text', 'Timeout (seconds)', 'How long to wait for the mail server before giving up. Blank uses the default.'],
    ];

    /** Valid values for the two `choice` fields, so a save cannot store nonsense. */
    public const TRANSPORTS = ['smtp', 'log'];

    public const ENCRYPTIONS = ['ssl', 'tls', 'none'];

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

        if ($out['mail_transport'] === '' || ! in_array($out['mail_transport'], self::TRANSPORTS, true)) {
            $out['mail_transport'] = 'smtp';
        }

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

    public function transport(): string
    {
        return $this->get('mail_transport', 'smtp');
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
        if ($this->transport() === 'log') {
            return true;    // nothing to configure; it writes to the log
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
        if ($this->transport() === 'log') {
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
             * The one field this class validates itself. See the SCHEMA entry:
             * MailApiController's rule list is in another lane's file and has no
             * entry for it, and an unvalidated address here is an alert nobody
             * ever receives. Blank is allowed and means "fall back to From".
             */
            if ($key === 'mail_merchant_address'
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

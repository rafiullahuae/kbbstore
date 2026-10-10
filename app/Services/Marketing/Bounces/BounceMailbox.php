<?php

declare(strict_types=1);

namespace App\Services\Marketing\Bounces;

use App\Services\Mail\MailCredentials;
use App\Services\Mail\MailSettings;
use App\Services\SettingsService;

/**
 * Where bounce reports are read from (Lane EB) — and why it is IMAP.
 *
 * The shop sends through smtp.gmail.com with an app password (Store → Mail →
 * Google Workspace). Google always uses the SIGNED-IN mailbox as the envelope
 * sender (Return-Path) on that route, whatever the message says, so every
 * bounce report — "Mail Delivery Subsystem <mailer-daemon@googlemail.com>" —
 * lands in that mailbox. The options, measured against "the owner sets it up
 * himself in a few clicks":
 *
 *   A. smtp-relay.gmail.com with a custom envelope sender (a bounces@ VERP
 *      address). Needs a Google Admin console relay rule, an IP allow-list or
 *      SMTP auth on the relay, and a second mailbox. Most robust at scale;
 *      not a few clicks.                                         Not chosen.
 *   B. Plus-addressing VERP (info+b-123@…) as the envelope sender over
 *      smtp.gmail.com. Google REWRITES the envelope to the signed-in account,
 *      so the token never comes back.                     Does not work here.
 *   C. A Gmail filter that labels the reports and skips the inbox, and IMAP
 *      reading ONLY that label with the SAME app password.         CHOSEN.
 *      One filter, one checkbox; the reports never reach his inbox, and the
 *      campaign is identified by the Message-ID the shop wrote (BounceRef),
 *      which Google's reports quote.
 *
 * The account and app password default to Store → Mail's Google Workspace
 * ones — an app password works for IMAP as well as SMTP — so the usual setup
 * is: make the filter, tick "Read bounces", Test connection. A different
 * mailbox can be given; its app password is stored encrypted in the same
 * vault as the SMTP one (mail_credentials, encrypted:array) and never
 * returned — the screen is told only whether one is set.
 *
 * Host and port are FIXED (imap.gmail.com:993, TLS): not settings, so the
 * screen cannot be used to make the server connect anywhere else.
 */
final class BounceMailbox
{
    public const HOST = 'imap.gmail.com';

    public const PORT = 993;

    public const DEFAULT_LABEL = 'KBB Bounces';

    public const PROCESSED_SUFFIX = '/Processed';

    public const VAULT = 'bounce_imap_password';

    public const KEYS = [
        'enabled' => 'mkt_bounce_imap_enabled',
        'username' => 'mkt_bounce_imap_username',
        'label' => 'mkt_bounce_imap_label',
    ];

    public function __construct(
        private SettingsService $settings,
        private MailSettings $mail,
        private MailCredentials $vault,
    ) {}

    public function enabled(): bool
    {
        return (string) $this->settings->get(self::KEYS['enabled'], '0') === '1';
    }

    /** The account read: its own box, else Store → Mail's Google account. */
    public function username(): string
    {
        $own = trim((string) $this->settings->get(self::KEYS['username'], ''));

        return $own !== '' && filter_var($own, FILTER_VALIDATE_EMAIL) !== false ? $own : $this->mail->gmailUsername();
    }

    public function usesMailAccount(): bool
    {
        return trim((string) $this->settings->get(self::KEYS['username'], '')) === '' && ! $this->vault->filled(self::VAULT);
    }

    /** Only the reader and the test call this. Never returned to a browser. */
    public function password(): string
    {
        $own = $this->vault->get(self::VAULT);

        return str_replace(' ', '', $own !== '' ? $own : $this->mail->gmailPassword());
    }

    public function hasPassword(): bool
    {
        return $this->vault->filled(self::VAULT) || $this->mail->hasGmailPassword();
    }

    public function label(): string
    {
        $label = self::cleanLabel((string) $this->settings->get(self::KEYS['label'], ''));

        return $label ?? self::DEFAULT_LABEL;
    }

    public function processedLabel(): string
    {
        return $this->label() . self::PROCESSED_SUFFIX;
    }

    /**
     * A label this reader may open, or null. Plain ASCII words only (IMAP
     * names outside ASCII need modified UTF-7), and NEVER the inbox, Sent,
     * Spam, or anything under Gmail's own [Gmail]/ folders: the reader moves
     * what it reads, so pointing it at a real folder would be the one way it
     * could touch the owner's mail.
     */
    public static function cleanLabel(string $raw): ?string
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $raw) === 1) {
            return null;   // a line break in a label is a second IMAP command
        }

        $label = trim(preg_replace('/ +/', ' ', $raw) ?? '');

        if ($label === '' || preg_match('/^[A-Za-z0-9][A-Za-z0-9 _.\-\/]{0,59}$/', $label) !== 1) {
            return null;
        }

        $first = strtolower(explode('/', $label)[0]);

        if (in_array($first, ['inbox', 'sent', 'sent mail', 'drafts', 'spam', 'trash', 'starred', 'important', 'all mail'], true)
            || str_contains($label, '..') || str_ends_with($label, '/')) {
            return null;
        }

        return $label;
    }

    public function configured(): bool
    {
        return $this->username() !== '' && $this->hasPassword();
    }

    /** The mailto: List-Unsubscribe address, or '' when nobody reads it. */
    public function unsubscribeAddress(): string
    {
        $user = $this->username();
        $at = strrpos($user, '@');

        if (! $this->enabled() || ! $this->configured() || $at === false) {
            return '';
        }

        return substr($user, 0, $at) . '+unsubscribe' . substr($user, $at);
    }

    /** What Gmail's filter box "Has the words" should hold. */
    public function filterQuery(): string
    {
        $unsub = $this->unsubscribeAddressFor($this->username());

        return '{from:mailer-daemon from:postmaster' . ($unsub !== '' ? ' to:' . $unsub : '') . '}';
    }

    private function unsubscribeAddressFor(string $user): string
    {
        $at = strrpos($user, '@');

        return $at === false ? '' : substr($user, 0, $at) . '+unsubscribe' . substr($user, $at);
    }

    /**
     * Save from the screen. A blank password box leaves the stored one alone;
     * `forget_password` removes it (back to Store → Mail's).
     *
     * @param  array{enabled?:bool, username?:?string, label?:?string, password?:?string, forget_password?:bool}  $in
     * @return list<string> problems; empty when saved
     */
    public function save(array $in): array
    {
        $problems = [];
        $username = trim((string) ($in['username'] ?? ''));
        $label = trim((string) ($in['label'] ?? ''));

        if ($username !== '' && filter_var($username, FILTER_VALIDATE_EMAIL) === false) {
            $problems[] = 'The mailbox address is not an email address.';
        }

        if ($label !== '' && self::cleanLabel($label) === null) {
            $problems[] = 'Use a plain label name such as "KBB Bounces" — letters, numbers and spaces, and never Inbox, Sent, Spam or Trash.';
        }

        if ($problems !== []) {
            return $problems;
        }

        $this->settings->set(self::KEYS['enabled'], ! empty($in['enabled']) ? '1' : '0', false);
        $this->settings->set(self::KEYS['username'], $username, false);
        $this->settings->set(self::KEYS['label'], $label !== '' ? (string) self::cleanLabel($label) : self::DEFAULT_LABEL, false);

        if (! empty($in['forget_password'])) {
            $this->vault->put(self::VAULT, null);
        } elseif (trim((string) ($in['password'] ?? '')) !== '') {
            $this->vault->put(self::VAULT, str_replace(' ', '', (string) $in['password']));
        }

        return [];
    }

    /**
     * Masked state for the screen. The password is a boolean, the address is
     * shown (the owner typed it), nothing secret leaves.
     *
     * @return array<string, mixed>
     */
    public function state(): array
    {
        $user = $this->username();

        return [
            'enabled' => $this->enabled(),
            'host' => self::HOST . ':' . self::PORT . ' (SSL)',
            'username' => $user,
            'own_username' => trim((string) $this->settings->get(self::KEYS['username'], '')),
            'mail_account' => $this->mail->gmailUsername(),
            'uses_mail_account' => $this->usesMailAccount(),
            'password_set' => $this->hasPassword(),
            'own_password_set' => $this->vault->filled(self::VAULT),
            'label' => $this->label(),
            'processed_label' => $this->processedLabel(),
            'filter_query' => $this->filterQuery(),
            'unsubscribe_address' => $this->unsubscribeAddressFor($user),
            'google' => $this->mail->transport() === MailSettings::TRANSPORT_GMAIL,
        ];
    }
}

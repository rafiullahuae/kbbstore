<?php

declare(strict_types=1);

namespace App\Services\CustomerInvites;

use App\Models\Customer;
use App\Services\Mail\EmailBranding;
use App\Services\SettingsService;
use App\Support\StoreTime;
use Carbon\CarbonInterface;

/**
 * The words of the account-invite email, and the one place they are filled in.
 * (Lane PQ — Store → Customers → Send account invite.)
 *
 * The owner asked to "see the email template and [have it] edited". So the
 * subject and body are HIS, stored in settings, and this class does three
 * things with them and nothing else: says what the shipped default is, refuses
 * a template that cannot work, and substitutes the placeholders.
 *
 * ── THE BODY IS PLAIN TEXT, AND IT IS ESCAPED WHERE IT IS PRINTED ──────────
 *
 * The admin box is a textarea, not a rich-text editor. segments() hands the
 * Blade view a list of plain strings and one marker for the link; the view runs
 * every string through e() before nl2br(), exactly as emails/back-in-stock does
 * with the owner's prose there. A compromised admin account therefore cannot
 * use this to put markup, a tracking pixel or a different link into an email
 * sent in the shop's name — and neither can a shopper whose NAME is
 * `<a href=…>`, because {name} is substituted into a string that is escaped
 * after substitution, never before.
 *
 * ── {set_password_link} IS REQUIRED, AND ONLY IN THE BODY ──────────────────
 *
 * An invite with no link is an email that tells somebody they have an account
 * they cannot get into, so saving one is refused, not warned about. In the
 * SUBJECT it is refused too: a subject line is shown on lock screens and kept
 * in every mail server's log, and Store → Mail → log keeps subjects forever.
 */
final class InviteTemplate
{
    public const KEY_SUBJECT = 'customer_invite.subject';

    public const KEY_BODY = 'customer_invite.body';

    public const KEY_EXPIRY = 'customer_invite.expiry_days';

    public const LINK = 'set_password_link';

    /** name => what it becomes, for the legend beside the editor. */
    public const PLACEHOLDERS = [
        'first_name' => 'The customer\'s first name, or "there" when the record has no name.',
        'name' => 'Their full name as it is on the customer record, or their email address when there is none.',
        'email' => 'The email address the invite is sent to.',
        'shop_name' => 'The shop\'s name from Settings → Business details.',
        'set_password_link' => 'Required. A button that opens a page where they choose a password. It works once and expires.',
        'link_expires' => 'The date the link stops working, e.g. "9 October 2026".',
    ];

    public const DEFAULT_EXPIRY_DAYS = 7;

    public const MIN_EXPIRY_DAYS = 1;

    public const MAX_EXPIRY_DAYS = 30;

    public const SUBJECT_MAX = 200;

    public const BODY_MAX = 10000;

    public const DEFAULT_SUBJECT = 'Your {shop_name} account is ready';

    public const DEFAULT_BODY = <<<'TXT'
Hi {first_name},

Thank you for shopping with {shop_name}. Our shop has moved to a new website, and we have set up an account for you with this email address ({email}), so you can see your orders and check out faster next time.

Your account is ready. All you need to do is choose a password:

{set_password_link}

This link is just for you and works once. It expires on {link_expires}. After that you can still use "Forgot password" on the sign-in page.

If you did not shop with us, you can ignore this email. Nothing happens unless the link is used.
TXT;

    public function __construct(
        private SettingsService $settings,
        private EmailBranding $branding,
    ) {}

    /** @return array{subject: string, body: string, expiry_days: int} */
    public function current(): array
    {
        $subject = trim((string) ($this->settings->get(self::KEY_SUBJECT, '') ?? ''));
        $body = trim((string) ($this->settings->get(self::KEY_BODY, '') ?? ''));

        return [
            'subject' => $subject !== '' ? $subject : self::DEFAULT_SUBJECT,
            'body' => $body !== '' ? $body : self::DEFAULT_BODY,
            'expiry_days' => self::clampExpiry((int) ($this->settings->get(self::KEY_EXPIRY, self::DEFAULT_EXPIRY_DAYS) ?? self::DEFAULT_EXPIRY_DAYS)),
        ];
    }

    public function save(string $subject, string $body, int $expiryDays): void
    {
        $this->settings->set(self::KEY_SUBJECT, self::normaliseSubject($subject), false);
        $this->settings->set(self::KEY_BODY, self::normaliseBody($body), false);
        $this->settings->set(self::KEY_EXPIRY, self::clampExpiry($expiryDays), false);
    }

    /**
     * Why this template cannot be used, or [] when it can.
     *
     * @return array<string, string> field => sentence
     */
    public static function problems(string $subject, string $body): array
    {
        $errors = [];
        $subject = self::normaliseSubject($subject);
        $body = self::normaliseBody($body);

        if ($subject === '') {
            $errors['subject'] = 'Write a subject line.';
        } elseif (mb_strlen($subject) > self::SUBJECT_MAX) {
            $errors['subject'] = 'The subject is longer than ' . self::SUBJECT_MAX . ' characters.';
        } elseif (str_contains($subject, '{' . self::LINK . '}')) {
            $errors['subject'] = 'The {set_password_link} cannot go in the subject — it belongs in the body. A subject is shown on lock screens and kept in mail logs.';
        } elseif (($unknown = self::unknownPlaceholders($subject)) !== []) {
            $errors['subject'] = 'Unknown placeholder ' . implode(', ', $unknown) . ' in the subject.';
        }

        if ($body === '') {
            $errors['body'] = 'Write the email.';
        } elseif (mb_strlen($body) > self::BODY_MAX) {
            $errors['body'] = 'The email is longer than ' . self::BODY_MAX . ' characters.';
        } elseif (! str_contains($body, '{' . self::LINK . '}')) {
            $errors['body'] = 'The email must contain {set_password_link} — without it the customer has no way to set a password.';
        } elseif (($unknown = self::unknownPlaceholders($body)) !== []) {
            $errors['body'] = 'Unknown placeholder ' . implode(', ', $unknown) . '. The ones that work are listed beside the editor.';
        }

        return $errors;
    }

    /** @return list<string> e.g. ['{firstname}'] */
    public static function unknownPlaceholders(string $text): array
    {
        preg_match_all('/\{([a-z_]+)\}/i', $text, $m);

        $unknown = [];

        foreach ($m[1] as $name) {
            if (! array_key_exists($name, self::PLACEHOLDERS)) {
                $unknown['{' . $name . '}'] = true;
            }
        }

        return array_keys($unknown);
    }

    public static function clampExpiry(int $days): int
    {
        return max(self::MIN_EXPIRY_DAYS, min(self::MAX_EXPIRY_DAYS, $days));
    }

    /** One line, no control characters. A subject is a mail header. */
    public static function normaliseSubject(string $subject): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $subject));
    }

    public static function normaliseBody(string $body): string
    {
        $body = str_replace(["\r\n", "\r"], "\n", $body);

        // Control characters other than newline and tab have no business in prose.
        return trim((string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', '', $body));
    }

    /* ------------------------------------------------------------ filling in */

    /**
     * The values for one customer. The link is passed in: it is minted by the
     * sender at the moment of sending, and a preview gets a dummy.
     *
     * @return array<string, string>
     */
    public function values(Customer $customer, string $link, CarbonInterface $expires): array
    {
        $name = trim((string) ($customer->name ?? ''));
        $first = trim((string) ($customer->first_name ?? ''));

        if ($name === '') {
            $name = trim($first . ' ' . trim((string) ($customer->last_name ?? '')));
        }

        if ($first === '' && $name !== '') {
            $first = (string) strtok($name, ' ');
        }

        $email = (string) $customer->email;

        return [
            'first_name' => $first !== '' ? $first : 'there',
            'name' => $name !== '' ? $name : $email,
            'email' => $email,
            'shop_name' => $this->branding->storeName(),
            'set_password_link' => $link,
            'link_expires' => $expires->copy()->setTimezone(StoreTime::timezone())->format('j F Y'),
        ];
    }

    /** The subject, filled in, on one line. */
    public static function subject(string $template, array $values): string
    {
        $out = self::fill(self::normaliseSubject($template), $values, false);

        return mb_substr(self::normaliseSubject($out), 0, 250);
    }

    /** The text part: the body with every placeholder replaced by its raw value. */
    public static function text(string $template, array $values): string
    {
        return self::fill(self::normaliseBody($template), $values, true);
    }

    /**
     * The HTML part, as segments for the view to escape.
     *
     * Each entry is ['text' => string] — the owner's prose with the ordinary
     * placeholders already substituted, NOT yet escaped — or ['link' => true],
     * where the view draws the button. The view escapes every text segment;
     * nothing here is markup.
     *
     * @return list<array{text?: string, link?: bool}>
     */
    public static function segments(string $template, array $values): array
    {
        $marker = '{' . self::LINK . '}';
        $parts = explode($marker, self::normaliseBody($template));
        $out = [];

        foreach ($parts as $i => $part) {
            if ($i > 0) {
                $out[] = ['link' => true];
            }

            $text = self::fill($part, $values, false);

            if ($text !== '') {
                $out[] = ['text' => $text];
            }
        }

        return $out;
    }

    /**
     * Substitute every known placeholder in ONE pass.
     *
     * strtr() and not a str_replace() per name: a customer whose name is the
     * literal text "{set_password_link}" must stay that text, and a chain of
     * replacements would expand it on the next step.
     */
    private static function fill(string $text, array $values, bool $withLink): string
    {
        $map = [];

        foreach (array_keys(self::PLACEHOLDERS) as $name) {
            if ($name === self::LINK && ! $withLink) {
                continue;
            }

            $map['{' . $name . '}'] = (string) ($values[$name] ?? '');
        }

        return strtr($text, $map);
    }
}

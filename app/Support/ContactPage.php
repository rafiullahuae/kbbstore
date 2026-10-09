<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\Mail\OrderMailer;
use App\Services\SettingsService;
use App\Services\SiteFooter;

/**
 * The contact page's own sections: the WhatsApp, phone and email cards, the
 * social icons, the opening hours and the inquiry form.            (Lane CT)
 *
 * The owner, 9 October: "the contact page should have proper sections for
 * whatsapp, contact, email, and a inquiry form nicely design. and our social
 * media icons." He asked for it, so it SHIPS ON (CLAUDE.md, 30 September):
 * every card, the icons and the form are on by default, and Store → Inquiries →
 * Contact page can take each one back off.
 *
 * ── NOTHING HERE IS A NEW FACT ABOUT THE SHOP ───────────────────────────────
 *
 * Every value a card prints is a setting the shop already had, read through
 * the reader that already owned it:
 *
 *   WhatsApp   SupportContact::whatsapp() / whatsappDigits() — the number the
 *              floating button, the header and the footer already dial
 *   Phone      SupportContact::phone() — what the header chip prints
 *   Email      SupportContact::email() — `support_email`, no shipped fallback,
 *              so a shop that cleared it shows no email card at all
 *   Icons      SiteFooter::socials() and SiteFooter::SOCIAL_ICONS — the footer's
 *              own list and marks, not a second copy
 *   Hours      `store_hours`, only when OpeningHours::spec() accepts it — the
 *              same parser the business screen and the JSON-LD use
 *
 * The only settings this class adds are the switches, the recipient and the
 * topic list, in ONE row (`contact_page`) read through the request's settings
 * memo: the page costs no query it did not already make.
 *
 * ── WHAT REACHES THE MARKUP ─────────────────────────────────────────────────
 *
 * Icons are code constants and the only thing printed unescaped. Every other
 * value is escaped by Blade. The three hrefs are built here from digits or a
 * checked address and nothing else: `https://wa.me/` + digits, `tel:` + digits,
 * `mailto:` + an address FILTER_VALIDATE_EMAIL accepted; the social links are
 * SafeUrl::href()'s answer, which refuses any scheme but the web's.
 */
final class ContactPage
{
    /** The one settings row. */
    public const KEY = 'contact_page';

    /** The switches, all on: he asked for every one of them. */
    public const SWITCHES = ['wa', 'phone', 'email', 'socials', 'hours', 'form'];

    /**
     * The topic list the form ships with, keyed so a default line can be shown
     * in Arabic through store.contact.topic_{key}; a line the owner types is
     * printed as he typed it.
     */
    public const DEFAULT_TOPICS = [
        'order' => 'Order question',
        'advice' => 'Product advice',
        'wholesale' => 'Wholesale',
        'other' => 'Other',
    ];

    public const MAX_TOPICS = 8;

    public const TOPIC_MAX = 60;

    /** Seconds a person needs at least to fill the form in. Faster is a script. */
    public const MIN_SECONDS = 3;

    /** The field caps, shared by the validator and the markup's maxlength. */
    public const MAX = ['name' => 120, 'email' => 160, 'phone' => 40, 'message' => 5000];

    public const MESSAGE_MIN = 10;

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return array_fill_keys(self::SWITCHES, true) + [
            'recipient' => '',
            'topics' => array_values(self::DEFAULT_TOPICS),
        ];
    }

    /**
     * The stored row over the defaults, every value re-checked on the way out:
     * a row written by hand or by an older build reads back as something this
     * class would have stored, never as whatever is in it.
     *
     * @return array<string, mixed>
     */
    public static function config(?SettingsService $settings = null): array
    {
        $settings ??= app(SettingsService::class);
        $raw = $settings->get(self::KEY, []);
        $raw = is_array($raw) ? $raw : [];
        $out = self::defaults();

        foreach (self::SWITCHES as $k) {
            if (array_key_exists($k, $raw)) {
                $out[$k] = (bool) $raw[$k];
            }
        }

        $recipient = trim((string) ($raw['recipient'] ?? ''));
        $out['recipient'] = self::validEmail($recipient) ? $recipient : '';

        if (isset($raw['topics']) && is_array($raw['topics'])) {
            $out['topics'] = self::topicLines($raw['topics']);
        }

        return $out;
    }

    /**
     * Validate a whole settings array from the admin and store it. Returns the
     * errors by field; nothing is stored when there is one.
     *
     * @param  array<string, mixed>  $in
     * @return array<string, string>
     */
    public static function save(array $in): array
    {
        $errors = [];
        $clean = [];

        foreach (self::SWITCHES as $k) {
            $clean[$k] = filter_var($in[$k] ?? false, FILTER_VALIDATE_BOOL);
        }

        $recipient = trim((string) ($in['recipient'] ?? ''));
        if ($recipient !== '' && ! self::validEmail($recipient)) {
            $errors['recipient'] = 'The recipient is not an email address.';
        }
        $clean['recipient'] = $recipient;

        $topics = $in['topics'] ?? [];
        if (is_string($topics)) {
            $topics = preg_split('/\R/', $topics) ?: [];
        }
        if (! is_array($topics)) {
            $topics = [];
        }
        $lines = [];
        foreach ($topics as $t) {
            $t = trim(self::singleLine((string) (is_scalar($t) ? $t : '')));
            if ($t === '') {
                continue;
            }
            if (mb_strlen($t) > self::TOPIC_MAX) {
                $errors['topics'] = 'Each topic can be at most '.self::TOPIC_MAX.' characters.';
            }
            $lines[] = $t;
        }
        $lines = array_values(array_unique($lines));
        if (count($lines) > self::MAX_TOPICS) {
            $errors['topics'] = 'At most '.self::MAX_TOPICS.' topics.';
        }
        if ($lines === []) {
            $errors['topics'] = 'Give the form at least one topic.';
        }
        $clean['topics'] = $lines;

        if ($errors !== []) {
            return $errors;
        }

        app(SettingsService::class)->set(self::KEY, $clean);

        return [];
    }

    /**
     * Where an inquiry is sent: the owner's own box, else the address the shop
     * already sends its new-order alerts to, else the support address. Empty
     * only when the shop has none of the three, and then the inquiry is still
     * stored — the inbox is the record, the email a courtesy.
     */
    public static function recipient(?array $config = null): string
    {
        return self::recipientWithSource($config)[0];
    }

    /**
     * The same answer and where it came from: 'own' (the box), 'merchant'
     * (the new-order alert address), 'support' (support_email) or 'none'.
     *
     * @return array{0:string,1:string}
     */
    public static function recipientWithSource(?array $config = null): array
    {
        $config ??= self::config();

        if ($config['recipient'] !== '') {
            return [$config['recipient'], 'own'];
        }

        try {
            $merchant = trim(app(OrderMailer::class)->merchantAddress());
        } catch (\Throwable) {
            $merchant = '';
        }

        if (self::validEmail($merchant)) {
            return [$merchant, 'merchant'];
        }

        $support = SupportContact::email();

        return self::validEmail($support) ? [$support, 'support'] : ['', 'none'];
    }

    /** A topic line as the shopper sees it: a shipped one in their language, an owner's one as typed. */
    public static function topicLabel(string $line): string
    {
        $key = array_search($line, self::DEFAULT_TOPICS, true);

        return $key === false ? $line : (string) __('store.contact.topic_'.$key);
    }

    /**
     * Everything the page prints, reduced to checked values.
     *
     * @return array<string, mixed>
     */
    public static function view(?SettingsService $settings = null): array
    {
        $settings ??= app(SettingsService::class);
        $c = self::config($settings);
        $icon = static fn (string $k): string => str_replace('stroke="#fff"', 'stroke="currentColor"', SiteFooter::HELP_ICONS[$k]);

        $cards = [];

        $wa = SupportContact::whatsappDigits();
        if ($c['wa'] && $wa !== '') {
            $cards[] = [
                'key' => 'wa', 'icon' => $icon('whatsapp'),
                'title' => __('store.contact.wa_title'), 'note' => __('store.contact.wa_note'),
                'detail' => Bidi::number(SupportContact::whatsapp()),
                'href' => 'https://wa.me/'.$wa, 'action' => __('store.contact.wa_action'), 'external' => true,
            ];
        }

        $phone = SupportContact::phone();
        $digits = (string) preg_replace('/\D+/', '', $phone);
        if ($c['phone'] && strlen($digits) >= 6) {
            $cards[] = [
                'key' => 'phone', 'icon' => $icon('phone'),
                'title' => __('store.contact.phone_title'), 'note' => __('store.contact.phone_note'),
                'detail' => $phone,
                'href' => 'tel:'.(str_contains($phone, '+') ? '+' : '').$digits, 'action' => __('store.contact.phone_action'), 'external' => false,
            ];
        }

        $email = SupportContact::email();
        if ($c['email'] && self::validEmail($email)) {
            $cards[] = [
                'key' => 'email', 'icon' => $icon('mail'),
                'title' => __('store.contact.email_title'), 'note' => __('store.contact.email_note'),
                'detail' => $email,
                'href' => 'mailto:'.$email, 'action' => __('store.contact.email_action'), 'external' => false,
            ];
        }

        $socials = [];
        if ($c['socials']) {
            foreach (SiteFooter::socials($settings) as [$key, $name, $href]) {
                $socials[] = ['name' => $name, 'href' => $href, 'icon' => SiteFooter::SOCIAL_ICONS[$key]];
            }
        }

        $hours = [];
        $hoursText = trim((string) $settings->get('store_hours', ''));
        if ($c['hours'] && $hoursText !== '' && OpeningHours::spec($hoursText) !== null) {
            $hours = array_values(array_filter(array_map('trim', preg_split('/\R/', $hoursText) ?: []), 'strlen'));
        }

        $form = null;
        if ($c['form']) {
            $topics = [];
            foreach ($c['topics'] as $i => $line) {
                $topics[] = [$i, self::topicLabel($line)];
            }
            $form = [
                'action' => Url::to('/contact-us/send'),
                'stamp' => self::stamp(now()->getTimestamp()),
                'topics' => $topics,
                'max' => self::MAX,
            ];
        }

        return ['cards' => $cards, 'socials' => $socials, 'hours' => $hours, 'form' => $form];
    }

    /**
     * The form's issue time, signed, so the minimum fill time cannot be met by
     * sending an old number. Not secret and not a session: an HMAC under the
     * app key over the time, which is all a script cannot make up.
     */
    public static function stamp(int $time): string
    {
        return $time.'.'.substr(hash_hmac('sha256', 'kbb-contact-form|'.$time, (string) config('app.key')), 0, 20);
    }

    /** Seconds since a stamp was issued, or null when it is missing, forged or from the future. */
    public static function age(mixed $stamp): ?int
    {
        if (! is_string($stamp) || preg_match('/^(\d{9,11})\.([0-9a-f]{20})$/', $stamp, $m) !== 1) {
            return null;
        }

        if (! hash_equals(self::stamp((int) $m[1]), $stamp)) {
            return null;
        }

        $age = now()->getTimestamp() - (int) $m[1];

        return $age < 0 ? null : $age;
    }

    public static function validEmail(string $value): bool
    {
        return $value !== '' && strlen($value) <= 160 && filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    /** No line breaks and no control characters: a value that is one line stays one line. */
    public static function singleLine(string $value): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value));
    }

    /**
     * @param  array<mixed>  $raw
     * @return list<string>
     */
    private static function topicLines(array $raw): array
    {
        $out = [];
        foreach ($raw as $t) {
            if (! is_string($t)) {
                continue;
            }
            $t = self::singleLine($t);
            if ($t !== '' && mb_strlen($t) <= self::TOPIC_MAX && ! in_array($t, $out, true)) {
                $out[] = $t;
            }
            if (count($out) === self::MAX_TOPICS) {
                break;
            }
        }

        return $out === [] ? array_values(self::DEFAULT_TOPICS) : $out;
    }
}

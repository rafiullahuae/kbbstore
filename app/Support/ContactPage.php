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
 * every card, the icons and the form are on by default — except the phone
 * card, which he then asked to remove ("we don't receive calls") — and
 * Store → Inquiries → Contact page can switch each one.
 *
 * ── NOTHING HERE IS A NEW FACT ABOUT THE SHOP ───────────────────────────────
 *
 * Every value a card prints is a setting the shop already had, read through
 * the reader that already owned it:
 *
 *   WhatsApp   SupportContact::whatsapp() / whatsappDigits() — the number the
 *              floating button, the header and the footer already dial
 *   Instagram  instagramProfile(): SiteFooter::socials()' instagram profile,
 *              else Store → Mail's Instagram handle, else the shop's known
 *              profile — opened as ig.me/m/<handle> (Lane CT2)
 *   Phone      SupportContact::phone() — what the header chip prints (off)
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
 * value is escaped by Blade. The shop's own hrefs are built here from digits or
 * a checked address and nothing else: `https://wa.me/` + digits, `tel:` +
 * digits, `mailto:` + an address FILTER_VALIDATE_EMAIL accepted; the social
 * links are SafeUrl::href()'s answer, which refuses any scheme but the web's.
 * A link typed on the page editor's Contact cards card (Lane CT2) is drawn
 * only when linkOk() accepts it — checked when it is saved AND when it is read.
 */
final class ContactPage
{
    /** The one settings row. */
    public const KEY = 'contact_page';

    /**
     * The switches. All on — he asked for every one — except the phone card:
     * "remove the call option, we don't receive calls, we provide support on
     * whatsapp, instagram and email" (the owner, 9 October, on the first
     * screenshot). It stays a switch so he can bring it back.
     */
    public const SWITCHES = ['wa', 'ig', 'email', 'phone', 'socials', 'hours', 'form'];

    /** The switches that ship off. */
    public const OFF_BY_DEFAULT = ['phone'];

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

    /*
     * ── THE CARDS, EDITABLE ON THE PAGE'S OWN EDITOR (Lane CT2) ─────────────
     *
     * The owner, 9 October, on a screenshot of the live page with two cards:
     * "i have added the instagram link, but not showing the third block. please
     * add manually and give such blocks edits option directly in contact us
     * page edits." So the cards live in `contact_page.cards`, an ordered list,
     * edited at Pages → User pages → Contact Us → Edit → Contact cards. The
     * four switches Store → Inquiries → Contact page has always shown (wa, ig,
     * email, phone) are the `on` flag of the same entries: ONE stored list, two
     * screens, no second copy (migration 2027_10_15_180000 moved the old
     * top-level switches into it).
     *
     * An entry: ['k' => 'wa'|'ig'|'email'|'phone'|'c1'…'c9', 'on' => bool,
     * 'title', 'note', 'value', 'action', 'link' => string, 'icon' (custom
     * only)]. For the four built-in cards every text field is an OVERRIDE: ''
     * means "the shop's own" — the translated words, and the number, address
     * or profile from the settings that already own them — so a card he never
     * touched follows the business screen, and the Arabic page keeps its
     * Arabic. A custom card's fields are its own.
     */

    /** The cards that read the shop's own settings, in their shipped order. */
    public const BUILTIN = ['wa', 'ig', 'email', 'phone'];

    /** At most this many cards, built-in ones included. */
    public const MAX_CARDS = 6;

    /** Field caps, shared by the checker and the editor's maxlength. */
    public const CARD_MAX = ['title' => 60, 'note' => 120, 'value' => 120, 'action' => 40, 'link' => 500];

    public const CARD_FIELDS = ['title', 'note', 'value', 'action', 'link'];

    /** The icons a custom card may wear: keys of icon(), never markup. */
    public const ICON_CHOICES = ['chat', 'instagram', 'mail', 'phone', 'location', 'clock', 'link'];

    /**
     * The shop's known Instagram profile — SiteFooter::socials()' own default
     * for a shop that never wrote `social_instagram`. The card's last fallback,
     * so it is drawn even where that row was saved blank (see instagramProfile()).
     */
    public const DEFAULT_INSTAGRAM = 'https://www.instagram.com/kbeauty.bliss/';

    /** The three icons the footer has no mark for. Constants, stroked in currentColor. */
    private const EXTRA_ICONS = [
        'location' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-7-6.2-7-11.5A7 7 0 0 1 19 9.5C19 14.8 12 21 12 21Z"/><circle cx="12" cy="9.5" r="2.5"/></svg>',
        'clock' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/></svg>',
        'link' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M10 14a4.5 4.5 0 0 0 6.4 0l3-3a4.5 4.5 0 0 0-6.4-6.4l-1.2 1.2"/><path d="M14 10a4.5 4.5 0 0 0-6.4 0l-3 3a4.5 4.5 0 0 0 6.4 6.4l1.2-1.2"/></svg>',
    ];

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        $on = [];
        foreach (self::SWITCHES as $k) {
            $on[$k] = ! in_array($k, self::OFF_BY_DEFAULT, true);
        }

        return $on + [
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
        $raw = self::row($settings);
        $out = self::defaults();

        // The four card switches are the `on` flags of the card list (Lane
        // CT2); the other three are keys of the row, as they always were.
        $on = array_column(self::cardsFrom($raw), 'on', 'k');
        foreach (self::SWITCHES as $k) {
            if (in_array($k, self::BUILTIN, true)) {
                $out[$k] = (bool) ($on[$k] ?? $out[$k]);
            } elseif (array_key_exists($k, $raw)) {
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

        // The card switches are written into the card list, the one place
        // the page editor's Contact cards card also writes (Lane CT2): this
        // screen moves `on` and leaves every word and link on the card alone.
        $settings = app(SettingsService::class);
        $cards = self::cardsFrom(self::row($settings));
        foreach ($cards as $i => $card) {
            if (array_key_exists($card['k'], $clean)) {
                $cards[$i]['on'] = $clean[$card['k']];
            }
        }
        foreach (self::BUILTIN as $k) {
            unset($clean[$k]);
        }
        $clean['cards'] = $cards;

        $settings->set(self::KEY, $clean);

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

        // The cards, in the owner's order, each through resolve(): an entry
        // switched off, or one with no link the shop can follow, is not drawn.
        $cards = [];
        foreach (self::cardsFrom(self::row($settings)) as $card) {
            if (($drawn = self::resolve($card, $settings)) !== null) {
                $cards[] = $drawn;
            }
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

        // The form's WhatsApp-number field wears the WhatsApp mark, not a handset.
        return ['cards' => $cards, 'socials' => $socials, 'hours' => $hours, 'form' => $form, 'waIcon' => self::icon('whatsapp')];
    }

    /** The Instagram card's link and words with no override: instagram() of instagramProfile(). */
    public static function instagramFor(SettingsService $settings): ?array
    {
        return self::instagram(self::instagramProfile($settings));
    }

    /**
     * The profile the Instagram card opens when the page editor names none
     * (Lane CT2), first that answers:
     *
     *   1. `social_instagram`, as SiteFooter::socials() reads it (Store → SEO &
     *      Meta → Social profiles) — a full address, or a bare handle or
     *      "instagram.com/name" typed without the https://
     *   2. `mail_support_instagram` (Store → Mail → Footer → Instagram), the
     *      other box in this console labelled "Instagram", which takes a handle
     *   3. DEFAULT_INSTAGRAM, the shop's known profile
     *
     * The third is why the card no longer vanishes when the first was saved
     * blank — which is what the live shop's row holds: the SEO tab opens its
     * Social profiles boxes empty for a key with no row (the shop's default
     * lives in code), and one Save of that tab wrote '' over Instagram, TikTok
     * and Facebook together. The footer is not changed by this; only the card.
     */
    public static function instagramProfile(SettingsService $settings): string
    {
        foreach (SiteFooter::socials($settings) as [$key, , $href]) {
            if ($key !== 'instagram') {
                continue;
            }
            if (self::instagram($href) !== null) {
                return $href;
            }
            if (($handle = self::instagramHandle($href)) !== '') {
                return 'https://www.instagram.com/'.$handle.'/';
            }
        }

        $handle = self::instagramHandle((string) $settings->get('mail_support_instagram', ''));

        return $handle !== '' ? 'https://www.instagram.com/'.$handle.'/' : self::DEFAULT_INSTAGRAM;
    }

    /** "@name", "name", "instagram.com/name" or a profile URL, as a handle; '' when it is none. */
    public static function instagramHandle(string $value): string
    {
        if (preg_match('~instagram\.com/([^/?#\s]+)~i', $value, $m) === 1) {
            $value = $m[1];
        }

        $value = ltrim(trim($value), '@');

        return preg_match('/^[A-Za-z0-9._]{1,30}$/', $value) === 1 ? $value : '';
    }

    /**
     * The Instagram card's link and the words it prints, from the profile
     * address the footer already uses (`social_instagram`, which holds a URL:
     * https://www.instagram.com/kbeauty.bliss/ by default).
     *
     * A plain profile address on instagram.com becomes https://ig.me/m/<handle>,
     * Instagram's own "send a message" link, and prints as @handle. Anything
     * else that SafeUrl let through (an http/https address) is linked as typed
     * and printed without its scheme. An empty or refused address: null, and
     * the card is not drawn.
     *
     * @return array{href:string, detail:string}|null
     */
    public static function instagram(string $href): ?array
    {
        $href = trim($href);

        if ($href === '' || $href === '#' || preg_match('#^https?://#i', $href) !== 1) {
            return null;
        }

        $host = strtolower((string) parse_url($href, PHP_URL_HOST));
        $path = trim((string) parse_url($href, PHP_URL_PATH), '/');

        if (in_array($host, ['instagram.com', 'www.instagram.com', 'm.instagram.com'], true)
            && preg_match('/^[A-Za-z0-9._]{1,30}$/', $path) === 1) {
            return ['href' => 'https://ig.me/m/'.$path, 'detail' => '@'.$path];
        }

        return ['href' => $href, 'detail' => (string) preg_replace('#^https?://(www\.)?#i', '', rtrim($href, '/'))];
    }

    /* ══════════════════════ the cards (Lane CT2) ══════════════════════ */

    /** The stored row, as an array. One read of the request's settings memo. */
    private static function row(SettingsService $settings): array
    {
        $raw = $settings->get(self::KEY, []);

        return is_array($raw) ? $raw : [];
    }

    /**
     * The card list as stored, every entry re-checked.
     *
     * @return list<array<string, mixed>>
     */
    public static function cards(?SettingsService $settings = null): array
    {
        return self::cardsFrom(self::row($settings ?? app(SettingsService::class)));
    }

    /**
     * The card list out of a row: its `cards`, each entry re-checked on the way
     * out (a row written by hand reads back as something checkCards() would
     * have stored), then any built-in card the list lacks, in shipped order.
     * A row from before the list (the old top-level wa/ig/email/phone
     * switches) reads as those switches. Never more than MAX_CARDS: the four
     * built-in cards always, custom ones up to the cap.
     *
     * @param  array<string, mixed>  $raw
     * @return list<array<string, mixed>>
     */
    public static function cardsFrom(array $raw): array
    {
        $given = isset($raw['cards']) && is_array($raw['cards']) ? $raw['cards'] : null;
        $out = [];
        $seen = [];
        $room = self::MAX_CARDS - count(self::BUILTIN);

        foreach ($given ?? [] as $entry) {
            $card = is_array($entry) ? self::cleanStored($entry) : null;
            if ($card === null || isset($seen[$card['k']])) {
                continue;
            }
            if (self::isCustom($card['k']) && $room-- <= 0) {
                continue;
            }
            $seen[$card['k']] = true;
            $out[] = $card;
        }

        foreach (self::BUILTIN as $k) {
            if (! isset($seen[$k])) {
                $on = ($given === null && array_key_exists($k, $raw))
                    ? (bool) $raw[$k]
                    : ! in_array($k, self::OFF_BY_DEFAULT, true);
                $out[] = self::blank($k, $on);
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private static function blank(string $k, bool $on): array
    {
        $card = ['k' => $k, 'on' => $on, 'title' => '', 'note' => '', 'value' => '', 'action' => '', 'link' => ''];

        return self::isCustom($k) ? $card + ['icon' => 'link'] : $card;
    }

    public static function isCustom(string $k): bool
    {
        return ! in_array($k, self::BUILTIN, true);
    }

    /** One stored entry, or null when it is not one checkCards() could have written. */
    private static function cleanStored(array $e): ?array
    {
        $k = is_string($e['k'] ?? null) ? $e['k'] : '';
        if (! in_array($k, self::BUILTIN, true) && preg_match('/^c[1-9]$/', $k) !== 1) {
            return null;
        }

        $card = self::blank($k, (bool) ($e['on'] ?? false));
        foreach (self::CARD_FIELDS as $f) {
            $v = is_string($e[$f] ?? null) ? self::singleLine($e[$f]) : '';
            $card[$f] = mb_strlen($v) <= self::CARD_MAX[$f] ? $v : '';
        }
        if ($card['link'] !== '' && ! self::linkOk($card['link'])) {
            $card['link'] = '';
        }

        if (self::isCustom($k)) {
            $icon = is_string($e['icon'] ?? null) ? $e['icon'] : '';
            $card['icon'] = in_array($icon, self::ICON_CHOICES, true) ? $icon : 'link';
            if ($card['title'] === '' || $card['link'] === '' || $card['action'] === '') {
                return null;
            }
        }

        return $card;
    }

    /**
     * May this become a card's href? An http(s) address with a host, a
     * mailto: with one valid address, a tel: of digits, or a path on this shop
     * ("/track-my-order/"). Asked of the RAW string, so an entity- or
     * whitespace-smuggled scheme (`jav&#x09;ascript:`, `java\nscript:`) is
     * simply not one of the four shapes; `javascript:`, `data:`, `vbscript:`
     * and `//other.host` are refused by name of shape, not by a blocklist.
     */
    public static function linkOk(string $url): bool
    {
        if ($url === '' || mb_strlen($url) > self::CARD_MAX['link']
            || preg_match('/[\s\x00-\x1F\x7F\\\\<>"\']/u', $url) === 1) {
            return false;
        }

        if (preg_match('#^https?://[^/?\#@]+\.[^/?\#@]+#i', $url) === 1) {
            return SafeUrl::web($url) === $url;
        }

        if (preg_match('/^mailto:([^?]+)$/i', $url, $m) === 1) {
            return self::validEmail($m[1]);
        }

        if (preg_match('/^tel:\+?[0-9]{6,20}$/i', $url) === 1) {
            return true;
        }

        return preg_match('#^/(?!/)#', $url) === 1;
    }

    /**
     * What a built-in card prints with no override, from the settings that
     * already own each value. $editor: the plain value and the English words,
     * which is what the page editor's boxes are pre-filled with.
     *
     * @return array{title:string, note:string, value:string, action:string, link:string}
     */
    public static function builtinDefaults(string $k, SettingsService $settings, bool $editor = false): array
    {
        $t = static fn (string $key): string => (string) ($editor ? __('store.contact.'.$key, [], 'en') : __('store.contact.'.$key));
        $words = ['title' => $t($k.'_title'), 'note' => $t($k.'_note'), 'action' => $t($k.'_action')];

        switch ($k) {
            case 'wa':
                $digits = SupportContact::whatsappDigits();

                return $words + ['value' => $digits !== '' ? SupportContact::whatsapp() : '', 'link' => $digits !== '' ? 'https://wa.me/'.$digits : ''];
            case 'ig':
                $ig = self::instagramFor($settings) ?? ['href' => '', 'detail' => ''];

                return $words + ['value' => $ig['detail'], 'link' => $ig['href']];
            case 'email':
                $email = SupportContact::email();
                $ok = self::validEmail($email);

                return $words + ['value' => $ok ? $email : '', 'link' => $ok ? 'mailto:'.$email : ''];
            default:
                $phone = SupportContact::phone();
                $digits = (string) preg_replace('/\D+/', '', $phone);
                $ok = strlen($digits) >= 6;

                return $words + ['value' => $ok ? $phone : '', 'link' => $ok ? 'tel:'.(str_contains($phone, '+') ? '+' : '').$digits : ''];
        }
    }

    /**
     * The link a typed VALUE implies for a built-in card, so a new number or
     * address carries its own button: wa.me/<digits>, ig.me/m/<handle>,
     * mailto:, tel:. '' when the value implies none.
     */
    public static function derivedLink(string $k, string $value): string
    {
        $digits = (string) preg_replace('/\D+/', '', $value);

        return match ($k) {
            'wa' => strlen($digits) >= 6 ? 'https://wa.me/'.$digits : '',
            'phone' => strlen($digits) >= 6 ? 'tel:'.(str_contains($value, '+') ? '+' : '').$digits : '',
            'email' => self::validEmail($value) ? 'mailto:'.$value : '',
            'ig' => ($h = self::instagramHandle($value)) !== '' ? 'https://ig.me/m/'.$h : '',
            default => '',
        };
    }

    /**
     * One card as the page draws it, or null when it is switched off or has no
     * link. The href is the override, else the one the typed value implies,
     * else the shop's own; every one of them passed linkOk() or was built here.
     *
     * @return array<string, mixed>|null
     */
    private static function resolve(array $card, SettingsService $settings): ?array
    {
        if (! $card['on']) {
            return null;
        }

        $k = $card['k'];

        if (self::isCustom($k)) {
            $d = ['title' => '', 'note' => '', 'value' => '', 'action' => '', 'link' => ''];
            $icon = self::icon($card['icon']);
        } else {
            $d = self::builtinDefaults($k, $settings);
            $icon = self::icon(['wa' => 'whatsapp', 'ig' => 'instagram', 'email' => 'mail', 'phone' => 'phone'][$k]);
        }

        $pick = static fn (string $f): string => $card[$f] !== '' ? $card[$f] : $d[$f];
        $href = $card['link'] !== '' ? $card['link']
            : (($card['value'] !== '' ? self::derivedLink($k, $card['value']) : '') ?: $d['link']);

        if ($href === '' || ! self::linkOk($href)) {
            return null;
        }

        $value = $pick('value');

        return [
            'key' => $k, 'icon' => $icon,
            'title' => $pick('title'), 'note' => $pick('note'),
            // A number or an @handle is a left-to-right token: isolated on the
            // Arabic page, or "@kbeauty.bliss" reads "kbeauty.bliss@" there
            // (measured on the RTL preview). English is untouched.
            'detail' => in_array($k, ['wa', 'ig', 'phone'], true) ? Bidi::number($value) : $value,
            'href' => $href, 'action' => $pick('action'),
            'external' => preg_match('#^https?://#i', $href) === 1,
        ];
    }

    /** A card icon by key: code constants only, the one thing the view prints unescaped. */
    public static function icon(string $key): string
    {
        return match ($key) {
            'instagram' => SiteFooter::SOCIAL_ICONS['instagram'],
            'whatsapp', 'chat', 'mail', 'phone' => str_replace('stroke="#fff"', 'stroke="currentColor"', SiteFooter::HELP_ICONS[$key]),
            default => self::EXTRA_ICONS[$key] ?? self::EXTRA_ICONS['link'],
        };
    }

    /**
     * The page editor's Contact cards card: each card with its boxes filled
     * with what the shop prints today (the override, else the shop's own), the
     * shop's own beside it, and the limits.
     *
     * @return array<string, mixed>
     */
    public static function editor(?SettingsService $settings = null): array
    {
        $settings ??= app(SettingsService::class);
        $cards = [];

        foreach (self::cards($settings) as $card) {
            $custom = self::isCustom($card['k']);
            $d = $custom ? null : self::builtinDefaults($card['k'], $settings, true);

            $row = ['k' => $card['k'], 'on' => $card['on'], 'custom' => $custom, 'icon' => $card['icon'] ?? ''];
            foreach (self::CARD_FIELDS as $f) {
                $row[$f] = $card[$f] !== '' ? $card[$f] : (string) ($d[$f] ?? '');
            }
            // The link box shows the link the card opens: a typed value's own
            // (derivedLink) when only the value was overridden.
            if ($d !== null && $card['link'] === '' && $card['value'] !== '') {
                $row['link'] = self::derivedLink($card['k'], $card['value']) ?: $row['link'];
            }
            $row['shop'] = $d;
            $cards[] = $row;
        }

        return ['cards' => $cards, 'icons' => self::ICON_CHOICES, 'max' => self::MAX_CARDS, 'limits' => self::CARD_MAX];
    }

    private const CARD_NAMES = ['wa' => 'WhatsApp card', 'ig' => 'Instagram card', 'email' => 'Email card', 'phone' => 'Phone card'];

    private const FIELD_NAMES = ['title' => 'title', 'note' => 'subtitle', 'value' => 'value', 'action' => 'button text', 'link' => 'link'];

    /**
     * The page editor's card list, checked. Returns [errors, cards]; nothing is
     * stored by this. A box left at the shop's own value is stored as '' so
     * the card goes on following the settings (and the Arabic page its own
     * words); a typed value is the override.
     *
     * @return array{0: list<string>, 1: list<array<string, mixed>>}
     */
    public static function checkCards(mixed $in, ?SettingsService $settings = null): array
    {
        $settings ??= app(SettingsService::class);

        if (! is_array($in) || ! array_is_list($in)) {
            return [['The contact cards could not be read. Reload the page and try again.'], []];
        }
        $customs = count(array_filter($in, static fn ($e): bool => is_array($e) && is_string($e['k'] ?? null) && ! in_array($e['k'], self::BUILTIN, true)));
        if (count($in) > self::MAX_CARDS || $customs > self::MAX_CARDS - count(self::BUILTIN)) {
            return [['At most '.self::MAX_CARDS.' contact cards: the four the shop has and '.(self::MAX_CARDS - count(self::BUILTIN)).' of your own.'], []];
        }

        $errors = [];
        $out = [];
        $seen = [];

        foreach ($in as $i => $e) {
            $k = is_array($e) && is_string($e['k'] ?? null) ? $e['k'] : '';
            $custom = preg_match('/^c[1-9]$/', $k) === 1;
            if (! $custom && ! in_array($k, self::BUILTIN, true)) {
                $errors[] = 'Card '.($i + 1).' is not a contact card this page has.';

                continue;
            }
            if (isset($seen[$k])) {
                $errors[] = 'Card '.($i + 1).' appears twice.';

                continue;
            }
            $seen[$k] = true;
            $name = $custom ? 'card '.($i + 1) : self::CARD_NAMES[$k];

            $card = self::blank($k, filter_var($e['on'] ?? false, FILTER_VALIDATE_BOOL));
            foreach (self::CARD_FIELDS as $f) {
                $v = $e[$f] ?? '';
                $v = self::singleLine(is_scalar($v) ? (string) $v : '');
                if (mb_strlen($v) > self::CARD_MAX[$f]) {
                    $errors[] = 'The '.$name.'’s '.self::FIELD_NAMES[$f].' can be at most '.self::CARD_MAX[$f].' characters.';
                }
                $card[$f] = $v;
            }

            if ($custom) {
                $icon = is_string($e['icon'] ?? null) ? $e['icon'] : '';
                if (! in_array($icon, self::ICON_CHOICES, true)) {
                    $errors[] = 'Pick an icon for '.$name.'.';
                }
                $card['icon'] = $icon;
                foreach (['title', 'action', 'link'] as $f) {
                    if ($card[$f] === '') {
                        $errors[] = ucfirst($name).' needs a '.self::FIELD_NAMES[$f].'.';
                    }
                }
            } else {
                $d = self::builtinDefaults($k, $settings, true);
                foreach (['title', 'note', 'value', 'action'] as $f) {
                    if ($card[$f] === $d[$f]) {
                        $card[$f] = '';
                    }
                }
                if ($card['link'] === $d['link']
                    || ($card['value'] !== '' && $card['link'] === self::derivedLink($k, $card['value']))) {
                    $card['link'] = '';
                }
            }

            if ($card['link'] !== '' && ! self::linkOk($card['link'])) {
                $errors[] = 'The '.$name.'’s link must start with https://, http://, mailto: or tel:, or with / for a page on this shop.';
            }

            $out[] = $card;
        }

        // A built-in card the screen did not send keeps what is stored.
        foreach (self::cards($settings) as $card) {
            if (! self::isCustom($card['k']) && ! isset($seen[$card['k']])) {
                $out[] = $card;
            }
        }

        return [$errors, $errors === [] ? $out : []];
    }

    /** Store a list checkCards() returned, in the same row Store → Inquiries saves. */
    public static function storeCards(array $cards): void
    {
        $settings = app(SettingsService::class);
        $raw = self::row($settings);
        foreach (self::BUILTIN as $k) {
            unset($raw[$k]);
        }
        $raw['cards'] = array_values($cards);

        $settings->set(self::KEY, $raw);
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

<?php

declare(strict_types=1);

namespace App\Services;

/**
 * The slim footer — one bar, on the cart page and the checkout.
 *
 * ── WHY THIS IS NOT THE SITE FOOTER ─────────────────────────────────────────
 *
 * partials/footer.blade.php is the shop's footer: columns of links, a
 * newsletter box, payment marks, several hundred pixels of it. The checkout
 * declares `bare` and the cart declares `no-footer`, so neither has ever drawn
 * it, and that is deliberate — a page asking for money should not offer twenty
 * ways to leave.
 *
 * The owner asked for something else: "i need a seperate footer ... overall
 * height of the new footer i want very less, like a bar type." So this is a
 * second, much smaller thing, with its own words and its own switches, and it
 * does not touch the site footer or either of those two sections.
 *
 * ── WHERE THE WORDS LIVE, AND WHY NOT IN InterfaceStrings ───────────────────
 *
 * In `settings`. App\Services\Translation\InterfaceStrings is for the fixed
 * wording of the interface; its own header names the exclusion this falls
 * under — "anything the owner types into an admin setting ... the header's
 * support label, the cart panel's wording, the trust claims". A support number
 * and a company name are that: they change without a release, and giving them
 * a second English source would mean two places to correct and one of them
 * silently wrong.
 *
 * ── THE TWO SWITCHES ARE SEPARATE ON PURPOSE ────────────────────────────────
 *
 * `co_on` ships ON and `cart_on` ships OFF. "don't disturb anything in cart
 * page" — so the cart renders exactly as it does today until somebody turns
 * this on, and the checkout gains the bar the owner asked for.
 */
class SlimFooter
{
    /**
     * key => [type, label, default, help, options]
     *
     * Types: text, bool, range, select — the same four the checkout screen
     * already draws, so this screen needs no renderer of its own.
     */
    public const SCHEMA = [
        // ── Where it shows ──
        'co_on'      => ['bool', 'Show it on the checkout', true,
                         'The bar under the Payment section, at the foot of the checkout.'],
        'cart_on'    => ['bool', 'Show it on the cart page', false,
                         'Off, so the cart page is exactly as it is today until you turn this on. It is the same bar with the same words — there is no second set to keep in step.'],

        // ── Layout ──
        /*
         * THREE SHAPES OF THE SAME CONTENT, and the order is by height. The
         * owner asked for "a bar type" and for "some options to choose from",
         * so `bar` ships and the other two are there for a shop that wants the
         * contact details to carry more weight.
         */
        'variant'    => ['select', 'Shape', 'bar',
                         'All three carry the same words. One line is the shortest; the other two give the contact details more room.', [
                             'bar'   => 'One line — everything on a single row',
                             'split' => 'Two columns — brand on one side, contact on the other',
                             'stack' => 'Stacked — brand, then help, then the links',
                         ]],
        'tone'       => ['select', 'Background', 'cream',
                         'Three tones from the shop\'s own palette rather than a colour box: a footer that can be set to anything is a footer that can be set to something unreadable.', [
                             'cream' => 'Cream — the page\'s own ground',
                             'white' => 'White',
                             'ink'   => 'Dark',
                         ]],
        'divider'    => ['bool', 'Line above the bar', true,
                         'A hairline between the page and the footer. Off on the dark tone usually reads better, because the colour already separates them.'],
        'pad_y'      => ['range', 'Height', 12,
                         'Padding above and below. With "One line" this IS the height of the bar — 12 gives about 44px in total.',
                         ['min' => 2, 'max' => 40, 'step' => 1, 'unit' => 'px']],
        'pad_x'      => ['range', 'Side padding', 20,
                         'Between the screen edge and the first word.',
                         ['min' => 0, 'max' => 48, 'step' => 2, 'unit' => 'px']],
        'gap'        => ['range', 'Space between blocks', 18,
                         'Between the brand, the help line, the contacts and the links. On a phone they wrap, and this is the gap on both axes.',
                         ['min' => 4, 'max' => 40, 'step' => 1, 'unit' => 'px']],
        'font'       => ['range', 'Text size', 100,
                         'Everything in the bar except the brand, which has its own size below. A multiplier on 12px.',
                         ['min' => 70, 'max' => 140, 'step' => 5, 'unit' => '%']],
        'brand_size' => ['range', 'Brand size', 100,
                         'The wordmark alone. A multiplier on 14px.',
                         ['min' => 70, 'max' => 190, 'step' => 5, 'unit' => '%']],
        'top_on'     => ['bool', 'Back-to-top arrow', true,
                         'A small arrow at the far end of the bar. It scrolls the page rather than jumping, and it is hidden from screen readers only if it has no label.'],

        // ── Content ──
        'brand'      => ['text', 'Brand name', 'K-BEAUTY BLISS',
                         'The wordmark at the start of the bar. Leave it empty to draw no brand at all.'],
        'byline'     => ['text', 'Under the brand', 'by FUSION DISTRICT GROUP.',
                         'The company line. Empty draws nothing.'],
        'help_title' => ['text', 'Help heading', 'Need Help?',
                         'Empty draws nothing.'],
        'help_sub'   => ['text', 'Help line', '24/7 Customer Support',
                         'Empty draws nothing.'],
        'phone'      => ['text', 'Phone shown', '+971 58 505 2611',
                         'Exactly as it should read. Empty removes the phone and its mark.'],
        'phone_url'  => ['text', 'Phone link', 'https://wa.me/971585052611',
                         'Where tapping the number goes — a wa.me link for WhatsApp, or tel: to dial. Only http, https, tel and mailto links are accepted; anything else is drawn as plain text rather than as a link.'],
        'email'      => ['text', 'Email shown', 'info@kbeautybliss.com',
                         'Empty removes the email and its mark. It is linked with mailto: automatically.'],
        'l1_text'    => ['text', 'First link', 'Shipping policy',
                         'Empty removes the link.'],
        'l1_url'     => ['text', 'First link goes to', '/shipping-policy',
                         'A path on this shop, or a full https:// address.'],
        'l2_text'    => ['text', 'Second link', 'Terms of service', 'Empty removes the link.'],
        'l2_url'     => ['text', 'Second link goes to', '/terms-of-service',
                         'A path on this shop, or a full https:// address.'],
        'top_label'  => ['text', 'Arrow label', 'Back to top',
                         'Read out by a screen reader; never shown. Empty hides the arrow from screen readers entirely, which is right only if the page has another way back up.'],
    ];

    public const TABS = [
        'pages'   => ['Where it shows', 'One bar, two pages, two switches. The cart ships off so that page is untouched until you say otherwise.',
                      ['co_on', 'cart_on']],
        'layout'  => ['Layout', 'Its shape, its tone and its height. "One line" is the shortest of the three.',
                      ['variant', 'tone', 'divider', 'pad_y', 'pad_x', 'gap', 'font', 'brand_size', 'top_on']],
        'content' => ['Content', 'Every word in the bar. Anything left empty is not drawn at all, rather than drawn empty — so the bar can be as short as a brand and a phone number.',
                      ['brand', 'byline', 'help_title', 'help_sub', 'phone', 'phone_url', 'email',
                       'l1_text', 'l1_url', 'l2_text', 'l2_url', 'top_label']],
    ];

    private const PREFIX = 'slimfooter_';

    /** key => the custom property it is emitted as, in px. */
    private const VARS = [
        'pad_y' => '--sf-pady',
        'pad_x' => '--sf-padx',
        'gap'   => '--sf-gap',
    ];

    /** key => the custom property it is emitted as, as a unitless factor. */
    private const RATIO_VARS = [
        'font'       => '--sf-f',
        'brand_size' => '--sf-bf',
    ];

    public function __construct(private SettingsService $settings) {}

    /** @return array<string, mixed> */
    public function all(): array
    {
        $out = [];

        foreach (self::SCHEMA as $key => $def) {
            $saved = $this->settings->get(self::PREFIX.$key, null);
            $out[$key] = $saved === null ? $def[2] : $this->cast($key, $saved);
        }

        return $out;
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? null;
    }

    /** @param array<string, mixed> $values */
    public function save(array $values): void
    {
        foreach ($values as $key => $value) {
            if (isset(self::SCHEMA[$key])) {
                $this->settings->set(self::PREFIX.$key, $this->cast($key, $value));
            }
        }
    }

    private function cast(string $key, mixed $value): mixed
    {
        $def = self::SCHEMA[$key] ?? null;

        if ($def === null) {
            return $value;
        }

        return match ($def[0]) {
            'bool' => (bool) $value,
            'range' => max((int) $def[4]['min'], min((int) $def[4]['max'], (int) $value)),
            /*
             * A SELECT MAY ONLY EVER HOLD ONE OF ITS OWN OPTIONS. Both of them
             * are printed into a class name, so a value from anywhere else
             * would be a class this stylesheet has never heard of at best.
             * Anything unrecognised falls back to the shipped default rather
             * than being stored.
             */
            'select' => isset($def[4][(string) $value]) ? (string) $value : (string) $def[2],
            /*
             * Trimmed and capped. These are printed into the page, escaped at
             * the point of use; the cap is so a paste accident cannot put a
             * novel in the footer of every order.
             */
            'text' => mb_substr(trim((string) $value), 0, 160),
            default => $value,
        };
    }

    /** True when this shop draws the bar on the page named. */
    public function onCheckout(): bool
    {
        return (bool) $this->get('co_on');
    }

    public function onCart(): bool
    {
        return (bool) $this->get('cart_on');
    }

    /**
     * A link the footer is allowed to draw, or null.
     *
     * ── WHY THIS EXISTS FOR A SETTING ONLY AN ADMIN CAN WRITE ───────────────
     *
     * `javascript:` in an href is script that runs as the shopper, on the page
     * they are paying from. The people who can reach this screen are trusted,
     * but "trusted" is a statement about intent and not about whether an
     * account has ever been taken. The cost of refusing three schemes here is
     * nothing, and the value on the day it matters is the whole checkout.
     *
     * A relative path is passed through Url::to() so a subdirectory
     * deployment cannot produce a link that escapes the app; an absolute
     * http(s), mailto or tel link is used as typed. Anything else returns
     * null, and the caller draws the words as plain text.
     */
    public function url(string $raw): ?string
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        if (str_starts_with($raw, '/')) {
            return \App\Support\Url::to($raw);
        }

        $scheme = strtolower((string) parse_url($raw, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https', 'mailto', 'tel'], true) ? $raw : null;
    }

    /**
     * Structural choices as classes. Leading space included, or an empty
     * string — the view interpolates it straight after `kbb-slimfoot`.
     */
    public function bodyClass(): string
    {
        $c = $this->all();

        $classes = array_filter([
            'sf-'.$c['variant'],
            'sf-t-'.$c['tone'],
            $c['divider'] ? '' : 'sf-noline',
        ]);

        return ' '.implode(' ', $classes);
    }

    /**
     * Only what the owner has actually moved, so the attribute is short enough
     * to read in a page source when something looks wrong.
     */
    public function cssVariables(): string
    {
        $c = $this->all();
        $out = [];

        foreach (self::VARS as $key => $prop) {
            if ($c[$key] !== self::SCHEMA[$key][2]) {
                $out[] = $prop.':'.$c[$key].'px';
            }
        }

        foreach (self::RATIO_VARS as $key => $prop) {
            if ($c[$key] !== self::SCHEMA[$key][2]) {
                $out[] = $prop.':'.rtrim(rtrim(number_format((int) $c[$key] / 100, 2, '.', ''), '0'), '.');
            }
        }

        return implode(';', $out);
    }

    /** `style="..."`, or nothing at all. */
    public function styleAttr(): string
    {
        $vars = $this->cssVariables();

        return $vars === '' ? '' : ' style="'.e($vars).'"';
    }
}

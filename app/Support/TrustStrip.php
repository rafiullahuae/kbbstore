<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The trust strip: "100% Authentic · Express UAE Delivery · Tabby, Tamara
 * Card, COD · 24/7 Support", in three places, plus the thin delivery line at
 * the top of /super-sale/.                                          (Lane TS)
 *
 * The owner picked H1 and F1 off docs/trust-strip-options and asked:
 *   "reduce little bit overall height, and reduce the spacing between banner
 *    and this strip. and the same strip will come right above the support
 *    strip in footer, without the box! and this one will come on all pages
 *    except, homepage, super-sale, cart, checkout, AND for DESKTOP this is one
 *    is fine ... AND this without box strip will come on super sale header
 *    below, and remove the previous strip from the super sale page which is
 *    below header. AND this thin 1-3 days delivery strip will also come on
 *    super sale page at the top."
 *
 * So, one partial (partials/trust-strip) in three shapes:
 *   card  the homepage, under the banner: a white card (H1);
 *   bare  /super-sale/, under its header: the same row with no box;
 *   foot  above the footer's pink help strip on every other page: no box on a
 *         phone, the F1 big-icon row on a laptop.
 *
 * ── LIGHT BY CONSTRUCTION ───────────────────────────────────────────────────
 * No script, no picture, no query. The words are interface strings (Translation
 * → Strings, English and Arabic), read from the group every shop page already
 * loads. The switches are read from SettingsService::all() — the request's one
 * settings map — never through get(), whose miss on a key nobody has saved
 * would take the non-autoload snapshot. The icons are the constants below and
 * the stylesheet is kbb.css's, so the strip adds markup and nothing else.
 *
 * ── RULE 5 ──────────────────────────────────────────────────────────────────
 * The only raw output is ICONS. A placement select answers one of its own
 * options or its default; the thin line's wording is printed escaped.
 */
final class TrustStrip
{
    /** Where it shows: phone and laptop, one of them, or nowhere. */
    public const PLACES = ['both' => 'Phone and laptop', 'phone' => 'Phone only', 'laptop' => 'Laptop only', 'off' => 'Off'];

    /** The four items, in order: interface-string stem => icon. */
    public const ITEMS = ['auth', 'del', 'pay', 'sup'];

    /**
     * 24-unit stroke icons, drawn on whole and half units. The stroke is set
     * by the stylesheet (1.75, non-scaling), so it is the same at every size.
     */
    public const ICONS = [
        // a scalloped seal with a tick
        'auth' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8.6 3.9a4 4 0 0 1 6.8 0 4 4 0 0 1 4.7 4.7 4 4 0 0 1 0 6.8 4 4 0 0 1-4.7 4.7 4 4 0 0 1-6.8 0 4 4 0 0 1-4.7-4.7 4 4 0 0 1 0-6.8 4 4 0 0 1 4.7-4.7Z"/><path d="m9 12 2 2 4-4"/></svg>',
        // a delivery van with a speed line
        'del' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 17V7a1 1 0 0 0-1-1H6"/><path d="M2 10h5M3 13.5h3"/><path d="M14 9h3.6a1 1 0 0 1 .8.4l2.4 3.2a1 1 0 0 1 .2.6V16a1 1 0 0 1-1 1h-1"/><path d="M9.5 17h5"/><circle cx="7.5" cy="17" r="2"/><circle cx="17" cy="17" r="2"/></svg>',
        // a card
        'pay' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="2.5" y="5" width="19" height="14" rx="2.5"/><path d="M2.5 10h19M6.5 15h4"/></svg>',
        // a chat bubble with a heart
        'sup' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7.9 20A9 9 0 1 0 4 16.1L2.5 21.5Z"/><path d="M12 15.5 9 12.6a1.9 1.9 0 0 1 3-2.4 1.9 1.9 0 0 1 3 2.4Z"/></svg>',
    ];

    private const PLACE_HELP = 'Phone and laptop, one of them, or off.';

    /** Appearance → Homepage content → Trust strip. Every default is what he asked for. */
    public const SCHEMA = [
        'ts_home' => ['type' => 'select', 'label' => 'Homepage · under the banner', 'default' => 'both', 'store' => 'setting', 'options' => self::PLACES,
            'help' => 'The white card under the homepage banner. '.self::PLACE_HELP],
        'ts_foot' => ['type' => 'select', 'label' => 'Above the footer · every other page', 'default' => 'both', 'store' => 'setting', 'options' => self::PLACES,
            'help' => 'Directly above the footer’s pink help strip, on every page except the homepage, Super Sale, the cart and the checkout, which never show it. No box on a phone; large icons with a short line under each on a laptop.'],
        'ts_sale' => ['type' => 'select', 'label' => 'Super Sale · under the page header', 'default' => 'both', 'store' => 'setting', 'options' => self::PLACES,
            'help' => 'The same four items with no box, under the Super Sale header. '.self::PLACE_HELP],
        'ts_sale_line' => ['type' => 'select', 'label' => 'Super Sale · thin delivery line at the top', 'default' => 'both', 'store' => 'setting', 'options' => self::PLACES,
            'help' => 'The thin pink line at the very top of the Super Sale page. Its colours and size are the homepage Top strip’s.'],
        'ts_sale_line_en' => ['type' => 'text', 'label' => 'Thin line wording · English', 'default' => '', 'store' => 'setting', 'max' => 140,
            'help' => 'Empty: “1-3 Days Delivery all over UAE – Free Delivery over AED 199”, with the figure read from Store → Delivery & Shipping.'],
        'ts_sale_line_ar' => ['type' => 'text', 'label' => 'Thin line wording · Arabic', 'default' => '', 'store' => 'setting', 'max' => 140,
            'help' => 'Empty: the standard Arabic of the line above. The four items’ own words are under Translation → Strings, “trust_strip”.'],
    ];

    public const TABS = [
        'truststrip' => ['Trust strip', '“100% Authentic · Express UAE Delivery · Tabby, Tamara Card, COD · 24/7 Support”: under the homepage banner, under the Super Sale header and above the footer, plus the thin delivery line at the top of Super Sale.', [
            'ts_home', 'ts_foot', 'ts_sale', 'ts_sale_line', 'ts_sale_line_en', 'ts_sale_line_ar',
        ]],
    ];

    /**
     * The device class for a placement — '' for both, the shop's own d-off /
     * m-off for one — or null when it is off and nothing is drawn.
     *
     * @param  array<string, mixed>  $all  SettingsService::all()
     */
    public static function place(array $all, string $key): ?string
    {
        $v = $all[$key] ?? null;
        $v = is_string($v) && isset(self::PLACES[$v]) ? $v : (string) self::SCHEMA[$key]['default'];

        return match ($v) {
            'off' => null,
            'phone' => 'd-off',
            'laptop' => 'm-off',
            default => '',
        };
    }

    /**
     * The thin line for /super-sale/: the owner's wording for this language,
     * or null for the shop's own line (partials/home/top-strip draws that),
     * with the homepage Top strip's colours and size.
     *
     * @param  array<string, mixed>  $all  SettingsService::all()
     * @return array{text: ?string, url: string, style: string}
     */
    public static function saleLine(array $all): array
    {
        $arabic = ! Locale::isDefault() && Locale::current() === 'ar';
        $text = trim(strip_tags((string) ($all[$arabic ? 'ts_sale_line_ar' : 'ts_sale_line_en'] ?? '')));

        return ['text' => $text === '' ? null : mb_substr($text, 0, 140), 'url' => '', 'style' => HomeStrips::top($all)['style']];
    }
}

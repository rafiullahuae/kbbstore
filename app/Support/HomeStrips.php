<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The homepage's Top strip (Lane HC).
 *
 * The owner, of the old shop on a phone: "ONLY FOR MOBILE: turn this off in
 * laptop by default. i need the the top bar strip, same color, same text and
 * size etc." — a thin rose line under the header and search: "1-3 Days
 * Delivery all over UAE – Free Delivery over AED 199".
 *
 * ITS SETTINGS ARE HOMEPAGE CONTENT SETTINGS, the row HomeController already
 * reads (HomeSections::settings()), so the strip costs no query. Whether it
 * shows, per device, is its HomepageSections row — `topstrip`, phones only by
 * default — exactly like every other section.
 *
 * BLANK WORDING IS NOT EMPTY. It means the shop's own line, built from the two
 * readers this shop already trusts for delivery claims, so the strip cannot
 * promise what the checkout will not honour: the first half only where
 * DeliveryLine::here() has something true to say for this shopper's country,
 * the second half only where ShippingService::thresholdHere() has a
 * free-delivery figure for it — the same rule the hero's delivery band
 * follows. Both halves are translated keys, so /ar reads Arabic. Typed
 * wording replaces it in both languages and is printed escaped.
 */
final class HomeStrips
{
    private const SIZES = ['11' => '11px', '12' => '12px', '13' => '13px', '14' => '14px', '15' => '15px', '16' => '16px'];

    private const HEIGHTS = ['24' => '24px', '26' => '26px', '28' => '28px', '30' => '30px', '32' => '32px', '36' => '36px', '40' => '40px', '44' => '44px', '48' => '48px'];

    public const SCHEMA = [
        'home_ts_text' => ['type' => 'text', 'label' => 'Strip wording', 'default' => '', 'store' => 'setting', 'help' => 'Empty: “1-3 Days Delivery all over UAE – Free Delivery over AED 199”, with the figure read from Store → Delivery & Shipping, in English and Arabic. Type your own to replace it in both.'],
        'home_ts_url' => ['type' => 'text', 'label' => 'Strip link', 'default' => '', 'store' => 'setting', 'help' => 'Optional. A path on this shop (/…) or a full https:// address; anything else is ignored and the strip is not a link.'],
        'home_ts_size' => ['type' => 'select', 'label' => 'Strip text size', 'default' => '13', 'store' => 'setting', 'options' => self::SIZES, 'help' => ''],
        'home_ts_h' => ['type' => 'select', 'label' => 'Strip height', 'default' => '30', 'store' => 'setting', 'options' => self::HEIGHTS, 'help' => 'A longer line wraps onto a second row rather than being cut off.'],
        'home_ts_bg' => ['type' => 'colour', 'label' => 'Strip background', 'default' => '#E991AE', 'store' => 'setting', 'help' => ''],
        'home_ts_ink' => ['type' => 'colour', 'label' => 'Strip text colour', 'default' => '#FFFFFF', 'store' => 'setting', 'help' => ''],
    ];

    public const TABS = [
        'topstrip' => ['Top strip', 'The thin line directly under the header: its wording, link, size and colours. Phones only by default — switch laptops on in its card under All sections, or on Appearance → Homepage.', ['home_ts_text', 'home_ts_url', 'home_ts_size', 'home_ts_h', 'home_ts_bg', 'home_ts_ink']],
    ];

    /**
     * Printed once, with the strip, from this constant — no value inside it,
     * so nothing a setting holds reaches a stylesheet. The colours and sizes
     * arrive as custom properties on the element, which Blade escapes.
     */
    public const CSS = '<style id="kbb-kts">.kts{display:flex;align-items:center;justify-content:center;box-sizing:border-box;min-height:var(--kts-h,30px);padding:3px 12px;background:var(--kts-bg,#E991AE);color:var(--kts-ink,#fff);font-size:var(--kts-s,13px);font-weight:600;line-height:1.3;text-align:center;text-decoration:none}a.kts:hover{color:var(--kts-ink,#fff);opacity:.92}</style>';

    /**
     * What the strip draws: the owner's own line or null for the shop's, its
     * link ('' for none), and the custom properties.
     *
     * @return array{text: ?string, url: string, style: string}
     */
    public static function top(array $c): array
    {
        $hex = static fn (string $k, string $d): string => preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($c[$k] ?? '')) === 1 ? (string) $c[$k] : $d;
        $size = (string) ($c['home_ts_size'] ?? '');
        $h = (string) ($c['home_ts_h'] ?? '');
        $text = trim((string) ($c['home_ts_text'] ?? ''));

        return [
            'text' => $text === '' ? null : $text,
            'url' => HomeSections::url((string) ($c['home_ts_url'] ?? ''), ''),
            'style' => '--kts-bg:'.$hex('home_ts_bg', '#E991AE').';--kts-ink:'.$hex('home_ts_ink', '#FFFFFF')
                .';--kts-s:'.(isset(self::SIZES[$size]) ? $size : '13').'px;--kts-h:'.(isset(self::HEIGHTS[$h]) ? $h : '30').'px',
        ];
    }
}

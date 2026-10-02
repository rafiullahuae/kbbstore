<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The inline SVGs the product page's trust and share blocks draw.  (Lane PW)
 *
 * CONSTANTS, and that is the whole reason they live here rather than in the
 * partials: each is printed with `{!! !!}`, and rule 5 says anything printed
 * unescaped is a constant, never a setting. Nothing a setting holds is ever
 * concatenated into one of these.
 *
 * Every icon is `aria-hidden` — the control it sits in carries the name.
 */
final class TrustShareIcons
{
    /**
     * The drawn "FAST DELIVERY" mark the delivery box ships with, until the
     * owner uploads his own picture in Appearance → Product page → Trust ·
     * Delivery box → Delivery picture.
     *
     * Drawn from his description of the old site's picture: a navy delivery
     * truck with speed lines, FAST in orange bold italic, DELIVERY in navy bold
     * italic, and an orange swoosh arrow under the lot. `textLength` pins each
     * word to its width whatever face the browser substitutes, so the mark
     * cannot overflow its box on a phone without the face.
     */
    public const TRUCK = '<svg class="pts-truck" viewBox="0 0 124 52" aria-hidden="true" focusable="false">'
        .'<g fill="#1B1F5E">'
        .'<rect x="1" y="12" width="11" height="2.6" rx="1.3"/><rect x="4" y="18" width="9" height="2.6" rx="1.3"/><rect x="1" y="24" width="12" height="2.6" rx="1.3"/>'
        .'<rect x="16" y="7" width="30" height="22" rx="2.5"/>'
        .'<path d="M47.5 12.5h8.2c.9 0 1.7.4 2.2 1.1l4.4 6.2c.4.5.6 1.2.6 1.8V29h-15.4z"/>'
        .'</g>'
        .'<path d="M50.5 15.5h5l3.4 5h-8.4z" fill="#CFE3FF"/>'
        .'<g fill="#1B1F5E" stroke="#fff" stroke-width="1.6"><circle cx="25" cy="30" r="4.6"/><circle cx="54.5" cy="30" r="4.6"/></g>'
        .'<g fill="#fff"><circle cx="25" cy="30" r="1.5"/><circle cx="54.5" cy="30" r="1.5"/></g>'
        .'<text x="66" y="19" fill="#F7941D" font-family="Arial,Helvetica,sans-serif" font-size="16" font-weight="800" font-style="italic" textLength="40" lengthAdjust="spacingAndGlyphs">FAST</text>'
        .'<text x="66" y="32" fill="#1B1F5E" font-family="Arial,Helvetica,sans-serif" font-size="11" font-weight="800" font-style="italic" textLength="56" lengthAdjust="spacingAndGlyphs">DELIVERY</text>'
        .'<path d="M5 45.5C38 50.5 78 49 112 39.5" fill="none" stroke="#F7941D" stroke-width="3.2" stroke-linecap="round"/>'
        .'<path d="M105.5 35.2 117.5 37.6 109.4 46.4z" fill="#F7941D"/>'
        .'</svg>';

    /** The green outlined check-square before "Authenticity Guaranteed". */
    public const CHECK_SQUARE = '<svg class="pts-ic" viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="4"/><path d="m8 12.5 2.8 2.8L16.5 9.5"/></svg>';

    /** The small grey circled "?" after it. */
    public const INFO = '<svg class="pts-info" viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9.5"/><path d="M9.6 9.3a2.5 2.5 0 0 1 4.8.9c0 1.7-2.4 2.2-2.4 3.6"/><path d="M12 17.2h.01"/></svg>';

    /** The green "yes" tick inside the opened panel. */
    public const YES = '<svg class="pts-yes" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="11" fill="currentColor"/><path d="m7 12.4 3.3 3.3L17.2 8.8" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>';

    /** The × in the small reddish circle that closes the panel. */
    public const CLOSE = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M7.5 7.5l9 9M16.5 7.5l-9 9"/></svg>';

    /**
     * The share glyphs, white on each network's colour. key => <svg>.
     *
     * @var array<string, string>
     */
    public const NETWORK = [
        'whatsapp' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="currentColor"><path d="M12.04 2.5a9.4 9.4 0 0 0-8.1 14.2L2.5 21.5l4.9-1.4a9.4 9.4 0 1 0 4.64-17.6zm0 17.1a7.7 7.7 0 0 1-3.94-1.08l-.28-.17-2.9.82.83-2.83-.18-.29a7.7 7.7 0 1 1 6.47 3.55zm4.23-5.77c-.23-.12-1.37-.68-1.58-.75-.21-.08-.37-.12-.52.11-.15.24-.6.76-.73.91-.14.15-.27.17-.5.06-.23-.12-.98-.36-1.86-1.15a7 7 0 0 1-1.29-1.6c-.13-.24 0-.36.1-.48.1-.1.23-.27.35-.4.11-.14.15-.24.23-.4.07-.15.04-.29-.02-.4-.06-.12-.52-1.26-.72-1.72-.19-.45-.38-.39-.52-.4h-.45a.86.86 0 0 0-.62.3c-.21.22-.82.8-.82 1.95s.84 2.27.96 2.42c.11.15 1.65 2.52 4 3.53.56.24 1 .39 1.34.5.56.18 1.07.15 1.48.09.45-.07 1.37-.56 1.57-1.1.19-.54.19-1 .13-1.1-.06-.1-.21-.16-.44-.27z"/></svg>',
        'facebook' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="currentColor"><path d="M13.6 21.5v-7.7h2.6l.4-3h-3V8.9c0-.87.25-1.46 1.5-1.46h1.6V4.75a21 21 0 0 0-2.33-.12c-2.3 0-3.88 1.4-3.88 3.98v2.2H7.9v3h2.6v7.7z"/></svg>',
        'x' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="currentColor"><path d="M17.3 3.5h2.9l-6.3 7.2 7.4 9.8h-5.8l-4.5-5.9-5.2 5.9H2.9l6.7-7.7L2.5 3.5h5.9l4.1 5.4zm-1 15.3h1.6L7.7 5.1H6z"/></svg>',
        'pinterest' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="currentColor"><path d="M12.2 2.5c-5.3 0-8 3.8-8 7 0 1.9.7 3.6 2.3 4.3.3.1.5 0 .6-.3l.2-.9c.1-.3 0-.4-.2-.7-.5-.6-.8-1.3-.8-2.3 0-3 2.2-5.6 5.8-5.6 3.2 0 4.9 1.9 4.9 4.5 0 3.4-1.5 6.3-3.8 6.3-1.2 0-2.2-1-1.9-2.3.4-1.5 1.1-3.1 1.1-4.2 0-1-.5-1.8-1.6-1.8-1.3 0-2.3 1.3-2.3 3.1 0 1.1.4 1.9.4 1.9l-1.5 6.4c-.4 1.9-.1 4.2 0 4.4 0 .1.2.2.3.1.1-.2 1.5-1.9 2-3.6l.8-3c.4.7 1.5 1.4 2.7 1.4 3.6 0 6-3.3 6-7.6 0-3.3-2.8-6.3-7-6.3z"/></svg>',
        'linkedin' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="currentColor"><path d="M5.3 3.5a1.9 1.9 0 1 1 0 3.8 1.9 1.9 0 0 1 0-3.8zM3.7 8.8h3.3v11.2H3.7zm5.4 0h3.1v1.5h.05c.44-.8 1.5-1.7 3.1-1.7 3.3 0 3.9 2.2 3.9 5V20h-3.3v-5.5c0-1.3 0-3-1.8-3s-2.1 1.4-2.1 2.9V20H9.1z"/></svg>',
        'telegram' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="currentColor"><path d="M20.7 4.3 2.9 11.2c-1.2.5-1.2 1.2-.2 1.5l4.6 1.4 1.7 5.4c.2.6.4.8.8.8.4 0 .6-.2.9-.5l2.2-2.1 4.6 3.4c.8.5 1.4.2 1.6-.8l3-14.1c.3-1.2-.5-1.8-1.4-1.4zM8.6 13.8l9.6-6.1c.5-.3.9-.1.5.2l-8 7.3-.3 3.3z"/></svg>',
        'email' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5.5" width="18" height="13" rx="2.5"/><path d="m3.8 7 8.2 6 8.2-6"/></svg>',
        'copy' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M10 14a4.5 4.5 0 0 0 6.4 0l3-3a4.5 4.5 0 0 0-6.4-6.4l-1.2 1.2"/><path d="M14 10a4.5 4.5 0 0 0-6.4 0l-3 3a4.5 4.5 0 0 0 6.4 6.4l1.2-1.2"/></svg>',
        'more' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3.5v11"/><path d="m7.5 7.5 4.5-4 4.5 4"/><path d="M5 12.5v6a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-6"/></svg>',
    ];

    /**
     * The share icon beside the product title — Amazon's glyph, three dots
     * joined by two lines.                                            (Lane QB)
     *
     * For Lane QA's `partials/product/share-button.blade.php`: printed with
     * `{!! !!}` because it is a constant. `currentColor`, so the button sets it.
     */
    public const SHARE = '<svg class="pdp-share-ic" viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M8.3 10.9l7.4-4.3M8.3 13.1l7.4 4.3"/><circle cx="6" cy="12" r="2.7" fill="currentColor"/><circle cx="18" cy="5.3" r="2.7" fill="currentColor"/><circle cx="18" cy="18.7" r="2.7" fill="currentColor"/></svg>';

    /** The sheet's ×: white, drawn on the dimmed page above the sheet's corner. */
    public const SHEET_CLOSE = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg>';

    /**
     * The sheet's app tiles: each a rounded square in the app's own colours
     * with its glyph in white, the way a phone's home screen draws it. key =>
     * <svg>. 48-unit box, 12-unit corner radius, 28-unit glyph centred.
     *
     * The gradient ids are prefixed `pdpsg-` and each appears once on a page
     * (the sheet is @once), so no two tiles can collide on a <linearGradient>.
     *
     * @var array<string, string>
     */
    public const TILE = [
        'whatsapp' => '<svg viewBox="0 0 48 48" aria-hidden="true" focusable="false"><rect width="48" height="48" rx="12" fill="#25D366"/><g transform="translate(10 10) scale(1.1667)" fill="#fff"><path d="M12.04 2.5a9.4 9.4 0 0 0-8.1 14.2L2.5 21.5l4.9-1.4a9.4 9.4 0 1 0 4.64-17.6zm0 17.1a7.7 7.7 0 0 1-3.94-1.08l-.28-.17-2.9.82.83-2.83-.18-.29a7.7 7.7 0 1 1 6.47 3.55zm4.23-5.77c-.23-.12-1.37-.68-1.58-.75-.21-.08-.37-.12-.52.11-.15.24-.6.76-.73.91-.14.15-.27.17-.5.06-.23-.12-.98-.36-1.86-1.15a7 7 0 0 1-1.29-1.6c-.13-.24 0-.36.1-.48.1-.1.23-.27.35-.4.11-.14.15-.24.23-.4.07-.15.04-.29-.02-.4-.06-.12-.52-1.26-.72-1.72-.19-.45-.38-.39-.52-.4h-.45a.86.86 0 0 0-.62.3c-.21.22-.82.8-.82 1.95s.84 2.27.96 2.42c.11.15 1.65 2.52 4 3.53.56.24 1 .39 1.34.5.56.18 1.07.15 1.48.09.45-.07 1.37-.56 1.57-1.1.19-.54.19-1 .13-1.1-.06-.1-.21-.16-.44-.27z"/></g></svg>',
        'messenger' => '<svg viewBox="0 0 48 48" aria-hidden="true" focusable="false"><defs><linearGradient id="pdpsg-ms" x1="0" y1="1" x2="1" y2="0"><stop offset="0" stop-color="#0099FF"/><stop offset=".6" stop-color="#A033FF"/><stop offset=".9" stop-color="#FF5280"/><stop offset="1" stop-color="#FF7061"/></linearGradient></defs><rect width="48" height="48" rx="12" fill="url(#pdpsg-ms)"/><g transform="translate(10 10) scale(1.1667)" fill="#fff"><path d="M12 2.4c-5.4 0-9.6 4-9.6 9.3 0 2.8 1.1 5.2 3 6.9v3.4l3.2-1.8c1 .3 2.2.4 3.4.4 5.4 0 9.6-4 9.6-9.2S17.4 2.4 12 2.4zm1 12.4-2.5-2.6-4.8 2.6 5.3-5.6 2.5 2.6 4.7-2.6z"/></g></svg>',
        'pinterest' => '<svg viewBox="0 0 48 48" aria-hidden="true" focusable="false"><rect width="48" height="48" rx="12" fill="#E60023"/><g transform="translate(10 10) scale(1.1667)" fill="#fff"><path d="M12.2 2.5c-5.3 0-8 3.8-8 7 0 1.9.7 3.6 2.3 4.3.3.1.5 0 .6-.3l.2-.9c.1-.3 0-.4-.2-.7-.5-.6-.8-1.3-.8-2.3 0-3 2.2-5.6 5.8-5.6 3.2 0 4.9 1.9 4.9 4.5 0 3.4-1.5 6.3-3.8 6.3-1.2 0-2.2-1-1.9-2.3.4-1.5 1.1-3.1 1.1-4.2 0-1-.5-1.8-1.6-1.8-1.3 0-2.3 1.3-2.3 3.1 0 1.1.4 1.9.4 1.9l-1.5 6.4c-.4 1.9-.1 4.2 0 4.4 0 .1.2.2.3.1.1-.2 1.5-1.9 2-3.6l.8-3c.4.7 1.5 1.4 2.7 1.4 3.6 0 6-3.3 6-7.6 0-3.3-2.8-6.3-7-6.3z"/></g></svg>',
        'telegram' => '<svg viewBox="0 0 48 48" aria-hidden="true" focusable="false"><defs><linearGradient id="pdpsg-tg" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#37AEE2"/><stop offset="1" stop-color="#1E96C8"/></linearGradient></defs><rect width="48" height="48" rx="12" fill="url(#pdpsg-tg)"/><g transform="translate(9 10) scale(1.1667)" fill="#fff"><path d="M20.7 4.3 2.9 11.2c-1.2.5-1.2 1.2-.2 1.5l4.6 1.4 1.7 5.4c.2.6.4.8.8.8.4 0 .6-.2.9-.5l2.2-2.1 4.6 3.4c.8.5 1.4.2 1.6-.8l3-14.1c.3-1.2-.5-1.8-1.4-1.4zM8.6 13.8l9.6-6.1c.5-.3.9-.1.5.2l-8 7.3-.3 3.3z"/></g></svg>',
        'snapchat' => '<svg viewBox="0 0 48 48" aria-hidden="true" focusable="false"><rect width="48" height="48" rx="12" fill="#FFFC00"/><g transform="translate(10 9.5) scale(1.1667)"><path d="M12 3.2c2.9 0 5 2.1 5 5.1v2.3c.4.2.9.1 1.4-.1.5-.2 1 .3.6.8-.4.4-1.1.6-1.6.8-.3.1-.4.4-.3.7.8 1.7 2.1 2.8 3.6 3.2.4.1.4.6.1.8-.6.4-1.5.5-2.2.7-.2.4-.1 1-.6 1.1-.6.1-1.3-.2-2.1 0-1 .2-1.9 1.4-3.9 1.4s-2.9-1.2-3.9-1.4c-.8-.2-1.5.1-2.1 0-.5-.1-.4-.7-.6-1.1-.7-.2-1.6-.3-2.2-.7-.3-.2-.3-.7.1-.8 1.5-.4 2.8-1.5 3.6-3.2.1-.3 0-.6-.3-.7-.5-.2-1.2-.4-1.6-.8-.4-.5.1-1 .6-.8.5.2 1 .3 1.4.1V8.3c0-3 2.1-5.1 5-5.1z" fill="#fff" stroke="#111" stroke-width="1.15" stroke-linejoin="round"/></g></svg>',
        'sms' => '<svg viewBox="0 0 48 48" aria-hidden="true" focusable="false"><defs><linearGradient id="pdpsg-sm" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#5FF777"/><stop offset="1" stop-color="#0CBD2A"/></linearGradient></defs><rect width="48" height="48" rx="12" fill="url(#pdpsg-sm)"/><g transform="translate(10 10) scale(1.1667)" fill="#fff"><path d="M12 3.6c-5 0-9.1 3.4-9.1 7.6 0 2.4 1.3 4.6 3.4 6-.2 1.3-.9 2.6-1.9 3.4 2 0 3.8-.8 5-1.9.8.2 1.7.3 2.6.3 5 0 9.1-3.4 9.1-7.6S17 3.6 12 3.6z"/></g></svg>',
        'email' => '<svg viewBox="0 0 48 48" aria-hidden="true" focusable="false"><defs><linearGradient id="pdpsg-em" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#56CCF2"/><stop offset="1" stop-color="#2F80ED"/></linearGradient></defs><rect width="48" height="48" rx="12" fill="url(#pdpsg-em)"/><g transform="translate(10 10) scale(1.1667)" fill="none" stroke="#fff" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5.5" width="18" height="13" rx="2.5"/><path d="m3.8 7 8.2 6 8.2-6"/></g></svg>',
        'copy' => '<svg viewBox="0 0 48 48" aria-hidden="true" focusable="false"><defs><linearGradient id="pdpsg-cp" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#7B8794"/><stop offset="1" stop-color="#4A5563"/></linearGradient></defs><rect width="48" height="48" rx="12" fill="url(#pdpsg-cp)"/><g transform="translate(10 10) scale(1.1667)" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 14a4.5 4.5 0 0 0 6.4 0l3-3a4.5 4.5 0 0 0-6.4-6.4l-1.2 1.2"/><path d="M14 10a4.5 4.5 0 0 0-6.4 0l-3 3a4.5 4.5 0 0 0 6.4 6.4l1.2-1.2"/></g></svg>',
        'native' => '<svg viewBox="0 0 48 48" aria-hidden="true" focusable="false"><rect width="48" height="48" rx="12" fill="#ECECF1"/><g fill="#3A3A40"><circle cx="15" cy="24" r="3"/><circle cx="24" cy="24" r="3"/><circle cx="33" cy="24" r="3"/></g></svg>',
        'facebook' => '<svg viewBox="0 0 48 48" aria-hidden="true" focusable="false"><rect width="48" height="48" rx="12" fill="#1877F2"/><g transform="translate(10 10) scale(1.1667)" fill="#fff"><path d="M13.6 21.5v-7.7h2.6l.4-3h-3V8.9c0-.87.25-1.46 1.5-1.46h1.6V4.75a21 21 0 0 0-2.33-.12c-2.3 0-3.88 1.4-3.88 3.98v2.2H7.9v3h2.6v7.7z"/></g></svg>',
        'x' => '<svg viewBox="0 0 48 48" aria-hidden="true" focusable="false"><rect width="48" height="48" rx="12" fill="#000"/><g transform="translate(11 11) scale(1.0833)" fill="#fff"><path d="M17.3 3.5h2.9l-6.3 7.2 7.4 9.8h-5.8l-4.5-5.9-5.2 5.9H2.9l6.7-7.7L2.5 3.5h5.9l4.1 5.4zm-1 15.3h1.6L7.7 5.1H6z"/></g></svg>',
        'linkedin' => '<svg viewBox="0 0 48 48" aria-hidden="true" focusable="false"><rect width="48" height="48" rx="12" fill="#0A66C2"/><g transform="translate(11 11) scale(1.0833)" fill="#fff"><path d="M5.3 3.5a1.9 1.9 0 1 1 0 3.8 1.9 1.9 0 0 1 0-3.8zM3.7 8.8h3.3v11.2H3.7zm5.4 0h3.1v1.5h.05c.44-.8 1.5-1.7 3.1-1.7 3.3 0 3.9 2.2 3.9 5V20h-3.3v-5.5c0-1.3 0-3-1.8-3s-2.1 1.4-2.1 2.9V20H9.1z"/></g></svg>',
    ];

    /** One tile, or '' — a constant or nothing. */
    public static function tile(string $key): string
    {
        return self::TILE[$key] ?? '';
    }

    /** One network's glyph, or '' — never a lookup that can return anything but a constant. */
    public static function network(string $key): string
    {
        return self::NETWORK[$key] ?? '';
    }
}

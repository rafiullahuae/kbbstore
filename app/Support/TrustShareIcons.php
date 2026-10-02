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

    /** One network's glyph, or '' — never a lookup that can return anything but a constant. */
    public static function network(string $key): string
    {
        return self::NETWORK[$key] ?? '';
    }
}

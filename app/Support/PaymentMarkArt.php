<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The card schemes' acceptance marks, drawn as inline SVG.
 *
 * ── WHY INLINE SVG AND NOT IMAGE FILES ──────────────────────────────────────
 *
 * Three reasons, each of them a property of THIS deployment rather than a
 * preference:
 *
 * 1. `bootstrap/app.php` ends with usePublicPath() pointing at a directory
 *    that is not the application root. A file dropped in `public/` in this
 *    repo is not the file the web server serves, so an <img src> here is a
 *    broken image there until somebody copies it by hand over FTP.
 * 2. Code reaches the server as a signed zip applied through Store -> Core
 *    Updates. Markup that lives inside a PHP string ships with the PHP: it
 *    cannot arrive half-applied the way a package that carries a .php and six
 *    .svg files can, and packages 2.60.102-.106 are the reminder that
 *    half-applied is a real state on this host.
 * 3. An inline SVG sized in `em` inherits the font-size of the row it sits in,
 *    so `trust_size` keeps scaling the marks with the tick and the wording
 *    with no second control and no extra plumbing.
 *
 * ── HOW THEY ARE SIZED ──────────────────────────────────────────────────────
 *
 * `height:2.2em` against the trust row's own font-size (6.5px * --cpg-trust-s)
 * gives roughly 14px of artwork at trust_size 100%, which is the size at which
 * a scheme mark is still read as a mark rather than as a label. `width:auto`
 * lets each viewBox's own aspect ratio decide the width, because a Visa
 * wordmark squeezed to a Mastercard's proportions is not a Visa wordmark.
 *
 * `max-width:100%` is the one that is easy to drop and should not be. The
 * chips live in a `flex-wrap:nowrap` row, so when the row runs out of width
 * the browser shrinks the chips; artwork with a fixed height and an automatic
 * width does not shrink with them and simply overlaps its neighbour. With the
 * cap the mark scales down inside its chip instead. This is strictly better
 * than the text chips it replaces, which overflow the summary card outright at
 * trust_size 150% and on a 320px screen.
 *
 * ── WHAT THESE DRAWINGS ARE ─────────────────────────────────────────────────
 *
 * They are clean, simplified renderings in each scheme's own colours, of the
 * kind a merchant acceptance row carries. They are NOT the schemes' licensed
 * asset kits, and no attempt is made to reproduce the licensed originals
 * outline-for-outline. Mastercard's two interlocking circles are geometry and
 * so are exact; the rest are wordmarks drawn to the right proportions and
 * colours. If the owner later obtains the schemes' supplied artwork, this
 * class is the single place it is swapped in: nothing else in the application
 * knows what a payment mark looks like.
 *
 * ── AND WHY IT IS A CONSTANT ────────────────────────────────────────────────
 *
 * NOTHING USER-SUPPLIED REACHES THIS OUTPUT. The trust row prints these with
 * `{!! !!}`, unescaped, which is the whole point of them -- so the list is a
 * hardcoded constant with no setting, no database read and no interpolation
 * anywhere in it. `/api/*` on this site is unauthenticated and the repo's
 * landmine list is largely made of things that reached a page because
 * somebody let a value travel further than they meant it to. An SVG assembled
 * from a setting would be a stored-XSS sink on the cart page; a constant
 * cannot be one.
 *
 * The brand names sit in the `aria-label` and `<title>` of each drawing, in
 * PHP, which is also where StorefrontStringsAreKeyedTest needs them to be:
 * they are company names, a translated one is a different company, and that
 * guard exists to catch English prose sitting in a storefront Blade.
 */
final class PaymentMarkArt
{
    /**
     * The six marks, keyed by the `pay_*` setting that switches each one on,
     * in the order the trust row prints them.
     *
     * Each value is a complete, self-contained <svg>: no external file, no
     * <image>, no base64, no reference to anything outside itself. `role="img"`
     * plus `aria-label` plus a `<title>` is what keeps the row's meaning for a
     * screen reader once the words become pictures.
     *
     * @var array<string, string>
     */
    private const MARKS = [
            'pay_visa' => '<svg style="height:2.2em;width:auto;max-width:100%;display:block" viewBox="0 0 41 16" role="img" aria-label="Visa"><title>Visa</title><path fill="#1434CB" fill-rule="evenodd" transform="skewX(-10) translate(2.6 0)" d="M0 3l4.1 10h2.8L11 3H7.6L5.5 9.6 3.4 3Zm11.9 0H15v10h-3.1Zm12.3.4-.4 2.4c-.8-.4-1.7-.7-2.7-.7-1.1 0-1.7.4-1.7 1 0 .4.4.7 1.3.9l1.3.3c1.9.4 2.8 1.3 2.8 2.6 0 2-1.8 3.3-4.5 3.3-1.3 0-2.6-.2-3.6-.6l.5-2.4c.9.7 2.1 1.1 3.3 1.1 1 0 1.8-.4 1.8-1.1 0-.5-.4-.8-1.3-1l-1.3-.3c-1.9-.5-2.9-1.3-2.9-2.7 0-1.8 1.6-3.2 4.1-3.2 1.2 0 2.4.2 3.3.4ZM29.9 3h2.6L37 13h-3l-.7-1.9h-4.2L28.4 13h-3Zm1.3 2.9L29.9 9.4h2.6Z"/></svg>',
            'pay_mc' => '<svg style="height:2.2em;width:auto;max-width:100%;display:block" viewBox="0 0 21.2 16" role="img" aria-label="Mastercard"><title>Mastercard</title><circle cx="7.8" cy="8" r="7.2" fill="#EB001B"/><circle cx="13.4" cy="8" r="7.2" fill="#F79E1B"/><path fill="#FF5F00" d="M10.6 1.37A7.2 7.2 0 0 1 10.6 14.63 7.2 7.2 0 0 1 10.6 1.37Z"/></svg>',
            'pay_apple' => '<svg style="height:2.2em;width:auto;max-width:100%;display:block" viewBox="0 0 39 16" role="img" aria-label="Apple Pay"><title>Apple Pay</title><path fill-rule="evenodd" d="M7.3 4.6c.5-.6.8-1.4.7-2.2-.7 0-1.6.5-2.1 1.1-.5.5-.9 1.4-.7 2.2.8.1 1.6-.4 2.1-1.1Zm.7 1.2c-1.2-.1-2.2.7-2.7.7-.6 0-1.4-.6-2.3-.6-1.2 0-2.3.7-2.9 1.8-1.2 2.2-.3 5.4.9 7.1.6.9 1.3 1.8 2.2 1.8.9 0 1.2-.6 2.3-.6s1.4.6 2.3.6c1 0 1.6-.9 2.2-1.7.7-1 1-1.9 1-2-.1 0-1.9-.7-1.9-2.8 0-1.7 1.4-2.5 1.5-2.6-.8-1.2-2-1.4-2.6-1.5ZM17.9 3.2h-4.4V13h1.9V9.6h2.5c2.1 0 3.6-1.3 3.6-3.2s-1.5-3.2-3.6-3.2Zm-2.5 1.6h2.1c1.2 0 2 .6 2 1.6s-.8 1.6-2 1.6h-2.1ZM27.6 13h1.8V8c0-1.6-1.3-2.7-3.3-2.7-1.9 0-3.2 1-3.4 2.5h1.7c.2-.7.8-1.1 1.6-1.1 1 0 1.6.5 1.6 1.3v.6l-2.4.1c-2 .1-3.1 1-3.1 2.4 0 1.5 1.2 2.4 2.8 2.4 1.1 0 2.1-.5 2.6-1.4h.1Zm-3.1-2c0-.6.5-1 1.5-1.1l2-.1v.6c0 1-.9 1.7-2 1.7-.9 0-1.5-.4-1.5-1.1ZM31.3 15.7c1.8 0 2.7-.7 3.4-2.8l3.3-9.2h-1.9l-2.2 7.1h-.1l-2.2-7.1h-2l3.2 8.8-.2.5c-.3.9-.8 1.2-1.6 1.2h-.6v1.4c.1 0 .7.1.9.1Z"/></svg>',
            'pay_google' => '<svg style="height:2.2em;width:auto;max-width:100%;display:block" viewBox="0 0 41 16" role="img" aria-label="Google Pay"><title>Google Pay</title><g fill="none" stroke-width="2.6"><path stroke="#EA4335" d="M2.46 7.22A5.6 5.6 0 0 1 9.45 2.59"/><path stroke="#4285F4" d="M9.45 2.59A5.6 5.6 0 0 1 13.08 10.37"/><path stroke="#34A853" d="M13.08 10.37A5.6 5.6 0 0 1 6.09 13.26"/><path stroke="#FBBC04" d="M6.09 13.26A5.6 5.6 0 0 1 2.46 7.22"/></g><path fill="#4285F4" d="M8.2 6.9h5.9v2.4H8.2Z"/><path fill="#5F6368" fill-rule="evenodd" d="M21.7 3.2h-4.1V13h1.8V9.6h2.3c2 0 3.4-1.3 3.4-3.2s-1.4-3.2-3.4-3.2Zm-2.3 1.6h1.9c1.2 0 1.9.6 1.9 1.6s-.7 1.6-1.9 1.6h-1.9ZM30.4 13h1.7V8c0-1.6-1.3-2.7-3.2-2.7-1.8 0-3.1 1-3.2 2.5h1.6c.2-.7.8-1.1 1.6-1.1.9 0 1.5.5 1.5 1.3v.6l-2.3.1c-1.9.1-2.9 1-2.9 2.4 0 1.5 1.1 2.4 2.7 2.4 1 0 2-.5 2.4-1.4h.1Zm-2.9-2c0-.6.5-1 1.4-1.1l1.9-.1v.6c0 1-.8 1.7-1.9 1.7-.8 0-1.4-.4-1.4-1.1ZM34 15.7c1.7 0 2.6-.7 3.2-2.8l3.1-9.2h-1.8l-2.1 7.1h-.1l-2.1-7.1h-1.9l3 8.8-.2.5c-.2.9-.7 1.2-1.5 1.2h-.5v1.4c.1 0 .7.1.9.1Z"/></svg>',
            'pay_tabby' => '<svg style="height:2.2em;width:auto;max-width:100%;display:block" viewBox="0 0 49 18.3" role="img" aria-label="tabby"><title>tabby</title><rect width="49" height="18.3" rx="3" fill="#3EEBC0"/><path fill="#14312B" fill-rule="evenodd" d="M6 4.1v2.2h1.9v2H6v2.4c0 .6.3.9.9.9.4 0 .7 0 1-.1v2c-.4.1-1 .2-1.6.2-1.8 0-2.8-.9-2.8-2.7V8.3H3.2v-2h1.3V4.1ZM16.2 13.5H13.9v-1c-.5.7-1.4 1.2-2.4 1.2C9.8 13.7 8.6 12.6 8.6 11.1c0-1.6 1.2-2.5 3.4-2.6l1.8-.1v-.3c0-.7-.5-1.1-1.4-1.1-.9 0-1.8.3-2.5.7L9.4 5.9c.9-.4 2.1-.7 3.2-.7 2.3 0 3.6 1.1 3.6 3.1Zm-2.4-3.6l-1.4.1c-.9.1-1.4.4-1.4 1s.5.9 1.1.9c1 0 1.7-.6 1.7-1.5ZM19.1 2.8v3.6c.5-.7 1.4-1.1 2.4-1.1 2.1 0 3.6 1.6 3.6 4s-1.5 4-3.6 4c-1.1 0-1.9-.4-2.5-1.2v1H16.8V2.8Zm1.9 8.5c1.1 0 1.8-.8 1.8-2s-.7-2-1.8-2-1.9.8-1.9 2 .8 2 1.9 2ZM28.2 2.8v3.6c.5-.7 1.4-1.1 2.4-1.1 2.1 0 3.6 1.6 3.6 4s-1.5 4-3.6 4c-1.1 0-1.9-.4-2.5-1.2v1H25.9V2.8Zm1.9 8.5c1.1 0 1.8-.8 1.8-2s-.7-2-1.8-2-1.9.8-1.9 2 .8 2 1.9 2ZM35 5.4h2.9l2.6 6.7 2.4-6.7h2.9l-3.9 10.1H39.2l1.3-3.2z"/></svg>',
            'pay_tamara' => '<svg style="height:2.2em;width:auto;max-width:100%;display:block" viewBox="0 0 55.5 16.3" role="img" aria-label="tamara"><title>tamara</title><rect width="55.5" height="16.3" rx="3" fill="#3B1E5E"/><path fill="#FFFFFF" fill-rule="evenodd" d="M6 4.1v2.2h1.9v2H6v2.4c0 .6.3.9.9.9.4 0 .7 0 1-.1v2c-.4.1-1 .2-1.6.2-1.8 0-2.8-.9-2.8-2.7V8.3H3.2v-2h1.3V4.1ZM16.2 13.5H13.9v-1c-.5.7-1.4 1.2-2.4 1.2C9.8 13.7 8.6 12.6 8.6 11.1c0-1.6 1.2-2.5 3.4-2.6l1.8-.1v-.3c0-.7-.5-1.1-1.4-1.1-.9 0-1.8.3-2.5.7L9.4 5.9c.9-.4 2.1-.7 3.2-.7 2.3 0 3.6 1.1 3.6 3.1Zm-2.4-3.6l-1.4.1c-.9.1-1.4.4-1.4 1s.5.9 1.1.9c1 0 1.7-.6 1.7-1.5ZM16.8 5.7h2.3v1c.5-.7 1.3-1.2 2.3-1.2 1.1 0 1.9.5 2.3 1.4.6-.9 1.5-1.4 2.6-1.4 1.7 0 2.8 1.2 2.8 3.1v5.4H26.8V9.5c0-1-.5-1.5-1.3-1.5s-1.4.6-1.4 1.6v4.4H21.8V9.5c0-1-.5-1.5-1.3-1.5s-1.4.6-1.4 1.6v4.4H16.8ZM37.7 13.5H35.4v-1c-.5.7-1.4 1.2-2.4 1.2C31.3 13.7 30.1 12.6 30.1 11.1c0-1.6 1.2-2.5 3.4-2.6l1.8-.1v-.3c0-.7-.5-1.1-1.4-1.1-.9 0-1.8.3-2.5.7L30.9 5.9c.9-.4 2.1-.7 3.2-.7 2.3 0 3.6 1.1 3.6 3.1Zm-2.4-3.6l-1.4.1c-.9.1-1.4.4-1.4 1s.5.9 1.1.9c1 0 1.7-.6 1.7-1.5ZM38.3 5.7h2.3v1.3c.4-.9 1.2-1.5 2.2-1.5.3 0 .5 0 .7.1v2.3c-.3-.1-.6-.1-.9-.1-1.2 0-2 .8-2 2.1v3.6H38.3ZM51.7 13.5H49.4v-1c-.5.7-1.4 1.2-2.4 1.2C45.3 13.7 44.1 12.6 44.1 11.1c0-1.6 1.2-2.5 3.4-2.6l1.8-.1v-.3c0-.7-.5-1.1-1.4-1.1-.9 0-1.8.3-2.5.7L44.9 5.9c.9-.4 2.1-.7 3.2-.7 2.3 0 3.6 1.1 3.6 3.1Zm-2.4-3.6l-1.4.1c-.9.1-1.4.4-1.4 1s.5.9 1.1.9c1 0 1.7-.6 1.7-1.5Z"/></svg>',
    ];

    /**
     * Every mark, in display order, keyed by its `pay_*` setting.
     *
     * @return array<string, string>
     */
    public static function marks(): array
    {
        return self::MARKS;
    }

    /**
     * The setting keys, in display order.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::MARKS);
    }
}

<?php

declare(strict_types=1);

namespace App\Support;

/**
 * An image address on its way into a CSS `url()`, made safe for THAT context.
 *
 * ── WHY e() IS NOT THE ANSWER HERE, AND WHAT IT ACTUALLY BUYS ───────────────
 *
 * Fifteen places in the storefront build a background declaration by pasting an
 * address between `url('` and `')` and then printing it into a `style=`
 * attribute. Every one of them used `e()`, the HTML escaper, and `e()` is
 * answering a different question. A browser reads a style attribute in two
 * stages: the HTML parser decodes the entities FIRST, and only then does the
 * CSS parser read what is left. `e()` turns `'` into `&#39;`; the HTML parser
 * turns it straight back into `'`; CSS sees a quote that closes the `url(` and
 * reads everything after it as further declarations.
 *
 * ── THE SIZE OF IT, MEASURED RATHER THAN ASSUMED ────────────────────────────
 *
 * It is CSS-context injection and not script injection: `e()` still escapes
 * `"`, `<` and `>`, so the attribute cannot be closed and no tag can be opened.
 * What a quote in the address buys is one rule of somebody else's choosing on
 * that element — a `background:url(https://theirs/…)` that fires a request from
 * the shopper's browser, or a restyle that covers something up.
 *
 * And of the fifteen, only TWO could be reached, which is the part a reader has
 * to know before trusting this file. Eleven of them build the declaration in
 * PHP with `e($img)` and then print the whole string through `{{ }}`, which is
 * `e()` a SECOND time with double-encoding on: `'` becomes `&#39;` becomes
 * `&amp;#39;`, the parser decodes one layer, and CSS is handed the six harmless
 * characters `&#39;` inside the quoted url. They were safe by accident. The two
 * that were not wrote the quotes as literal template text —
 * `url('{{ $v->image }}')` — so the value is escaped exactly once and the quote
 * comes back. Measured both ways, not reasoned about: see
 * tests/Feature/CssUrlInjectionTest.php, which renders the pages.
 *
 * The accident is not a defence. It depends on Blade's double-encoding staying
 * on (one `Blade::withoutDoubleEncoding()` in a service provider re-arms all
 * eleven) and on nobody ever moving a declaration into `{!! !!}`. So every site
 * goes through here, and the guard in tests/Feature/CssUrlSitesGuardTest.php
 * fails on a sixteenth that does not.
 *
 * It is worth a lane NOW because the WordPress import is about to write
 * thousands of these addresses from a database this shop did not author.
 *
 * ── WHAT THIS REFUSES versus WHAT IT MERELY ESCAPES ─────────────────────────
 *
 * REFUSED — the caller gets '' and draws its gradient placeholder instead:
 *   · a scheme that is not http or https (`javascript:`, `data:`, `vbscript:`,
 *     anything else), read the way Banners::safeUrl() reads one: entities
 *     decoded, whitespace and C0/C1 controls stripped, and only then the scheme
 *     matched, because a browser resolves `jav&#x09;ascript:…` to a javascript
 *     URL while a naive str_starts_with() waves it through;
 *   · a protocol-relative `//evil.test/x.jpg`, which carries no scheme and is
 *     not relative either — refused by name, exactly as Banners does;
 *   · null, '' and '0' — the three values every call site's ternary already
 *     treated as "no picture", so routing through here cannot change which
 *     branch a template takes.
 *
 * MERELY ESCAPED — the address is still drawn, with the character neutralised:
 *   · `'` `"` `(` `)` and `\`, backslash-escaped, which is what CSS reads
 *     inside a quoted string and what survives the `e()` the call site still
 *     applies afterwards (`e()` leaves `\` alone, and turns the `'` into
 *     `&#39;`, which the HTML parser hands back to CSS sitting behind its
 *     backslash);
 *   · newline, carriage return, form feed and every other C0/C1 control,
 *     hex-escaped `\XX ` — a raw newline inside a CSS string is a parse error
 *     that ends the declaration and lets what follows be read as a new one.
 *
 * NOT TOUCHED, deliberately: `&`, `<`, `>`, `#`, `?`, `%`, spaces and every
 * non-ASCII byte. They are ordinary in an address, harmless inside a quoted
 * CSS string, and the `e()` at the call site handles the ones HTML cares about.
 * That is also what keeps the rendered bytes identical for every address that
 * was already safe — this returns its argument unchanged for all of them.
 *
 * ── HOW TO USE IT ───────────────────────────────────────────────────────────
 *
 * The call site keeps its own `e()`; this composes with it rather than
 * replacing it, because the value ends up inside an HTML attribute AND inside
 * CSS and both escapes are needed, in this order:
 *
 *     $imgCss = CssUrl::value($img);
 *     $thumb  = $imgCss !== ''
 *         ? "background-image:url('" . e($imgCss) . "')"
 *         : 'background:' . Gradient::for($seed);
 */
final class CssUrl
{
    /** The only schemes this shop serves a picture from. */
    public const SAFE_SCHEMES = ['http', 'https'];

    /**
     * The address as it may appear between `url('` and `')`, or '' for no picture.
     *
     * '' is returned for a refused address as well as for an absent one, so a
     * caller has exactly one thing to test and a refused address falls back to
     * the same placeholder an absent one does — never `url('')`, which resolves
     * against the document and makes the browser fetch the page as an image.
     */
    public static function value(?string $raw): string
    {
        /*
         * PHP truthiness on purpose, not `=== null` and not a trim(). Every
         * call site asked `$img ? … : gradient`, so null, '' and the string '0'
         * all chose the gradient; matching that exactly is what lets this be
         * dropped in without moving a single byte of any page that renders
         * today. A trim() would move bytes, so leading and trailing whitespace
         * is carried through and escaped below like any other control.
         */
        if (! $raw) {
            return '';
        }

        $url = (string) $raw;

        if (! self::schemeIsServed($url)) {
            return '';
        }

        return self::escape($url);
    }

    /**
     * Is the scheme one this shop serves, reading it the way a browser will?
     *
     * The decode-then-strip-then-match order is Banners::safeUrl()'s and the
     * reasoning is recorded there in full: entities first, because a browser
     * resolves `jav&#x09;ascript:alert(1)` to a javascript URL and a check run
     * on the raw string sees something starting "jav&" and passes it; then
     * whitespace and C0/C1 controls, for the same reason; and only then the
     * scheme, so the match runs on the same string the browser resolves.
     *
     * It is repeated rather than called because Banners::safeUrl() answers a
     * different question — it guards an `href` an operator typed and returns
     * the URL, allowing mailto: and tel:, neither of which is a picture.
     */
    private static function schemeIsServed(string $url): bool
    {
        $probe = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $probe = (string) preg_replace('/[\s\x00-\x1F\x7F-\x9F]+/u', '', $probe);
        $probe = strtolower($probe);

        // No scheme and not relative either: `//evil.test/x.jpg` is somebody
        // else's host wearing this page's scheme.
        if (str_starts_with($probe, '//')) {
            return false;
        }

        if (preg_match('/^([a-z][a-z0-9+.\-]*):/', $probe, $m) === 1) {
            return in_array($m[1], self::SAFE_SCHEMES, true);
        }

        // A path, a query or a fragment. Safe by construction — it cannot name
        // a scheme, so it cannot name one this shop does not serve.
        return true;
    }

    /**
     * Neutralise the characters that mean something to the CSS tokeniser.
     *
     * Identity for every address that was already safe, which is the whole
     * display-parity argument: strtr() only rewrites the five delimiters, and
     * the preg_replace_callback only fires on a control character.
     */
    private static function escape(string $url): string
    {
        /*
         * Backslash FIRST and in the same pass as the rest — strtr() replaces
         * left-to-right without re-scanning what it wrote, so `\` cannot double
         * up the escapes it produces for the four delimiters. Running two
         * str_replace() calls instead would turn `\'` into `\\\'`.
         */
        $out = strtr($url, [
            '\\' => '\\\\',
            "'" => "\\'",
            '"' => '\\"',
            '(' => '\\(',
            ')' => '\\)',
        ]);

        /*
         * C0 and DEL, matched as BYTES and not with /u. A raw newline is the
         * one that matters: CSS forbids it inside a quoted string, so it
         * terminates the declaration and hands what follows to the parser as a
         * fresh one. Hex escape, with the trailing space CSS requires to end
         * the escape.
         *
         * The C1 range is deliberately NOT in this class, though the scheme
         * probe above strips it: C1 is two bytes in UTF-8, ord() reads only the
         * first of them and would emit "\c2 " while dropping the second — a
         * mangled address where a sound one went in — and no C1 character means
         * anything to the CSS tokeniser, which cares about the quote, the
         * backslash and the newline.
         */
        return (string) preg_replace_callback(
            '/[\x00-\x1F\x7F]/',
            static fn (array $m): string => '\\'.dechex(ord($m[0])).' ',
            $out
        );
    }
}

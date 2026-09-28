<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A URL that is about to become an `href` or an `<img src>`.
 *
 * =============================================================================
 * WHY AN HTML ESCAPER IS NOT ENOUGH, WHICH IS THE WHOLE REASON THIS EXISTS
 * =============================================================================
 *
 * `{{ }}` escapes `&`, `<`, `>`, `"` and `'`. The string `javascript:alert(1)`
 * contains none of them. It comes through an HTML escaper **byte for byte** and
 * lands in the attribute exactly as it was written, and the browser runs it.
 * The same is true of `//evil.test/x.png`, which is somebody else's host
 * wearing this page's scheme.
 *
 * Lane JS measured both, in a browser, on this shop:
 *
 *     javascript:alert(1)     escapeHtml -> UNCHANGED
 *     //evil.test/x.png       escapeHtml -> UNCHANGED
 *
 * So escaping is the wrong tool for the question "may this address be an
 * attribute". The question is the SCHEME, and it has to be asked separately.
 *
 * ── WHERE THESE VALUES COME FROM, WHICH SETS THE SEVERITY ───────────────────
 *
 * Not from a shopper. `ReviewController` UPLOADS a photo and writes the path
 * itself (`/uploads/reviews/{uuid}.{ext}`), so the public form cannot supply an
 * address at all — that was checked before this was written rather than
 * assumed.
 *
 * They come from **the WordPress import**: menu rows and review photographs
 * both arrive from a database this shop did not author, and an imported menu
 * URL is exactly the kind of value nobody inspects. That is why this landed
 * before the import rather than after it.
 *
 * ── THE TWO ANSWERS ARE DIFFERENT, AND THE DIFFERENCE MATTERS ───────────────
 *
 * A refused LINK becomes `#`. A refused PICTURE becomes `''` and the caller
 * draws no `<img>` at all — because `src="#"` and `src=""` both make the
 * browser fetch THIS PAGE and try to decode it as an image. `#` is right for a
 * link and wrong for a picture, and the JavaScript twin in
 * `resources/js/kbb/safe.js` makes the same distinction for the same reason.
 * Keep the two in step.
 */
final class SafeUrl
{
    /**
     * Schemes a link may carry.
     *
     * `mailto` and `tel` are here because a menu row legitimately holds one and
     * the shop renders such rows today. Anything that can execute — `javascript`,
     * `vbscript`, `data` — is not, and `data:` is refused for a link even though
     * it cannot execute in an `href` on a current browser: it is a whole
     * document inlined into an attribute and there is no menu row that wants
     * one.
     */
    private const LINK_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    /**
     * Schemes a picture — or any other address that must be a page on the
     * public web — may carry. Nothing else is servable as an image here, and
     * nothing else is a social profile either.
     */
    private const IMAGE_SCHEMES = ['http', 'https'];

    /** A link, or `#` when the scheme is one this shop will not follow. */
    public static function href(?string $raw, string $refused = '#'): string
    {
        $url = trim((string) $raw);

        return ($url !== '' && self::schemeIsAllowed($url, self::LINK_SCHEMES))
            ? $url
            : $refused;
    }

    /**
     * A picture, or `''` — and `''` means DRAW NOTHING, not `<img src="">`.
     * The caller has to check it; every call site in this repo does.
     */
    public static function src(?string $raw): string
    {
        return self::web($raw);
    }

    /**
     * An address on the public web — http or https and nothing else — or `''`.
     *
     * Same answer as src() and a different question, which is why it has its
     * own name rather than callers reaching for src(). It exists for the
     * organisation's `sameAs` in the JSON-LD: those are social PROFILES, not
     * pictures, and `mailto:` or `tel:` would be as wrong there as
     * `javascript:` — Google reads sameAs as a list of pages about this
     * business.
     *
     * Found by a screenshot rather than by reading. With the menu and the
     * footer icon both gated, the home page still carried
     * `"sameAs":[…,"javascript:alert(4)",…]` — the shop publishing an
     * executable URL as its own social profile. Not clickable, and still the
     * same unvalidated setting reaching the page through a fourth door.
     */
    public static function web(?string $raw): string
    {
        $url = trim((string) $raw);

        return ($url !== '' && self::schemeIsAllowed($url, self::IMAGE_SCHEMES))
            ? $url
            : '';
    }

    /**
     * Read the scheme the way a browser will, which is not the way the raw
     * string reads.
     *
     * Entities FIRST: a browser resolves `jav&#x09;ascript:alert(1)` to a
     * javascript URL, so a check run on the raw string sees something starting
     * `jav&` and waves it through. Then whitespace and the C0/C1 controls, for
     * the same reason — `java\nscript:` is a javascript URL too. Only then the
     * scheme, so the match runs on the same string the browser resolves.
     *
     * This is the order `Banners::safeUrl()` and `App\Support\CssUrl` already
     * use. It is repeated rather than shared because those two answer different
     * questions — one returns a URL for an operator-typed `href` and the other
     * escapes for the CSS tokeniser — and folding three different answers into
     * one method is how a security helper starts being wrong for two of its
     * callers.
     *
     * @param  list<string>  $allowed
     */
    private static function schemeIsAllowed(string $url, array $allowed): bool
    {
        $probe = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $probe = (string) preg_replace('/[\s\x00-\x1F\x7F-\x9F]+/u', '', $probe);
        $probe = strtolower($probe);

        // Somebody else's host wearing this page's scheme.
        if (str_starts_with($probe, '//')) {
            return false;
        }

        if (preg_match('/^([a-z][a-z0-9+.\-]*):/', $probe, $m) === 1) {
            return in_array($m[1], $allowed, true);
        }

        // No scheme: a path, a query or a fragment. It cannot name a scheme, so
        // it cannot name one this shop will not follow.
        return true;
    }
}

<?php

namespace App\Support;

use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * A page stylesheet printed INLINE on the request that opens the shop, and as
 * the usual <link> on every request after it. (Lane CC)
 *
 * WHY. Lighthouse's mobile run (Moto G Power, Slow 4G) found the homepage's
 * second render-blocking stylesheet, kbb-grid-skins.css (5 KB on the wire,
 * 28 KB of rules), costing a request of its own on the critical path: it is
 * asked for alongside kbb.css, app.js, the Outfit face and the site-app
 * script, and first paint waits for the last of them. Printed in a <style> at
 * the SAME place as its <link>, the same rules arrive with the page itself.
 * Measured on Lane LH's homepage seed, Lighthouse medians, interleaved runs:
 * mobile (n=14) FCP 1,360 -> 1,266 ms, Speed Index 1,360 -> 1,266 ms, LCP
 * 2,484 -> 2,419 ms, CLS 0 -> 0; desktop (n=29) FCP 372 -> 366, LCP 522 -> 522.
 *
 * WHY NOT kbb.css AS WELL, OR A "CRITICAL CSS" CUT OF IT. Both were measured
 * and both lost. kbb.css is needed by the very next page, so inlining it moves
 * 44 KB onto the first click. A critical subset inlined with the full sheet
 * loaded behind it (media swap) gave FCP -83 ms but LCP +457 ms, because the
 * full sheet then competes with the banner picture; even an ideal per-page
 * subset is 15 KB gzipped on the homepage. See the Lane CC report.
 *
 * WHY ONLY ON THE FIRST PAGE. Inline rules are not cached. A shopper who
 * arrives on the homepage and then opens a product page loses nothing — no
 * other page in that walk loads this sheet — but one who comes BACK to the
 * homepage from inside the shop would download the 5 KB again inside the
 * page, where today the browser already holds the file. So the inline copy
 * is printed only when the browser POSITIVELY says the request opens the shop:
 * it sends Sec-Fetch-Site (every Chromium, Firefox, and Safari since 16.4)
 * with a value other than same-origin, AND no Referer on this host. Anything
 * else — an ordinary click (same-origin), a browser or bot that sends no
 * Sec-Fetch-Site at all, the test client — gets the <link> exactly as before,
 * byte for byte, so page switching cannot get slower and no other test or
 * crawler sees a different page.
 *
 * The Referer half is not decoration: Chrome's speculation-rules prefetch —
 * the instant navigation every product, category and brand link uses — is
 * sent as `Sec-Fetch-Site: none` with `Sec-Purpose: prefetch` and the shop
 * page as its Referer (measured in Chromium 141, Lane CC). Keyed on
 * Sec-Fetch-Site alone, every prefetched homepage carried the inline copy.
 *
 * Nothing about the PICTURE changes on either branch: a <style> and a <link>
 * at one position in <head> are the same author stylesheet at the same place
 * in the cascade, and this file holds no url() and no @import that would
 * resolve differently from the document (LandingCssTest pins both). Either
 * branch is safe to serve to anybody, which is why no Vary header is needed:
 * a cache that hands one visitor the other's page costs a few kilobytes,
 * never a style.
 *
 * Secure by construction: the bytes printed are a build artefact named by a
 * constant allowlist, never a setting or anything from the request, and a
 * file that could close the <style> element is refused and linked instead.
 */
final class LandingCss
{
    /** The only Vite entries this may print inline. */
    public const INLINE = ['resources/css/kbb/kbb-grid-skins.css'];

    /** Per-process memo of the built file's bytes, keyed by entry. */
    private static array $css = [];

    /**
     * What `@vite($entry)` prints, or the same rules in a <style> on the page
     * that opens the shop.
     */
    public static function tags(string $entry, ?Request $request = null): HtmlString
    {
        $vite = app(Vite::class);
        $tags = $vite($entry);

        if (! in_array($entry, self::INLINE, true)
            || (string) $tags === ''
            || $vite->isRunningHot()
            || ! self::isLanding($request ?? request())) {
            return $tags;
        }

        $css = self::content($vite, $entry);

        return $css === null ? $tags : new HtmlString('<style id="kbb-css-inline">'.$css.'</style>');
    }

    /**
     * True only when the browser says this request opens the shop: a
     * Sec-Fetch-Site other than same-origin, and no Referer on this host (the
     * only case in which the shop's stylesheets cannot be in the cache yet).
     * Both headers are the browser's word and either may be forged; a wrong
     * answer costs a few kilobytes one way or a request the other, and the
     * page looks the same.
     */
    public static function isLanding(Request $request): bool
    {
        $site = strtolower(trim((string) $request->headers->get('Sec-Fetch-Site', '')));

        if ($site === '' || $site === 'same-origin') {
            return false;
        }

        $host = parse_url((string) $request->headers->get('Referer', ''), PHP_URL_HOST);

        return ! is_string($host) || strcasecmp($host, $request->getHost()) !== 0;
    }

    /** The built file, or null when it is missing or could end the element. */
    private static function content(Vite $vite, string $entry): ?string
    {
        if (! array_key_exists($entry, self::$css)) {
            try {
                $css = (string) $vite->content($entry);
            } catch (Throwable) {
                $css = '';
            }

            self::$css[$entry] = ($css === '' || stripos($css, '</') !== false || str_contains($css, '<!--'))
                ? null
                : rtrim($css, "\n");
        }

        return self::$css[$entry];
    }

    /** For tests that swap the build. */
    public static function forget(): void
    {
        self::$css = [];
    }
}

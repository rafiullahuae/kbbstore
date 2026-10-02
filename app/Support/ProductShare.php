<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Product;
use App\Services\ProductTrustShare;

/**
 * The share bar's links, built on the server from the product.       (Lane PW)
 *
 * The owner: "each share icon must carry proper url, short description, image
 * etc. and other things if you recommend any."
 *
 * ── WHAT EACH NETWORK IS GIVEN, AND WHY NOT MORE ────────────────────────────
 *
 *   WhatsApp, Telegram   text = "<name> – <price>" ⏎ <blurb> ⏎ <url>. Both put
 *                        the link in the message body and unfurl it from the
 *                        page's own Open Graph tags.
 *   Facebook             u = url, and nothing else: Facebook's sharer ignores
 *                        every other parameter and reads og:* off the page.
 *   X                    text = "<name> – <price>", url = url. X counts a link
 *                        as 23 characters whatever its length, so the text is
 *                        capped well inside 280 with room for it.
 *   Pinterest            url, media = the ABSOLUTE main photograph,
 *                        description = name + blurb. No photograph, no `media`
 *                        — an empty one makes Pinterest fall back to scraping
 *                        the page and choosing a logo.
 *   LinkedIn             url, which it unfurls from og:* like Facebook.
 *   Email                subject = name, body = blurb ⏎⏎ url.
 *   Copy link            the url.
 *   More (phones)        navigator.share({title, text, url}).
 *
 * ── PLAIN TEXT, THEN ENCODED ONCE ───────────────────────────────────────────
 *
 * The blurb is HTML (a WooCommerce excerpt always is), stored with entities —
 * "Lift &amp; glow". RichText::toText() strips the tags and decodes the
 * entities ONCE, so the text a friend receives says "Lift & glow"; then
 * rawurlencode() encodes it for the query string, and Blade escapes the whole
 * href for the attribute. Three layers, each the right one for its context, and
 * none of them doing another's job.
 *
 * ── UTM ON THE SHARED LINK ONLY ─────────────────────────────────────────────
 *
 * `utm_source=<network>&utm_medium=social&utm_campaign=product_share` is
 * appended to the link a visitor carries away when Appearance → Product page →
 * Trust · Share bar → "Tag shared links for analytics" is on. It is never added
 * to the canonical, og:url or anything in the <head>: the canonical is what
 * search engines index, and a crawler following a shared link lands on a page
 * whose canonical names the clean address.
 *
 * NO QUERY. Everything here is read off the product the page already loaded
 * and the settings snapshot it already took.
 */
final class ProductShare
{
    /** Characters of blurb carried in a message. */
    public const BLURB = 150;

    /** The longest X text we send, leaving room for the 23-character link. */
    private const X_TEXT = 240;

    /**
     * The buttons, in order, for the switches that are on.
     *
     * @param  array{name: string, headline: string, blurb: string, url: string, image: ?string}  $facts  from facts()
     * @return list<array{key: string, name: string, href: string, colour: string, external: bool}>
     */
    public static function links(array $facts, ProductTrustShare $ts): array
    {
        $out = [];

        foreach (ProductTrustShare::NETWORKS as $key => [$name, $colour]) {
            if (! $ts->on('share_'.$key)) {
                continue;
            }

            $url = $ts->on('share_utm') ? self::tag($facts['url'], $key) : $facts['url'];

            $href = self::href($key, $url, $facts);

            if ($href === null) {
                continue;
            }

            $out[] = [
                'key' => $key,
                'name' => $name,
                'href' => $href,
                'colour' => $colour,
                'external' => ! in_array($key, ['email', 'copy'], true),
            ];
        }

        return $out;
    }

    /**
     * What navigator.share() is handed on a phone: title, text and the url.
     *
     * @param  array{name: string, headline: string, blurb: string, url: string, image: ?string}  $facts  from facts()
     * @return array{title: string, text: string, url: string}
     */
    public static function native(array $facts, ProductTrustShare $ts): array
    {
        return [
            'title' => $facts['name'],
            'text' => trim($facts['headline']."\n".$facts['blurb']),
            'url' => $ts->on('share_utm') ? self::tag($facts['url'], 'native') : $facts['url'],
        ];
    }

    /**
     * The plain-text facts every network draws from.
     *
     * @param  array<string, mixed>  $seoCtx  the page's own SEO context, so the
     *   link and the picture are the canonical and og:image the <head> names.
     * @return array{name: string, headline: string, blurb: string, url: string, image: ?string}
     */
    public static function facts(Product $product, array $seoCtx, int $priceMinor): array
    {
        $targets = Seo::shareTargets($seoCtx);

        // The canonical the <head> publishes; the page's own address if the
        // SEO layer has none (an install with no site_url and no app.url).
        $url = $targets['url'] ?? url($product->url());

        $name = self::plain((string) $product->t('name'));
        $price = $priceMinor > 0 ? Money::plain($priceMinor) : '';
        $blurb = self::clip(RichText::toText((string) $product->t('short_description')), self::BLURB);

        return [
            'name' => $name,
            'headline' => $price === '' ? $name : $name.' – '.$price,
            'blurb' => $blurb,
            'url' => $url,
            // The product's OWN photograph, absolute, or null. Deliberately
            // not og_default_image: a pin of the shop's logo is not a pin of
            // this product.
            'image' => $targets['image'],
        ];
    }

    /** @param array{name: string, headline: string, blurb: string, url: string, image: ?string} $f */
    private static function href(string $key, string $url, array $f): ?string
    {
        $q = static fn (array $params): string => http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $message = implode("\n", array_filter([$f['headline'], $f['blurb'], $url], static fn ($s) => $s !== ''));

        return match ($key) {
            'whatsapp' => 'https://wa.me/?'.$q(['text' => $message]),
            'facebook' => 'https://www.facebook.com/sharer/sharer.php?'.$q(['u' => $url]),
            'x' => 'https://x.com/intent/post?'.$q(['text' => self::clip($f['headline'], self::X_TEXT), 'url' => $url]),
            'pinterest' => 'https://www.pinterest.com/pin/create/button/?'.$q(array_filter([
                'url' => $url,
                'media' => $f['image'],
                'description' => self::clip(trim($f['name'].' – '.$f['blurb'], ' –'), 480),
            ], static fn ($v) => $v !== null && $v !== '')),
            'linkedin' => 'https://www.linkedin.com/sharing/share-offsite/?'.$q(['url' => $url]),
            'telegram' => 'https://t.me/share/url?'.$q(['url' => $url, 'text' => trim($f['headline']."\n".$f['blurb'])]),
            'email' => 'mailto:?'.$q(['subject' => $f['name'], 'body' => trim($f['blurb']."\n\n".$url)]),
            'copy' => $url,
            default => null,
        };
    }

    /**
     * The link with this network's UTM tags, merged into any query it has.
     *
     * The network key is one of NETWORKS' own keys or `native`, so nothing a
     * setting holds can reach utm_source.
     */
    public static function tag(string $url, string $network): string
    {
        $fragment = '';

        if (($hash = strpos($url, '#')) !== false) {
            $fragment = substr($url, $hash);
            $url = substr($url, 0, $hash);
        }

        $sep = str_contains($url, '?') ? '&' : '?';

        return $url.$sep.http_build_query([
            'utm_source' => $network,
            'utm_medium' => 'social',
            'utm_campaign' => 'product_share',
        ], '', '&', PHP_QUERY_RFC3986).$fragment;
    }

    /** Tags off, entities decoded once, whitespace collapsed. */
    private static function plain(string $text): string
    {
        return RichText::toText($text);
    }

    /** At most $max characters, cut on a word boundary with an ellipsis. */
    public static function clip(string $text, int $max): string
    {
        $text = trim($text);

        if (mb_strlen($text) <= $max) {
            return $text;
        }

        $cut = mb_substr($text, 0, $max - 1);
        $space = mb_strrpos($cut, ' ');

        if ($space !== false && $space > (int) ($max * 0.6)) {
            $cut = mb_substr($cut, 0, $space);
        }

        return rtrim($cut, " \t\n,.;:–-").'…';
    }
}

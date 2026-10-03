<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Product;
use App\Services\ProductTrustShare;

/**
 * The share sheet's links, built on the server from the product.
 *                                              (Lane PW; the sheet, Lane QB)
 *
 * The owner: "each share icon must carry proper url, short description, image
 * etc. and other things if you recommend any." And, of the old row: "the share
 * icons don't bring the image along with the message."
 *
 * ── THE PICTURE TRAVELS AS THE LINK'S PREVIEW, NOT AS AN ATTACHMENT ────────
 *
 * A wa.me, t.me, sms: or mailto: link carries TEXT and nothing else — no web
 * page can hand WhatsApp a file through a link. The picture a friend sees is
 * the link PREVIEW the sender's app draws from the page's og:image, which is
 * now a JPEG made for exactly that (App\Support\ShareImage). So every message
 * below makes sure the product link is IN it, on its own line, and is the ONLY
 * address in it — WhatsApp previews the first link it finds, and a blurb that
 * happened to contain one would steal the preview. "More" is the one path that
 * sends the actual picture file, through the phone's own share menu
 * (navigator.share with files), where the phone allows it.
 *
 * ── WHAT EACH PLATFORM IS GIVEN ─────────────────────────────────────────────
 *
 *   WhatsApp    text = "<name> – <price>" ⏎ <blurb> ⏎ <url>; since 2.60.365, while
 *               the link preview card is on, "<card message>" ⏎ <url> instead
 *               (App\Support\ShareCard::message(), also Telegram, SMS, More).
 *   Messenger   phone: fb-messenger://share/?link=<url> (the app's own share
 *               composer). Laptop: Facebook's sharer, whose window has "Send
 *               in Messenger" — Facebook's web Send dialog needs an app id this
 *               shop does not have. The script picks by `(pointer:coarse)`.
 *   Pinterest   url, media = the ABSOLUTE main photograph (the original, not
 *               the 1.91:1 share card: a pin wants the tall/square picture),
 *               description = name + blurb. No photograph, no `media`.
 *   Telegram    url, text = "<name> – <price>" ⏎ <blurb>. Telegram puts the
 *               link first and previews it.
 *   Snapchat    phone: https://www.snapchat.com/scan?attachmentUrl=<url> —
 *               the deep link Snap's Creative Kit for Web opens, which lands in
 *               the app's camera with the link attached to a Snap. Laptop:
 *               https://www.snapchat.com/share?link=<url> — Snap's documented
 *               Share Sheet link for Snapchat for Web.
 *   Messages    sms:?&body=<name – price ⏎ url>. `?&` is the form both iOS
 *               (which wants `&body=`) and Android (which wants `?body=`) read.
 *   Email       subject = name, body = blurb ⏎⏎ url.
 *   Copy        the url.
 *   More        navigator.share({files: [the JPEG], title, text}) where
 *               navigator.canShare says files are accepted, else
 *               {title, text, url}.
 *   Facebook    u = url (off by default). X: text + url. LinkedIn: url.
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
 * Share → "Tag shared links for analytics" is on. It is never added to the
 * canonical, og:url or anything in the <head>. It also makes each platform's
 * URL distinct, so each app keeps its own preview cache of the page.
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
     * The sheet's tiles, in his order, for the switches that are on. `native`
     * ("More") is not here — it has no href; see native().
     *
     * @param  array{name: string, headline: string, blurb: string, url: string, image: ?string}  $facts  from facts()
     * @return list<array{key: string, name: string, href: string, app: ?string, colour: string, external: bool}>
     */
    public static function links(array $facts, ProductTrustShare $ts): array
    {
        $out = [];

        foreach ($ts->shareNetworks() as $key) {
            if ($key === 'native' || ! isset(ProductTrustShare::NETWORKS[$key])) {
                continue;
            }

            [$name, $colour] = ProductTrustShare::NETWORKS[$key];
            $url = $ts->on('share_utm') ? self::tag($facts['url'], $key) : $facts['url'];
            $href = self::href($key, $url, $facts, ShareCard::message($ts, $key));

            if ($href === null) {
                continue;
            }

            $out[] = [
                'key' => $key,
                'name' => $name,
                'href' => $href,
                'app' => self::appHref($key, $url),
                'colour' => $colour,
                'external' => ! in_array($key, ['email', 'copy', 'sms'], true),
            ];
        }

        return $out;
    }

    /**
     * Where "More" sits among the tiles (its index in the drawn order), or
     * null when it is switched off.
     */
    public static function nativeAt(ProductTrustShare $ts): ?int
    {
        $i = array_search('native', $ts->shareNetworks(), true);

        return $i === false ? null : (int) $i;
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
            // (2.60.365) The card's message when "Use the message for" covers More.
            'text' => ShareCard::message($ts, 'native') ?? trim($facts['headline']."\n".$facts['blurb']),
            'url' => $ts->on('share_utm') ? self::tag($facts['url'], 'native') : $facts['url'],
        ];
    }

    /**
     * The plain-text facts every network draws from.
     *
     * @param  array<string, mixed>  $seoCtx  the page's own SEO context, so the
     *   link and the picture are the canonical and og:image the <head> names.
     * @return array{name: string, headline: string, blurb: string, url: string, image: ?string, share_image: ?string, share_path: ?string}
     */
    public static function facts(Product $product, array $seoCtx, int $priceMinor): array
    {
        $targets = Seo::shareTargets($seoCtx);

        // The canonical the <head> publishes; the page's own address if the
        // SEO layer has none (an install with no site_url and no app.url).
        $url = $targets['url'] ?? url($product->url());

        // No address but the product's own may appear in a message: WhatsApp
        // previews the FIRST link it finds (Lane QB).
        $name = self::unlinked(self::plain((string) $product->t('name')));
        $price = $priceMinor > 0 ? Money::plain($priceMinor) : '';
        $blurb = self::clip(self::unlinked(RichText::toText((string) $product->t('short_description'))), self::BLURB);

        return [
            'name' => $name,
            'headline' => $price === '' ? $name : $name.' – '.$price,
            'blurb' => $blurb,
            'url' => $url,
            // The product's OWN photograph, absolute, or null. Deliberately
            // not og_default_image: a pin of the shop's logo is not a pin of
            // this product.
            //
            // Lane QB: a picture the migration could not bring across
            // (LostPictures — memoised, so the gallery already paid for it) is
            // no picture: no dead `media` for Pinterest, no broken card.
            'image' => LostPictures::usable($targets['image']),
            // Lane QB: what og:image names (the JPEG share card, or the
            // original until it is made), and the same file root-relative for
            // the phone share sheet's same-origin fetch, or null.
            'share_image' => LostPictures::usable($targets['share_image'] ?? $targets['image']),
            'share_path' => $targets['share_path'] ?? null,
        ];
    }

    /** Any web address taken out of a sentence, and the spaces it leaves tidied. */
    private static function unlinked(string $text): string
    {
        $text = (string) preg_replace('#\b(?:https?://|www\.)\S+#iu', '', $text);

        return trim((string) preg_replace('/[ \t]{2,}/u', ' ', $text));
    }

    /** @param array{name: string, headline: string, blurb: string, url: string, image: ?string} $f */
    private static function href(string $key, string $url, array $f, ?string $card = null): ?string
    {
        $q = static fn (array $params): string => http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $message = implode("\n", array_filter([$f['headline'], $f['blurb'], $url], static fn ($s) => $s !== ''));

        /*
         * (2.60.365) The link preview card's message — "See what I’ve found on
         * K-Beauty Bliss 💖" then the link, as the owner asked — on the tiles
         * Share · Link preview card → "Use the message for" names. The card
         * itself (picture, name, points, domain) is drawn by the app from the
         * page's og: tags, so the message carries no name or blurb of its own.
         */
        if ($card !== null) {
            return match ($key) {
                'whatsapp' => 'https://wa.me/?'.$q(['text' => $card."\n".$url]),
                'telegram' => 'https://t.me/share/url?'.$q(['url' => $url, 'text' => $card]),
                'sms' => 'sms:?&'.$q(['body' => $card."\n".$url]),
                default => self::href($key, $url, $f),
            };
        }

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
            // Laptop forms; appHref() gives the phone ones.
            'messenger' => 'https://www.facebook.com/sharer/sharer.php?'.$q(['u' => $url]),
            'snapchat' => 'https://www.snapchat.com/share?'.$q(['link' => $url]),
            // `sms:?&body=` — iOS reads `&body=`, Android `?body=`; this is both.
            'sms' => 'sms:?&'.$q(['body' => trim($f['headline']."\n".$url)]),
            'email' => 'mailto:?'.$q(['subject' => $f['name'], 'body' => trim($f['blurb']."\n\n".$url)]),
            'copy' => $url,
            default => null,
        };
    }

    /**
     * The phone form of a link, where the platform has an app of its own that
     * the web form would only reach through a login page. The sheet's script
     * swaps it in on a `(pointer:coarse)` device.
     */
    private static function appHref(string $key, string $url): ?string
    {
        $q = static fn (array $params): string => http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        return match ($key) {
            'messenger' => 'fb-messenger://share/?'.$q(['link' => $url]),
            'snapchat' => 'https://www.snapchat.com/scan?'.$q(['attachmentUrl' => $url]),
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

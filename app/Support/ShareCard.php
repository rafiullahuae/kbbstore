<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Product;
use App\Services\ProductTrustShare;
use App\Services\Seo\SeoSettings;

/**
 * The link preview card a shared product link draws.          (2.60.365)
 *
 * THE OWNER, 3 October 2026, after picking template C off the preview sheet:
 * "icon point is fine. but give options to chooose from backend and control
 * everything." Every word and switch is on Appearance → Product page →
 * Share · Link preview card (ProductTrustShare's `card_*` fields).
 *
 * ── WHAT IT CHANGES ─────────────────────────────────────────────────────────
 *
 *   og:title / twitter:title             the card title (product name, by default)
 *   og:description / twitter:description the points, e.g.
 *       "🚚 Express delivery all over UAE & Gulf · ✅ 100% original products
 *        from the brand · 💳 Accepts Tabby & Tamara"
 *   the share sheet's message            "See what I’ve found on K-Beauty Bliss 💖"
 *                                        then the link (ProductShare)
 *
 * On a PRODUCT page only, and never the <title> or <meta name="description">:
 * those are what Google prints, and nobody asked to change them. The domain
 * under the card is drawn by WhatsApp itself from the link.
 *
 * Plain text out. Every value is escaped by whoever prints it (Seo's $e,
 * rawurlencode in ProductShare); nothing here is ever printed raw.
 */
final class ShareCard
{
    /** The separators, by the select's own keys. */
    public const SEPARATORS = ['dot' => ' · ', 'bullet' => ' • ', 'bar' => ' | ', 'space' => '   '];

    /**
     * The card's title and description, or null when the card is switched off.
     *
     * @return array{title: string, description: string}|null
     */
    public static function forProduct(Product $product, ?ProductTrustShare $ts = null): ?array
    {
        $ts ??= app(ProductTrustShare::class);

        if (! $ts->on('card_on')) {
            return null;
        }

        $name = RichText::toText((string) $product->t('name'));

        $title = match ($ts->choice('card_title')) {
            'name_price' => ($minor = (int) $product->effectivePrice()) > 0 ? $name.' – '.Money::plain($minor) : $name,
            'name_shop' => trim($name.' | '.trim((string) ($ts->all()['card_shop'] ?? '')), ' |'),
            default => $name,
        };

        return ['title' => $title, 'description' => self::points($ts)];
    }

    /** The points, joined on one line, with the domain last when asked. */
    public static function points(ProductTrustShare $ts): string
    {
        $style = $ts->choice('card_icons');
        $out = [];

        foreach ([1, 2, 3] as $n) {
            if (! $ts->on('card_p'.$n.'_on')) {
                continue;
            }

            $text = trim($ts->text('card_p'.$n.'_text'));

            if ($text === '') {
                continue;
            }

            $icon = match ($style) {
                'icons' => trim((string) ($ts->all()['card_p'.$n.'_icon'] ?? '')),
                'ticks' => '✓',
                default => '',
            };

            $out[] = $icon === '' ? $text : $icon.' '.$text;
        }

        if ($ts->on('card_domain') && ($domain = self::domain()) !== '') {
            $out[] = $domain;
        }

        return implode(self::SEPARATORS[$ts->choice('card_sep')] ?? ' · ', $out);
    }

    /** This shop's domain, without a scheme or www — "extrabeauty.ae". */
    public static function domain(): string
    {
        $s = SeoSettings::map();
        $host = (string) (parse_url(SeoSettings::firstFilled($s['site_url'] ?? null, (string) config('app.url')), PHP_URL_HOST) ?: '');

        return (string) preg_replace('/^www\./i', '', strtolower($host));
    }

    /**
     * The message a share tile types above the link, or null for "as before".
     * `$network` is one of ProductTrustShare::NETWORKS' keys or `native`.
     */
    public static function message(ProductTrustShare $ts, string $network): ?string
    {
        if (! $ts->on('card_on')) {
            return null;
        }

        $uses = match ($ts->choice('card_msg_use')) {
            'chat' => in_array($network, ['whatsapp', 'telegram', 'sms', 'native'], true),
            'whatsapp' => $network === 'whatsapp',
            default => false,
        };

        if (! $uses) {
            return null;
        }

        // No address in it but the product's own: WhatsApp previews the FIRST
        // link it finds, so a web address typed here would steal the card.
        $msg = trim((string) preg_replace('#\b(?:https?://|www\.)\S+#iu', '', $ts->text('card_msg')));

        return $msg === '' ? null : $msg;
    }
}

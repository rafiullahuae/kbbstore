<?php

declare(strict_types=1);

namespace App\Services\Mail\Kit;

use App\Models\Product;
use Illuminate\Support\HtmlString;

/**
 * The product pictures and cards the approved emails carry — Lane RM.
 *
 * Today's emails carry no pictures; the approved look puts the product's own
 * main image beside every line (64px, served from the 200w variant) and on
 * the back-in-stock card (200px, the 400w variant). Everything else on a line
 * — name, brand, price — stays the snapshot the email was handed, so a
 * renamed or repriced product never rewrites a receipt; only the picture is
 * looked up, and a product that has gone away simply has no picture.
 *
 * ONE QUERY PER EMAIL, whatever the number of lines (whereIn on the ids or
 * slugs), with the brand eager-loaded. A missing or unreadable image is null
 * and the kit draws the soft placeholder square instead.
 */
final class KitProducts
{
    /**
     * Picture URL per product id.
     *
     * @param  iterable<mixed>  $ids
     * @return array<int, string|null>
     */
    public static function imagesForIds(iterable $ids): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', is_array($ids) ? $ids : iterator_to_array($ids)),
            static fn (int $id) => $id > 0,
        )));

        if ($ids === []) {
            return [];
        }

        try {
            $rows = Product::query()->whereIn('id', $ids)->get(['id', 'image']);
        } catch (\Throwable) {
            return [];
        }

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row->id] = MailKit::image($row->image, 200);
        }

        return $out;
    }

    /**
     * Picture and brand per slug, for a basket (CartRecovery::basket() lines
     * carry the slug and no image).
     *
     * @param  list<string>  $slugs
     * @return array<string, array{img: string|null, brand: string}>
     */
    public static function forSlugs(array $slugs): array
    {
        $slugs = array_values(array_unique(array_filter(array_map('strval', $slugs), static fn (string $s) => $s !== '')));

        if ($slugs === []) {
            return [];
        }

        try {
            $rows = Product::query()->with('brand')->whereIn('slug', $slugs)->get();
        } catch (\Throwable) {
            return [];
        }

        $out = [];

        foreach ($rows as $row) {
            $out[(string) $row->slug] = [
                'img' => MailKit::image($row->image, 200),
                'brand' => trim((string) ($row->brand?->name ?? '')),
            ];
        }

        return $out;
    }

    /**
     * The back-in-stock card for the product a link points at.
     *
     * The alert is handed the product's NAME and URL (OutboundSender builds the
     * URL from `stock_alerts.product_slug`), so the slug is read back out of
     * that URL rather than the Mailable's constructor growing a parameter its
     * one caller would have to change. The name the alert was handed is kept
     * whatever the lookup finds; the picture, brand and current price are the
     * shop's as of sending, which is what "it is back" is about.
     *
     * @return array{img: string|null, h: int, brand: string, name: string, price: string|HtmlString, was: string|HtmlString|null, href: string}
     */
    public static function card(string $productUrl, string $name): array
    {
        $card = ['img' => null, 'h' => 200, 'brand' => '', 'name' => $name, 'price' => '', 'was' => null, 'href' => $productUrl];

        if (preg_match('#/product/([^/?\#]+)/?(?:[?\#]|$)#', $productUrl, $m) !== 1) {
            return $card;
        }

        try {
            $product = Product::query()->with('brand')->where('slug', rawurldecode($m[1]))->first();
        } catch (\Throwable) {
            return $card;
        }

        if ($product === null) {
            return $card;
        }

        $card['img'] = MailKit::image($product->image, 400);
        $card['h'] = MailKit::heightFor(is_string($product->image) ? $product->image : null, 200);
        $card['brand'] = trim((string) ($product->brand?->name ?? ''));

        try {
            $price = $product->effectivePrice();
            $was = $product->compareAtPrice();

            if ($price > 0) {
                $card['price'] = MailKit::money($price);
                $card['was'] = $was !== null && $was > $price ? MailKit::money($was) : null;
            }
        } catch (\Throwable) {
            // A card with no price is still the card; a wrong price is not.
        }

        return $card;
    }
}

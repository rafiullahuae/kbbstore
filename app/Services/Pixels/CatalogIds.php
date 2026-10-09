<?php

declare(strict_types=1);

namespace App\Services\Pixels;

/**
 * The product id the pixels send, and the id the Meta / TikTok catalog feeds
 * publish — ONE rule, so a catalog ad can find the product a shopper viewed.
 * (Lane MP)
 *
 *   a simple product         "<product id>"           content_type product
 *   one variant of a product "<product id>-<variant>" content_type product
 *   the product as a group   "<product id>"           content_type product_group
 *                            (matched against item_group_id)
 *
 * The plain product id is what the pixels have always sent for a simple
 * product, so a shop already collecting audiences loses none of them.
 *
 * WHY NOT THE SKU. The Google feed (Lane SEO) keys items by SKU, which is
 * Merchant Center's preference. But the add-to-cart listener runs on every
 * product card and only knows the product id printed on the button; teaching
 * it SKUs would mean new markup on every card in the shop. So the two catalog
 * feeds this lane adds (/feeds/meta-catalog.xml, /feeds/tiktok-catalog.xml)
 * use this rule instead, and every Meta and TikTok event — browser and server
 * — matches them exactly.
 */
final class CatalogIds
{
    public static function product(int $productId): string
    {
        return (string) $productId;
    }

    public static function line(int $productId, ?int $variantId): string
    {
        return $variantId ? $productId . '-' . $variantId : (string) $productId;
    }
}

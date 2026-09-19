<?php

/*
 * THE MIGRATION CENSUS: three columns wide, and machine-checked.
 *
 * ============================================================================
 * WHAT THIS FILE IS FOR
 * ============================================================================
 *
 * The owner's question is not "does the import work". It is *"make sure
 * everything is there in export and compatible to our app… i want everything
 * super same, nothing should be disturbed."* He is about to switch off a
 * five-year-old shop -- 671 products, 4,159 orders, 3,712 customers, 2,514
 * reviews -- and he is asking whether anything will be lost.
 *
 * That question has three columns:
 *
 *   1. what a real WooCommerce shop HOLDS   (wordpress-plugin/harness/shop.php,
 *                                            and manifest.json's own census of
 *                                            the post types and taxonomies it
 *                                            found)
 *   2. what the export CARRIES              (the 17 files and their columns)
 *   3. what the import LANDS                (a table and a column, or the name
 *                                            of the channel that says it did
 *                                            not: `rejected`, `adjusted`,
 *                                            `discarded`, unread-file)
 *
 * A thing in column 1 that appears in NEITHER column 2 NOR a report channel is
 * the dangerous case, and it is the exact failure this project has already paid
 * for twice: coupons.csv and reviews.csv were ignored in silence until
 * something finally listed the files nobody opened, and the shop's own
 * photographs were counted as "remote" for months.
 *
 * ── WHY IT IS A TEST AND NOT A TABLE IN A DOCUMENT ─────────────────────────
 *
 * docs/GQ-MIGRATION-COMPLETENESS.md is the verdict the owner reads. A verdict
 * in prose is true on the day it is written. The day a column stops crossing --
 * an importer stops reading a field, an exporter drops one, a lane adds a post
 * type nobody classified -- a document says nothing and the shop quietly
 * arrives without the field. So the census is ALSO this file, where every entry
 * is asserted against the real export and the real ImportRunner, and a column
 * that stops crossing fails BY NAME.
 *
 * ── NO TEST DOUBLES, SAME AS Lane GE ───────────────────────────────────────
 *
 * tests/Fixtures/kbb-export/ is the WordPress plugin's own output, written by
 * its stage classes over WordPress-shaped MySQL tables, and every run below
 * goes through App\Services\Import\ImportRunner -- the class `kbb:import` runs.
 */

use App\Services\Import\ImportOptions;
use App\Services\Import\ImportRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/* ══════════════════════════════════════════════════════════ the census itself */

/** Verdict prefixes. A column carries exactly one. */
const GQ_LANDS = '-> ';          // it reaches the database, at these table.column(s)
const GQ_CONSOLIDATED = 'DROPPED';  // named by the runner's one-line-per-entity ignored-column discard
const GQ_NAMED = 'NAMED';        // named by a discard of the importer's own, with its value
const GQ_CARRIED = 'CARRIED';    // read, and the fact it holds lands under a SIBLING column
const GQ_GUARD = 'GUARD';        // read only to check something; no column of its own
const GQ_ELSEWHERE = 'ELSEWHERE'; // read by a different command, not by kbb:import
const GQ_UNREAD = 'UNREAD FILE'; // nothing opens the file at all; the export channel names it whole

/**
 * Column three, per file, for every column the export writes.
 *
 * KEYED BY THE NORMALISED COLUMN NAME -- lower case, every run of
 * non-alphanumerics collapsed to one underscore, leading and trailing
 * underscores stripped -- because that is what CsvRowSource::normaliseHeader()
 * hands the importer and therefore the only spelling any of this is true of.
 * `_yoast_wpseo_opengraph-image` in the file is `yoast_wpseo_opengraph_image`
 * here, and the gap between those two spellings is exactly what lost the
 * shop's OpenGraph images and its noindex flags until this lane measured it.
 *
 * @return array<string, array{entity: ?string, columns: array<string, string>}>
 */
function gqCensus(): array
{
    return [
        'categories.csv' => ['entity' => 'categories', 'columns' => [
            'term_id' => GQ_LANDS.'categories.source_term_id',
            'name' => GQ_LANDS.'categories.name',
            'slug' => GQ_LANDS.'categories.slug',
            'parent' => GQ_LANDS.'categories.parent_id',
            'description' => GQ_LANDS.'categories.description',
            'image' => GQ_LANDS.'categories.image',
            'position' => GQ_LANDS.'categories.position',
        ]],

        'brands.csv' => ['entity' => 'brands', 'columns' => [
            'term_id' => GQ_LANDS.'brands.source_term_id',
            'name' => GQ_LANDS.'brands.name',
            'slug' => GQ_LANDS.'brands.slug',
            'description' => GQ_LANDS.'brands.description',
            'logo' => GQ_LANDS.'brands.logo',
            'position' => GQ_LANDS.'brands.position',
        ]],

        'tags.csv' => ['entity' => 'tags', 'columns' => [
            'term_id' => GQ_LANDS.'tags.source_term_id',
            'name' => GQ_LANDS.'tags.name',
            'slug' => GQ_LANDS.'tags.slug',
            'product_ids' => GQ_LANDS.'product_tag.tag_id',
            'description' => GQ_CONSOLIDATED.': `tags` has no description column; a tag archive here has no prose',
            'parent' => GQ_CONSOLIDATED.': tags are flat in this shop',
            'count' => GQ_CONSOLIDATED.": WordPress's cached count, recomputed here from the pivot",
        ]],

        'attributes.csv' => ['entity' => 'attributes', 'columns' => [
            'attribute_id' => GQ_LANDS.'attributes.source_attribute_id',
            'attribute_name' => GQ_LANDS.'attributes.slug',
            'attribute_label' => GQ_LANDS.'attributes.name',
            'taxonomy' => GQ_CARRIED.': the fallback for attribute_name when that cell is empty',
            'term_id' => GQ_LANDS.'attribute_values.source_term_id',
            'name' => GQ_LANDS.'attribute_values.name',
            'slug' => GQ_LANDS.'attribute_values.slug',
            'product_ids' => GQ_LANDS.'product_attribute_value.product_id',
            'attribute_type' => GQ_CONSOLIDATED.": WooCommerce's editor widget (select/text); this shop draws one control",
            'attribute_orderby' => GQ_CONSOLIDATED.': term ordering is `position` here',
            'attribute_public' => GQ_CONSOLIDATED.': whether the attribute had its own archive on WordPress',
            'description' => GQ_CONSOLIDATED.': `attribute_values` has no description column',
            'count' => GQ_CONSOLIDATED.": WordPress's cached count",
        ]],

        'products.csv' => ['entity' => 'products', 'columns' => [
            'id' => GQ_LANDS.'products.wc_id',
            'name' => GQ_LANDS.'products.name',
            'slug' => GQ_LANDS.'products.slug',
            'sku' => GQ_LANDS.'products.sku',
            'status' => GQ_LANDS.'products.status',
            'type' => GQ_LANDS.'products.type',
            'regular_price' => GQ_LANDS.'products.price',
            'sale_price' => GQ_LANDS.'products.sale_price',
            'sale_starts_at' => GQ_LANDS.'products.sale_starts_at',
            'sale_ends_at' => GQ_LANDS.'products.sale_ends_at',
            'stock_status' => GQ_LANDS.'products.stock_status',
            'stock' => GQ_LANDS.'products.stock',
            'manage_stock' => GQ_LANDS.'products.manage_stock',
            'featured' => GQ_LANDS.'products.featured',
            'is_visible' => GQ_LANDS.'products.is_visible',
            'brand_term_id' => GQ_LANDS.'products.brand_id',
            'category_term_ids' => GQ_LANDS.'products.category_id + category_product.category_id',
            'position' => GQ_LANDS.'products.position',
            'date_created' => GQ_LANDS.'products.created_at',
            'image' => GQ_LANDS.'products.image',
            'images' => GQ_LANDS.'products.images',
            'short_description' => GQ_LANDS.'products.short_description',
            'description' => GQ_LANDS.'products.description',
            'total_sales' => GQ_LANDS.'products.total_sales',
            'date_modified' => GQ_CONSOLIDATED.': this shop stamps its own updated_at',
            'product_visibility' => GQ_CONSOLIDATED.": WooCommerce's four-state visibility, folded into is_visible and featured",
            'backorders' => GQ_CONSOLIDATED.': no backorder policy column',
            'low_stock_amount' => GQ_CONSOLIDATED.': the low-stock threshold is a shop-wide setting here',
            'weight' => GQ_CONSOLIDATED.': no shipping dimensions; shipping is flat-rate per zone',
            'length' => GQ_CONSOLIDATED.': no shipping dimensions',
            'width' => GQ_CONSOLIDATED.': no shipping dimensions',
            'height' => GQ_CONSOLIDATED.': no shipping dimensions',
            'tax_status' => GQ_CONSOLIDATED.': tax is a shop-wide rate here, not per product',
            'tax_class' => GQ_CONSOLIDATED.': tax is a shop-wide rate here, not per product',
            'shipping_class' => GQ_CONSOLIDATED.': no per-product shipping class',
            'virtual' => GQ_CONSOLIDATED.': every product this shop sells is a physical good',
            'downloadable' => GQ_CONSOLIDATED.': no downloadable products',
            'purchase_note' => GQ_CONSOLIDATED.': no per-product note on the order-received page',
            'upsell_ids' => GQ_CONSOLIDATED.': related products are computed from the category here',
            'cross_sell_ids' => GQ_CONSOLIDATED.': no cross-sell list',
            'grouped_ids' => GQ_CONSOLIDATED.': grouped products are not a type this shop has',
            'tag_term_ids' => GQ_CONSOLIDATED.': the same pivot arrives from tags.csv `product_ids`, which IS read',
            'attribute_summary' => GQ_CONSOLIDATED.": custom (non-taxonomy) attributes -- `Scent=Unscented`. attributes.csv carries the pa_* ones; these have no table",
        ]],

        'variations.csv' => ['entity' => 'variations', 'columns' => [
            'id' => GQ_LANDS.'product_variants.wc_id',
            'parent_id' => GQ_LANDS.'product_variants.product_id',
            'sku' => GQ_LANDS.'product_variants.sku',
            'status' => GQ_LANDS.'product_variants.stock_status',
            'position' => GQ_LANDS.'product_variants.position',
            'regular_price' => GQ_LANDS.'product_variants.price',
            'sale_price' => GQ_LANDS.'product_variants.sale_price',
            'sale_starts_at' => GQ_LANDS.'product_variants.sale_price',
            'sale_ends_at' => GQ_LANDS.'product_variants.sale_price',
            'stock_status' => GQ_LANDS.'product_variants.stock_status',
            'stock' => GQ_LANDS.'product_variants.stock',
            'manage_stock' => GQ_LANDS.'product_variants.manage_stock',
            'image' => GQ_LANDS.'product_variants.image',
            'attributes' => GQ_LANDS.'product_variant_attribute_value.attribute_value_id',
            'backorders' => GQ_CONSOLIDATED.': no backorder policy column',
            'weight' => GQ_CONSOLIDATED.': no shipping dimensions',
            'length' => GQ_CONSOLIDATED.': no shipping dimensions',
            'width' => GQ_CONSOLIDATED.': no shipping dimensions',
            'height' => GQ_CONSOLIDATED.': no shipping dimensions',
            'tax_class' => GQ_CONSOLIDATED.': tax is shop-wide here',
            'description' => GQ_CONSOLIDATED.': `product_variants` has no description column',
            'date_created' => GQ_CONSOLIDATED.': a variant has no created date of its own here',
        ]],

        'seo.csv' => ['entity' => 'seo', 'columns' => [
            'id' => GQ_LANDS.'products.wc_id',
            'yoast_wpseo_title' => GQ_LANDS.'products.seo',
            'yoast_wpseo_metadesc' => GQ_LANDS.'products.seo',
            'yoast_wpseo_opengraph_image' => GQ_LANDS.'products.seo',
            'wpseo_global_identifier_values' => GQ_LANDS.'products.gtin',
            'yoast_wpseo_focuskw' => GQ_NAMED.': the keyphrase Yoast scored the page against; nothing here scores a page',
            'yoast_wpseo_linkdex' => GQ_NAMED.": Yoast's own 0-100 score for that keyphrase",
            'yoast_wpseo_primary_product_cat' => GQ_NAMED.': already spent -- the exporter used it to put the primary category first in products.csv',
        ]],

        'coupons.csv' => ['entity' => 'coupons', 'columns' => [
            'id' => GQ_LANDS.'coupons.wc_id',
            'code' => GQ_LANDS.'coupons.code',
            'post_status' => GQ_GUARD.': only published coupons are exported; a non-publish row is refused rather than turned back into a live discount',
            'description' => GQ_LANDS.'coupons.description',
            'discount_type' => GQ_LANDS.'coupons.type',
            'coupon_amount' => GQ_LANDS.'coupons.amount',
            'date_created' => GQ_LANDS.'coupons.created_at',
            'date_starts' => GQ_LANDS.'coupons.starts_at',
            'date_expires' => GQ_LANDS.'coupons.expires_at',
            'usage_count' => GQ_LANDS.'coupons.usage_count',
            'usage_limit' => GQ_LANDS.'coupons.usage_limit',
            'usage_limit_per_user' => GQ_LANDS.'coupons.usage_limit_per_user',
            'limit_usage_to_x_items' => GQ_LANDS.'coupons.limit_usage_to_x_items',
            'free_shipping' => GQ_LANDS.'coupons.free_shipping',
            'individual_use' => GQ_LANDS.'coupons.individual_use',
            'exclude_sale_items' => GQ_LANDS.'coupons.exclude_sale_items',
            'minimum_amount' => GQ_LANDS.'coupons.minimum_amount',
            'maximum_amount' => GQ_LANDS.'coupons.maximum_amount',
            'product_ids' => GQ_LANDS.'coupons.product_ids',
            'exclude_product_ids' => GQ_LANDS.'coupons.excluded_product_ids',
            'product_categories' => GQ_LANDS.'coupons.category_ids',
            'exclude_product_categories' => GQ_LANDS.'coupons.excluded_category_ids',
            'customer_email' => GQ_LANDS.'coupons.allowed_emails',
            'used_by' => GQ_NAMED.': WHO has already used this code. There is no column for it, so every imported code starts every shopper again from zero uses',
        ]],

        'customers.csv' => ['entity' => 'customers', 'columns' => [
            'user_id' => GQ_LANDS.'customers.wp_user_id',
            'email' => GQ_LANDS.'customers.email',
            'name' => GQ_LANDS.'customers.name',
            'first_name' => GQ_LANDS.'customers.first_name',
            'last_name' => GQ_LANDS.'customers.last_name',
            'phone' => GQ_LANDS.'customers.phone',
            'registered' => GQ_LANDS.'customers.created_at',
            'password_hash' => GQ_LANDS.'customers.legacy_password',
            'roles' => GQ_CONSOLIDATED.': this shop has customers and admin_users and no role column between them',
            'billing_first_name' => GQ_LANDS.'addresses.first_name',
            'billing_last_name' => GQ_LANDS.'addresses.last_name',
            'billing_company' => GQ_LANDS.'addresses.company',
            'billing_address_1' => GQ_LANDS.'addresses.line1',
            'billing_address_2' => GQ_LANDS.'addresses.line2',
            'billing_city' => GQ_LANDS.'addresses.city',
            'billing_state' => GQ_LANDS.'addresses.state',
            'billing_postcode' => GQ_LANDS.'addresses.postcode',
            'billing_country' => GQ_LANDS.'addresses.country',
            'billing_phone' => GQ_LANDS.'addresses.phone',
            'shipping_first_name' => GQ_LANDS.'addresses.first_name',
            'shipping_last_name' => GQ_LANDS.'addresses.last_name',
            'shipping_company' => GQ_LANDS.'addresses.company',
            'shipping_address_1' => GQ_LANDS.'addresses.line1',
            'shipping_address_2' => GQ_LANDS.'addresses.line2',
            'shipping_city' => GQ_LANDS.'addresses.city',
            'shipping_state' => GQ_LANDS.'addresses.state',
            'shipping_postcode' => GQ_LANDS.'addresses.postcode',
            'shipping_country' => GQ_LANDS.'addresses.country',
            'shipping_phone' => GQ_LANDS.'addresses.phone',
        ]],

        'orders.csv' => ['entity' => 'orders', 'columns' => [
            'order_id' => GQ_LANDS.'orders.wc_order_id',
            'order_number' => GQ_LANDS.'orders.order_number',
            'status' => GQ_LANDS.'orders.status',
            'currency' => GQ_LANDS.'orders.currency',
            'customer_id' => GQ_LANDS.'orders.customer_id',
            'billing_email' => GQ_LANDS.'orders.email',
            'date_created' => GQ_LANDS.'orders.created_at',
            'date_created_gmt' => GQ_GUARD.': the only cell that can catch a wrong --timezone, compared against date_created and reported once with a count',
            'date_modified' => GQ_LANDS.'orders.updated_at',
            'date_paid' => GQ_LANDS.'orders.paid_at',
            'date_completed' => GQ_LANDS.'orders.completed_at',
            'subtotal' => GQ_LANDS.'orders.subtotal',
            'discount_total' => GQ_LANDS.'orders.discount_total',
            'shipping_total' => GQ_LANDS.'orders.shipping_total',
            'fee_total' => GQ_LANDS.'orders.fee_total',
            'tax_total' => GQ_LANDS.'orders.tax_total',
            'total' => GQ_LANDS.'orders.total',
            'payment_method' => GQ_LANDS.'orders.payment_method',
            'payment_method_title' => GQ_LANDS.'orders.payment_method_title',
            'transaction_id' => GQ_LANDS.'orders.transaction_id',
            'shipping_method' => GQ_LANDS.'orders.shipping_method',
            'coupon_code' => GQ_LANDS.'orders.coupon_code',
            'customer_note' => GQ_LANDS.'orders.customer_note',
            'origin' => GQ_LANDS.'orders.origin',
            'invoice_number' => GQ_LANDS.'orders.invoice_number',
            'billing_first_name' => GQ_LANDS.'orders.billing_address',
            'billing_last_name' => GQ_LANDS.'orders.billing_address',
            'billing_company' => GQ_LANDS.'orders.billing_address',
            'billing_address_1' => GQ_LANDS.'orders.billing_address',
            'billing_address_2' => GQ_LANDS.'orders.billing_address',
            'billing_city' => GQ_LANDS.'orders.billing_address',
            'billing_state' => GQ_LANDS.'orders.billing_address',
            'billing_postcode' => GQ_LANDS.'orders.billing_address',
            'billing_country' => GQ_LANDS.'orders.billing_address',
            'billing_phone' => GQ_LANDS.'orders.phone',
            'shipping_first_name' => GQ_LANDS.'orders.shipping_address',
            'shipping_last_name' => GQ_LANDS.'orders.shipping_address',
            'shipping_company' => GQ_LANDS.'orders.shipping_address',
            'shipping_address_1' => GQ_LANDS.'orders.shipping_address',
            'shipping_address_2' => GQ_LANDS.'orders.shipping_address',
            'shipping_city' => GQ_LANDS.'orders.shipping_address',
            'shipping_state' => GQ_LANDS.'orders.shipping_address',
            'shipping_postcode' => GQ_LANDS.'orders.shipping_address',
            'shipping_country' => GQ_LANDS.'orders.shipping_address',
            'shipping_phone' => GQ_LANDS.'orders.shipping_address',
        ]],

        'order_items.csv' => ['entity' => 'order-items', 'columns' => [
            'item_id' => GQ_LANDS.'order_items.wc_item_id',
            'order_id' => GQ_LANDS.'order_items.order_id',
            'product_id' => GQ_LANDS.'order_items.product_id',
            'variation_id' => GQ_LANDS.'order_items.product_variant_id + order_items.variant_attributes',
            'name' => GQ_LANDS.'order_items.name',
            'sku' => GQ_LANDS.'order_items.sku',
            'brand' => GQ_LANDS.'order_items.brand',
            'quantity' => GQ_LANDS.'order_items.quantity',
            'subtotal' => GQ_LANDS.'order_items.subtotal',
            'total' => GQ_LANDS.'order_items.total',
            'tax_total' => GQ_LANDS.'order_items.tax_total',
        ]],

        'refunds.csv' => ['entity' => 'refunds', 'columns' => [
            'refund_id' => GQ_LANDS.'refunds.wc_refund_id',
            'order_id' => GQ_LANDS.'refunds.order_id',
            'date_created' => GQ_LANDS.'refunds.created_at',
            'amount' => GQ_LANDS.'refunds.amount',
            'reason' => GQ_LANDS.'refunds.reason',
            'refunded_by' => GQ_LANDS.'refunds.refunded_by',
            'total' => GQ_GUARD.": Woo's same money with the opposite sign; read to check it agrees with `amount` rather than guessing which convention arrived",
            'currency' => GQ_GUARD.': `refunds` has no currency column, so a refund in a currency that is not the order\'s is reported rather than silently counted as dirhams',
            'refunded_items' => GQ_NAMED.': WHICH LINES the refund covered, as item:qty:total. The money is exact; the per-line breakdown has nowhere to go',
        ]],

        'order_notes.csv' => ['entity' => 'order-notes', 'columns' => [
            'note_id' => GQ_LANDS.'order_notes.source_comment_id',
            'order_id' => GQ_LANDS.'order_notes.order_id',
            'date_created' => GQ_LANDS.'order_notes.created_at',
            'date_created_gmt' => GQ_CARRIED.': preferred over date_created when present, so one --timezone cannot shift the history',
            'author' => GQ_LANDS.'order_notes.author',
            'content' => GQ_LANDS.'order_notes.content',
            'is_customer_note' => GQ_LANDS.'order_notes.is_customer_note',
            'author_email' => GQ_NAMED.': `order_notes` keeps the author as a name only',
        ]],

        'reviews.csv' => ['entity' => 'reviews', 'columns' => [
            'comment_id' => GQ_LANDS.'reviews.source_id',
            'comment_post_id' => GQ_LANDS.'reviews.product_id',
            'comment_type' => GQ_GUARD.": refused unless it is a review -- the exporter rewrites the pre-WooCommerce-3.0 empty type so a 2016 review is not refused",
            'author' => GQ_LANDS.'reviews.author_name',
            'email' => GQ_LANDS.'reviews.author_email',
            'rating' => GQ_LANDS.'reviews.rating',
            'title' => GQ_LANDS.'reviews.title',
            'content' => GQ_LANDS.'reviews.content',
            'comment_approved' => GQ_LANDS.'reviews.status',
            'comment_date' => GQ_LANDS.'reviews.created_at',
            'verified' => GQ_LANDS.'reviews.verified',
            'user_id' => GQ_LANDS.'reviews.customer_id',
            'ip' => GQ_LANDS.'reviews.ip',
            'reply' => GQ_LANDS.'reviews.reply',
            'comment_date_gmt' => GQ_CONSOLIDATED.": the same instant as comment_date, which IS read -- the date crosses, and this line of the discard list overstates the loss",
        ]],

        'posts.csv' => ['entity' => 'posts', 'columns' => [
            'id' => GQ_LANDS.'posts.source_post_id',
            'type' => GQ_GUARD.': `post` becomes a Journal article; every other type is refused BY NAME and listed in the discard channel',
            'slug' => GQ_LANDS.'posts.slug',
            'status' => GQ_LANDS.'posts.status',
            'title' => GQ_LANDS.'posts.title',
            'excerpt' => GQ_LANDS.'posts.excerpt',
            'content' => GQ_LANDS.'posts.body',
            'author_name' => GQ_LANDS.'posts.author',
            'date_created_gmt' => GQ_LANDS.'posts.published_at',
            'image' => GQ_LANDS.'posts.cover',
            'categories' => GQ_LANDS.'posts.tag',
            'tags' => GQ_LANDS.'posts.tag',
            'date_created' => GQ_CONSOLIDATED.': the same instant as date_created_gmt, which IS read -- the date crosses, and this line of the discard list overstates the loss',
            'author_id' => GQ_CONSOLIDATED.': `posts.author` is a name, not a user link',
            'author_email' => GQ_CONSOLIDATED.': `posts` keeps the author as a name only',
            'comment_status' => GQ_CONSOLIDATED.': the Journal takes no comments',
            'date_modified' => GQ_CONSOLIDATED.': this shop stamps its own updated_at',
            'parent_id' => GQ_CONSOLIDATED.': articles are flat',
            'position' => GQ_CONSOLIDATED.': the Journal orders by date',
        ]],

        /*
         * The two files kbb:import does not open, and the difference between
         * them, which is the whole point of having this row in the census.
         */
        'permalinks.csv' => ['entity' => null, 'columns' => [
            'type' => GQ_ELSEWHERE.': `kbb:import-redirects` / Store -> Import -> Build the URL map',
            'wc_id' => GQ_ELSEWHERE.': RedirectMap::fromPermalinks()',
            'slug' => GQ_ELSEWHERE.': RedirectMap::fromPermalinks()',
            'permalink' => GQ_ELSEWHERE.': RedirectMap::fromPermalinks() -> redirects.from',
            'status' => GQ_ELSEWHERE.": for the owner's eye; the map cannot be confused by it",
            'source' => GQ_ELSEWHERE.': whether WordPress answered or the address was derived',
            'note' => GQ_ELSEWHERE.': why a term has no archive address',
        ]],

        'media.csv' => ['entity' => null, 'columns' => [
            'url' => GQ_UNREAD.': NOBODY OPENS THIS FILE. kbb:import-media re-derives the download list from the imported product URLs instead',
            'attachment_id' => GQ_UNREAD.': the WordPress attachment id; `media.source_attachment_id` exists and stays empty',
            'size' => GQ_UNREAD.': which registered size the reference asked for',
            'path' => GQ_UNREAD.': the path under the old uploads directory',
            'exists' => GQ_UNREAD.': a stat() on the OLD server -- which pictures the media library names and the disk no longer has. Answerable now and never again',
            'bytes' => GQ_UNREAD.': the file size, for the download estimate',
            'referenced_by' => GQ_UNREAD.': which product goes blank if the fetch fails',
            'referenced_id' => GQ_UNREAD.': the referrer id',
            'field' => GQ_UNREAD.': which field of the referrer held the reference',
        ]],
    ];
}

/**
 * Column one: what a WordPress shop holds, and where each kind of thing goes.
 *
 * Keyed by the name WordPress uses, because that is the name the manifest's own
 * census reports and therefore the name a NEW one will arrive under.
 *
 * @return array<string, string>
 */
function gqPostTypeCensus(): array
{
    return [
        'product' => 'products.csv -> products',
        'product_variation' => 'variations.csv -> product_variants',
        'shop_order' => 'orders.csv -> orders (legacy storage; on HPOS these are wc_orders rows and this type is absent)',
        'shop_order_refund' => 'refunds.csv -> refunds',
        'shop_coupon' => 'coupons.csv -> coupons, published ones only; the count withheld is a manifest note',
        'post' => 'posts.csv -> posts (the Journal)',
        'page' => 'posts.csv, and REFUSED BY NAME: this shop ships its own /about/, /delivery/, /faqs/, /privacy-policy/ and /terms-and-conditions/',
        'attachment' => 'media.csv -- the referenced sizes only, and NOTHING OPENS IT; the rest of the media library is a manifest note',
    ];
}

/** @return array<string, string> */
function gqTaxonomyCensus(): array
{
    return [
        'product_cat' => 'categories.csv -> categories',
        'product_tag' => 'tags.csv -> tags + product_tag',
        'pa_brands' => 'brands.csv -> brands',
        'pa_size' => 'attributes.csv -> attributes + attribute_values',
        'product_type' => 'products.csv `type` -> products.type',
        'product_visibility' => 'products.csv `featured` and `is_visible`',
        'category' => 'posts.csv `categories` -> posts.tag',
    ];
}

/* ═══════════════════════════════════════════════════════════════════ helpers */

function gqExportDir(): string
{
    return base_path('tests/Fixtures/kbb-export');
}

function gqManifest(): array
{
    return json_decode((string) file_get_contents(gqExportDir().'/manifest.json'), true);
}

/** The normalised header of one export file, exactly as CsvRowSource sees it. */
function gqHeader(string $file): array
{
    $handle = fopen(gqExportDir().'/'.$file, 'rb');
    $header = fgetcsv($handle);
    fclose($handle);

    return array_map(static function (string $name): string {
        $key = mb_strtolower(trim($name));

        return trim((string) preg_replace('/[^a-z0-9]+/', '_', $key), '_');
    }, $header);
}

/** The real import, run the way docs/IMPORT-RUNBOOK.md says to run this export. */
function gqImport(): App\Services\Import\ImportReport
{
    return (new ImportRunner)->run(new ImportOptions(
        directory: gqExportDir(),
        sourceTimezone: gqManifest()['source']['timezone'],
        adoptBySlug: true,
    ));
}

/**
 * The columns one entity's consolidated discard named, out of the report.
 *
 * Parsed out of the channel the OWNER reads rather than re-derived from the
 * importer, deliberately: the thing being asserted is what the report SAYS, not
 * what a second copy of the same logic would say.
 *
 * @return list<string>
 */
function gqIgnoredColumns(App\Services\Import\ImportReport $report, string $entity): array
{
    foreach ($report->for($entity)->discards() as $kind => $discard) {
        if (! str_contains($kind, 'columns in this export that no field of this importer reads')) {
            continue;
        }

        $out = [];

        foreach (explode(' | ', $discard['samples'][0]['before']) as $part) {
            $out[] = trim(explode(' = ', str_replace(' (always empty)', '', $part))[0]);
        }

        sort($out);

        return $out;
    }

    return [];
}

/** @return list<string> the census's own answer for the same entity */
function gqCensusDropped(string $file): array
{
    $out = [];

    foreach (gqCensus()[$file]['columns'] as $column => $verdict) {
        if (str_starts_with($verdict, GQ_CONSOLIDATED)) {
            $out[] = $column;
        }
    }

    sort($out);

    return $out;
}

/* ══════════════════════════════════════════════════════ column two: the files */

it('has a census entry for every file the export writes, and writes every file the census has an entry for', function () {
    $written = array_keys(gqManifest()['files']);
    $censused = array_keys(gqCensus());

    sort($written);
    sort($censused);

    /*
     * array_diff both ways and NOT expect()->not->toContain(), which is
     * variadic and therefore passes vacuously on a single argument -- the idiom
     * this repository has already been bitten by.
     */
    expect(array_values(array_diff($written, $censused)))->toBe(
        [],
        'the export writes files this census does not classify. Every file has to be in column three: '
        .'landed, or named by a channel. A file nobody classified is the shape coupons.csv and reviews.csv '
        .'were in when they were ignored in silence.'
    );

    expect(array_values(array_diff($censused, $written)))->toBe(
        [],
        'this census classifies files the export no longer writes'
    );
});

it('accounts for every column of every file, by name', function () {
    foreach (gqCensus() as $file => $entry) {
        $header = gqHeader($file);
        $censused = array_keys($entry['columns']);

        sort($header);
        sort($censused);

        expect(array_values(array_diff($header, $censused)))->toBe(
            [],
            $file.': the export carries columns this census does not classify. A NEW column is a new thing '
            .'the old shop holds, and until it is classified nobody knows whether it crosses.'
        );

        expect(array_values(array_diff($censused, $header)))->toBe(
            [],
            $file.': this census classifies columns the export no longer writes. A column that has STOPPED '
            .'crossing is the failure this file exists to catch -- check the exporter stage before deleting '
            .'the entry.'
        );
    }
});

it('names a real table and a real column for everything it says lands', function () {
    foreach (gqCensus() as $file => $entry) {
        foreach ($entry['columns'] as $column => $verdict) {
            if (! str_starts_with($verdict, GQ_LANDS)) {
                continue;
            }

            foreach (explode(' + ', substr($verdict, strlen(GQ_LANDS))) as $destination) {
                [$table, $target] = explode('.', trim($destination), 2);

                expect(Schema::hasTable($table))->toBeTrue(
                    $file.' '.$column.': the census names table `'.$table.'`, which does not exist'
                );

                expect(Schema::hasColumn($table, $target))->toBeTrue(
                    $file.' '.$column.': the census names `'.$destination.'`, and that column does not exist. '
                    .'A destination that has been renamed means the value is going somewhere else or nowhere.'
                );
            }
        }
    }
});

/* ═════════════════════════════════════════ column three: what the import lands */

it('lands every column the census says lands, and drops exactly the ones it says are dropped', function () {
    $report = gqImport();

    foreach (gqCensus() as $file => $entry) {
        if ($entry['entity'] === null) {
            continue;
        }

        $reported = gqIgnoredColumns($report, $entry['entity']);
        $expected = gqCensusDropped($file);

        /*
         * ── THIS IS THE ASSERTION THE WHOLE FILE IS FOR ────────────────────
         *
         * A column that stops crossing does not disappear. It moves out of the
         * importer's reads and INTO the runner's consolidated discard, where
         * the owner would see it as one name among nineteen in a list he is
         * asked to approve. Here it moves a name from one side of this
         * comparison to the other and fails with the name in the message.
         *
         * MUTATION: delete the `variation_id` read from OrderItemImporter.
         * `variation_id` appears in $reported and not in $expected. Red, by
         * name.
         *
         * AND THE MUTATION THIS ONE DOES NOT CATCH, which is why the value
         * check further down exists: deleting `'sku' => $row->text('sku'),`
         * from ProductImporter's apply() leaves this GREEN, because
         * ProductImporter asks for `sku` in a second place -- the duplicate-SKU
         * check -- so the column is still READ while the SKU has stopped
         * ARRIVING. Read and landed are different claims.
         */
        expect($reported)->toBe(
            $expected,
            $file.': what the import drops is not what this census says it drops. A name that appeared on the '
            .'left has STOPPED CROSSING -- the column is in the export and will not be in the database. A name '
            .'that appeared on the right now crosses, and the census is out of date.'
        );
    }
});

it('names the one file in the export that nothing opens, with its row count', function () {
    $report = gqImport();

    $named = [];

    foreach ($report->for('export')->discards() as $kind => $discard) {
        if (! str_contains($kind, 'no importer opens')) {
            continue;
        }

        foreach ($discard['samples'] as $sample) {
            $named[] = $sample['field'];
        }
    }

    sort($named);

    /*
     * media.csv AND NOTHING ELSE. permalinks.csv is claimed by
     * kbb:import-redirects and manifest.json by ImportManifest, and naming
     * either would train the owner to skim the one list that is only worth
     * anything if every line in it is true.
     *
     * MUTATION: register any importer for media.csv, or add a file to the
     * fixture. Red either way.
     */
    expect($named)->toBe(['media.csv'], 'the set of unopened files in the export has changed');

    $rows = $report->for('export')->discards();
    $sample = array_values(array_filter($rows, static fn (string $k): bool => str_contains($k, 'no importer opens'), ARRAY_FILTER_USE_KEY));

    /*
     * str_contains, NOT expect()->toContain(): toContain is VARIADIC, so the
     * message would be read as a second needle and the assertion would fail for
     * the wrong reason -- which it did, once, here. The same shape as
     * expect()->not->toContain() passing vacuously, which CLAUDE.md's lanes
     * have paid for before.
     */
    expect(str_contains($sample[0]['samples'][0]['before'], (string) gqManifest()['files']['media.csv']['rows']))->toBeTrue(
        'the unread-file line has to carry the row count, or the owner cannot tell a stray file from 8 photographs'
    );
});

/* ═════════════════════════════════ column one: what a WordPress shop holds */

it('gives every WordPress post type and taxonomy in the export a destination', function () {
    $manifest = gqManifest();

    /*
     * array_key_exists and not expect()->toHaveKey(), whose second argument is
     * an expected VALUE and not a message -- which is the same family of
     * mistake as expect()->not->toContain() passing vacuously, and it cost one
     * run here before it was noticed.
     */
    foreach (array_keys($manifest['source']['post_types']) as $type) {
        expect(array_key_exists($type, gqPostTypeCensus()))->toBeTrue(
            "the shop holds a `{$type}` post type and this census does not say where it goes. That is the "
            .'dangerous case: a kind of thing the old shop has, in neither the export nor a report channel.'
        );
    }

    foreach (array_keys($manifest['source']['taxonomies']) as $taxonomy) {
        expect(array_key_exists($taxonomy, gqTaxonomyCensus()))->toBeTrue(
            "the shop holds a `{$taxonomy}` taxonomy and this census does not say where it goes"
        );
    }
});

it('fills the tables the census says it fills, and leaves the rest visibly empty', function () {
    /*
     * ── THE TABLES A COMPLETE IMPORT STILL LEAVES AT ZERO ──────────────────
     *
     * docs/FV-IMPORT-AT-VOLUME.md §11 was this list, and Lanes GH, GI and GJ
     * closed six of its seven lines. What is left is what the owner has to re-enter
     * by hand, and it is asserted here so that "still empty" is a measurement
     * rather than a memory -- and so that a lane which CLOSES one of these
     * fails this test and has to come and say so.
     *
     * `media` and `redirects` are in the list because kbb:import does not fill
     * them and it is worth saying so out loud; kbb:import-media and
     * kbb:import-redirects are separate commands by design and neither shows up
     * in a row count.
     */
    $stillEmptyAfterAFullImport = [
        'shipping_zones' => 'nothing in the export carries a WooCommerce shipping zone',
        'shipping_methods' => 'nothing in the export carries a shipping rate',
        'shipping_zone_locations' => 'nothing in the export carries a zone location',
        'tax_rates' => 'nothing in the export carries a WooCommerce tax rate',
        'delivery_countries' => 'nothing in the export carries a delivery country',
        'payment_providers' => 'gateway configuration is secrets and is re-entered by hand',
        'menus' => "the WordPress navigation menu is a nav_menu_item post type on the exporter's denylist",
        'menu_items' => 'the same',
        'media' => 'kbb:import-media, a separate command; media.csv itself is opened by nothing',
        'redirects' => 'kbb:import-redirects, a separate command',
        'pages' => 'a WordPress page is refused BY NAME -- this shop ships its own',
    ];

    $filled = ['products', 'categories', 'tags', 'attributes', 'attribute_values', 'product_variants',
        'coupons', 'customers', 'addresses', 'orders', 'order_items', 'refunds', 'order_notes',
        'reviews', 'posts'];

    $before = [];

    foreach ([...array_keys($stillEmptyAfterAFullImport), ...$filled] as $table) {
        $before[$table] = DB::table($table)->count();
    }

    gqImport();

    foreach ($stillEmptyAfterAFullImport as $table => $why) {
        expect(DB::table($table)->count())->toBe(
            $before[$table],
            "`{$table}` gained rows from a full import, and this census says it cannot: {$why}. "
            .'If a lane has closed that gap, this census and docs/GQ-MIGRATION-COMPLETENESS.md are now wrong.'
        );
    }

    foreach ($filled as $table) {
        expect(DB::table($table)->count())->toBeGreaterThan(
            $before[$table],
            "`{$table}` gained NOTHING from a full import of the plugin's own export. An entity that has "
            .'stopped landing is the loudest version of a column that has stopped crossing.'
        );
    }
});

/* ════════════════════════ the three fixes this census found, pinned by value */

it('lands the barcode the WooCommerce SEO add-on carried', function () {
    /*
     * Every piece of this existed and nothing joined them: the export carried
     * `wpseo_global_identifier_values` (Lane GE made the SELECT match
     * `wpseo_%` as well as `_yoast_wpseo_%` precisely so it would), YoastTiers
     * unpicked both encodings and checked the digit, `products.gtin` was there
     * and App\Support\Seo published it. The census is what noticed.
     *
     * MUTATION: remove the gtin block from SeoImporter. Red.
     */
    gqImport();

    expect(DB::table('products')->where('wc_id', 4021)->value('gtin'))->toBe('8809453510003');
});

it('remembers which size of a variable product was actually sold', function () {
    /*
     * `order_items.variation_id` was read by nothing for as long as this shop
     * had no variants; after Lane GH it went on being read by nothing while
     * both the row it points at and the column it belongs in existed. What it
     * costs is the line under the product name on the invoice, the order email
     * and the shopper's own order page.
     *
     * MUTATION: drop `product_variant_id` from OrderItemImporter's apply().
     * Red here and in the census comparison above.
     */
    gqImport();

    $line = DB::table('order_items')->where('wc_item_id', 5508)->first();

    expect($line->product_variant_id)->not->toBeNull();
    expect(json_decode((string) $line->variant_attributes, true))->toBe(['50ml']);

    // And a simple product's line is NOT given a variant: Woo writes 0 there,
    // the exporter writes '', and neither is a variant.
    expect(DB::table('order_items')->where('wc_item_id', 5501)->value('product_variant_id'))->toBeNull();
});

it('lands the OpenGraph image, which a hyphen had been eating', function () {
    /*
     * `_yoast_wpseo_opengraph-image` and `_yoast_wpseo_meta-robots-noindex` are
     * the two mapped keys with a HYPHEN in them, and CsvRowSource rewrites a
     * hyphen in a header to an underscore. YoastSeo::pick() compared only
     * hyphenated spellings, so both fell out of every import ever run, and the
     * noindex one is the expensive half: a product the owner had deliberately
     * hidden from Google would have been published.
     *
     * MUTATION: remove the underscored spellings from YoastSeo::spellings().
     * Red.
     */
    gqImport();

    $seo = json_decode((string) DB::table('products')->where('wc_id', 4021)->value('seo'), true);

    expect($seo['og_image'] ?? null)->toBe('https://kbeautybliss.com/wp-content/uploads/2019/03/ginseng-og.jpg');
});

/* ════════════════ column one, from the shop itself: every meta key, classified */

/**
 * Where each WordPress meta key goes.
 *
 * ── WHY THIS LIST AND NOT A PARAGRAPH ──────────────────────────────────────
 *
 * Columns two and three above are checked against the export and the importer,
 * which is most of the census -- but both of them start from the export. A
 * thing the old shop holds that the EXPORT never picked up cannot appear in
 * either, and that is precisely the dangerous case: not in the files, therefore
 * not in the ignored-column line, therefore in no channel at all.
 *
 * So this half starts from the WordPress database instead. The test below asks
 * the harness shop for every distinct meta key it holds and requires a line
 * here for each one. A key nobody has classified fails BY NAME.
 *
 * `_order_key` is the entry worth reading. It is on every order in WooCommerce,
 * it is in no column of orders.csv, and until this census nothing anywhere in
 * this repository said so.
 *
 * @return array<string, string>
 */
function gqMetaKeyCensus(): array
{
    $orders = 'orders.csv -> orders';
    $products = 'products.csv -> products';
    $coupons = 'coupons.csv -> coupons';
    $seo = 'seo.csv -> products.seo';
    $addresses = 'orders.csv / customers.csv -> addresses + orders.billing_address/shipping_address';

    return [
        /* ── the order header ───────────────────────────────────────────── */
        '_order_total' => $orders.'.total',
        '_order_tax' => $orders.'.tax_total',
        '_order_shipping' => $orders.'.shipping_total',
        '_cart_discount' => $orders.'.discount_total',
        '_order_currency' => $orders.'.currency',
        '_order_number' => $orders.'.order_number',
        '_customer_user' => $orders.'.customer_id',
        '_billing_email' => $orders.'.email',
        '_payment_method' => $orders.'.payment_method',
        '_payment_method_title' => $orders.'.payment_method_title',
        '_transaction_id' => $orders.'.transaction_id',
        '_created_via' => $orders.'.origin',
        '_date_paid' => $orders.'.paid_at',
        '_date_completed' => $orders.'.completed_at',

        /*
         * ▲ NOT EXPORTED AND IN NO CHANNEL.
         *
         * `_order_key` is WooCommerce's per-order token. Every order-received
         * and order-view link WooCommerce has ever emailed carries it --
         * /checkout/order-received/10233/?key=wc_order_ccc -- and there is no
         * column for it in orders.csv and no column for it in `orders`. So
         * those links cannot be served after the cutover whatever the redirect
         * map does, and nothing in the import report would have said so.
         * Named here so it is a decision. docs/GQ-MIGRATION-COMPLETENESS.md.
         */
        '_order_key' => 'NOT EXPORTED, NOT NAMED: the order-view token in every order email WooCommerce sent',

        /* ── the addresses ──────────────────────────────────────────────── */
        '_billing_first_name' => $addresses, '_billing_last_name' => $addresses,
        '_billing_address_1' => $addresses, '_billing_city' => $addresses,
        '_billing_state' => $addresses, '_billing_postcode' => $addresses,
        '_billing_country' => $addresses, '_billing_phone' => $addresses,
        '_shipping_first_name' => $addresses, '_shipping_last_name' => $addresses,
        '_shipping_address_1' => $addresses, '_shipping_city' => $addresses,
        '_shipping_state' => $addresses, '_shipping_country' => $addresses,

        /* ── the refund ─────────────────────────────────────────────────── */
        '_refund_amount' => 'refunds.csv -> refunds.amount',
        '_refund_reason' => 'refunds.csv -> refunds.reason',
        '_refunded_by' => 'refunds.csv -> refunds.refunded_by',

        /* ── the catalogue ──────────────────────────────────────────────── */
        '_sku' => $products.'.sku',
        '_regular_price' => $products.'.price',
        '_sale_price' => $products.'.sale_price',
        '_sale_price_dates_from' => $products.'.sale_starts_at',
        '_sale_price_dates_to' => $products.'.sale_ends_at',
        '_stock' => $products.'.stock',
        '_stock_status' => $products.'.stock_status',
        '_manage_stock' => $products.'.manage_stock',
        '_thumbnail_id' => $products.'.image (and posts.cover, categories.image, brands.logo)',
        '_product_image_gallery' => $products.'.images',
        'total_sales' => $products.'.total_sales',
        'attribute_pa_size' => 'variations.csv `attributes` -> product_variant_attribute_value',
        '_weight' => 'products.csv `weight` -- DROPPED, named in the discard list',
        '_tax_status' => 'products.csv `tax_status` -- DROPPED, named in the discard list',
        '_product_attributes' => 'products.csv `attribute_summary` -- DROPPED, named in the discard list. '
            .'A CUSTOM (non-taxonomy) attribute has no table in this shop; the pa_* ones cross in attributes.csv',

        /* ── the coupons ────────────────────────────────────────────────── */
        'discount_type' => $coupons.'.type',
        'coupon_amount' => $coupons.'.amount',
        'date_expires' => $coupons.'.expires_at',
        'usage_count' => $coupons.'.usage_count',
        'usage_limit' => $coupons.'.usage_limit',
        'usage_limit_per_user' => $coupons.'.usage_limit_per_user (the LIMIT crosses; WHO has used it cannot)',
        'free_shipping' => $coupons.'.free_shipping',
        'individual_use' => $coupons.'.individual_use',
        'exclude_sale_items' => $coupons.'.exclude_sale_items',
        'product_categories' => $coupons.'.category_ids',
        '_used_by' => 'coupons.csv `used_by` -- NAMED in the discard list: every imported code starts every shopper again from zero uses',

        /* ── Yoast ──────────────────────────────────────────────────────── */
        '_yoast_wpseo_title' => $seo.'.title',
        '_yoast_wpseo_metadesc' => $seo.'.desc',
        '_yoast_wpseo_opengraph-image' => $seo.'.og_image',
        '_yoast_wpseo_primary_product_cat' => 'seo.csv -- already spent: the exporter used it to order products.csv `category_term_ids`',
        '_yoast_wpseo_focuskw' => 'seo.csv -- NAMED in the discard list',
        '_yoast_wpseo_linkdex' => 'seo.csv -- NAMED in the discard list',
        'wpseo_global_identifier_values' => 'seo.csv -> products.gtin',

        /* ── the media library ──────────────────────────────────────────── */
        '_wp_attached_file' => 'media.csv `path` -- and NOTHING OPENS media.csv',
        '_wp_attachment_metadata' => 'media.csv `size` -- and NOTHING OPENS media.csv',

        /* ── the users ──────────────────────────────────────────────────── */
        'first_name' => 'customers.csv -> customers.first_name',
        'last_name' => 'customers.csv -> customers.last_name',
        'billing_first_name' => $addresses, 'billing_last_name' => $addresses,
        'billing_address_1' => $addresses, 'billing_city' => $addresses,
        'billing_state' => $addresses, 'billing_postcode' => $addresses,
        'billing_country' => $addresses, 'billing_phone' => $addresses,
        'shipping_first_name' => $addresses, 'shipping_last_name' => $addresses,
        'shipping_address_1' => $addresses, 'shipping_city' => $addresses,
        'shipping_state' => $addresses, 'shipping_country' => $addresses,
        'capabilities' => 'customers.csv `roles` -- DROPPED, named in the discard list. This shop has '
            .'`customers` and `admin_users` and no role column between them',

        /* ── the comments ───────────────────────────────────────────────── */
        'rating' => 'reviews.csv -> reviews.rating (and the filter that decides what IS a review)',
        'verified' => 'reviews.csv -> reviews.verified',
        'is_customer_note' => 'order_notes.csv -> order_notes.is_customer_note',

        /* ── the terms ──────────────────────────────────────────────────── */
        'order' => 'categories.csv / brands.csv `position`',
        'thumbnail_id' => 'categories.csv `image`, brands.csv `logo`',

        /* ── the line items ─────────────────────────────────────────────── */
        '_product_id' => 'order_items.csv -> order_items.product_id',
        '_variation_id' => 'order_items.csv -> order_items.product_variant_id',
        '_qty' => 'order_items.csv -> order_items.quantity',
        '_line_subtotal' => 'order_items.csv -> order_items.subtotal (and summed into orders.subtotal by the exporter)',
        '_line_total' => 'order_items.csv -> order_items.total',
        '_line_tax' => 'order_items.csv -> order_items.tax_total',
        'discount_amount' => 'a coupon LINE ITEM -- the exporter turns it into orders.csv `coupon_code`',
        '_refunded_item_id' => 'refunds.csv `refunded_items` -- NAMED in the discard list',
    ];
}

it('classifies every meta key the WordPress shop actually holds', function () {
    /*
     * ── THE ONE HALF OF THE CENSUS THAT DOES NOT START FROM THE EXPORT ─────
     *
     * Needs MySQL and the harness database (KBB_WP_DB, default kbb_ge_wp), so
     * it skips where those are absent, exactly as Lane GE's regeneration test
     * does. Everything above runs everywhere, including CI.
     *
     * MUTATION: delete any single entry from gqMetaKeyCensus(). Red, with the
     * key in the message.
     */
    $script = base_path('wordpress-plugin/harness/run-export.php');
    $out = sys_get_temp_dir().'/kbb-gq-census-'.bin2hex(random_bytes(4));
    $db = getenv('KBB_WP_DB') ?: 'kbb_ge_wp';

    exec(
        escapeshellcmd(PHP_BINARY).' '.escapeshellarg($script)
        .' --storage=posts --out='.escapeshellarg($out).' --db='.$db.' 2>&1',
        $lines,
        $status
    );

    if ($status === 3) {
        $this->markTestSkipped('no MySQL here: '.implode(' ', $lines));
    }

    expect($status)->toBe(0, 'the harness export failed: '.implode("\n", $lines));

    $pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname='.$db.';charset=utf8mb4', 'kbb', 'kbb', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    $keys = [];

    foreach ([
        'wp_postmeta', 'wp_usermeta', 'wp_commentmeta', 'wp_termmeta', 'wp_woocommerce_order_itemmeta',
    ] as $table) {
        foreach ($pdo->query('SELECT DISTINCT meta_key FROM `'.$table.'` ORDER BY 1')->fetchAll(PDO::FETCH_COLUMN) as $key) {
            /*
             * The capability key carries the table prefix -- `wp_capabilities`
             * on this shop and something else on a shop whose prefix somebody
             * changed -- so it is classified by the part that is the same
             * everywhere. Nothing else in WordPress does this.
             */
            $keys[str_ends_with((string) $key, 'capabilities') ? 'capabilities' : (string) $key] = $table;
        }
    }

    $census = gqMetaKeyCensus();

    $unclassified = array_values(array_diff(array_keys($keys), array_keys($census)));
    $stale = array_values(array_diff(array_keys($census), array_keys($keys)));

    sort($unclassified);
    sort($stale);

    expect($unclassified)->toBe(
        [],
        'the WordPress shop holds meta keys this census does not classify. Until one is classified nobody '
        .'knows whether it crosses, and a key that is in neither the export nor a report channel is lost in '
        .'silence -- which is the failure this whole census exists to make impossible.'
    );

    expect($stale)->toBe(
        [],
        'this census classifies meta keys the WordPress shop no longer holds. Either the harness stopped '
        .'modelling something a real shop has, or the entry was wrong.'
    );

    // And the comment types, which are what decide whether a wp_comments row is
    // a review, a question, the shop's own reply, or an order note.
    $types = $pdo->query('SELECT DISTINCT comment_type FROM wp_comments ORDER BY 1')->fetchAll(PDO::FETCH_COLUMN);

    sort($types);

    expect($types)->toBe(
        ['', 'comment', 'order_note', 'review'],
        'the comment types in the shop have changed. `review` and the EMPTY type (WooCommerce before 3.0) '
        .'become reviews.csv; `order_note` becomes order_notes.csv; a bare `comment` is either the shop\'s '
        .'reply, which rides on its parent review, or a customer QUESTION, which has nowhere to go and is '
        .'counted in the manifest notes.'
    );
});

/* ═══════════════════════════ the census, checked at the VALUE and not the read */

/**
 * How to find the rows an import wrote, per destination table.
 *
 * Every one of these is the table's own external id, which is the only honest
 * answer on this shop: 2026_08_27_100000_seed_demo_catalogue puts 24 products,
 * 8 brands and 6 categories on EVERY install with no external id at all, so
 * `SELECT COUNT(*) FROM products` is 28 for 4 imported and a column that stops
 * crossing would hide behind the demo rows that do have a value in it.
 *
 * @return array<string, ?string>  table => the column that marks an imported row
 */
function gqImportedRowMarker(): array
{
    return [
        'categories' => 'source_term_id',
        'brands' => 'source_term_id',
        'tags' => 'source_term_id',
        'attributes' => 'source_attribute_id',
        'attribute_values' => 'source_term_id',
        'products' => 'wc_id',
        'product_variants' => 'wc_id',
        'coupons' => 'wc_id',
        'customers' => null,        // guests have no wp_user_id and are still imported rows
        'addresses' => 'source_key',
        'orders' => 'wc_order_id',
        'order_items' => 'wc_item_id',
        'refunds' => 'wc_refund_id',
        'order_notes' => 'source_comment_id',
        'reviews' => 'source_id',
        'posts' => 'source_post_id',
        'category_product' => null,
        'product_tag' => null,
        'product_attribute_value' => null,
        'product_variant_attribute_value' => null,
    ];
}

/**
 * Destinations this fixture genuinely leaves empty, each with the reason.
 *
 * An exemption is a CLAIM about the fixture, not a way of quietening the test:
 * every line below says which row of wordpress-plugin/harness/shop.php would
 * have to change for the column to carry something. Widening this list is how
 * the guard would be defeated, so it is short and each entry is arguable.
 *
 * @return array<string, string>
 */
function gqEmptyInThisFixture(): array
{
    return [
        /*
         * ── THE COUPON FIELDS WELCOME20 NEVER HAD ──────────────────────────
         *
         * wordpress-plugin/harness/shop.php seeds coupon 9101 with a discount
         * type, an amount, an expiry, the two usage counters and a category
         * restriction, and nothing else. Every line below names a coupon meta
         * key that shop does not write; adding one to the harness removes the
         * line from here.
         */
        'coupons.minimum_amount' => "the harness coupon has no `minimum_amount` meta",
        'coupons.maximum_amount' => "the harness coupon has no `maximum_amount` meta",
        'coupons.product_ids' => "the harness coupon restricts by CATEGORY, not by product",
        'coupons.excluded_product_ids' => "the harness coupon has no `exclude_product_ids` meta",
        'coupons.excluded_category_ids' => "the harness coupon has no `exclude_product_categories` meta",
        'coupons.allowed_emails' => "the harness coupon has no `customer_email` meta",
        'coupons.limit_usage_to_x_items' => "the harness coupon has no `limit_usage_to_x_items` meta",
        'coupons.starts_at' => "`date_starts` comes from the Smart Coupons `_wc_sc_start_date` meta, which core WooCommerce does not write and the harness does not seed",

        /*
         * ── AND THE THREE THAT ARE FACTS ABOUT WooCommerce, NOT THE FIXTURE ─
         */
        'reviews.title' => 'A WOOCOMMERCE REVIEW HAS NO TITLE. The column exists because this shop\'s own '
            .'review form has one; every review imported from WordPress will have it empty, all 2,514 of them, '
            .'and that is a property of the source and not of this fixture',
        'orders.invoice_number' => 'the invoice number comes from the WooCommerce PDF Invoices plugin '
            .'(`_wf_invoice_number`); a shop that never ran it has none, and the harness models that shop',

        'addresses.company' => 'no shopper in the harness shop has a company on file, and none of the '
            .'three orders carries a `_billing_company`',
        'addresses.line2' => 'no address in the harness shop has a second line',

        /* ── the variations the harness priced and photographed simply ────── */
        'product_variants.sale_price' => 'neither harness variation carries a `_sale_price`',
        'product_variants.stock' => 'both harness variations carry `_stock_status` and no `_stock` count',
        'product_variants.image' => 'neither harness variation has its own `_thumbnail_id`',
    ];
}

it('finds a real value in every column the census says things land in', function () {
    /*
     * ── WHY THE "IS IT READ" CHECK ABOVE IS NOT ENOUGH ON ITS OWN ──────────
     *
     * MUTATION THAT SURVIVED, and the reason this test exists. Deleting
     * `'sku' => $row->text('sku'),` from ProductImporter's apply() left the
     * census comparison GREEN: ProductImporter asks for `sku` in a SECOND
     * place, the duplicate-SKU check, so the column was still READ and never
     * appeared in the ignored-column line. The SKU simply stopped arriving.
     *
     * "Read" and "landed" are different claims and only the second one is what
     * the owner asked about. So this asserts the second: after a real import of
     * the plugin's own export, every table.column the census names holds a
     * value on at least one IMPORTED row.
     *
     * Re-run with the same mutation: red, naming products.sku.
     */
    gqImport();

    $missing = [];
    $exempt = gqEmptyInThisFixture();
    $markers = gqImportedRowMarker();

    foreach (gqCensus() as $file => $entry) {
        foreach ($entry['columns'] as $column => $verdict) {
            if (! str_starts_with($verdict, GQ_LANDS)) {
                continue;
            }

            foreach (explode(' + ', substr($verdict, strlen(GQ_LANDS))) as $destination) {
                $destination = trim($destination);
                [$table, $target] = explode('.', $destination, 2);

                if (isset($exempt[$destination])) {
                    continue;
                }

                expect($markers)->toHaveKey($table);

                $query = DB::table($table)->whereNotNull($target)->where($target, '!=', '');

                if ($markers[$table] !== null) {
                    $query->whereNotNull($markers[$table]);
                }

                if ($query->count() === 0) {
                    $missing[] = $destination.'  (from '.$file.' `'.$column.'`)';
                }
            }
        }
    }

    $missing = array_values(array_unique($missing));
    sort($missing);

    expect($missing)->toBe(
        [],
        'these columns are named in the census as where something lands, and after a full import of the '
        ."plugin's own export not one imported row has a value in them. Either the column has stopped "
        .'crossing, or the fixture genuinely cannot exercise it — and the second case belongs in '
        .'gqEmptyInThisFixture() with the row of the harness shop that would have to change.'
    );
});

<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Brand;
use App\Models\Category;
use App\Models\GridSection;
use App\Models\Post;
use App\Services\GridSections;
use App\Services\HomepageContent;
use App\Services\ModuleSchema;
use App\Services\SettingsService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * The homepage, section by section — master plan row 55.          (Lane HA)
 *
 * THE OWNER, 3 October 2026: "please keept noting all sections ... i really
 * need super beautiful this homepage", in this order: the picture banner,
 * 1 Big savings bundles (HomeBundles, 2.60.370), 2 Best Sellers, 3 Brands,
 * 4 #KBeautyBliss Spotted (Lane HB's partial), 5 Trending, 6 Blog, 7 Under
 * AED 54, 8 a two-column feature, 9 About us — "don't include anything from
 * our existing homepage ... except banner".
 *
 * This class is the pattern HomeBundles set, applied seven times: every value
 * printed into a class or a style is built HERE from a select's own option
 * keys, never from a string an owner typed; every URL is scheme-checked here;
 * the words are escaped by the template. The controls are Appearance →
 * Homepage content → one tab per section (HomepageContent::TABS, which spreads
 * this class's TABS into its own), so the existing screen and the existing
 * endpoint draw, validate and save them — no new admin route.
 *
 * ── WHAT IS REUSED, AND WHAT IS NOT REWRITTEN ───────────────────────────────
 *
 *   · the CARD is `<x-product-card>` through partials/home/grid.blade.php, at
 *     the shop's own grid skin (GridSkins::resolve(null)) — "the grid cards
 *     design must not be changed". Nothing here styles a card.
 *   · the PRODUCT QUERIES are GridSections::pool() — Appearance → Grid
 *     sections' own sources, tie-breaks and eager load, extended by one
 *     source (`trending`) and one filter (the price ceiling) rather than
 *     copied.
 *   · the per-device on/off is Appearance → Homepage's Desktop/Mobile
 *     switches on each section's row, which every section already has.
 *
 * ── COST ────────────────────────────────────────────────────────────────────
 *
 * ONE cache entry for everything the seven sections read (three product
 * rails, the brands, the posts and the Sunscreens address), keyed by a digest
 * of the settings that change WHICH rows come back — so saving a control
 * builds a new entry and nothing has to remember to evict it. A warm homepage
 * pays nothing (StorefrontQueryBudgetTest); a cold one pays two queries per
 * rail (products + the brand eager load), two for trending's scores, one for
 * brands, one for posts and one for the category.
 */
final class HomeSections
{
    /** Section key (HomepageSections::REGISTRY) => setting prefix, for the three product rails. */
    public const RAILS = ['bestselling' => 'bs', 'trending' => 'tr', 'under54' => 'u54'];

    /** Under AED 54's button (2.60.385): the shop's own budget collection. */
    public const U54_BTN = 'Shop all under AED 54';

    public const U54_URL = '/everything-under-54-aed';

    /** The four bands home.html offered; a select's own keys, so a class name can only be one of these. */
    public const BACKGROUNDS = ['none', 'blush', 'cream', 'lilac'];

    /**
     * The owner's four About us paragraphs, VERBATIM from master plan row 55,
     * separated by a blank line — each becomes its own <p>.
     */
    public const ABOUT_DEFAULT = "At K-Beauty Bliss UAE, we are passionate about bringing the best of Korean beauty to skincare enthusiasts across the UAE. Our mission is to provide you with authentic, high-quality K-beauty products that deliver real results. Whether you’re looking to enhance your skincare routine, discover the latest beauty trends, or find effective solutions for your skin concerns, we’ve got you covered.\n\n"
        ."We carefully curate our product range, ensuring that every item fulfills the highest standards of quality and effectiveness. From skincare and hair care to makeup and beauty sets, we offer a wide variety of products created to cater to all your beauty needs.\n\n"
        ."Customer satisfaction is at the heart of everything we do. With fast delivery options, including 1-3 day delivery across the UAE and free shipping on orders over 199 AED, we make it easy and convenient for you to get the products you love.\n\n"
        ."Join the K-Beauty Bliss community and experience the beauty revolution that’s taking the world by storm. Your journey to flawless, radiant skin starts here!";

    /** The live shop's Sunscreens category (/product-category/sunscreens/ on the old WooCommerce site). */
    public const SUNSCREENS_SLUG = 'sunscreens';

    public const SUNSCREENS_FALLBACK = '/collections/sunscreens/';

    private const TTL = 600;

    /** Bumped by flush(); part of every entry's key. */
    private const GENERATION = 'kbb.home.hs.gen';

    /** The arrow inside the pill button — the same glyph the All sets button draws. A constant, so printing it raw is safe. */
    public const ARROW = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>';

    /** The About us controls; spread into HomepageContent::SCHEMA directly after `about_text`. */
    public const ABOUT_SCHEMA = [
        'home_ab_eyebrow' => ['type' => 'text', 'label' => 'Small line above the heading', 'default' => 'Our story', 'store' => 'setting', 'help' => 'Empty draws none.'],
        'home_ab_title' => ['type' => 'text', 'label' => 'Heading (H2)', 'default' => 'About K-Beauty Bliss UAE', 'store' => 'setting', 'help' => 'Empty hides the heading.'],
        // (Lane PF) The owner, 4 October: "i want a read more faded functionality
        // which we have on product page on short and long description need the
        // same. also in mobile." On, AT HIS REQUEST; the whole text stays in the
        // page for search engines — only its height is clamped.
        'home_ab_more' => ['type' => 'bool', 'label' => 'Read more', 'default' => true, 'store' => 'setting', 'help' => 'Shows the first lines, fading out, with “Read more ↓” — the product page’s description toggle. Off: the whole text, always open.'],
        'home_ab_clamp_d' => ['type' => 'select', 'label' => 'Text shown before Read more · laptop', 'default' => '140', 'store' => 'setting', 'options' => ['90' => '90px', '104' => '104px', '120' => '120px', '140' => '140px', '160' => '160px', '180' => '180px', '200' => '200px', '240' => '240px'], 'help' => 'The height of the text shown closed. 140px is about five lines.'],
        'home_ab_clamp_m' => ['type' => 'select', 'label' => 'Text shown before Read more · phone', 'default' => '200', 'store' => 'setting', 'options' => ['104' => '104px', '120' => '120px', '140' => '140px', '160' => '160px', '180' => '180px', '200' => '200px', '240' => '240px', '280' => '280px'], 'help' => '900px and narrower. 200px is about eight lines.'],
        'home_ab_bg' => ['type' => 'select', 'label' => 'Section background', 'default' => 'none', 'store' => 'setting', 'options' => ['none' => 'None — the page shows through', 'blush' => 'Blush — soft pink band', 'cream' => 'Cream — warm white band', 'lilac' => 'Lilac — pink-to-lilac band'], 'help' => ''],
        'home_ab_pt_d' => ['type' => 'select', 'label' => 'Space above · laptop', 'default' => '40', 'store' => 'setting', 'options' => ['0' => '0px', '4' => '4px', '8' => '8px', '12' => '12px', '16' => '16px', '20' => '20px', '24' => '24px', '28' => '28px', '32' => '32px', '40' => '40px', '48' => '48px', '56' => '56px', '64' => '64px', '72' => '72px', '80' => '80px', '96' => '96px'], 'help' => ''],
        'home_ab_pt_m' => ['type' => 'select', 'label' => 'Space above · phone', 'default' => '28', 'store' => 'setting', 'options' => ['0' => '0px', '4' => '4px', '8' => '8px', '12' => '12px', '16' => '16px', '20' => '20px', '24' => '24px', '28' => '28px', '32' => '32px', '40' => '40px', '48' => '48px', '56' => '56px', '64' => '64px', '72' => '72px', '80' => '80px', '96' => '96px'], 'help' => ''],
        'home_ab_pb_d' => ['type' => 'select', 'label' => 'Space below · laptop', 'default' => '40', 'store' => 'setting', 'options' => ['0' => '0px', '4' => '4px', '8' => '8px', '12' => '12px', '16' => '16px', '20' => '20px', '24' => '24px', '28' => '28px', '32' => '32px', '40' => '40px', '48' => '48px', '56' => '56px', '64' => '64px', '72' => '72px', '80' => '80px', '96' => '96px'], 'help' => ''],
        'home_ab_pb_m' => ['type' => 'select', 'label' => 'Space below · phone', 'default' => '28', 'store' => 'setting', 'options' => ['0' => '0px', '4' => '4px', '8' => '8px', '12' => '12px', '16' => '16px', '20' => '20px', '24' => '24px', '28' => '28px', '32' => '32px', '40' => '40px', '48' => '48px', '56' => '56px', '64' => '64px', '72' => '72px', '80' => '80px', '96' => '96px'], 'help' => ''],
    ];

    /**
     * Every other control, one block per section in page order. Generated
     * from one list of shapes (storage/ha-logs/gen_schema.py in Lane HA's
     * worktree), which is why each section's controls read the same way.
     */
    public const SCHEMA = [
        'home_bs_eyebrow' => ['type' => 'text', 'label' => 'Small line above the heading', 'default' => 'Loved most', 'store' => 'setting', 'help' => 'Empty draws none.'],
        'home_bs_title' => ['type' => 'text', 'label' => 'Heading (H2)', 'default' => 'Best-Selling Korean Skincare in the UAE', 'store' => 'setting', 'help' => 'Written for search. Empty hides the heading — keep one: every section should carry an H2.'],
        'home_bs_sub' => ['type' => 'text', 'label' => 'Line under the heading', 'default' => 'The Korean skincare our customers buy most, ranked by units sold.', 'store' => 'setting', 'help' => 'Empty draws none.'],
        'home_bs_source' => ['type' => 'select', 'label' => 'Which products', 'default' => 'bestsellers', 'store' => 'setting', 'options' => GridSection::SOURCES, 'help' => 'Trending = most ordered (×3) plus most viewed in the last 7 days; a quiet week falls back to best sellers.'],
        'home_bs_cat' => ['type' => 'ids', 'label' => 'Category (when “One category”)', 'default' => '', 'store' => 'setting', 'options' => ['cap' => '1', 'of' => 'categories'], 'help' => ''],
        'home_bs_brand' => ['type' => 'ids', 'label' => 'Brand (when “One brand”)', 'default' => '', 'store' => 'setting', 'options' => ['cap' => '1', 'of' => 'brands'], 'help' => ''],
        'home_bs_picks' => ['type' => 'ids', 'label' => 'Products, in order (when “A list I pick myself”)', 'default' => '', 'store' => 'setting', 'options' => ['cap' => '24', 'of' => 'products'], 'help' => 'Pick them and use ↑ ↓ to order.'],
        'home_bs_brands' => ['type' => 'ids', 'label' => 'Brands (when “Brands and categories”)', 'default' => '', 'store' => 'setting', 'options' => ['cap' => '20', 'of' => 'brands'], 'help' => 'Any of these. None picked: every brand.'],
        'home_bs_cats' => ['type' => 'ids', 'label' => 'Categories (when “Brands and categories”)', 'default' => '', 'store' => 'setting', 'options' => ['cap' => '20', 'of' => 'categories'], 'help' => 'Any of these — mix as many as you like. None picked: every category.'],
        'home_bs_sort' => ['type' => 'select', 'label' => 'Order (when “Brands and categories”)', 'default' => 'bestselling', 'store' => 'setting', 'options' => ProductSource::SORTS, 'help' => ''],
        'home_bs_stock' => ['type' => 'bool', 'label' => 'In stock only (when “Brands and categories”)', 'default' => false, 'store' => 'setting', 'help' => ''],
        'home_bs_max' => ['type' => 'select', 'label' => 'Price ceiling', 'default' => '0', 'store' => 'setting', 'options' => ['0' => 'No ceiling', '25' => 'AED 25 or less', '30' => 'AED 30 or less', '35' => 'AED 35 or less', '39' => 'AED 39 or less', '40' => 'AED 40 or less', '45' => 'AED 45 or less', '49' => 'AED 49 or less', '50' => 'AED 50 or less', '54' => 'AED 54 or less', '59' => 'AED 59 or less', '60' => 'AED 60 or less', '69' => 'AED 69 or less', '75' => 'AED 75 or less', '79' => 'AED 79 or less', '89' => 'AED 89 or less', '99' => 'AED 99 or less', '100' => 'AED 100 or less', '149' => 'AED 149 or less', '150' => 'AED 150 or less', '199' => 'AED 199 or less', '200' => 'AED 200 or less'], 'help' => 'Only products the shopper can buy at or under this price — the sale price while a sale runs, the cheapest option of a product with options.'],
        'home_bs_count_d' => ['type' => 'select', 'label' => 'How many · laptop', 'default' => '8', 'store' => 'setting', 'options' => ['2' => '2', '3' => '3', '4' => '4', '5' => '5', '6' => '6', '7' => '7', '8' => '8', '9' => '9', '10' => '10', '12' => '12', '15' => '15', '16' => '16', '20' => '20'], 'help' => ''],
        'home_bs_count_m' => ['type' => 'select', 'label' => 'How many · phone', 'default' => '6', 'store' => 'setting', 'options' => ['2' => '2', '3' => '3', '4' => '4', '5' => '5', '6' => '6', '7' => '7', '8' => '8', '9' => '9', '10' => '10', '12' => '12', '15' => '15', '16' => '16', '20' => '20'], 'help' => ''],
        'home_bs_cols_d' => ['type' => 'select', 'label' => 'Per row · laptop', 'default' => '4', 'store' => 'setting', 'options' => ['2' => '2', '3' => '3', '4' => '4', '5' => '5', '6' => '6'], 'help' => ''],
        'home_bs_cols_m' => ['type' => 'select', 'label' => 'Per row · phone', 'default' => '2', 'store' => 'setting', 'options' => ['1' => '1', '2' => '2', '3' => '3'], 'help' => ''],
        'home_bs_btn' => ['type' => 'text', 'label' => 'Button text', 'default' => 'Shop best sellers', 'store' => 'setting', 'help' => 'Empty: no button.'],
        'home_bs_url' => ['type' => 'text', 'label' => 'Button link', 'default' => '/best-sellers', 'store' => 'setting', 'help' => 'A path on this shop (/…) or a full https:// address; anything else is ignored.'],
        'home_bs_bg' => ['type' => 'select', 'label' => 'Section background', 'default' => 'none', 'store' => 'setting', 'options' => ['none' => 'None — the page shows through', 'blush' => 'Blush — soft pink band', 'cream' => 'Cream — warm white band', 'lilac' => 'Lilac — pink-to-lilac band'], 'help' => ''],
        'home_bs_pt_d' => ['type' => 'select', 'label' => 'Space above · laptop', 'default' => '40', 'store' => 'setting', 'options' => ['0' => '0px', '4' => '4px', '8' => '8px', '12' => '12px', '16' => '16px', '20' => '20px', '24' => '24px', '28' => '28px', '32' => '32px', '40' => '40px', '48' => '48px', '56' => '56px', '64' => '64px', '72' => '72px', '80' => '80px', '96' => '96px'], 'help' => ''],
        'home_bs_pt_m' => ['type' => 'select', 'label' => 'Space above · phone', 'default' => '28', 'store' => 'setting', 'options' => ['0' => '0px', '4' => '4px', '8' => '8px', '12' => '12px', '16' => '16px', '20' => '20px', '24' => '24px', '28' => '28px', '32' => '32px', '40' => '40px', '48' => '48px', '56' => '56px', '64' => '64px', '72' => '72px', '80' => '80px', '96' => '96px'], 'help' => ''],
        'home_bs_pb_d' => ['type' => 'select', 'label' => 'Space below · laptop', 'default' => '40', 'store' => 'setting', 'options' => ['0' => '0px', '4' => '4px', '8' => '8px', '12' => '12px', '16' => '16px', '20' => '20px', '24' => '24px', '28' => '28px', '32' => '32px', '40' => '40px', '48' => '48px', '56' => '56px', '64' => '64px', '72' => '72px', '80' => '80px', '96' => '96px'], 'help' => ''],
        'home_bs_pb_m' => ['type' => 'select', 'label' => 'Space below · phone', 'default' => '28', 'store' => 'setting', 'options' => ['0' => '0px', '4' => '4px', '8' => '8px', '12' => '12px', '16' => '16px', '20' => '20px', '24' => '24px', '28' => '28px', '32' => '32px', '40' => '40px', '48' => '48px', '56' => '56px', '64' => '64px', '72' => '72px', '80' => '80px', '96' => '96px'], 'help' => ''],
        'home_bs_hg_d' => ['type' => 'select', 'label' => 'Space under the heading · laptop', 'default' => '28', 'store' => 'setting', 'options' => ['0' => '0px', '4' => '4px', '8' => '8px', '12' => '12px', '16' => '16px', '20' => '20px', '24' => '24px', '28' => '28px', '32' => '32px', '40' => '40px', '48' => '48px', '56' => '56px', '64' => '64px', '72' => '72px', '80' => '80px', '96' => '96px'], 'help' => ''],
        'home_bs_hg_m' => ['type' => 'select', 'label' => 'Space under the heading · phone', 'default' => '16', 'store' => 'setting', 'options' => ['0' => '0px', '4' => '4px', '8' => '8px', '12' => '12px', '16' => '16px', '20' => '20px', '24' => '24px', '28' => '28px', '32' => '32px', '40' => '40px', '48' => '48px', '56' => '56px', '64' => '64px', '72' => '72px', '80' => '80px', '96' => '96px'], 'help' => ''],
        'home_br_eyebrow' => ['type' => 'text', 'label' => 'Small line above the heading', 'default' => 'Brands we carry', 'store' => 'setting', 'help' => 'Empty draws none.'],
        'home_br_title' => ['type' => 'text', 'label' => 'Heading (H2)', 'default' => 'Shop Top Korean Beauty Brands', 'store' => 'setting', 'help' => 'Written for search. Empty hides the heading — keep one: every section should carry an H2.'],
        'home_br_sub' => ['type' => 'text', 'label' => 'Line under the heading', 'default' => 'Browse the Korean beauty brands we stock and shop each brand’s full range in one place.', 'store' => 'setting', 'help' => 'Empty draws none.'],
        'home_br_picks' => ['type' => 'ids', 'label' => 'Brands, in order', 'default' => '', 'store' => 'setting', 'options' => ['cap' => '24', 'of' => 'brands'], 'help' => 'Empty: the brands with the most products. Pick them and use ↑ ↓ to set the order.'],
        // Lane BS: a picture of the owner's choosing per brand, one setting
        // (a JSON map brand id → path), so the shop reads it with the rest of
        // the homepage's settings and no extra query. Drawn on the Brands tab
        // beside each picked brand (options.for), not as a box of its own.
        'home_br_imgs' => ['type' => 'text', 'label' => 'Brand images', 'default' => '', 'store' => 'setting', 'options' => ['picker' => 'per-pick', 'for' => 'home_br_picks'], 'rule' => [self::class, 'cleanBrandImages'], 'help' => 'Choose from the Media Library beside each brand. The laptop card always shows it; a phone shows it when Look · phone is Logo + image or Image only. None: the brand’s banner photo, as before.'],
        'home_br_count_d' => ['type' => 'select', 'label' => 'How many · laptop', 'default' => '14', 'store' => 'setting', 'options' => ['4' => '4', '5' => '5', '6' => '6', '7' => '7', '8' => '8', '9' => '9', '10' => '10', '12' => '12', '14' => '14', '15' => '15', '16' => '16', '18' => '18', '20' => '20', '21' => '21', '24' => '24'], 'help' => ''],
        'home_br_count_m' => ['type' => 'select', 'label' => 'How many · phone', 'default' => '10', 'store' => 'setting', 'options' => ['4' => '4', '5' => '5', '6' => '6', '7' => '7', '8' => '8', '9' => '9', '10' => '10', '12' => '12', '14' => '14', '15' => '15', '16' => '16', '18' => '18', '20' => '20', '21' => '21', '24' => '24'], 'help' => ''],
        'home_br_cols_d' => ['type' => 'select', 'label' => 'Per row · laptop', 'default' => '7', 'store' => 'setting', 'options' => ['4' => '4', '5' => '5', '6' => '6', '7' => '7', '8' => '8'], 'help' => 'Photo cards with the name on a frosted label.'],
        'home_br_cols_m' => ['type' => 'select', 'label' => 'Per row · phone', 'default' => '2', 'store' => 'setting', 'options' => ['2' => '2', '3' => '3', '4' => '4'], 'help' => 'Name tiles on Look · phone Text only; image cards on Logo + image and Image only.'],
        // (Lane PF) The owner, 4 October: "the brands boxes need to be little bit
        // squeezed, reduce the height" — 74px to 54px, AT HIS REQUEST.
        'home_br_th_m' => ['type' => 'select', 'label' => 'Tile height · phone', 'default' => '54', 'store' => 'setting', 'options' => ['44' => '44px', '48' => '48px', '52' => '52px', '54' => '54px', '56' => '56px', '60' => '60px', '64' => '64px', '70' => '70px', '74' => '74px — the previous height'], 'help' => 'The name tiles on a phone (Look · phone: Text only). The name stays centred.'],
        // Lane BS, 5 October — both shipped at what the owner asked for: "for
        // desktop image + name, no logo!" and, on a phone, "keep by default
        // only text". The options keep the previous looks one select away.
        'home_br_layout_d' => ['type' => 'select', 'label' => 'Look · laptop', 'default' => 'image', 'store' => 'setting', 'options' => ['image' => 'Image + name — no logo', 'image_logo' => 'Image + name — a brand with no image shows its logo (the previous look)'], 'help' => 'The image is the one chosen on the Brands tab, else the brand’s banner photo, else a soft colour panel.'],
        'home_br_layout_m' => ['type' => 'select', 'label' => 'Look · phone', 'default' => 'text', 'store' => 'setting', 'options' => ['logo_image' => 'Logo + image', 'image' => 'Image only', 'text' => 'Text only'], 'help' => 'Text only: the brand names as light tiles, and the phone downloads no brand pictures at all.'],
        'home_br_btn' => ['type' => 'text', 'label' => 'Button text', 'default' => 'Shop all brands', 'store' => 'setting', 'help' => 'Empty: no button.'],
        'home_br_url' => ['type' => 'text', 'label' => 'Button link', 'default' => '/brands/', 'store' => 'setting', 'help' => 'A path on this shop (/…) or a full https:// address; anything else is ignored.'],
        'home_br_bg' => ['type' => 'select', 'label' => 'Section background', 'default' => 'cream', 'store' => 'setting', 'options' => ['none' => 'None — the page shows through', 'blush' => 'Blush — soft pink band', 'cream' => 'Cream — warm white band', 'lilac' => 'Lilac — pink-to-lilac band'], 'help' => ''],
        'home_br_pt_d' => ['type' => 'select', 'label' => 'Space above · laptop', 'default' => '40', 'store' => 'setting', 'options' => ['0' => '0px', '4' => '4px', '8' => '8px', '12' => '12px', '16' => '16px', '20' => '20px', '24' => '24px', '28' => '28px', '32' => '32px', '40' => '40px', '48' => '48px', '56' => '56px', '64' => '64px', '72' => '72px', '80' => '80px', '96' => '96px'], 'help' => ''],
        'home_br_pt_m' => ['type' => 'select', 'label' => 'Space above · phone', 'default' => '28', 'store' => 'setting', 'options' => ['0' => '0px', '4' => '4px', '8' => '8px', '12' => '12px', '16' => '16px', '20' => '20px', '24' => '24px', '28' => '28px', '32' => '32px', '40' => '40px', '48' => '48px', '56' => '56px', '64' => '64px', '72' => '72px', '80' => '80px', '96' => '96px'], 'help' => ''],
        'home_br_pb_d' => ['type' => 'select', 'label' => 'Space below · laptop', 'default' => '40', 'store' => 'setting', 'options' => ['0' => '0px', '4' => '4px', '8' => '8px', '12' => '12px', '16' => '16px', '20' => '20px', '24' => '24px', '28' => '28px', '32' => '32px', '40' => '40px', '48' => '48px', '56' => '56px', '64' => '64px', '72' => '72px', '80' => '80px', '96' => '96px'], 'help' => ''],
        'home_br_pb_m' => ['type' => 'select', 'label' => 'Space below · phone', 'default' => '28', 'store' => 'setting', 'options' => ['0' => '0px', '4' => '4px', '8' => '8px', '12' => '12px', '16' => '16px', '20' => '20px', '24' => '24px', '28' => '28px', '32' => '32px', '40' => '40px', '48' => '48px', '56' => '56px', '64' => '64px', '72' => '72px', '80' => '80px', '96' => '96px'], 'help' => ''],
        'home_br_hg_d' => ['type' => 'select', 'label' => 'Space under the heading · laptop', 'default' => '28', 'store' => 'setting', 'options' => ['0' => '0px', '4' => '4px', '8' => '8px', '12' => '12px', '16' => '16px', '20' => '20px', '24' => '24px', '28' => '28px', '32' => '32px', '40' => '40px', '48' => '48px', '56' => '56px', '64' => '64px', '72' => '72px', '80' => '80px', '96' => '96px'], 'help' => ''],
        'home_br_hg_m' => ['type' => 'select', 'label' => 'Space under the heading · phone', 'default' => '16', 'store' => 'setting', 'options' => ['0' => '0px', '4' => '4px', '8' => '8px', '12' => '12px', '16' => '16px', '20' => '20px', '24' => '24px', '28' => '28px', '32' => '32px', '40' => '40px', '48' => '48px', '56' => '56px', '64' => '64px', '72' => '72px', '80' => '80px', '96' => '96px'], 'help' => ''],
        'home_tr_eyebrow' => ['type' => 'text', 'label' => 'Small line above the heading', 'default' => 'Hot this week', 'store' => 'setting', 'help' => 'Empty draws none.'],
        'home_tr_title' => ['type' => 'text', 'label' => 'Heading (H2)', 'default' => 'Trending K-Beauty This Week', 'store' => 'setting', 'help' => 'Written for search. Empty hides the heading — keep one: every section should carry an H2.'],
        'home_tr_sub' => ['type' => 'text', 'label' => 'Line under the heading', 'default' => 'The K-beauty products ordered and viewed most on our shop over the past seven days.', 'store' => 'setting', 'help' => 'Empty draws none.'],
        'home_tr_source' => ['type' => 'select', 'label' => 'Which products', 'default' => 'trending', 'store' => 'setting', 'options' => GridSection::SOURCES, 'help' => 'Trending = most ordered (×3) plus most viewed in the last 7 days; a quiet week falls back to best sellers.'],
        'home_tr_cat' => ['type' => 'ids', 'label' => 'Category (when “One category”)', 'default' => '', 'store' => 'setting', 'options' => ['cap' => '1', 'of' => 'categories'], 'help' => ''],
        'home_tr_brand' => ['type' => 'ids', 'label' => 'Brand (when “One brand”)', 'default' => '', 'store' => 'setting', 'options' => ['cap' => '1', 'of' => 'brands'], 'help' => ''],
        'home_tr_picks' => ['type' => 'ids', 'label' => 'Products, in order (when “A list I pick myself”)', 'default' => '', 'store' => 'setting', 'options' => ['cap' => '24', 'of' => 'products'], 'help' => 'Pick them and use ↑ ↓ to order.'],
        'home_tr_brands' => ['type' => 'ids', 'label' => 'Brands (when “Brands and categories”)', 'default' => '', 'store' => 'setting', 'options' => ['cap' => '20', 'of' => 'brands'], 'help' => 'Any of these. None picked: every brand.'],
        'home_tr_cats' => ['type' => 'ids', 'label' => 'Categories (when “Brands and categories”)', 'default' => '', 'store' => 'setting', 'options' => ['cap' => '20', 'of' => 'categories'], 'help' => 'Any of these — mix as many as you like. None picked: every category.'],
        'home_tr_sort' => ['type' => 'select', 'label' => 'Order (when “Brands and categories”)', 'default' => 'bestselling', 'store' => 'setting', 'options' => ProductSource::SORTS, 'help' => ''],
        'home_tr_stock' => ['type' => 'bool', 'label' => 'In stock only (when “Brands and categories”)', 'default' => false, 'store' => 'setting', 'help' => ''],
        'home_tr_max' => ['type' => 'select', 'label' => 'Price ceiling', 'default' => '0', 'store' => 'setting', 'options' => ['0' => 'No ceiling', '25' => 'AED 25 or less', '30' => 'AED 30 or less', '35' => 'AED 35 or less', '39' => 'AED 39 or less', '40' => 'AED 40 or less', '45' => 'AED 45 or less', '49' => 'AED 49 or less', '50' => 'AED 50 or less', '54' => 'AED 54 or less', '59' => 'AED 59 or less', '60' => 'AED 60 or less', '69' => 'AED 69 or less', '75' => 'AED 75 or less', '79' => 'AED 79 or less', '89' => 'AED 89 or less', '99' => 'AED 99 or less', '100' => 'AED 100 or less', '149' => 'AED 149 or less', '150' => 'AED 150 or less', '199' => 'AED 199 or less', '200' => 'AED 200 or less'], 'help' => 'Only products the shopper can buy at or under this price — the sale price while a sale runs, the cheapest option of a product with options.'],
        'home_tr_count_d' => ['type' => 'select', 'label' => 'How many · laptop', 'default' => '8', 'store' => 'setting', 'options' => ['2' => '2', '3' => '3', '4' => '4', '5' => '5', '6' => '6', '7' => '7', '8' => '8', '9' => '9', '10' => '10', '12' => '12', '15' => '15', '16' => '16', '20' => '20'], 'help' => ''],
        'home_tr_count_m' => ['type' => 'select', 'label' => 'How many · phone', 'default' => '6', 'store' => 'setting', 'options' => ['2' => '2', '3' => '3', '4' => '4', '5' => '5', '6' => '6', '7' => '7', '8' => '8', '9' => '9', '10' => '10', '12' => '12', '15' => '15', '16' => '16', '20' => '20'], 'help' => ''],
        'home_tr_cols_d' => ['type' => 'select', 'label' => 'Per row · laptop', 'default' => '4', 'store' => 'setting', 'options' => ['2' => '2', '3' => '3', '4' => '4', '5' => '5', '6' => '6'], 'help' => ''],
        'home_tr_cols_m' => ['type' => 'select', 'label' => 'Per row · phone', 'default' => '2', 'store' => 'setting', 'options' => ['1' => '1', '2' => '2', '3' => '3'], 'help' => ''],
        'home_tr_btn' => ['type' => 'text', 'label' => 'Button text', 'default' => '', 'store' => 'setting', 'help' => 'Empty: no button.'],
        'home_tr_url' => ['type' => 'text', 'label' => 'Button link', 'default' => '', 'store' => 'setting', 'help' => 'A path on this shop (/…) or a full https:// address; anything else is ignored.'],
        'home_tr_bg' => ['type' => 'select', 'label' => 'Section background', 'default' => 'none', 'store' => 'setting', 'options' => ['none' => 'None — the page shows through', 'blush' => 'Blush — soft pink band', 'cream' => 'Cream — warm white band', 'lilac' => 'Lilac — pink-to-lilac band'], 'help' => ''],
        'home_tr_pt_d' => ['type' => 'select', 'label' => 'Space above · laptop', 'default' => '40', 'store' => 'setting', 'options' => ['0' => '0px', '4' => '4px', '8' => '8px', '12' => '12px', '16' => '16px', '20' => '20px', '24' => '24px', '28' => '28px', '32' => '32px', '40' => '40px', '48' => '48px', '56' => '56px', '64' => '64px', '72' => '72px', '80' => '80px', '96' => '96px'], 'help' => ''],
        'home_tr_pt_m' => ['type' => 'select', 'label' => 'Space above · phone', 'default' => '28', 'store' => 'setting', 'options' => ['0' => '0px', '4' => '4px', '8' => '8px', '12' => '12px', '16' => '16px', '20' => '20px', '24' => '24px', '28' => '28px', '32' => '32px', '40' => '40px', '48' => '48px', '56' => '56px', '64' => '64px', '72' => '72px', '80' => '80px', '96' => '96px'], 'help' => ''],
        'home_tr_pb_d' => ['type' => 'select', 'label' => 'Space below · laptop', 'default' => '40', 'store' => 'setting', 'options' => ['0' => '0px', '4' => '4px', '8' => '8px', '12' => '12px', '16' => '16px', '20' => '20px', '24' => '24px', '28' => '28px', '32' => '32px', '40' => '40px', '48' => '48px', '56' => '56px', '64' => '64px', '72' => '72px', '80' => '80px', '96' => '96px'], 'help' => ''],
        'home_tr_pb_m' => ['type' => 'select', 'label' => 'Space below · phone', 'default' => '28', 'store' => 'setting', 'options' => ['0' => '0px', '4' => '4px', '8' => '8px', '12' => '12px', '16' => '16px', '20' => '20px', '24' => '24px', '28' => '28px', '32' => '32px', '40' => '40px', '48' => '48px', '56' => '56px', '64' => '64px', '72' => '72px', '80' => '80px', '96' => '96px'], 'help' => ''],
        'home_tr_hg_d' => ['type' => 'select', 'label' => 'Space under the heading · laptop', 'default' => '28', 'store' => 'setting', 'options' => ['0' => '0px', '4' => '4px', '8' => '8px', '12' => '12px', '16' => '16px', '20' => '20px', '24' => '24px', '28' => '28px', '32' => '32px', '40' => '40px', '48' => '48px', '56' => '56px', '64' => '64px', '72' => '72px', '80' => '80px', '96' => '96px'], 'help' => ''],
        'home_tr_hg_m' => ['type' => 'select', 'label' => 'Space under the heading · phone', 'default' => '16', 'store' => 'setting', 'options' => ['0' => '0px', '4' => '4px', '8' => '8px', '12' => '12px', '16' => '16px', '20' => '20px', '24' => '24px', '28' => '28px', '32' => '32px', '40' => '40px', '48' => '48px', '56' => '56px', '64' => '64px', '72' => '72px', '80' => '80px', '96' => '96px'], 'help' => ''],
        'home_bl_eyebrow' => ['type' => 'text', 'label' => 'Small line above the heading', 'default' => 'The journal', 'store' => 'setting', 'help' => 'Empty draws none.'],
        'home_bl_title' => ['type' => 'text', 'label' => 'Heading (H2)', 'default' => 'Korean Skincare Tips & Guides', 'store' => 'setting', 'help' => 'Written for search. Empty hides the heading — keep one: every section should carry an H2.'],
        'home_bl_sub' => ['type' => 'text', 'label' => 'Line under the heading', 'default' => 'Fresh articles from the K-Beauty Bliss journal to help you choose and use Korean skincare.', 'store' => 'setting', 'help' => 'Empty draws none.'],
        'home_bl_source' => ['type' => 'select', 'label' => 'Which articles', 'default' => 'latest', 'store' => 'setting', 'options' => ['latest' => 'The latest three', 'manual' => 'Three I pick myself'], 'help' => ''],
        'home_bl_picks' => ['type' => 'ids', 'label' => 'Articles, in order (when “Three I pick myself”)', 'default' => '', 'store' => 'setting', 'options' => ['cap' => '3', 'of' => 'posts'], 'help' => ''],
        'home_bl_count_m' => ['type' => 'select', 'label' => 'How many · phone', 'default' => '2', 'store' => 'setting', 'options' => ['1' => '1', '2' => '2', '3' => '3'], 'help' => 'Stacked in one column.'],
        // (Lane HS) The owner, 4 October: "turn off the 6 min read, tag on blog
        // section on homepage". Both OFF, AT HIS REQUEST; the /blog/ page is
        // not this section and keeps both.
        'home_bl_read' => ['type' => 'bool', 'label' => 'Reading time', 'default' => false, 'store' => 'setting', 'help' => 'The “6 min read” line on each homepage card.'],
        'home_bl_tag' => ['type' => 'bool', 'label' => 'Category tag', 'default' => false, 'store' => 'setting', 'help' => 'The article’s category above its title on each homepage card. The blog page is not affected.'],
        'home_bl_more' => ['type' => 'text', 'label' => 'Link under each article', 'default' => 'Read the article', 'store' => 'setting', 'help' => 'Empty draws none; the whole card is still a link.'],
        'home_bl_btn' => ['type' => 'text', 'label' => 'Button text', 'default' => '', 'store' => 'setting', 'help' => 'Empty: no button.'],
        'home_bl_url' => ['type' => 'text', 'label' => 'Button link', 'default' => '/blog/', 'store' => 'setting', 'help' => 'A path on this shop (/…) or a full https:// address; anything else is ignored.'],
        'home_bl_bg' => ['type' => 'select', 'label' => 'Section background', 'default' => 'cream', 'store' => 'setting', 'options' => ['none' => 'None — the page shows through', 'blush' => 'Blush — soft pink band', 'cream' => 'Cream — warm white band', 'lilac' => 'Lilac — pink-to-lilac band'], 'help' => ''],
        'home_bl_pt_d' => ['type' => 'select', 'label' => 'Space above · laptop', 'default' => '40', 'store' => 'setting', 'options' => ['0' => '0px', '4' => '4px', '8' => '8px', '12' => '12px', '16' => '16px', '20' => '20px', '24' => '24px', '28' => '28px', '32' => '32px', '40' => '40px', '48' => '48px', '56' => '56px', '64' => '64px', '72' => '72px', '80' => '80px', '96' => '96px'], 'help' => ''],
        'home_bl_pt_m' => ['type' => 'select', 'label' => 'Space above · phone', 'default' => '28', 'store' => 'setting', 'options' => ['0' => '0px', '4' => '4px', '8' => '8px', '12' => '12px', '16' => '16px', '20' => '20px', '24' => '24px', '28' => '28px', '32' => '32px', '40' => '40px', '48' => '48px', '56' => '56px', '64' => '64px', '72' => '72px', '80' => '80px', '96' => '96px'], 'help' => ''],
        'home_bl_pb_d' => ['type' => 'select', 'label' => 'Space below · laptop', 'default' => '40', 'store' => 'setting', 'options' => ['0' => '0px', '4' => '4px', '8' => '8px', '12' => '12px', '16' => '16px', '20' => '20px', '24' => '24px', '28' => '28px', '32' => '32px', '40' => '40px', '48' => '48px', '56' => '56px', '64' => '64px', '72' => '72px', '80' => '80px', '96' => '96px'], 'help' => ''],
        'home_bl_pb_m' => ['type' => 'select', 'label' => 'Space below · phone', 'default' => '28', 'store' => 'setting', 'options' => ['0' => '0px', '4' => '4px', '8' => '8px', '12' => '12px', '16' => '16px', '20' => '20px', '24' => '24px', '28' => '28px', '32' => '32px', '40' => '40px', '48' => '48px', '56' => '56px', '64' => '64px', '72' => '72px', '80' => '80px', '96' => '96px'], 'help' => ''],
        'home_bl_hg_d' => ['type' => 'select', 'label' => 'Space under the heading · laptop', 'default' => '28', 'store' => 'setting', 'options' => ['0' => '0px', '4' => '4px', '8' => '8px', '12' => '12px', '16' => '16px', '20' => '20px', '24' => '24px', '28' => '28px', '32' => '32px', '40' => '40px', '48' => '48px', '56' => '56px', '64' => '64px', '72' => '72px', '80' => '80px', '96' => '96px'], 'help' => ''],
        'home_bl_hg_m' => ['type' => 'select', 'label' => 'Space under the heading · phone', 'default' => '16', 'store' => 'setting', 'options' => ['0' => '0px', '4' => '4px', '8' => '8px', '12' => '12px', '16' => '16px', '20' => '20px', '24' => '24px', '28' => '28px', '32' => '32px', '40' => '40px', '48' => '48px', '56' => '56px', '64' => '64px', '72' => '72px', '80' => '80px', '96' => '96px'], 'help' => ''],
        'home_u54_eyebrow' => ['type' => 'text', 'label' => 'Small line above the heading', 'default' => 'Little luxuries', 'store' => 'setting', 'help' => 'Empty draws none.'],
        'home_u54_title' => ['type' => 'text', 'label' => 'Heading (H2)', 'default' => 'K-Beauty Under AED 54', 'store' => 'setting', 'help' => 'Written for search. Empty hides the heading — keep one: every section should carry an H2.'],
        'home_u54_sub' => ['type' => 'text', 'label' => 'Line under the heading', 'default' => 'Korean skincare and beauty finds that each cost AED 54 or less.', 'store' => 'setting', 'help' => 'Empty draws none.'],
        'home_u54_source' => ['type' => 'select', 'label' => 'Which products', 'default' => 'bestsellers', 'store' => 'setting', 'options' => GridSection::SOURCES, 'help' => 'Trending = most ordered (×3) plus most viewed in the last 7 days; a quiet week falls back to best sellers.'],
        'home_u54_cat' => ['type' => 'ids', 'label' => 'Category (when “One category”)', 'default' => '', 'store' => 'setting', 'options' => ['cap' => '1', 'of' => 'categories'], 'help' => ''],
        'home_u54_brand' => ['type' => 'ids', 'label' => 'Brand (when “One brand”)', 'default' => '', 'store' => 'setting', 'options' => ['cap' => '1', 'of' => 'brands'], 'help' => ''],
        'home_u54_picks' => ['type' => 'ids', 'label' => 'Products, in order (when “A list I pick myself”)', 'default' => '', 'store' => 'setting', 'options' => ['cap' => '24', 'of' => 'products'], 'help' => 'Pick them and use ↑ ↓ to order.'],
        'home_u54_brands' => ['type' => 'ids', 'label' => 'Brands (when “Brands and categories”)', 'default' => '', 'store' => 'setting', 'options' => ['cap' => '20', 'of' => 'brands'], 'help' => 'Any of these. None picked: every brand.'],
        'home_u54_cats' => ['type' => 'ids', 'label' => 'Categories (when “Brands and categories”)', 'default' => '', 'store' => 'setting', 'options' => ['cap' => '20', 'of' => 'categories'], 'help' => 'Any of these — mix as many as you like. None picked: every category.'],
        'home_u54_sort' => ['type' => 'select', 'label' => 'Order (when “Brands and categories”)', 'default' => 'bestselling', 'store' => 'setting', 'options' => ProductSource::SORTS, 'help' => ''],
        'home_u54_stock' => ['type' => 'bool', 'label' => 'In stock only (when “Brands and categories”)', 'default' => false, 'store' => 'setting', 'help' => ''],
        'home_u54_max' => ['type' => 'select', 'label' => 'Price ceiling', 'default' => '54', 'store' => 'setting', 'options' => ['0' => 'No ceiling', '25' => 'AED 25 or less', '30' => 'AED 30 or less', '35' => 'AED 35 or less', '39' => 'AED 39 or less', '40' => 'AED 40 or less', '45' => 'AED 45 or less', '49' => 'AED 49 or less', '50' => 'AED 50 or less', '54' => 'AED 54 or less', '59' => 'AED 59 or less', '60' => 'AED 60 or less', '69' => 'AED 69 or less', '75' => 'AED 75 or less', '79' => 'AED 79 or less', '89' => 'AED 89 or less', '99' => 'AED 99 or less', '100' => 'AED 100 or less', '149' => 'AED 149 or less', '150' => 'AED 150 or less', '199' => 'AED 199 or less', '200' => 'AED 200 or less'], 'help' => 'Only products the shopper can buy at or under this price — the sale price while a sale runs, the cheapest option of a product with options.'],
        'home_u54_count_d' => ['type' => 'select', 'label' => 'How many · laptop', 'default' => '10', 'store' => 'setting', 'options' => ['2' => '2', '3' => '3', '4' => '4', '5' => '5', '6' => '6', '7' => '7', '8' => '8', '9' => '9', '10' => '10', '12' => '12', '15' => '15', '16' => '16', '20' => '20'], 'help' => ''],
        'home_u54_count_m' => ['type' => 'select', 'label' => 'How many · phone', 'default' => '6', 'store' => 'setting', 'options' => ['2' => '2', '3' => '3', '4' => '4', '5' => '5', '6' => '6', '7' => '7', '8' => '8', '9' => '9', '10' => '10', '12' => '12', '15' => '15', '16' => '16', '20' => '20'], 'help' => ''],
        'home_u54_cols_d' => ['type' => 'select', 'label' => 'Per row · laptop', 'default' => '5', 'store' => 'setting', 'options' => ['2' => '2', '3' => '3', '4' => '4', '5' => '5', '6' => '6'], 'help' => ''],
        'home_u54_cols_m' => ['type' => 'select', 'label' => 'Per row · phone', 'default' => '2', 'store' => 'setting', 'options' => ['1' => '1', '2' => '2', '3' => '3'], 'help' => ''],
        // (2.60.385) ON, at the owner's request: "on this section too on
        // homepage" — the button beside the centred heading. Empty either and
        // the button goes, as before.
        'home_u54_btn' => ['type' => 'text', 'label' => 'Button text', 'default' => self::U54_BTN, 'store' => 'setting', 'help' => 'Empty: no button.'],
        'home_u54_url' => ['type' => 'text', 'label' => 'Button link', 'default' => self::U54_URL, 'store' => 'setting', 'help' => 'A path on this shop (/…) or a full https:// address; anything else is ignored.'],
        'home_u54_bg' => ['type' => 'select', 'label' => 'Section background', 'default' => 'blush', 'store' => 'setting', 'options' => ['none' => 'None — the page shows through', 'blush' => 'Blush — soft pink band', 'cream' => 'Cream — warm white band', 'lilac' => 'Lilac — pink-to-lilac band'], 'help' => ''],
        'home_u54_pt_d' => ['type' => 'select', 'label' => 'Space above · laptop', 'default' => '40', 'store' => 'setting', 'options' => ['0' => '0px', '4' => '4px', '8' => '8px', '12' => '12px', '16' => '16px', '20' => '20px', '24' => '24px', '28' => '28px', '32' => '32px', '40' => '40px', '48' => '48px', '56' => '56px', '64' => '64px', '72' => '72px', '80' => '80px', '96' => '96px'], 'help' => ''],
        'home_u54_pt_m' => ['type' => 'select', 'label' => 'Space above · phone', 'default' => '28', 'store' => 'setting', 'options' => ['0' => '0px', '4' => '4px', '8' => '8px', '12' => '12px', '16' => '16px', '20' => '20px', '24' => '24px', '28' => '28px', '32' => '32px', '40' => '40px', '48' => '48px', '56' => '56px', '64' => '64px', '72' => '72px', '80' => '80px', '96' => '96px'], 'help' => ''],
        'home_u54_pb_d' => ['type' => 'select', 'label' => 'Space below · laptop', 'default' => '40', 'store' => 'setting', 'options' => ['0' => '0px', '4' => '4px', '8' => '8px', '12' => '12px', '16' => '16px', '20' => '20px', '24' => '24px', '28' => '28px', '32' => '32px', '40' => '40px', '48' => '48px', '56' => '56px', '64' => '64px', '72' => '72px', '80' => '80px', '96' => '96px'], 'help' => ''],
        'home_u54_pb_m' => ['type' => 'select', 'label' => 'Space below · phone', 'default' => '28', 'store' => 'setting', 'options' => ['0' => '0px', '4' => '4px', '8' => '8px', '12' => '12px', '16' => '16px', '20' => '20px', '24' => '24px', '28' => '28px', '32' => '32px', '40' => '40px', '48' => '48px', '56' => '56px', '64' => '64px', '72' => '72px', '80' => '80px', '96' => '96px'], 'help' => ''],
        'home_u54_hg_d' => ['type' => 'select', 'label' => 'Space under the heading · laptop', 'default' => '28', 'store' => 'setting', 'options' => ['0' => '0px', '4' => '4px', '8' => '8px', '12' => '12px', '16' => '16px', '20' => '20px', '24' => '24px', '28' => '28px', '32' => '32px', '40' => '40px', '48' => '48px', '56' => '56px', '64' => '64px', '72' => '72px', '80' => '80px', '96' => '96px'], 'help' => ''],
        'home_u54_hg_m' => ['type' => 'select', 'label' => 'Space under the heading · phone', 'default' => '16', 'store' => 'setting', 'options' => ['0' => '0px', '4' => '4px', '8' => '8px', '12' => '12px', '16' => '16px', '20' => '20px', '24' => '24px', '28' => '28px', '32' => '32px', '40' => '40px', '48' => '48px', '56' => '56px', '64' => '64px', '72' => '72px', '80' => '80px', '96' => '96px'], 'help' => ''],
        'home_ft_l_img' => ['type' => 'text', 'label' => 'Left · photo', 'default' => '', 'store' => 'setting', 'options' => ['picker' => 'media'], 'help' => 'Choose it from the Media Library. Empty draws a soft colour panel.'],
        'home_ft_l_alt' => ['type' => 'text', 'label' => 'Left · photo description (alt text)', 'default' => '', 'store' => 'setting', 'help' => 'What the photo shows, for search engines and screen readers. Empty: the title.'],
        'home_ft_l_title' => ['type' => 'text', 'label' => 'Left · title (H2)', 'default' => 'Embrace the Sunshine!', 'store' => 'setting', 'help' => 'Empty hides the title.'],
        'home_ft_l_text' => ['type' => 'textarea', 'label' => 'Left · text', 'default' => 'Daily SPF is the step every Korean routine is built around. Browse our Korean sunscreens and find the texture that suits your skin.', 'store' => 'setting', 'help' => 'Empty draws none.'],
        'home_ft_l_btn' => ['type' => 'text', 'label' => 'Left · link text', 'default' => 'Shop now', 'store' => 'setting', 'help' => 'Empty: no link line; the photo and title still link.'],
        'home_ft_l_url' => ['type' => 'text', 'label' => 'Left · link', 'default' => '', 'store' => 'setting', 'help' => 'Empty: the Sunscreens category. A path on this shop (/…) or a full https:// address; anything else is ignored.'],
        'home_ft_r_img' => ['type' => 'text', 'label' => 'Right · photo', 'default' => '', 'store' => 'setting', 'options' => ['picker' => 'media'], 'help' => 'Choose it from the Media Library. Empty draws a soft colour panel.'],
        'home_ft_r_alt' => ['type' => 'text', 'label' => 'Right · photo description (alt text)', 'default' => '', 'store' => 'setting', 'help' => 'What the photo shows, for search engines and screen readers. Empty: the title.'],
        'home_ft_r_title' => ['type' => 'text', 'label' => 'Right · title (H2)', 'default' => 'Top K-Beauty Picks', 'store' => 'setting', 'help' => 'Empty hides the title.'],
        'home_ft_r_text' => ['type' => 'textarea', 'label' => 'Right · text', 'default' => 'Not sure where to start? Begin with the K-Beauty Bliss best sellers and build your routine from there.', 'store' => 'setting', 'help' => 'Empty draws none.'],
        'home_ft_r_btn' => ['type' => 'text', 'label' => 'Right · link text', 'default' => 'Shop now', 'store' => 'setting', 'help' => 'Empty: no link line; the photo and title still link.'],
        'home_ft_r_url' => ['type' => 'text', 'label' => 'Right · link', 'default' => '/best-sellers', 'store' => 'setting', 'help' => 'A path on this shop (/…) or a full https:// address; anything else is ignored.'],
        'home_ft_bg' => ['type' => 'select', 'label' => 'Section background', 'default' => 'none', 'store' => 'setting', 'options' => ['none' => 'None — the page shows through', 'blush' => 'Blush — soft pink band', 'cream' => 'Cream — warm white band', 'lilac' => 'Lilac — pink-to-lilac band'], 'help' => ''],
        'home_ft_pt_d' => ['type' => 'select', 'label' => 'Space above · laptop', 'default' => '40', 'store' => 'setting', 'options' => ['0' => '0px', '4' => '4px', '8' => '8px', '12' => '12px', '16' => '16px', '20' => '20px', '24' => '24px', '28' => '28px', '32' => '32px', '40' => '40px', '48' => '48px', '56' => '56px', '64' => '64px', '72' => '72px', '80' => '80px', '96' => '96px'], 'help' => ''],
        'home_ft_pt_m' => ['type' => 'select', 'label' => 'Space above · phone', 'default' => '28', 'store' => 'setting', 'options' => ['0' => '0px', '4' => '4px', '8' => '8px', '12' => '12px', '16' => '16px', '20' => '20px', '24' => '24px', '28' => '28px', '32' => '32px', '40' => '40px', '48' => '48px', '56' => '56px', '64' => '64px', '72' => '72px', '80' => '80px', '96' => '96px'], 'help' => ''],
        'home_ft_pb_d' => ['type' => 'select', 'label' => 'Space below · laptop', 'default' => '40', 'store' => 'setting', 'options' => ['0' => '0px', '4' => '4px', '8' => '8px', '12' => '12px', '16' => '16px', '20' => '20px', '24' => '24px', '28' => '28px', '32' => '32px', '40' => '40px', '48' => '48px', '56' => '56px', '64' => '64px', '72' => '72px', '80' => '80px', '96' => '96px'], 'help' => ''],
        'home_ft_pb_m' => ['type' => 'select', 'label' => 'Space below · phone', 'default' => '28', 'store' => 'setting', 'options' => ['0' => '0px', '4' => '4px', '8' => '8px', '12' => '12px', '16' => '16px', '20' => '20px', '24' => '24px', '28' => '28px', '32' => '32px', '40' => '40px', '48' => '48px', '56' => '56px', '64' => '64px', '72' => '72px', '80' => '80px', '96' => '96px'], 'help' => ''],
    ];

    /** tab => [label, description, keys] — spread into HomepageContent::TABS. */
    public const TABS = [
        'bestselling' => ['Best Sellers', 'Section 2: the best-sellers grid — 8 on a laptop (4 × 2), 6 on a phone (2 × 3) — its heading, button, products, background and spacing. Show or hide it per device on Appearance → Homepage.', ['home_bs_eyebrow', 'home_bs_title', 'home_bs_sub', 'home_bs_source', 'home_bs_cat', 'home_bs_brand', 'home_bs_picks', 'home_bs_brands', 'home_bs_cats', 'home_bs_sort', 'home_bs_stock', 'home_bs_max', 'home_bs_count_d', 'home_bs_count_m', 'home_bs_cols_d', 'home_bs_cols_m', 'home_bs_btn', 'home_bs_url', 'home_bs_bg', 'home_bs_pt_d', 'home_bs_pt_m', 'home_bs_pb_d', 'home_bs_pb_m', 'home_bs_hg_d', 'home_bs_hg_m']],
        'brands' => ['Brands', 'Section 3: brand image cards with the name on a laptop; on a phone the names, the images, or the images with logos — ONE list of links, styled per device. Which brands, how many, and the Shop all brands button.', ['home_br_eyebrow', 'home_br_title', 'home_br_sub', 'home_br_picks', 'home_br_imgs', 'home_br_count_d', 'home_br_count_m', 'home_br_cols_d', 'home_br_cols_m', 'home_br_th_m', 'home_br_layout_d', 'home_br_layout_m', 'home_br_btn', 'home_br_url', 'home_br_bg', 'home_br_pt_d', 'home_br_pt_m', 'home_br_pb_d', 'home_br_pb_m', 'home_br_hg_d', 'home_br_hg_m']],
        'trending' => ['Trending', 'Section 5: what is moving this week — 8 on a laptop, 6 on a phone, no button.', ['home_tr_eyebrow', 'home_tr_title', 'home_tr_sub', 'home_tr_source', 'home_tr_cat', 'home_tr_brand', 'home_tr_picks', 'home_tr_brands', 'home_tr_cats', 'home_tr_sort', 'home_tr_stock', 'home_tr_max', 'home_tr_count_d', 'home_tr_count_m', 'home_tr_cols_d', 'home_tr_cols_m', 'home_tr_btn', 'home_tr_url', 'home_tr_bg', 'home_tr_pt_d', 'home_tr_pt_m', 'home_tr_pb_d', 'home_tr_pb_m', 'home_tr_hg_d', 'home_tr_hg_m']],
        'blog' => ['Blog', 'Section 6: three articles side by side on a laptop, stacked on a phone.', ['home_bl_eyebrow', 'home_bl_title', 'home_bl_sub', 'home_bl_source', 'home_bl_picks', 'home_bl_count_m', 'home_bl_read', 'home_bl_tag', 'home_bl_more', 'home_bl_btn', 'home_bl_url', 'home_bl_bg', 'home_bl_pt_d', 'home_bl_pt_m', 'home_bl_pb_d', 'home_bl_pb_m', 'home_bl_hg_d', 'home_bl_hg_m']],
        'under54' => ['Under AED 54', 'Section 7: 10 on a laptop (5 per row), 6 on a phone (2 per row), at or under the price ceiling.', ['home_u54_eyebrow', 'home_u54_title', 'home_u54_sub', 'home_u54_source', 'home_u54_cat', 'home_u54_brand', 'home_u54_picks', 'home_u54_brands', 'home_u54_cats', 'home_u54_sort', 'home_u54_stock', 'home_u54_max', 'home_u54_count_d', 'home_u54_count_m', 'home_u54_cols_d', 'home_u54_cols_m', 'home_u54_btn', 'home_u54_url', 'home_u54_bg', 'home_u54_pt_d', 'home_u54_pt_m', 'home_u54_pb_d', 'home_u54_pb_m', 'home_u54_hg_d', 'home_u54_hg_m']],
        'feature' => ['Two-column feature', 'Section 8: two photo panels with a title, a thin rule, a line of text and SHOP NOW ▸ — Sunscreens on the left, best sellers on the right.', ['home_ft_l_img', 'home_ft_l_alt', 'home_ft_l_title', 'home_ft_l_text', 'home_ft_l_btn', 'home_ft_l_url', 'home_ft_r_img', 'home_ft_r_alt', 'home_ft_r_title', 'home_ft_r_text', 'home_ft_r_btn', 'home_ft_r_url', 'home_ft_bg', 'home_ft_pt_d', 'home_ft_pt_m', 'home_ft_pb_d', 'home_ft_pb_m']],
        'about' => ['About us', 'Section 9, last on the page: the heading and the paragraphs, as real text search engines read. A blank line starts a new paragraph.', ['about_text', 'home_ab_eyebrow', 'home_ab_title', 'home_ab_more', 'home_ab_clamp_d', 'home_ab_clamp_m', 'home_ab_bg', 'home_ab_pt_d', 'home_ab_pt_m', 'home_ab_pb_d', 'home_ab_pb_m']],
    ];

    /**
     * Every value the sections read. Read once by HomeController and handed to
     * the view; NOT memoised in the container or a static, because a test (or
     * the admin's live preview) saves and renders inside one application, and
     * a memo here would serve it the value from before the save — the
     * Setting::map() trap CLAUDE.md names. SettingsService already memoises
     * the table, so this is array work, not a query.
     *
     * @return array<string, mixed>
     */
    public static function settings(): array
    {
        try {
            return ModuleSchema::read(app(SettingsService::class), 'homepage_content', HomepageContent::SCHEMA);
        } catch (\Throwable) {
            return [];
        }
    }

    /** A select's stored value, or its default when it is not one of its own options. */
    public static function pick(array $c, string $key): string
    {
        $field = HomepageContent::SCHEMA[$key] ?? null;

        if ($field === null) {
            return '';
        }

        $v = (string) ($c[$key] ?? $field['default']);

        return array_key_exists($v, $field['options'] ?? []) ? $v : (string) $field['default'];
    }

    /** Plain text from a text setting: tags gone, whitespace trimmed. Escaped by the template. */
    public static function text(array $c, string $key): string
    {
        $default = (string) (HomepageContent::SCHEMA[$key]['default'] ?? '');

        return trim(RichText::toText((string) ($c[$key] ?? $default)));
    }

    /**
     * A link from a setting, or $fallback. A path on this shop (one leading
     * slash, not two) or an http(s) address, nothing else — the HomeBundles
     * rule — and no control characters, so `java\nscript:` cannot slip past.
     */
    public static function url(string $raw, string $fallback = ''): string
    {
        $url = trim($raw);

        if ($url === '' || preg_match('/[\x00-\x20\x7F"<>\\\\]/', $url) === 1) {
            return $fallback;
        }

        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return Url::to($url);
        }

        return preg_match('#^https?://[^\s"<>]+$#i', $url) === 1 ? $url : $fallback;
    }

    /** A picture from a setting: SafeUrl::src() — http(s) or a path — or '' for "draw none". */
    public static function image(string $raw): string
    {
        $src = SafeUrl::src(trim($raw));

        return $src === '' || str_starts_with($src, '//') || preg_match('/[\s"<>]/', $src) === 1 ? '' : $src;
    }

    /**
     * The section band's classes and custom properties. Integers and option
     * keys only; nothing an owner types reaches either attribute.
     *
     * @return array{bg: string, style: string}
     */
    public static function frame(array $c, string $p, bool $heading = true): array
    {
        $style = [
            '--hs-pt-d:'.self::pick($c, "home_{$p}_pt_d").'px',
            '--hs-pt-m:'.self::pick($c, "home_{$p}_pt_m").'px',
            '--hs-pb-d:'.self::pick($c, "home_{$p}_pb_d").'px',
            '--hs-pb-m:'.self::pick($c, "home_{$p}_pb_m").'px',
        ];

        if ($heading) {
            $style[] = '--hs-hg-d:'.self::pick($c, "home_{$p}_hg_d").'px';
            $style[] = '--hs-hg-m:'.self::pick($c, "home_{$p}_hg_m").'px';
        }

        return ['bg' => 'hs-bg-'.self::pick($c, "home_{$p}_bg"), 'style' => implode(';', $style)];
    }

    /**
     * One product rail's configuration.
     *
     * @return array<string, mixed>
     */
    public static function rail(array $c, string $section): array
    {
        $p = self::RAILS[$section];
        $countD = (int) self::pick($c, "home_{$p}_count_d");
        $countM = (int) self::pick($c, "home_{$p}_count_m");
        $frame = self::frame($c, $p);

        return [
            'prefix' => $p,
            'source' => self::pick($c, "home_{$p}_source"),
            'category' => self::firstId($c["home_{$p}_cat"] ?? ''),
            'brand' => self::firstId($c["home_{$p}_brand"] ?? ''),
            'picks' => self::ids($c["home_{$p}_picks"] ?? ''),
            // Lane HC: what `query` reads. Cleaned here, so a hand-edited row
            // reaches the builder as ids and a known sort or not at all.
            'query' => ProductSource::clean([
                'brands' => $c["home_{$p}_brands"] ?? '',
                'cats' => $c["home_{$p}_cats"] ?? '',
                'sort' => self::pick($c, "home_{$p}_sort"),
                'stock' => (bool) ($c["home_{$p}_stock"] ?? false),
            ]),
            'max_fils' => ((int) self::pick($c, "home_{$p}_max")) * 100,
            'count_d' => $countD,
            'count_m' => $countM,
            'fetch' => max($countD, $countM),
            // (Lane LZ) One row, on whichever device has the wider one: the
            // cards not lazy when this rail is the first section on screen.
            'above' => max((int) self::pick($c, "home_{$p}_cols_d"), (int) self::pick($c, "home_{$p}_cols_m")),
            'classes' => 'hs hs-rail hs-'.$section.' '.$frame['bg'].' hs-dc-'.$countD.' hs-mc-'.$countM,
            'style' => $frame['style'].';--hs-cols-d:'.self::pick($c, "home_{$p}_cols_d").';--hs-cols-m:'.self::pick($c, "home_{$p}_cols_m"),
            'eyebrow' => self::text($c, "home_{$p}_eyebrow"),
            'title' => self::text($c, "home_{$p}_title"),
            'sub' => self::text($c, "home_{$p}_sub"),
            'btn' => self::text($c, "home_{$p}_btn"),
            // A missing key reads its default, as text() does for the label.
            'url' => self::url((string) ($c["home_{$p}_url"] ?? HomepageContent::SCHEMA["home_{$p}_url"]['default'] ?? ''), ''),
            // Beside the heading on a laptop, under the grid on a phone — the
            // Best Sellers layout home.html shows.
            'place' => 'top',
            // (2.60.385) Under AED 54 keeps its CENTRED heading with the
            // button out to the right, as Big savings bundles does; Best
            // Sellers keeps its left heading.
            'center' => $section === 'under54',
        ];
    }

    /** Look · phone → the section's class. A map, so only these three reach the page. */
    public const LOOK_CLASS = ['text' => 'text', 'image' => 'img', 'logo_image' => 'logo'];

    /**
     * A 1×1 transparent GIF, inline. A <picture> source carrying it for a
     * media query the picture is hidden at means the browser has nothing to
     * fetch there: a phone on Text only downloads no brand photo, and a laptop
     * never downloads a phone logo — whether or not the browser honours lazy
     * loading under display:none. A constant; CSP img-src allows data:.
     */
    public const BLANK = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    /**
     * At most this many brands may carry a picture. A brand taken off the list
     * keeps its picture for the day it comes back, so the map outgrows the 24
     * picks; 100 paths of 500 bytes still fit the 64 KB the read accepts.
     */
    public const IMG_CAP = 100;

    /**
     * The `home_br_imgs` rule (ModuleSchema `rule`): brand id → picture, as a
     * JSON object. Keys are positive integers; every path passes image() —
     * SafeUrl::src() plus no `//`, whitespace or quote — or is dropped. Takes
     * a JSON string or an array. Runs on save AND on every read, so a row
     * edited by hand reaches the page cleaned.
     */
    public static function cleanBrandImages(mixed $raw, array $field = []): string
    {
        $map = self::brandImages($raw);

        return $map === [] ? '' : (string) json_encode($map, JSON_FORCE_OBJECT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return array<int, string>  brand id → checked picture, ids ascending */
    public static function brandImages(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = trim($raw) === '' || strlen($raw) > 64000 ? [] : json_decode($raw, true);
        }

        $out = [];

        foreach (is_array($raw) ? $raw : [] as $id => $path) {
            $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $src = is_string($path) && strlen($path) <= 500 ? self::image($path) : '';

            if ($id !== false && $src !== '' && ! str_starts_with($src, 'data:')) {
                $out[$id] = $src;
            }
        }

        ksort($out);

        return array_slice($out, 0, self::IMG_CAP, true);
    }

    /** @return array<string, mixed> */
    public static function brands(array $c): array
    {
        $frame = self::frame($c, 'br');
        $d = (int) self::pick($c, 'home_br_count_d');
        $m = (int) self::pick($c, 'home_br_count_m');

        $lookM = self::pick($c, 'home_br_layout_m');

        return [
            'picks' => self::ids($c['home_br_picks'] ?? ''),
            'count_d' => $d,
            'count_m' => $m,
            'fetch' => max($d, $m),
            // Lane BS. Option keys only, so nothing typed reaches the class.
            'look_d' => self::pick($c, 'home_br_layout_d'),
            'look_m' => $lookM,
            'imgs' => self::brandImages($c['home_br_imgs'] ?? ''),
            'classes' => 'hs hs-brands hs-brm-'.self::LOOK_CLASS[$lookM].' '.$frame['bg'].' hs-dc-'.$d.' hs-mc-'.$m,
            'style' => $frame['style'].';--hs-cols-d:'.self::pick($c, 'home_br_cols_d').';--hs-cols-m:'.self::pick($c, 'home_br_cols_m').';--hs-br-th-m:'.self::pick($c, 'home_br_th_m').'px',
            'eyebrow' => self::text($c, 'home_br_eyebrow'),
            'title' => self::text($c, 'home_br_title'),
            'sub' => self::text($c, 'home_br_sub'),
            'btn' => self::text($c, 'home_br_btn'),
            'url' => self::url((string) ($c['home_br_url'] ?? ''), Url::to(UrlScheme::brandIndex())),
        ];
    }

    /** @return array<string, mixed> */
    public static function blog(array $c): array
    {
        $frame = self::frame($c, 'bl');
        $m = (int) self::pick($c, 'home_bl_count_m');

        return [
            'source' => self::pick($c, 'home_bl_source'),
            'picks' => self::ids($c['home_bl_picks'] ?? ''),
            'classes' => 'hs hs-blog '.$frame['bg'].' hs-mc-'.$m,
            'style' => $frame['style'],
            'eyebrow' => self::text($c, 'home_bl_eyebrow'),
            'title' => self::text($c, 'home_bl_title'),
            'sub' => self::text($c, 'home_bl_sub'),
            'more' => self::text($c, 'home_bl_more'),
            // (Lane HS) The two card details, off unless switched on.
            'read' => (bool) ($c['home_bl_read'] ?? false),
            'tag' => (bool) ($c['home_bl_tag'] ?? false),
            'btn' => self::text($c, 'home_bl_btn'),
            'url' => self::url((string) ($c['home_bl_url'] ?? ''), Url::to(UrlScheme::blogIndex())),
        ];
    }

    /**
     * The two feature panels. `$sunscreens` is the category's own address,
     * resolved inside the cached build so the left panel follows the
     * category wherever the shop files it.
     *
     * @return array<string, mixed>
     */
    public static function feature(array $c, string $sunscreens): array
    {
        $frame = self::frame($c, 'ft', false);
        $panels = [];

        foreach (['l' => $sunscreens, 'r' => Url::to('/best-sellers')] as $side => $fallback) {
            $title = self::text($c, "home_ft_{$side}_title");
            $alt = self::text($c, "home_ft_{$side}_alt");

            $panels[] = [
                'side' => $side,
                'image' => self::image((string) ($c["home_ft_{$side}_img"] ?? '')),
                'alt' => $alt !== '' ? $alt : $title,
                'title' => $title,
                'text' => self::text($c, "home_ft_{$side}_text"),
                'btn' => self::text($c, "home_ft_{$side}_btn"),
                'url' => self::url((string) ($c["home_ft_{$side}_url"] ?? ''), $fallback),
            ];
        }

        return ['classes' => 'hs hs-feature '.$frame['bg'], 'style' => $frame['style'], 'panels' => $panels];
    }

    /** @return array<string, mixed> */
    public static function about(array $c, string $body): array
    {
        $frame = self::frame($c, 'ab', false);

        $paragraphs = array_values(array_filter(
            array_map('trim', preg_split('/\R\s*\R/u', str_replace("\r\n", "\n", $body)) ?: []),
            static fn (string $p): bool => $p !== ''
        ));

        // (Lane PF) Read more: the clamp heights ride the section's own style,
        // integers from the selects' own option keys.
        $more = (bool) ($c['home_ab_more'] ?? true);

        return [
            'classes' => 'hs hs-about '.$frame['bg'],
            'style' => $frame['style'].($more ? ';--hs-ab-cl-d:'.self::pick($c, 'home_ab_clamp_d').'px;--hs-ab-cl-m:'.self::pick($c, 'home_ab_clamp_m').'px' : ''),
            'more' => $more,
            'eyebrow' => self::text($c, 'home_ab_eyebrow'),
            'title' => self::text($c, 'home_ab_title'),
            // A single newline inside a paragraph stays a space; the owner's
            // blank line is the paragraph break.
            'paragraphs' => array_map(static fn (string $p): string => (string) preg_replace('/\s*\n\s*/u', ' ', $p), $paragraphs),
        ];
    }

    /**
     * Everything the sections read from the database, in ONE cache entry.
     *
     * @return array{bestselling: Collection, trending: Collection, under54: Collection, brands: Collection, posts: Collection, sunscreens: string}
     */
    public static function data(?array $c = null): array
    {
        $c ??= self::settings();

        return Cache::remember(self::cacheKey($c), self::TTL, function () use ($c) {
            $grids = app(GridSections::class);
            $out = [];

            foreach (array_keys(self::RAILS) as $section) {
                $r = self::rail($c, $section);
                $out[$section] = $grids->pool($r['source'], $r['brand'], $r['category'], $r['picks'], $r['fetch'], $r['max_fils'] > 0 ? $r['max_fils'] : null, false, $r['query'])->values();
            }

            $b = self::brands($c);
            $out['brands'] = self::fetchBrands($b['picks'], $b['fetch']);

            // Only a MANUAL pick is read here; "the latest three" is the
            // journal row HomeController already reads and caches.
            $bl = self::blog($c);
            $out['posts'] = $bl['source'] === 'manual' && $bl['picks'] !== [] ? self::fetchPosts($bl['picks']) : collect();

            $sun = Category::query()->select('id', 'name', 'slug', 'path', 'parent_id')->where('slug', self::SUNSCREENS_SLUG)->first();
            $out['sunscreens'] = $sun !== null ? $sun->url() : Url::to(self::SUNSCREENS_FALLBACK);

            return $out;
        });
    }

    /**
     * Throw every built entry away. Called wherever the homepage's own caches
     * are (HomeController::flushCache(), the Brand and Post hooks).
     *
     * A GENERATION, NOT A KEY TO FORGET — and it must not read a setting. The
     * entry's key depends on the settings, so forgetting it would mean reading
     * them, and this runs inside Product::saved: a settings read there fills
     * SettingsService's memo in the middle of whatever is saving products — an
     * import, a seeder, a test that writes a setting next — which is the
     * Setting::map() trap CLAUDE.md names. Bumping one counter answers "every
     * entry is stale" with a single cache write and no read of anything else;
     * superseded entries simply expire.
     */
    public static function flush(): void
    {
        try {
            Cache::forever(self::GENERATION, (int) Cache::get(self::GENERATION, 0) + 1);
        } catch (\Throwable) {
            // A cache that cannot be reached is not a failed product save.
        }
    }

    /**
     * A brand's photo for the laptop card: its banner picture
     * (Catalogue → Brands → banner), scheme-checked by image(), with that
     * banner's alt text. ['', ''] when it has none.
     *
     * @return array{src: string, alt: string}
     */
    public static function brandPhoto(object $brand): array
    {
        // The picture is read whether or not the banner is switched ON for the
        // brand's own page: that switch is about the brand page's header, and
        // the owner uploading a photo for a brand is the signal this card wants.
        $banner = data_get($brand, 'banner');
        $banner = is_array($banner) ? $banner : [];

        if (trim((string) ($banner['image'] ?? '')) === '') {
            return ['src' => '', 'alt' => ''];
        }

        return ['src' => self::image((string) $banner['image']), 'alt' => trim(RichText::toText((string) ($banner['image_alt'] ?? '')))];
    }

    /**
     * Brands for section 3: the owner's pick in his order, or the brands with
     * the most visible products. Only columns the card prints — the name, the
     * slug, the logo and the banner (whose `image` is the photo).
     */
    private static function fetchBrands(array $picks, int $limit): Collection
    {
        $base = Brand::query()->select('id', 'name', 'slug', 'logo', 'banner');

        if ($picks !== []) {
            $rows = $base->whereIn('id', array_slice($picks, 0, 24))->get()->keyBy('id');

            return collect($picks)->map(fn (int $id) => $rows->get($id))->filter()->take($limit)->values();
        }

        return $base->withCount(['products' => fn ($q) => $q->visible()])
            ->whereHas('products', fn ($q) => $q->visible())
            ->orderByDesc('products_count')->orderBy('name')->orderBy('id')
            ->limit($limit)->get()->values();
    }

    /** The three posts: the owner's pick in his order, or the latest published. */
    private static function fetchPosts(array $picks): Collection
    {
        $base = Post::query()->where('status', 'published');

        if ($picks !== []) {
            $rows = $base->whereIn('id', array_slice($picks, 0, 3))->get()->keyBy('id');

            return collect($picks)->map(fn (int $id) => $rows->get($id))->filter()->take(3)->values();
        }

        return $base->latest('published_at')->orderByDesc('id')->limit(3)->get();
    }

    /**
     * What the console's pickers choose from — Appearance → Homepage content's
     * "Brands, in order", "Products, in order", the category and brand of a
     * rail and the three articles. The ADMIN's read only (four queries the
     * storefront never pays): the homepage hands a stored id to its query as a
     * bound parameter, and an id that names nothing simply draws nothing.
     *
     * @return array<string, list<array{id: int, name: string}>>
     */
    public static function pickOptions(): array
    {
        $rows = static fn ($q, string $label = 'name') => $q->get()->map(fn ($r) => ['id' => (int) $r->id, 'name' => (string) $r->{$label}])->values()->all();

        try {
            return [
                'products' => $rows(\App\Models\Product::query()->visible()->select('id', 'name')->orderBy('name')->orderBy('id')->limit(5000)),
                'brands' => $rows(Brand::query()->select('id', 'name')->orderBy('name')->orderBy('id')),
                'categories' => $rows(Category::query()->select('id', 'name')->orderBy('name')->orderBy('id')),
                'posts' => $rows(Post::query()->where('status', 'published')->select('id', 'title')->latest('published_at')->orderByDesc('id')->limit(500), 'title'),
            ];
        } catch (\Throwable) {
            return ['products' => [], 'brands' => [], 'categories' => [], 'posts' => []];
        }
    }

    /** The settings that change WHICH rows come back, and nothing else. */
    private static function cacheKey(array $c): string
    {
        $keys = ['home_br_picks', 'home_br_count_d', 'home_br_count_m', 'home_bl_source', 'home_bl_picks'];

        foreach (self::RAILS as $p) {
            foreach (['source', 'cat', 'brand', 'picks', 'max', 'count_d', 'count_m', 'brands', 'cats', 'sort', 'stock'] as $k) {
                $keys[] = "home_{$p}_{$k}";
            }
        }

        $sig = [];

        foreach ($keys as $k) {
            $sig[$k] = (string) ($c[$k] ?? '');
        }

        return 'kbb.home.hs.'.(int) Cache::get(self::GENERATION, 0).'.'.md5(json_encode($sig));
    }

    /** @return list<int> */
    public static function ids(mixed $raw): array
    {
        $out = [];

        foreach (explode(',', (string) $raw) as $one) {
            $id = (int) trim($one);

            if ($id > 0 && ! in_array($id, $out, true)) {
                $out[] = $id;
            }
        }

        return $out;
    }

    private static function firstId(mixed $raw): int
    {
        return self::ids($raw)[0] ?? 0;
    }

    /**
     * JSON-LD ItemList for one row of links — what Google reads as "this is a
     * list of these products / these articles". Encoded with the HEX flags
     * Seo::encodeJsonLd() uses, so no string in it can close the <script> it
     * sits in; printing it unescaped is therefore safe by construction.
     *
     * @param  iterable<array{url: string, name: string}>  $rows
     */
    public static function itemList(string $name, iterable $rows): string
    {
        $items = [];
        $i = 0;

        foreach ($rows as $row) {
            $url = (string) ($row['url'] ?? '');

            if ($url === '') {
                continue;
            }

            $items[] = ['@type' => 'ListItem', 'position' => ++$i, 'url' => self::absolute($url), 'name' => (string) ($row['name'] ?? '')];
        }

        if ($items === []) {
            return '';
        }

        $json = json_encode(
            ['@context' => 'https://schema.org', '@type' => 'ItemList', 'name' => $name, 'numberOfItems' => count($items), 'itemListElement' => $items],
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
        );

        return $json === false ? '' : '<script type="application/ld+json">'.$json.'</script>';
    }

    private static function absolute(string $url): string
    {
        if (preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }

        // $url is already Url::to()'d, so it carries the base path; only the
        // origin is added — scheme and host of APP_URL, never its path.
        $origin = (string) preg_replace('#^(https?://[^/]+).*$#i', '$1', (string) config('app.url'));

        return rtrim($origin, '/').'/'.ltrim($url, '/');
    }
}

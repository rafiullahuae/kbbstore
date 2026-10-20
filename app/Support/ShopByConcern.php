<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Category;
use App\Models\Product;
use App\Services\GridSections;
use App\Services\VariantPricing;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Shop by Concern — concern tabs, a tip panel and six products per tab. (Lane SBC)
 *
 * THE OWNER, 18 October 2026, picked "Content 1" of
 * docs/sbc-preview/shop-by-concern-previews-v3.html: the A tabs (sharp 2px
 * rectangles, the icon in a small circle, the chosen tab filled pink), ALL on
 * one row on a computer and a sideways row on a phone; under them a coloured
 * panel (TIP, the concern, the tip, a long View all) beside six list cards,
 * each with a pink cart-plus. Phone: a taller panel with View all across its
 * foot, then the products in a two-row scroll-snap carousel with dots.
 * On top of the preview he asked for: a full background PHOTO per tab (the
 * gradient and two product circles when none is chosen), the heading's icon
 * and the line under the heading OFF by default, and the eight tips below as
 * the shipped words, English and Arabic.
 *
 * ── WHERE IT SITS, AND WHY IT SHIPS OFF ────────────────────────────────────
 *
 * The brief: directly under Trending, ON — and if that cannot be done without
 * moving anything else, OFF. Directly under Trending is Skin concerns' place
 * (Lane CX, asked for there by the owner and pinned), so this sits directly
 * under Skin concerns and ships OFF (HomepageSections::OFF_BY_DEFAULT): the
 * homepage is byte-for-byte what it was until the owner switches it on.
 *
 * ── WHAT THE OWNER CONTROLS ─────────────────────────────────────────────────
 *
 * Appearance → Homepage content → Shop by Concern (and the same fields in
 * All sections → Shop by Concern → Edit content): the heading and its icon,
 * the line under it, TIP and View all — each with its Arabic; the colours;
 * how many products a tab shows; and the tabs — an ordered list, each row on
 * or off, its label, title and tip in both languages, its icon, its photo and
 * where its products come from (the concern's own collection, categories,
 * brands or a hand-picked list) in which order. Show/hide per device and the
 * place on the page are the section's own switches on Appearance → Homepage.
 *
 * ── SECURITY ────────────────────────────────────────────────────────────────
 *
 * The tab list is ONE setting, a JSON list cleaned by rows() on save AND on
 * every read (ModuleSchema `rule`), so a hand-edited row reaches the page as
 * option keys, ids, plain text and scheme-checked addresses or not at all.
 * Every value printed into a class or a style is an option key, an integer,
 * a colour that passed the hex check or a constant of this class. The icons
 * and the gradients are constants. Every word is printed through {{ }}.
 *
 * ── COST ────────────────────────────────────────────────────────────────────
 *
 * FLAT, whatever the number of tabs or products: one query for the
 * collections, ONE round trip for every tab's product ids
 * (GridSections::idsForMany(), a UNION ALL of each tab's own query) and one
 * for the products with their brands — inside one cache entry under the
 * homepage's own generation, so a warm homepage pays nothing and a product
 * save throws it away with the rest (HomeSections::flush()). Nothing is read
 * at all while the section is switched off on both devices.
 */
final class ShopByConcern
{
    /** The homepage cache generation HomeSections::flush() bumps — the same key, so one flush empties both. */
    public const GENERATION = 'kbb.home.hs.gen';

    private const TTL = 600;

    /** The most tabs one section draws. */
    public const CAP = 12;

    /** Products per tab: the select's options. */
    public const COUNTS = ['2' => '2', '3' => '3', '4' => '4', '5' => '5', '6' => '6', '7' => '7', '8' => '8', '9' => '9', '10' => '10', '11' => '11', '12' => '12'];

    /** Where a tab's products come from. */
    public const SOURCES = [
        'auto' => 'The concern’s own collection',
        'category' => 'Categories I choose',
        'brands' => 'Brands I choose',
        'manual' => 'Products I pick, in my order',
    ];

    /** Longest kept, in characters. */
    private const MAX = ['l' => 40, 'h' => 80, 't' => 400];

    /**
     * The refined face / drop / sun set (ICON2 of docs/sbc-preview/template-v3.html).
     * Shapes with class "f" take the circle's fill (--sbc-icf). Constants:
     * printed raw for that reason, each <svg> with its own width and height.
     */
    private const FACE = '<path class="f" d="M12 3.2c-3.9 0-6.4 3.1-6.4 7.4 0 4.7 2.9 10.2 6.4 10.2s6.4-5.5 6.4-10.2c0-4.3-2.5-7.4-6.4-7.4z"/>';

    public const ICONS = [
        'acne' => ['Acne (face with spots)', 'stroke-linecap="round"', self::FACE.'<circle cx="9" cy="13.2" r="1" fill="currentColor" stroke="none"/><circle cx="15.2" cy="12.6" r="1.2" fill="currentColor" stroke="none"/><circle cx="14.1" cy="15.4" r=".7" fill="currentColor" stroke="none"/><circle cx="10.3" cy="7.6" r=".6" fill="currentColor" stroke="none"/>'],
        'hydration' => ['Hydration (drop)', 'stroke-linecap="round" stroke-linejoin="round"', '<path class="f" d="M12 2.8s6.2 6.8 6.2 11.3a6.2 6.2 0 0 1-12.4 0C5.8 9.6 12 2.8 12 2.8z"/><path d="M8.6 14.6c1.1-.9 2.3-.9 3.4 0s2.3.9 3.4 0"/><path d="M9.6 10.6c.4-.9 1-1.8 1.5-2.4"/>'],
        'spots' => ['Dark spots (face with marks)', 'stroke-linecap="round"', self::FACE.'<ellipse cx="9.3" cy="12.6" rx="1.9" ry="1.5" fill="currentColor" opacity=".45" stroke="none"/><circle cx="15" cy="14.6" r="1.2" fill="currentColor" opacity=".45" stroke="none"/><circle cx="14.4" cy="8.4" r=".9" fill="currentColor" opacity=".45" stroke="none"/>'],
        'ageing' => ['Anti-ageing (face with lines)', 'stroke-linecap="round"', self::FACE.'<path d="M9.2 6.9c1.8-.6 3.8-.6 5.6 0M9.6 8.6c1.6-.4 3.2-.4 4.8 0"/><path d="M7.4 11.6l-1 .7M16.6 11.6l1 .7"/><path d="M9 16.2c.5.8 1.5 1.2 3 1.2s2.5-.4 3-1.2"/>'],
        'sensitive' => ['Redness (flushed face)', 'stroke-linecap="round"', self::FACE.'<ellipse cx="8.7" cy="13.4" rx="2" ry="1.3" fill="currentColor" opacity=".35" stroke="none"/><ellipse cx="15.3" cy="13.4" rx="2" ry="1.3" fill="currentColor" opacity=".35" stroke="none"/><path d="M8.2 10.2l.6-.6.6.6M14.6 10.2l.6-.6.6.6"/>'],
        'pores' => ['Pores (magnifier)', 'stroke-linecap="round"', '<circle class="f" cx="10.5" cy="10.5" r="6.8"/><path d="M15.4 15.4l4.6 4.6"/><g fill="currentColor" stroke="none"><circle cx="8.2" cy="8.6" r=".75"/><circle cx="11" cy="8" r=".75"/><circle cx="13.2" cy="10" r=".75"/><circle cx="8.6" cy="11.6" r=".75"/><circle cx="11.4" cy="11.2" r=".75"/><circle cx="10.2" cy="13.6" r=".75"/></g>'],
        'dull' => ['Glow (face with sparkles)', 'stroke-linecap="round" stroke-linejoin="round"', self::FACE.'<path d="M19.6 3.4l.5 1.5 1.5.5-1.5.5-.5 1.5-.5-1.5-1.5-.5 1.5-.5z" fill="currentColor"/><path d="M3.6 15.8l.4 1.1 1.1.4-1.1.4-.4 1.1-.4-1.1-1.1-.4 1.1-.4z" fill="currentColor"/><path d="M9.4 14.8c.7.6 1.6.9 2.6.9s1.9-.3 2.6-.9"/>'],
        // (20 Oct 2026) The owner's "Dull Skin" tab: a tired face — eyes half
        // closed — under a sparkle drawn in outline only, the glow gone flat.
        'dullskin' => ['Dull skin (tired face, faded sparkle)', 'stroke-linecap="round" stroke-linejoin="round"', self::FACE.'<path d="M8.4 11.6c.8.5 1.7.5 2.4 0M13.2 11.6c.8.5 1.7.5 2.4 0"/><path d="M10.4 16c1 .5 2.2.5 3.2 0"/><path d="M19.6 2.9l.6 1.7 1.7.6-1.7.6-.6 1.7-.6-1.7-1.7-.6 1.7-.6z" opacity=".55"/>'],
        'sun' => ['Sun care (sun and shield)', 'stroke-linecap="round" stroke-linejoin="round"', '<circle class="f" cx="9.5" cy="9.5" r="3.4"/><path d="M9.5 2.6v1.6M9.5 14.8v1.6M2.6 9.5h1.6M14.8 9.5h1.6M4.6 4.6l1.1 1.1M13.3 13.3l1.1 1.1M4.6 14.4l1.1-1.1M13.3 5.7l1.1-1.1"/><path d="M17 12.2l3.6 1.4v2.7c0 2.3-1.5 4.1-3.6 4.9-2.1-.8-3.6-2.6-3.6-4.9v-2.7z" fill="#fff"/><path d="M15.6 16.4l1 1 1.9-2"/>'],
    ];

    /** The heading's icon (the preview's magnifier over skin). A constant. */
    public const HEAD_ICON = '<svg viewBox="0 0 24 24" width="34" height="34" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="6.5"/><path d="M16 16l4.5 4.5"/><circle cx="9.5" cy="10" r=".9" fill="currentColor"/><circle cx="12.5" cy="12.5" r=".7" fill="currentColor"/></svg>';

    /**
     * The cart-plus, drawn ONCE per section as a <symbol> and used by every
     * card (48 copies of the drawing were 20 KB of the page). Constants, each
     * <svg> with its own width and height.
     */
    public const CART_SYMBOL = '<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false"><symbol id="sbc-cart" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M3 4h2l2.2 10.2a1.5 1.5 0 0 0 1.5 1.2h8.4a1.5 1.5 0 0 0 1.5-1.2L20 8H6.2"/><circle cx="9.5" cy="19.5" r="1.3"/><circle cx="16.5" cy="19.5" r="1.3"/><path d="M13 9.5v4M11 11.5h4"/></g></symbol></svg>';

    public const CART = '<svg width="20" height="20" aria-hidden="true" focusable="false"><use href="#sbc-cart"/></svg>';

    /** The preview's soft gradient per icon: [from, to]. Constants. */
    public const TONES = [
        'acne' => ['#FFE3E8', '#FFF4EC'], 'hydration' => ['#DDEFFF', '#F2F9FF'], 'spots' => ['#FBE6D6', '#FFF6EE'],
        'ageing' => ['#EDE3FA', '#FBF6FF'], 'sensitive' => ['#E0F3E6', '#F5FBF6'], 'pores' => ['#E6EEF2', '#F8FBFC'],
        'dull' => ['#FFF0C9', '#FFFAEC'], 'sun' => ['#FFE6C2', '#FFF7EA'],
        'dullskin' => ['#F1E9E2', '#FBF7F3'],
    ];

    /**
     * Each concern's collection, every address it may have on a shop (the
     * first that exists wins) — HomeConcerns' spellings, plus Glow and Sun.
     */
    public const COLLECTIONS = [
        'acne' => ['acne-blemish-care', 'acne-and-blemish-care', 'acne-blemish', 'acne-care', 'acne'],
        'hydration' => ['hydration-glow', 'hydration-and-glow', 'hydration', 'dryness-hydration'],
        'spots' => ['pigmentation', 'pigmentation-dark-spots', 'pigmentation-and-dark-spots', 'dark-spots'],
        'ageing' => ['anti-aging', 'anti-ageing', 'antiaging', 'fine-lines-ageing'],
        'sensitive' => ['redness-sensitive-skin', 'redness-and-sensitive-skin', 'sensitive-skin', 'redness-sensitive'],
        'pores' => ['pore-care', 'pores', 'oily-skin', 'oily'],
        'dull' => ['glow', 'dullness-glow', 'brightening', 'hydration-glow', 'hydration-and-glow'],
        'sun' => ['sun-care', 'sunscreens', 'sunscreen', 'sun-protection'],
        'dullskin' => ['dull-skin', 'dullness', 'brightening', 'glow', 'hydration-glow'],
    ];

    /** The eight tabs as shipped — the owner's words, 18 October 2026. */
    public const DEFAULT_TABS = [
        ['k' => 'acne', 'l' => 'Acne', 'la' => 'حب الشباب', 'h' => 'Acne & Blemishes', 'ha' => 'حب الشباب والشوائب',
            't' => 'Cleanse gently, twice a day. Use a 2% BHA (salicylic acid) three or four nights a week to keep pores clear, calm breakouts with centella, and never skip a light, oil-free moisturiser — dehydrated skin makes more oil.',
            'ta' => 'نظّفي بشرتكِ بلطف مرتين يوميًا. استخدمي BHA (حمض الساليسيليك) بتركيز 2% من ثلاث إلى أربع ليالٍ أسبوعيًا لتبقى المسام نظيفة، وهدّئي البثور بالسنتيلا، ولا تتخطّي مرطّبًا خفيفًا خاليًا من الزيوت — فالبشرة العطشى تفرز دهونًا أكثر.'],
        ['k' => 'hydration', 'l' => 'Hydration', 'la' => 'الترطيب', 'h' => 'Dryness & Hydration', 'ha' => 'الجفاف والترطيب',
            't' => 'Apply hyaluronic acid to damp skin, then seal it in with a ceramide cream. Skip hot water and harsh foaming cleansers — they strip the very barrier you are trying to repair.',
            'ta' => 'ضعي حمض الهيالورونيك على بشرة رطبة، ثم احبسيه بكريم السيراميد. تجنّبي الماء الساخن والمنظّفات الرغوية القوية — فهي تُضعف الحاجز الذي تحاولين إصلاحه.'],
        ['k' => 'spots', 'l' => 'Dark Spots', 'la' => 'البقع الداكنة', 'h' => 'Dark Spots', 'ha' => 'البقع الداكنة',
            't' => 'Niacinamide and tranexamic acid fade dark spots in 8–12 weeks — but only with SPF 50+ every single morning. Skip the sunscreen and the spots come back.',
            'ta' => 'يخفّف النياسيناميد وحمض الترانيكساميك البقع الداكنة خلال 8 إلى 12 أسبوعًا — لكن فقط مع واقي شمس SPF 50+ كل صباح. من دون واقي الشمس تعود البقع.'],
        ['k' => 'ageing', 'l' => 'Anti-Ageing', 'la' => 'مكافحة الشيخوخة', 'h' => 'Fine Lines & Ageing', 'ha' => 'الخطوط الدقيقة وعلامات التقدّم في السن',
            't' => 'Start retinol twice a week at night and build up slowly. In the morning: vitamin C, then SPF. Peptides and PDRN support firmness without the irritation.',
            'ta' => 'ابدئي بالريتينول مرتين أسبوعيًا ليلًا وزيدي تدريجيًا. وفي الصباح: فيتامين سي ثم واقي الشمس. الببتيدات وPDRN تدعم شدّ البشرة من دون تهيّج.'],
        ['k' => 'sensitive', 'l' => 'Redness', 'la' => 'الاحمرار', 'h' => 'Redness & Sensitivity', 'ha' => 'الاحمرار والحساسية',
            't' => 'Keep it short: a gentle cleanser, a centella or heartleaf serum and a barrier cream. Patch-test anything new along your jaw for three days first.',
            'ta' => 'اجعلي روتينكِ قصيرًا: منظّف لطيف، وسيروم بالسنتيلا أو الهارتليف، وكريم يحمي الحاجز. جرّبي أي منتج جديد على خط الفك لمدة ثلاثة أيام أولًا.'],
        ['k' => 'pores', 'l' => 'Pores', 'la' => 'المسام', 'h' => 'Pores & Oil Control', 'ha' => 'المسام والتحكّم في الدهون',
            't' => 'Oil-cleanse first to dissolve sebum, follow with a BHA toner, and use a clay mask once a week. Still moisturise — stripped skin only makes more oil.',
            'ta' => 'ابدئي بمنظّف زيتي لإذابة الدهون، ثم تونر BHA، واستخدمي قناع الطين مرة أسبوعيًا. ولا تتخلّي عن المرطّب — فالبشرة الجافة تفرز المزيد من الدهون.'],
        ['k' => 'dull', 'l' => 'Glow', 'la' => 'الإشراقة', 'h' => 'Dullness & Glow', 'ha' => 'البهتان والإشراقة',
            't' => 'Vitamin C in the morning, a gentle AHA or rice exfoliant two nights a week, and a hydrating essence every day. Glow comes from skin that is smooth and well hydrated.',
            'ta' => 'فيتامين سي صباحًا، ومقشّر لطيف بأحماض AHA أو الأرز ليلتين أسبوعيًا، وخلاصة مرطّبة كل يوم. الإشراقة تأتي من بشرة ناعمة ومرطّبة جيدًا.'],
        ['k' => 'sun', 'l' => 'Sun Care', 'la' => 'الحماية من الشمس', 'h' => 'Sun Protection', 'ha' => 'الحماية من الشمس',
            't' => 'SPF 50+ PA++++ every morning — even indoors by a window. Use two finger-lengths for face and neck, and reapply every two hours outdoors in the UAE sun.',
            'ta' => 'استخدمي واقي شمس SPF 50+ PA++++ كل صباح — حتى داخل المنزل قرب النافذة. ضعي ما يعادل طول إصبعين للوجه والرقبة، وجدّديه كل ساعتين في الخارج تحت شمس الإمارات.'],
    ];

    public const SUB_EN = 'Tell us what is bothering your skin — we picked what works.';

    public const SUB_AR = 'أخبرينا بما يزعج بشرتكِ — اخترنا لكِ ما ينفع.';

    /** Spread into HomepageContent::SCHEMA (after HomeConcerns::SCHEMA). */
    public const SCHEMA = [
        'home_sbc_title' => ['type' => 'text', 'label' => 'Heading (H2)', 'default' => 'Shop by Concern', 'store' => 'setting', 'max' => 160, 'help' => 'Empty hides the heading — keep one: every section should carry an H2.'],
        'home_sbc_title_ar' => ['type' => 'text', 'label' => 'Heading · Arabic', 'default' => 'تسوّقي حسب مشكلة البشرة', 'store' => 'setting', 'max' => 160, 'help' => 'Shown on /ar. Empty: the English heading.'],
        'home_sbc_icon' => ['type' => 'bool', 'label' => 'Icon beside the heading', 'default' => false, 'store' => 'setting', 'help' => 'The small magnifier before the heading. Off as shipped.'],
        'home_sbc_sub_on' => ['type' => 'bool', 'label' => 'Show the line under the heading', 'default' => false, 'store' => 'setting', 'help' => 'Off as shipped. The words are below, in both languages.'],
        'home_sbc_sub' => ['type' => 'text', 'label' => 'Line under the heading', 'default' => self::SUB_EN, 'store' => 'setting', 'max' => 240, 'help' => 'Only while the switch above is on.'],
        'home_sbc_sub_ar' => ['type' => 'text', 'label' => 'Line under the heading · Arabic', 'default' => self::SUB_AR, 'store' => 'setting', 'max' => 240, 'help' => 'Shown on /ar. Empty: the English line.'],
        'home_sbc_tip' => ['type' => 'text', 'label' => 'Tip badge', 'default' => 'TIP', 'store' => 'setting', 'max' => 24, 'help' => 'The small pink badge on the panel. Empty: no badge.'],
        'home_sbc_tip_ar' => ['type' => 'text', 'label' => 'Tip badge · Arabic', 'default' => 'نصيحة', 'store' => 'setting', 'max' => 24, 'help' => 'Shown on /ar. Empty: the English word.'],
        'home_sbc_all' => ['type' => 'text', 'label' => 'View all button', 'default' => 'View all', 'store' => 'setting', 'max' => 40, 'help' => 'Each tab’s button opens that tab’s collection. Empty: no button.'],
        'home_sbc_all_ar' => ['type' => 'text', 'label' => 'View all button · Arabic', 'default' => 'عرض الكل', 'store' => 'setting', 'max' => 40, 'help' => 'Shown on /ar. Empty: the English words.'],
        'home_sbc_tabs' => ['type' => 'textarea', 'label' => 'Concern tabs, in order', 'default' => '', 'store' => 'setting', 'max' => 60000,
            'options' => ['picker' => 'sbc-tabs'], 'rule' => [self::class, 'clean'],
            'help' => 'Each tab: its words in English and Arabic, its icon, its photo and where its products come from. A tab with no products to show is left out.'],
        'home_sbc_count' => ['type' => 'select', 'label' => 'Products per tab', 'default' => '6', 'store' => 'setting', 'options' => self::COUNTS, 'help' => 'Shipped: 6 — three rows of two on a laptop. A tab can set its own.'],
        'home_sbc_accent' => ['type' => 'colour', 'label' => 'Accent colour', 'default' => '#E0567B', 'store' => 'setting', 'help' => 'The chosen tab, the tip badge and the cart buttons. Shipped: the design’s pink.'],
        'home_sbc_card' => ['type' => 'colour', 'label' => 'Product card colour', 'default' => '#F6F2F3', 'store' => 'setting', 'help' => 'Behind each product. Shipped: soft grey-pink.'],
        'home_sbc_bg' => ['type' => 'colour', 'label' => 'Section background', 'default' => '#FFFFFF', 'store' => 'setting', 'help' => 'Shipped: white.'],
    ];

    /** tab => [label, description, keys] — placed in HomeSections::TABS right after Skin concerns. */
    public const TAB = ['Shop by Concern', 'Concern tabs with a tip panel and products, under Skin concerns. Switch it on in Appearance → Homepage (it ships off). The tabs (words, icons, photos, products), the heading and the colours — laptop and phone.', [
        'home_sbc_title', 'home_sbc_title_ar', 'home_sbc_icon', 'home_sbc_sub_on', 'home_sbc_sub', 'home_sbc_sub_ar',
        'home_sbc_tip', 'home_sbc_tip_ar', 'home_sbc_all', 'home_sbc_all_ar', 'home_sbc_tabs', 'home_sbc_count',
        'home_sbc_accent', 'home_sbc_card', 'home_sbc_bg',
    ]];

    /**
     * The `home_sbc_tabs` rule (ModuleSchema `rule`): runs on save AND on every
     * read. '' stays '' — "never set", the eight shipped tabs; anything else
     * becomes a JSON list of clean rows, possibly `[]` (every tab removed).
     */
    public static function clean(mixed $raw, array $field = []): string
    {
        if (is_string($raw) && trim($raw) === '') {
            return '';
        }

        return (string) json_encode(self::rows($raw), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * The tab rows, cleaned. Each is
     *   k     the shipped concern it began as ('' for one the owner added) — picks its collection
     *   on    shown or not
     *   i     an ICONS key
     *   l la  tab label; h ha  title; t ta  tip — plain text, English / Arabic
     *   img   background photo, imga its Arabic twin ('' = the same photo)
     *   src   a SOURCES key; cats / brands / picks  ids for it; sort  a ProductSource::SORTS key
     *   n     products (0 = the section's number)
     *   u     where View all goes ('' = the tab's collection)
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = trim($raw) === '' ? self::defaults() : (strlen($raw) > 60000 ? [] : json_decode($raw, true));
        }

        $out = [];

        foreach (is_array($raw) ? array_values($raw) : [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $k = is_string($row['k'] ?? null) && isset(self::COLLECTIONS[$row['k']]) ? $row['k'] : '';
            $icon = is_string($row['i'] ?? null) && isset(self::ICONS[$row['i']]) ? $row['i'] : ($k !== '' ? $k : 'acne');
            $src = is_string($row['src'] ?? null) && isset(self::SOURCES[$row['src']]) ? $row['src'] : 'auto';
            $sort = is_string($row['sort'] ?? null) && isset(ProductSource::SORTS[$row['sort']]) ? $row['sort'] : 'bestselling';
            $n = filter_var($row['n'] ?? 0, FILTER_VALIDATE_INT);
            $n = is_int($n) && isset(self::COUNTS[(string) $n]) ? $n : 0;

            $out[] = [
                'k' => $k,
                'on' => filter_var($row['on'] ?? true, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true,
                'i' => $icon,
                'l' => self::text($row['l'] ?? '', self::MAX['l']),
                'la' => self::text($row['la'] ?? '', self::MAX['l']),
                'h' => self::text($row['h'] ?? '', self::MAX['h']),
                'ha' => self::text($row['ha'] ?? '', self::MAX['h']),
                't' => self::text($row['t'] ?? '', self::MAX['t']),
                'ta' => self::text($row['ta'] ?? '', self::MAX['t']),
                'img' => self::picture($row['img'] ?? ''),
                'imga' => self::picture($row['imga'] ?? ''),
                'src' => $src,
                'cats' => ProductSource::ids($row['cats'] ?? [], ProductSource::CAP_TERMS),
                'brands' => ProductSource::ids($row['brands'] ?? [], ProductSource::CAP_TERMS),
                'picks' => ProductSource::ids($row['picks'] ?? [], ProductSource::CAP_PICKS),
                'sort' => $sort,
                'n' => $n,
                'u' => self::link($row['u'] ?? ''),
            ];

            if (count($out) >= self::CAP) {
                break;
            }
        }

        return $out;
    }

    /** The shipped eight, in the shape rows() keeps. */
    public static function defaults(): array
    {
        return array_map(fn (array $d) => $d + ['on' => true, 'i' => $d['k'], 'img' => '', 'imga' => '', 'src' => 'auto', 'cats' => [], 'brands' => [], 'picks' => [], 'sort' => 'bestselling', 'n' => 0, 'u' => ''], self::DEFAULT_TABS);
    }

    /** Plain text: tags and control characters gone, whitespace folded, capped. */
    private static function text(mixed $raw, int $max): string
    {
        if (! is_string($raw)) {
            return '';
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\x00-\x1F\x7F]/u', ' ', strip_tags($raw))));

        return mb_substr($text, 0, $max);
    }

    /** A picture address: what HomeSections::image() would draw, else ''. */
    private static function picture(mixed $raw): string
    {
        return is_string($raw) && strlen($raw) <= 500 ? HomeSections::image($raw) : '';
    }

    /** A View all address as typed: a path on this shop or http(s), else ''. Url::to() is added at render. */
    private static function link(mixed $raw): string
    {
        if (! is_string($raw) || strlen($raw) > 500) {
            return '';
        }

        $url = trim($raw);

        if ($url === '' || preg_match('/[\x00-\x20\x7F"<>\\\\]/', $url) === 1) {
            return '';
        }

        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return $url;
        }

        return preg_match('#^https?://[^\s"<>]+$#i', $url) === 1 ? $url : '';
    }

    /** The shown tabs and how many products each asks for. */
    private static function shown(array $c): array
    {
        $count = (int) HomeSections::pick($c, 'home_sbc_count');
        $out = [];

        foreach (self::rows($c['home_sbc_tabs'] ?? '') as $row) {
            if ($row['on']) {
                $row['limit'] = $row['n'] > 0 ? $row['n'] : max(2, $count);
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * Everything the section reads from the database, in ONE cache entry under
     * the homepage's generation. Three round trips cold, none warm, whatever
     * the number of tabs or products.
     *
     * @return array{cats: Collection, ids: array<int, list<int>>, products: Collection}
     */
    public static function data(array $c): array
    {
        $tabs = self::shown($c);
        $key = 'kbb.home.sbc.'.(int) Cache::get(self::GENERATION, 0).'.'.md5((string) json_encode([$tabs, ProductSource::daySeed()]));

        return Cache::remember($key, self::TTL, fn () => self::fetch($tabs));
    }

    /** @param list<array<string, mixed>> $tabs */
    private static function fetch(array $tabs): array
    {
        $empty = ['cats' => collect(), 'ids' => [], 'products' => collect()];

        if ($tabs === []) {
            return $empty;
        }

        // 1. Every collection a tab may need — its concern's addresses and the
        //    categories it chose — in one query.
        $slugs = [];
        $ids = [];

        foreach ($tabs as $t) {
            if ($t['k'] !== '') {
                array_push($slugs, ...self::COLLECTIONS[$t['k']]);
            }

            if ($t['src'] === 'category') {
                array_push($ids, ...$t['cats']);
            }
        }

        $cats = Category::query()->select('id', 'name', 'slug', 'path', 'parent_id')
            ->where(function ($q) use ($ids, $slugs) {
                $q->whereIn('slug', array_values(array_unique($slugs) ?: ['']));

                if ($ids !== []) {
                    $q->orWhereIn('id', array_values(array_unique($ids)));
                }
            })->get();

        // 2. Each tab's product selection, as the shop's own query machinery
        //    builds it, in ONE round trip.
        $parts = [];

        foreach ($tabs as $i => $t) {
            $parts[$i] = self::part($t, $cats);
        }

        $want = array_filter($parts, fn ($p) => $p !== null);
        $found = $want === [] ? [] : app(GridSections::class)->idsForMany($want);

        // 3. The products, with their brands.
        $all = array_values(array_unique(array_merge([], ...array_values($found))));

        $products = $all === [] ? collect() : Product::query()->select(GridSections::CARD_COLUMNS)
            ->with('brand:id,name,slug')->whereIn('id', $all)->get()->keyBy('id');

        return ['cats' => $cats, 'ids' => $found, 'products' => $products];
    }

    /**
     * One tab's selection for GridSections::idsForMany(), or null when it can
     * select nothing (its collection is not on this shop, nothing chosen).
     */
    private static function part(array $t, Collection $cats): ?array
    {
        $q = ['brands' => [], 'cats' => [], 'sort' => $t['sort'], 'stock' => true];

        switch ($t['src']) {
            case 'manual':
                return $t['picks'] === [] ? null : ['query' => $q, 'picks' => $t['picks'], 'limit' => $t['limit']];
            case 'brands':
                if ($t['brands'] === []) {
                    return null;
                }
                $q['brands'] = $t['brands'];
                break;
            case 'category':
                if ($t['cats'] === []) {
                    return null;
                }
                $q['cats'] = $t['cats'];
                break;
            default:
                $own = self::collection($t, $cats);

                if ($own === null) {
                    return null;
                }
                $q['cats'] = [(int) $own->id];
        }

        return ['query' => $q, 'picks' => [], 'limit' => $t['limit']];
    }

    /** The tab's concern collection: the first of its addresses this shop has. */
    private static function collection(array $t, iterable $cats): ?Category
    {
        if ($t['k'] === '') {
            return null;
        }

        $bySlug = [];

        foreach ($cats as $cat) {
            $bySlug[(string) $cat->slug] = $cat;
        }

        foreach (self::COLLECTIONS[$t['k']] as $slug) {
            if (isset($bySlug[$slug])) {
                return $bySlug[$slug];
            }
        }

        return null;
    }

    /**
     * The section as the template draws it, or null when it draws nothing (no
     * tab has a product to show).
     *
     * @param  array{cats: Collection, ids: array, products: Collection}  $data
     * @return array<string, mixed>|null
     */
    public static function section(array $c, array $data, ?bool $arabic = null, bool $aboveFold = false): ?array
    {
        $arabic ??= Locale::current() === 'ar';
        $tabs = [];
        $catsById = $data['cats']->keyBy('id');
        $pricing = app(VariantPricing::class);

        foreach (self::shown($c) as $i => $t) {
            $items = [];

            foreach ($data['ids'][$i] ?? [] as $id) {
                $p = $data['products']->get($id);

                if ($p !== null && $p->stock_status === 'instock') {
                    $items[] = self::card($p, $pricing);
                }
            }

            if ($items === []) {
                continue;
            }

            $tabs[] = self::tab($t, $items, $catsById, $data['products'], $arabic, $tabs === [] && $aboveFold);
        }

        if ($tabs === []) {
            return null;
        }

        $text = static function (string $key) use ($c, $arabic): string {
            $en = HomeSections::text($c, $key);

            if (! $arabic) {
                return $en;
            }

            $ar = HomeSections::text($c, $key.'_ar');

            return $ar !== '' ? $ar : ArabicWording::of($en);
        };

        return [
            'style' => '--sbc-pk:'.self::hex($c, 'home_sbc_accent').';--sbc-card:'.self::hex($c, 'home_sbc_card').';--sbc-bg:'.self::hex($c, 'home_sbc_bg'),
            'title' => $text('home_sbc_title'),
            'icon' => (bool) ($c['home_sbc_icon'] ?? false),
            'sub' => (bool) ($c['home_sbc_sub_on'] ?? false) ? $text('home_sbc_sub') : '',
            'tip' => $text('home_sbc_tip'),
            'all' => $text('home_sbc_all'),
            'add' => __('store.product_card.add_to_cart'),
            'view' => __('store.product_card.view_product'),
            'tabs' => $tabs,
        ];
    }

    /** One tab: its words in the page's language, its picture or gradient, its link and its cards. */
    private static function tab(array $t, array $items, Collection $catsById, Collection $products, bool $arabic, bool $eager): array
    {
        $shipped = [];

        foreach (self::DEFAULT_TABS as $d) {
            if ($d['k'] === $t['k']) {
                $shipped = $d;
            }
        }

        $word = static function (string $en, string $ar, string $f) use ($arabic, $shipped): string {
            if (! $arabic) {
                return $en;
            }

            if ($ar !== '') {
                return $ar;
            }

            // An English word left as shipped keeps its shipped Arabic.
            return $en !== '' && $en === ($shipped[$f] ?? null) ? (string) $shipped[$f.'a'] : ArabicWording::of($en);
        };

        $label = $word($t['l'], $t['la'], 'l');
        $title = $word($t['h'], $t['ha'], 'h');

        $src = $arabic && $t['imga'] !== '' ? $t['imga'] : $t['img'];
        $src = $src !== '' ? HomeSections::image($src) : '';

        // View all: the owner's own address, else the tab's collection.
        $url = $t['u'] !== '' ? HomeSections::url($t['u'], '') : '';

        if ($url === '') {
            $cat = match ($t['src']) {
                'category' => $catsById->get($t['cats'][0] ?? 0),
                default => self::collection($t, $catsById->values()),
            };

            if ($cat === null && $t['src'] === 'brands') {
                foreach ($products as $p) {
                    if ((int) $p->brand_id === (int) ($t['brands'][0] ?? 0) && $p->brand !== null) {
                        $url = $p->brand->url();
                        break;
                    }
                }
            }

            $url = $cat !== null ? $cat->url() : $url;
        }

        $tone = self::TONES[$t['i']];

        return [
            'label' => $label !== '' ? $label : $title,
            'title' => $title,
            'tip' => $word($t['t'], $t['ta'], 't'),
            'icon' => self::svg($t['i']),
            'grad' => 'linear-gradient(135deg,'.$tone[0].','.$tone[1].')',
            'img' => $src,
            'srcset' => $src !== '' ? self::photoSrcset($src) : '',
            'eager' => $eager,
            'url' => $url,
            'items' => $items,
        ];
    }

    /** One product card's words and numbers, read the way the shop's grid card reads them. */
    private static function card(Product $p, VariantPricing $pricing): array
    {
        $img = LostPictures::usable($p->image);
        $off = $p->isOnSale() ? $p->discountPercent() : 0;
        $range = $pricing->range($p);

        if ($range !== null) {
            $dp = Money::decimalsToDistinguish($range[0], $range[1]);
            $price = VariantPricing::isSpread($range)
                ? __('store.product_card.price_range', ['low' => Money::format($range[0], $dp), 'high' => Money::format($range[1], $dp)])
                : Money::format($range[0]);
        } else {
            $price = Money::format($p->effectivePrice());
        }

        /*
         * The cut price, as the grid card prints it (the owner, 20 Oct 2026:
         * "i don't need to show discount %, i need the cut price, same like in
         * our products grid"): the compare-at price struck through, with the
         * same decimals rule as components/product-card. Only for a single
         * price on sale -- a range shows the range alone, as the card does.
         */
        $was = '';
        if ($range === null && $p->isOnSale()) {
            $wasMinor = (int) $p->compareAtPrice();
            if ($wasMinor > $p->effectivePrice()) {
                $dp = Money::decimalsToDistinguish($wasMinor, $p->effectivePrice());
                $was = Money::format($wasMinor, $dp);
                $price = Money::format($p->effectivePrice(), $dp);
            }
        }

        return [
            'id' => (int) $p->id,
            'url' => $p->url(),
            'name' => (string) $p->t('name'),
            'brand' => (string) ($p->brand?->t('name') ?? ''),
            'img' => (string) ($img ?? ''),
            'alt' => $img ? $p->altFor($img) : '',
            // The thumb is 84px (132px as a panel circle): copies up to 400w
            // cover a 3x screen; the 800w candidate no browser would pick is left out.
            'srcset' => $img ? self::upTo(ImageVariants::srcsetFor($img), 400) : '',
            // No photograph: the grid card's own gradient and initials.
            'ph' => $img ? '' : Gradient::for(($p->brand?->name ?? '').$p->name),
            'ini' => $img ? '' : Gradient::initials((string) ($p->brand?->name ?: $p->name)),
            'off' => $off >= 1 ? Bidi::number($off.'%') : '',
            // Money::format()'s own markup, struck through; '' when not on sale.
            'was' => $was,
            // Money::format()'s own markup — the grid card prints the same string raw.
            'price' => $price,
            'add' => $p->isDirectlyBuyable(),
            'pid' => $p->publicId(),
            'amount' => number_format($p->effectivePrice() / 100, 2, '.', ''),
        ];
    }

    /**
     * A tab photo's srcset: the banner tier (the panel is up to ~480px wide, a
     * phone's full width). A photo the Media Library has not sized yet has no
     * copies on disk; srcsetFor() then schedules them after the response, so
     * the next visitor gets the srcset rather than the original for ever.
     */
    private static function photoSrcset(string $src): string
    {
        $srcset = ImageVariants::bannerSrcsetFor($src);

        if ($srcset === '') {
            ImageVariants::srcsetFor($src);
        }

        return $srcset;
    }

    /** A srcset with only the candidates at or under $max px wide. */
    private static function upTo(string $srcset, int $max): string
    {
        $keep = [];

        foreach (explode(',', $srcset) as $candidate) {
            if (preg_match('/\s(\d+)w\s*$/', $candidate, $m) === 1 && (int) $m[1] <= $max) {
                $keep[] = trim($candidate);
            }
        }

        return implode(', ', $keep);
    }

    /** One icon as inline SVG: a constant, with its own width and height. */
    public static function svg(string $key): string
    {
        $icon = self::ICONS[$key] ?? self::ICONS['acne'];

        return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.4" '.$icon[1].' aria-hidden="true" focusable="false">'.$icon[2].'</svg>';
    }

    /** A colour setting as `#RRGGBB`, or its default — checked again here, as it is printed into a style. */
    private static function hex(array $c, string $key): string
    {
        $v = strtoupper(trim((string) ($c[$key] ?? '')));

        return preg_match('/^#[0-9A-F]{6}$/', $v) === 1 ? $v : (string) self::SCHEMA[$key]['default'];
    }

    /**
     * What the admin's tab editor needs that the schema cannot carry: the
     * icons, the sources, the sorts, the counts and the shipped tabs. Constants only.
     *
     * @return array<string, mixed>
     */
    public static function editorVocab(): array
    {
        $icons = [];

        foreach (self::ICONS as $k => $icon) {
            $icons[] = ['key' => $k, 'label' => $icon[0], 'svg' => self::svg($k), 'grad' => 'linear-gradient(135deg,'.self::TONES[$k][0].','.self::TONES[$k][1].')'];
        }

        return [
            'icons' => $icons,
            'sources' => self::SOURCES,
            'sorts' => ProductSource::SORTS,
            'counts' => array_keys(self::COUNTS),
            'defaults' => self::defaults(),
            'collections' => self::COLLECTIONS,
            'cap' => self::CAP,
        ];
    }
}

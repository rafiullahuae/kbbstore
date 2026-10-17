<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Category;
use Illuminate\Support\Collection;

/**
 * Shop by skin concern — the homepage's concern chips.            (Lane CX)
 *
 * THE OWNER, 14 October 2026: "E · Concern chips is fine. make the section,
 * and give full control on backend in homepage sections page, under Trending
 * K-Beauty This Week section, with beautiful light background. also link the
 * collections pages to whole each capsule."
 *
 * The look is design E of docs/skin-concerns-options/index.html — a pill per
 * concern with the duotone icon (Set 4, the set he picked earlier) in a white
 * circle on the concern's own pastel — on a soft blush-to-cream band. It ships
 * ON, directly after Trending (HomepageSections::REGISTRY and FOLLOWS), because
 * he asked for it.
 *
 * ── WHAT THE OWNER CONTROLS ─────────────────────────────────────────────────
 *
 * Appearance → Homepage content → Skin concerns (and the same fields in the
 * All sections → Skin concerns → Edit content popup): the heading, line and
 * small line above it, each with its Arabic; the chips — an ordered list,
 * each row a category, an optional label (English and Arabic), an icon and a
 * colour; the band's background; chip sizes per device; alignment; whether a
 * phone scrolls the chips sideways or wraps them; spacing. Show/hide per
 * device is the section's own Laptop / Phone switch, as for every section.
 *
 * ── SECURITY ────────────────────────────────────────────────────────────────
 *
 * The chip list is ONE setting, a JSON list cleaned by items() on save AND on
 * every read (ModuleSchema `rule`), so a hand-edited row reaches the page as
 * ids, slugs, plain text and option keys or not at all. Every value printed
 * into a class or a style is an option key, an integer from a select, a
 * colour that passed the hex check, or a constant of this class. Every word is
 * printed through {{ }}. The icons are constants. The link is the category's
 * own url() — App\Support\UrlScheme through Url::to(), never a typed address.
 *
 * ── COST ────────────────────────────────────────────────────────────────────
 *
 * ONE query for every chip (fetch(): `id IN (...) OR slug IN (...)`), made
 * inside HomeSections::data()'s cache entry — a warm homepage pays nothing,
 * a cold one pays one. A row whose category is gone, or a default whose
 * category this shop does not have, is not drawn; with none left the section
 * prints nothing at all. No script: a phone's sideways row is CSS scroll-snap.
 */
final class HomeConcerns
{
    /** The icon set — Set 4 · Duotone of the design page: key => [label, accent shapes, line drawing]. */
    public const ICONS = [
        'pores' => ['Pores (magnifier)',
            '<circle class="hs-cc-a" cx="22.5" cy="22.5" r="9"/>',
            '<circle cx="21" cy="21" r="12"/><path d="M29.5 29.5 39 39"/><g fill="currentColor" stroke="none"><circle cx="17.2" cy="19" r="1.25"/><circle cx="24.2" cy="17.6" r="1.25"/><circle cx="21.4" cy="24.6" r="1.25"/></g>'],
        'spots' => ['Dark spots (sun and spot)',
            '<circle class="hs-cc-a" cx="37" cy="11" r="3"/><circle class="hs-cc-a" cx="23" cy="29" r="10"/>',
            '<circle cx="21" cy="27" r="13"/><circle cx="17" cy="24.5" r="3"/><circle cx="37" cy="11" r="3"/><path d="M37 4.5V6M37 16v1.5M30.5 11H32M42 11h1.5M32.4 6.4l1 1M41.6 6.4l-1 1M41.6 15.6l-1-1"/><g fill="currentColor" stroke="none"><circle cx="23.6" cy="28.4" r="1.35"/><circle cx="26.8" cy="31.4" r="1"/><circle cx="29.3" cy="34" r=".7"/></g>'],
        'acne' => ['Acne (patch)',
            '<circle class="hs-cc-a" cx="26" cy="26" r="11"/>',
            '<circle cx="24" cy="24" r="14.5"/><circle cx="24" cy="24" r="9" stroke-dasharray="0 3.534"/><circle cx="24" cy="24" r="1.7" fill="currentColor" stroke="none"/>'],
        'aging' => ['Anti-aging (profile)',
            '<circle class="hs-cc-a" cx="21" cy="21" r="10"/>',
            '<path d="M15.5 42V31.5c-3.3-2.8-5-6.6-5-11.5 0-7.5 5.5-13 12.5-13 6.5 0 10.5 4.5 10.5 10l3 5c.4.7.1 1.3-.6 1.5l-1.3.4.3 1.7-.9.8.6 1c.3.8-.2 1.5-1 1.6l-.2 1.5c-.2 1.5-1.4 2.5-3 2.5H28V42"/><path d="M26.6 18.6q1.6 1.1 3.2 0"/><path d="M41 5v6M38 8h6"/>'],
        'hydra' => ['Hydration (drop)',
            '<circle class="hs-cc-a" cx="26" cy="30" r="9"/>',
            '<path d="M24 6s12 12.5 12 21.5a12 12 0 0 1-24 0C12 18.5 24 6 24 6z"/><path d="M18 28.5a6 6 0 0 0 4 5"/><path d="M39.5 7v6M36.5 10h6"/>'],
        'calm' => ['Redness & sensitive (cica leaf)',
            '<circle class="hs-cc-a" cx="26" cy="22" r="10"/>',
            '<path d="M24 31c-3.5 3-12 2-13-6.5C10 15.5 16 9.5 24 9.5s14 6 13 15c-1 8.5-9.5 9.5-13 6.5z"/><path d="M24 31V16M24 31l-7-11.5M24 31l7-11.5M24 31c0 5-1 8.5-3.5 11"/>'],
        'oily' => ['Oily skin (blotting sheet)',
            '<path class="hs-cc-a" d="M25 18s4.5 4.8 4.5 8a4.5 4.5 0 0 1-9 0c0-3.2 4.5-8 4.5-8z"/>',
            '<path d="M13 9h20a3 3 0 0 1 3 3v19l-7 7H13a3 3 0 0 1-3-3V12a3 3 0 0 1 3-3z"/><path d="M36 31h-4a3 3 0 0 0-3 3v4"/><path d="M23 14.5s5.5 5.8 5.5 9.8a5.5 5.5 0 0 1-11 0c0-4 5.5-9.8 5.5-9.8z"/>'],
    ];

    /** The design page's pastel per concern: key => [label, chip background, icon accent]. */
    public const TONES = [
        'pink' => ['Pink', '#FCE6EE', '#F2AFC5'],
        'peach' => ['Peach', '#FFEBDD', '#F7BE9E'],
        'mint' => ['Mint', '#E3F4EA', '#A9D9BD'],
        'lavender' => ['Lavender', '#EFE6F8', '#CDB8EC'],
        'sky' => ['Sky', '#E0F1FB', '#A9D2F0'],
        'sage' => ['Sage', '#EDF4E4', '#BEDBA7'],
        'butter' => ['Butter', '#FFF4D8', '#F3D184'],
        'white' => ['White', '#FFFFFF', '#F6D3DF'],
    ];

    /** An icon's own colour, used when a row says "auto". */
    public const ICON_TONE = ['pores' => 'pink', 'spots' => 'peach', 'acne' => 'mint', 'aging' => 'lavender', 'hydra' => 'sky', 'calm' => 'sage', 'oily' => 'butter'];

    /**
     * The seven concern collections on the live shop, in the design's order.
     * `s` is every address the category may have (the first that exists wins;
     * the 2027_11_01 migration's spellings), resolved at render, so a fresh
     * install that has none of them draws nothing.
     */
    public const DEFAULT_ITEMS = [
        ['s' => ['pore-care', 'pores'], 'i' => 'pores', 'la' => 'العناية بالمسام'],
        ['s' => ['pigmentation', 'pigmentation-dark-spots', 'pigmentation-and-dark-spots', 'dark-spots'], 'i' => 'spots', 'la' => 'التصبّغات والبقع الداكنة'],
        ['s' => ['acne-blemish-care', 'acne-and-blemish-care', 'acne-blemish', 'acne-care'], 'i' => 'acne', 'la' => 'حبّ الشباب والشوائب'],
        ['s' => ['anti-aging', 'anti-ageing', 'antiaging'], 'i' => 'aging', 'la' => 'مكافحة الشيخوخة'],
        ['s' => ['hydration-glow', 'hydration-and-glow', 'hydration'], 'i' => 'hydra', 'la' => 'الترطيب والنضارة'],
        ['s' => ['redness-sensitive-skin', 'redness-and-sensitive-skin', 'sensitive-skin', 'redness-sensitive'], 'i' => 'calm', 'la' => 'الاحمرار والبشرة الحسّاسة'],
        ['s' => ['oily-skin', 'oily'], 'i' => 'oily', 'la' => 'البشرة الدهنية'],
    ];

    /** The most chips one section draws. */
    public const CAP = 12;

    /** The longest label kept, in characters. */
    public const LABEL_MAX = 60;

    /** The circle's line colour — the design's rose (#B03A62). A constant, never a setting. */
    public const LINE = '#B03A62';

    private const PX = ['0' => '0px', '4' => '4px', '8' => '8px', '12' => '12px', '16' => '16px', '20' => '20px', '24' => '24px', '28' => '28px', '32' => '32px', '40' => '40px', '48' => '48px', '56' => '56px', '64' => '64px', '72' => '72px', '80' => '80px', '96' => '96px'];

    private const CIRCLE = ['36' => '36px', '40' => '40px', '44' => '44px', '48' => '48px', '52' => '52px', '56' => '56px', '60' => '60px', '64' => '64px', '72' => '72px'];

    private const TEXT = ['12' => '12px', '13' => '13px', '14' => '14px', '15' => '15px', '16' => '16px', '17' => '17px', '18' => '18px'];

    private const GAP = ['4' => '4px', '6' => '6px', '8' => '8px', '10' => '10px', '12' => '12px', '14' => '14px', '16' => '16px', '20' => '20px', '24' => '24px'];

    /** Spread into HomepageContent::SCHEMA (after HomeSections::SCHEMA). */
    public const SCHEMA = [
        'home_cc_eyebrow' => ['type' => 'text', 'label' => 'Small line above the heading', 'default' => '', 'store' => 'setting', 'max' => 120, 'help' => 'Empty draws none.'],
        'home_cc_eyebrow_ar' => ['type' => 'text', 'label' => 'Small line above the heading · Arabic', 'default' => '', 'store' => 'setting', 'max' => 120, 'help' => 'Shown on /ar. Empty: the English line.'],
        'home_cc_title' => ['type' => 'text', 'label' => 'Heading (H2)', 'default' => 'Shop by skin concern', 'store' => 'setting', 'max' => 160, 'help' => 'Written for search. Empty hides the heading — keep one: every section should carry an H2.'],
        'home_cc_title_ar' => ['type' => 'text', 'label' => 'Heading · Arabic', 'default' => 'تسوّقي حسب مشكلة البشرة', 'store' => 'setting', 'max' => 160, 'help' => 'Shown on /ar. Empty: the English heading.'],
        'home_cc_sub' => ['type' => 'text', 'label' => 'Line under the heading', 'default' => '', 'store' => 'setting', 'max' => 240, 'help' => 'Empty draws none.'],
        'home_cc_sub_ar' => ['type' => 'text', 'label' => 'Line under the heading · Arabic', 'default' => '', 'store' => 'setting', 'max' => 240, 'help' => 'Shown on /ar. Empty: the English line.'],
        'home_cc_items' => ['type' => 'textarea', 'label' => 'Concern chips, in order', 'default' => '', 'store' => 'setting', 'max' => 20000,
            'options' => ['picker' => 'concern-chips'], 'rule' => [self::class, 'clean'],
            'help' => 'Each chip opens its collection — the whole capsule is the link. Pick the category; the label is its name unless you type one. A chip whose category is deleted is not shown.'],
        'home_cc_btn' => ['type' => 'text', 'label' => 'Button text', 'default' => '', 'store' => 'setting', 'max' => 60, 'help' => 'E.g. “View all”. Empty: no button.'],
        'home_cc_btn_ar' => ['type' => 'text', 'label' => 'Button text · Arabic', 'default' => '', 'store' => 'setting', 'max' => 60, 'help' => 'Shown on /ar. Empty: the English text.'],
        'home_cc_url' => ['type' => 'text', 'label' => 'Button link', 'default' => '', 'store' => 'setting', 'max' => 500, 'help' => 'A path on this shop (/…) or a full https:// address; anything else is ignored.'],
        'home_cc_bg' => ['type' => 'select', 'label' => 'Section background', 'default' => 'gradient', 'store' => 'setting', 'options' => ['gradient' => 'Soft gradient — the two colours below', 'solid' => 'One colour — the first colour below', 'none' => 'None — the page shows through'], 'help' => ''],
        'home_cc_bg_from' => ['type' => 'colour', 'label' => 'Background colour 1', 'default' => '#FFF6F0', 'store' => 'setting', 'help' => 'Light colours keep the chips readable. Shipped: warm cream.'],
        'home_cc_bg_to' => ['type' => 'colour', 'label' => 'Background colour 2', 'default' => '#FCEAF2', 'store' => 'setting', 'help' => 'The gradient’s other end. Shipped: blush pink.'],
        'home_cc_bg_dir' => ['type' => 'select', 'label' => 'Gradient direction', 'default' => '115', 'store' => 'setting', 'options' => ['90' => 'Side to side', '115' => 'Diagonal', '180' => 'Top to bottom'], 'help' => ''],
        'home_cc_align' => ['type' => 'select', 'label' => 'Alignment', 'default' => 'left', 'store' => 'setting', 'options' => ['left' => 'Start — heading and chips from the left (design E)', 'center' => 'Centre'], 'help' => 'On /ar “Start” is the right-hand side.'],
        'home_cc_layout_m' => ['type' => 'select', 'label' => 'Chips · phone', 'default' => 'scroll', 'store' => 'setting', 'options' => ['scroll' => 'One row that scrolls sideways', 'wrap' => 'Wrap onto more rows'], 'help' => '900px and narrower. The row snaps chip by chip; the next chip peeking shows there is more.'],
        // 2.60.509, the owner: "this will be carousel, one row." -- moved default.
        'home_cc_layout_d' => ['type' => 'select', 'label' => 'Chips · laptop', 'default' => 'scroll', 'store' => 'setting', 'options' => ['scroll' => 'One row, a carousel with arrows', 'wrap' => 'Wrap onto more rows'], 'help' => 'One row scrolls sideways with the arrows below; Wrap shows every chip at once.'],
        // 2.60.510, the owner: "freely move as per the user scroll ... not limits ... smooth, super calm." -- moved default.
        'home_cc_snap' => ['type' => 'select', 'label' => 'Row stops', 'default' => 'free', 'store' => 'setting', 'options' => ['free' => 'Freely — where the shopper lets go', 'chip' => 'Chip by chip — snaps to the nearest chip'], 'help' => 'For a one-row layout. Free scrolls like the page; on a laptop a mouse can also drag the row, which glides gently to a stop.'],
        'home_cc_arrows_d' => ['type' => 'bool', 'label' => 'Arrows · laptop', 'default' => true, 'store' => 'setting', 'help' => 'The round previous / next buttons, as on the product carousels. Only when the laptop row is one row.'],
        'home_cc_arrows_m' => ['type' => 'bool', 'label' => 'Arrows · phone', 'default' => false, 'store' => 'setting', 'help' => 'A phone swipes; arrows are optional. Only when the phone row is one row.'],
        'home_cc_ic_d' => ['type' => 'select', 'label' => 'Icon circle · laptop', 'default' => '60', 'store' => 'setting', 'options' => self::CIRCLE, 'help' => 'The white circle the icon sits in; the chip is this plus 10px tall.'],
        'home_cc_ic_m' => ['type' => 'select', 'label' => 'Icon circle · phone', 'default' => '52', 'store' => 'setting', 'options' => self::CIRCLE, 'help' => ''],
        'home_cc_txt_d' => ['type' => 'select', 'label' => 'Chip text size · laptop', 'default' => '15', 'store' => 'setting', 'options' => self::TEXT, 'help' => ''],
        'home_cc_txt_m' => ['type' => 'select', 'label' => 'Chip text size · phone', 'default' => '13', 'store' => 'setting', 'options' => self::TEXT, 'help' => ''],
        'home_cc_gap_d' => ['type' => 'select', 'label' => 'Space between chips · laptop', 'default' => '12', 'store' => 'setting', 'options' => self::GAP, 'help' => ''],
        'home_cc_gap_m' => ['type' => 'select', 'label' => 'Space between chips · phone', 'default' => '8', 'store' => 'setting', 'options' => self::GAP, 'help' => ''],
        'home_cc_pt_d' => ['type' => 'select', 'label' => 'Space above · laptop', 'default' => '40', 'store' => 'setting', 'options' => self::PX, 'help' => ''],
        'home_cc_pt_m' => ['type' => 'select', 'label' => 'Space above · phone', 'default' => '28', 'store' => 'setting', 'options' => self::PX, 'help' => ''],
        'home_cc_pb_d' => ['type' => 'select', 'label' => 'Space below · laptop', 'default' => '40', 'store' => 'setting', 'options' => self::PX, 'help' => ''],
        'home_cc_pb_m' => ['type' => 'select', 'label' => 'Space below · phone', 'default' => '28', 'store' => 'setting', 'options' => self::PX, 'help' => ''],
        'home_cc_hg_d' => ['type' => 'select', 'label' => 'Space under the heading · laptop', 'default' => '24', 'store' => 'setting', 'options' => self::PX, 'help' => ''],
        'home_cc_hg_m' => ['type' => 'select', 'label' => 'Space under the heading · phone', 'default' => '16', 'store' => 'setting', 'options' => self::PX, 'help' => ''],
    ];

    /** tab => [label, description, keys] — placed in HomeSections::TABS right after Trending. */
    public const TAB = ['Skin concerns', 'Shop by skin concern, directly under Trending: a pill per concern with its icon, each one a link to its collection. The chips, the heading, the background and the sizes — laptop and phone.', [
        'home_cc_eyebrow', 'home_cc_eyebrow_ar', 'home_cc_title', 'home_cc_title_ar', 'home_cc_sub', 'home_cc_sub_ar', 'home_cc_items',
        'home_cc_btn', 'home_cc_btn_ar', 'home_cc_url', 'home_cc_bg', 'home_cc_bg_from', 'home_cc_bg_to', 'home_cc_bg_dir',
        'home_cc_align', 'home_cc_layout_m', 'home_cc_layout_d', 'home_cc_snap', 'home_cc_arrows_d', 'home_cc_arrows_m', 'home_cc_ic_d', 'home_cc_ic_m', 'home_cc_txt_d', 'home_cc_txt_m',
        'home_cc_gap_d', 'home_cc_gap_m', 'home_cc_pt_d', 'home_cc_pt_m', 'home_cc_pb_d', 'home_cc_pb_m', 'home_cc_hg_d', 'home_cc_hg_m',
    ]];

    /**
     * The `home_cc_items` rule (ModuleSchema `rule`): runs on save AND on every
     * read. '' stays '' — "never set", which draws the seven defaults; anything
     * else becomes a JSON list of clean rows, possibly `[]` (the owner removed
     * every chip, which draws nothing).
     */
    public static function clean(mixed $raw, array $field = []): string
    {
        if (is_string($raw) && trim($raw) === '') {
            return '';
        }

        return (string) json_encode(self::items($raw), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * The chip rows, cleaned. Each is
     *   c  category id (0 = none chosen; then `s` must name it)
     *   s  slugs to try, first existing wins (the defaults only)
     *   l  label, la  Arabic label — plain text, LABEL_MAX characters
     *   i  an ICONS key, t  a TONES key or '' (the icon's own colour)
     *
     * @return list<array{c: int, s: list<string>, l: string, la: string, i: string, t: string}>
     */
    public static function items(mixed $raw): array
    {
        if (is_string($raw)) {
            if (trim($raw) === '') {
                $raw = self::DEFAULT_ITEMS;
            } else {
                $raw = strlen($raw) > 20000 ? [] : json_decode($raw, true);
            }
        }

        $out = [];

        foreach (is_array($raw) ? array_values($raw) : [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $c = filter_var($row['c'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $c = $c === false ? 0 : (int) $c;

            $s = [];

            if ($c === 0) {
                foreach (is_array($row['s'] ?? null) ? $row['s'] : [] as $slug) {
                    if (is_string($slug) && preg_match('/^[a-z0-9][a-z0-9-]{0,99}$/', $slug) === 1 && count($s) < 6) {
                        $s[] = $slug;
                    }
                }
            }

            if ($c === 0 && $s === []) {
                continue;
            }

            $icon = is_string($row['i'] ?? null) && isset(self::ICONS[$row['i']]) ? $row['i'] : 'pores';
            $tone = is_string($row['t'] ?? null) && isset(self::TONES[$row['t']]) ? $row['t'] : '';

            $out[] = ['c' => $c, 's' => $s, 'l' => self::label($row['l'] ?? ''), 'la' => self::label($row['la'] ?? ''), 'i' => $icon, 't' => $tone];

            if (count($out) >= self::CAP) {
                break;
            }
        }

        return $out;
    }

    /** Plain text: tags and control characters gone, whitespace folded, capped. */
    private static function label(mixed $raw): string
    {
        if (! is_string($raw)) {
            return '';
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\x00-\x1F\x7F]/u', ' ', strip_tags($raw))));

        return mb_substr($text, 0, self::LABEL_MAX);
    }

    /**
     * Every category the chips can name, in ONE query: the ids rows chose and
     * every slug a default may have. Only the columns url() and the label read.
     * Called inside HomeSections::data()'s cache entry.
     *
     * @return Collection<int, Category>
     */
    public static function fetch(array $c): Collection
    {
        $ids = [];
        $slugs = [];

        foreach (self::items($c['home_cc_items'] ?? '') as $row) {
            if ($row['c'] > 0) {
                $ids[] = $row['c'];
            }

            array_push($slugs, ...$row['s']);
        }

        if ($ids === [] && $slugs === []) {
            return collect();
        }

        return Category::query()->select('id', 'name', 'slug', 'path', 'parent_id')
            ->where(function ($q) use ($ids, $slugs) {
                if ($ids !== []) {
                    $q->whereIn('id', array_values(array_unique($ids)));
                }

                if ($slugs !== []) {
                    $q->orWhereIn('slug', array_values(array_unique($slugs)));
                }
            })
            ->get()->values();
    }

    /**
     * The section as the template draws it, or null when it draws nothing.
     *
     * @param  iterable<Category>  $rows  fetch()'s result
     * @return array<string, mixed>|null
     */
    public static function section(array $c, iterable $rows, ?bool $arabic = null): ?array
    {
        $arabic ??= Locale::current() === 'ar';
        $byId = [];
        $bySlug = [];

        foreach ($rows as $cat) {
            $byId[(int) $cat->id] = $cat;
            $bySlug[(string) $cat->slug] = $cat;
        }

        $chips = [];
        $seen = [];

        foreach (self::items($c['home_cc_items'] ?? '') as $row) {
            $cat = $row['c'] > 0 ? ($byId[$row['c']] ?? null) : null;

            foreach ($row['c'] > 0 ? [] : $row['s'] as $slug) {
                if (isset($bySlug[$slug])) {
                    $cat = $bySlug[$slug];
                    break;
                }
            }

            if ($cat === null || isset($seen[(int) $cat->id])) {
                continue;
            }

            $seen[(int) $cat->id] = true;

            $label = $row['l'] !== '' ? $row['l'] : trim(RichText::toText((string) $cat->name));

            if ($arabic) {
                $label = $row['la'] !== '' ? $row['la']
                    : ($row['l'] === '' && $cat->hasTranslation('name') ? trim(RichText::toText((string) $cat->t('name'))) : $label);
            }

            $tone = self::TONES[$row['t'] !== '' ? $row['t'] : self::ICON_TONE[$row['i']]];

            $chips[] = [
                'url' => $cat->url(),
                'label' => $label,
                'icon' => self::svg($row['i']),
                'style' => '--cc-bg:'.$tone[1].';--cc-acc:'.$tone[2],
            ];
        }

        if ($chips === []) {
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

        $frame = HomeSections::frame($c, 'cc');
        $bg = HomeSections::pick($c, 'home_cc_bg');
        $from = self::hex($c, 'home_cc_bg_from');
        $to = self::hex($c, 'home_cc_bg_to');

        $style = $frame['style']
            .';--cc-ic-d:'.HomeSections::pick($c, 'home_cc_ic_d').'px;--cc-ic-m:'.HomeSections::pick($c, 'home_cc_ic_m').'px'
            .';--cc-ts-d:'.HomeSections::pick($c, 'home_cc_txt_d').'px;--cc-ts-m:'.HomeSections::pick($c, 'home_cc_txt_m').'px'
            .';--cc-g-d:'.HomeSections::pick($c, 'home_cc_gap_d').'px;--cc-g-m:'.HomeSections::pick($c, 'home_cc_gap_m').'px';

        if ($bg !== 'none') {
            $style .= ';--cc-b1:'.$from.';--cc-b2:'.($bg === 'solid' ? $from : $to).';--cc-ang:'.HomeSections::pick($c, 'home_cc_bg_dir').'deg';
        }

        $align = HomeSections::pick($c, 'home_cc_align');

        // The arrows ride resources/js/kbb/ymal.js -- the product carousels' own
        // module, already on the page -- so this section ships no script.
        $arrD = HomeSections::pick($c, 'home_cc_layout_d') === 'scroll' && (bool) ($c['home_cc_arrows_d'] ?? true);
        $arrM = HomeSections::pick($c, 'home_cc_layout_m') === 'scroll' && (bool) ($c['home_cc_arrows_m'] ?? false);

        return [
            'classes' => 'hs hs-concerns hs-cc-bg-'.$bg.' hs-cc-'.($align === 'center' ? 'ac' : 'al')
                .' hs-cc-m-'.HomeSections::pick($c, 'home_cc_layout_m').' hs-cc-d-'.HomeSections::pick($c, 'home_cc_layout_d').' hs-cc-snap-'.HomeSections::pick($c, 'home_cc_snap'),
            'style' => $style,
            'eyebrow' => $text('home_cc_eyebrow'),
            'title' => $text('home_cc_title'),
            'sub' => $text('home_cc_sub'),
            'btn' => $text('home_cc_btn'),
            'url' => HomeSections::url((string) ($c['home_cc_url'] ?? ''), ''),
            'place' => 'top',
            'center' => $align === 'center',
            'chips' => $chips,
            'arrows' => $arrD || $arrM,
            // The row is driven by ymal.js (arrows, mouse drag) whenever the laptop row is one row.
            'carousel' => $arrD || $arrM || HomeSections::pick($c, 'home_cc_layout_d') === 'scroll',
            'arrows_d' => $arrD,
            'arrows_m' => $arrM,
        ];
    }

    /** One icon as inline SVG: a constant, with its own width and height. */
    public static function svg(string $key): string
    {
        $icon = self::ICONS[$key] ?? self::ICONS['pores'];

        return '<svg viewBox="0 0 48 48" width="32" height="32" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
            .$icon[1].$icon[2].'</svg>';
    }

    /** A colour setting as `#RRGGBB`, or its default — checked again here, as it is printed into a style. */
    private static function hex(array $c, string $key): string
    {
        $v = strtoupper(trim((string) ($c[$key] ?? '')));

        return preg_match('/^#[0-9A-F]{6}$/', $v) === 1 ? $v : (string) self::SCHEMA[$key]['default'];
    }

    /**
     * What the admin's chip editor needs that the schema cannot carry: the
     * icons (label + drawing) and the colours. Constants only.
     *
     * @return array<string, mixed>
     */
    public static function editorVocab(): array
    {
        $icons = [];

        foreach (self::ICONS as $k => $icon) {
            $icons[] = ['key' => $k, 'label' => $icon[0], 'svg' => self::svg($k), 'tone' => self::ICON_TONE[$k]];
        }

        $tones = [];

        foreach (self::TONES as $k => $t) {
            $tones[] = ['key' => $k, 'label' => $t[0], 'bg' => $t[1], 'acc' => $t[2]];
        }

        return ['icons' => $icons, 'tones' => $tones, 'defaults' => self::DEFAULT_ITEMS, 'cap' => self::CAP, 'line' => self::LINE];
    }
}

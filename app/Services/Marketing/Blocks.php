<?php

declare(strict_types=1);

namespace App\Services\Marketing;

use App\Services\Mail\Kit\MailKit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;

/**
 * The marketing email builder's blocks — a CLOSED list, validated per type
 * (Lane MK, docs/EMAILS-PLAN.md §2.2).
 *
 * A template or a campaign is a list of {type, props}. `type` comes from TYPES
 * and nothing else; every prop is checked against SCHEMA (lengths, the
 * options of a select, ids that must exist, URLs whose scheme is checked).
 * Unknown props are dropped. There is NO raw-HTML block, and no prop is ever
 * printed unescaped: text carries three marks — **bold**, *italic* and
 * [label](url) — and marks() renders them with every other character escaped.
 *
 * THE FOOTER IS REQUIRED AND LAST. It carries the unsubscribe link, the
 * postal addresses and why the email arrived, and a campaign without it is
 * not one the shop may send. The mini header, when present, is first.
 *
 * clean() is the only way a block list reaches the database or the renderer:
 * the admin endpoints store its output and refuse a 422 with its errors.
 */
final class Blocks
{
    public const TYPES = [
        'mini_header', 'hero_image', 'heading', 'text', 'button',
        'product_row', 'product_grid', 'coupon', 'image',
        'columns', 'divider', 'spacer', 'social', 'footer',
        // Lane EC: the four benefit chips of the "New look" email (design C).
        'badges',
    ];

    /** What the palette calls each one (the m2 mock's words). */
    public const LABELS = [
        'mini_header' => 'Mini header', 'hero_image' => 'Hero image', 'heading' => 'Heading',
        'text' => 'Text', 'button' => 'Button', 'product_row' => 'Product row',
        'product_grid' => 'Product grid', 'coupon' => 'Coupon', 'image' => 'Image',
        'columns' => 'Columns 2 / 3', 'divider' => 'Divider', 'spacer' => 'Spacer',
        'social' => 'Social links', 'footer' => 'Footer + unsubscribe',
        'badges' => 'Benefit chips',
    ];

    public const MAX_BLOCKS = 40;

    /** A button may point at the group's top brand page, resolved at send time. */
    public const TOP_BRAND_URL = '{top_brand_url}';

    /** Where a product block's products come from. */
    public const FILLS = [
        'newest' => 'New arrivals (newest first)',
        'best_sellers' => 'Best sellers (most units sold)',
        'on_sale' => 'On sale now',
        'under_price' => 'Under a price',
        'brand' => 'A brand',
        'category' => 'A category',
        'group_top_brand' => "This group's top brand (auto)",
        'hand_picked' => 'Picked by hand',
        'sets' => 'Bundles & sets',
    ];

    public const ORDERS = [
        'auto' => 'Automatic',
        'newest' => 'Newest',
        'best_sellers' => 'Best sellers',
        'biggest_saving' => 'Biggest saving',
        'price_low' => 'Price: low to high',
    ];

    /** Pictures that ship with the shop, served at /email/art/{key}.jpg. */
    public const ART = [
        'autumn-glow' => ['file' => 'autumn-glow.jpg', 'w' => 1200, 'h' => 640, 'alt' => 'Autumn Glow Edit — skin that glows back'],
        // Lane EC: design C's still life, as the owner approved it (Lane ED).
        'new-look-c' => ['file' => 'new-look-c.jpg', 'w' => 1200, 'h' => 640, 'alt' => 'numbuzin eye patches, Anua capsule mist, Anua TXA serum and Arencia Vitamin C shot on pastel circles'],
    ];

    /**
     * A benefit chip's picture (Lane EC). Constants, printed raw: a chip
     * stores one of these keys or the select's default, never an entity.
     */
    public const BADGE_ICONS = [
        'truck' => '&#128666;', 'bolt' => '&#9889;', 'card' => '&#128179;', 'sparkles' => '&#10024;',
        'gift' => '&#127873;', 'heart' => '&#128149;', 'star' => '&#11088;', 'box' => '&#128230;',
    ];

    /** The four chips of design C, as the owner approved them — no returns: the shop does not offer them. */
    public const BADGE_DEFAULTS = [
        ['icon' => 'truck', 'bold' => 'Free delivery', 'text' => 'over AED 199'],
        ['icon' => 'bolt', 'bold' => '1–3 days', 'text' => 'across the UAE'],
        ['icon' => 'card', 'bold' => 'Tabby, Tamara', 'text' => ', card or cash'],
        ['icon' => 'sparkles', 'bold' => '100% authentic', 'text' => ', from Korea'],
    ];

    public const SOCIAL = ['instagram' => 'Instagram', 'facebook' => 'Facebook', 'tiktok' => 'TikTok', 'youtube' => 'YouTube', 'whatsapp' => 'WhatsApp'];

    /**
     * type => prop => [kind, …args, default].
     *
     *   text   [max, default]           plain, single line
     *   marks  [max, default]           plain + **bold** *italic* [label](url)
     *   bool   [default]
     *   enum   [options, default]
     *   int    [min, max, default]
     *   url    [default]                https / http / mailto / a site path
     *   image  [default]                https / http / a site path
     *   brand, category, coupon [default]   an id that must exist
     *   products [max]                  ids that must exist
     *   items  [max]                    columns' cells
     *
     * @return array<string, array<string, array<int, mixed>>>
     */
    public static function schema(): array
    {
        $product = [
            'title' => ['text', 100, ''],
            'fill' => ['enum', array_keys(self::FILLS), 'newest'],
            'order' => ['enum', array_keys(self::ORDERS), 'auto'],
            'count' => ['int', 1, 8, 4],
            'brand_id' => ['brand', null],
            'category_id' => ['category', null],
            'max_price' => ['int', 1, 100000, 54],
            'product_ids' => ['products', 12],
            'cta' => ['text', 40, 'Shop now'],
            'show_sale' => ['bool', true],
        ];

        return [
            // tagline: the playful look's small line above the card ("✿ glow-up
            // alert ✿"). The standard look keeps its own topbar words.
            'mini_header' => ['topbar' => ['bool', true], 'nav' => ['bool', true], 'tagline' => ['text', 80, '']],
            'hero_image' => [
                'art' => ['enum', array_merge([''], array_keys(self::ART)), ''],
                'src' => ['image', ''],
                'alt' => ['text', 160, ''],
                'href' => ['url', ''],
            ],
            'heading' => [
                'style' => ['enum', ['hero', 'title', 'label'], 'hero'],
                'icon' => ['enum', array_merge(['none'], array_keys(MailKit::ICONS)), 'spark'],
                'tone' => ['enum', array_keys(MailKit::TONES), 'pink'],
                'eyebrow' => ['text', 80, ''],
                'title' => ['marks', 160, ''],
                'lead' => ['marks', 600, ''],
                'align' => ['enum', ['center', 'left'], 'center'],
                // A second line in a highlighter swipe ("less prices"). Plain.
                'highlight' => ['text', 80, ''],
            ],
            'text' => [
                'body' => ['marks', 3000, ''],
                'align' => ['enum', ['left', 'center'], 'left'],
                'size' => ['int', 13, 18, 15],
            ],
            'button' => [
                'label' => ['text', 60, 'Shop now'],
                'href' => ['url', '/shop/'],
                'style' => ['enum', ['solid', 'ghost', 'dark'], 'solid'],
                'align' => ['enum', ['center', 'left'], 'center'],
            ],
            'product_row' => $product,
            // layout: the card style. `playful` is design C's pastel cards
            // with a type sticker; 3 columns fold to 2 on a phone.
            'product_grid' => $product + ['columns' => ['enum', ['1', '2', '3'], '2'], 'layout' => ['enum', ['standard', 'playful'], 'standard']],
            'coupon' => [
                'coupon_id' => ['coupon', null],
                'line' => ['text', 160, ''],
                'expires' => ['text', 120, ''],
            ],
            'image' => [
                'src' => ['image', ''],
                'alt' => ['text', 160, ''],
                'href' => ['url', ''],
                'width' => ['enum', ['inset', 'full'], 'inset'],
            ],
            'columns' => [
                'count' => ['enum', ['2', '3'], '2'],
                'source' => ['enum', ['manual', 'latest_posts'], 'manual'],
                'items' => ['items', 3],
                'cta' => ['text', 40, 'Read more'],
            ],
            'divider' => [],
            'spacer' => ['height' => ['int', 8, 64, 24]],
            'social' => [
                'instagram' => ['url', ''],
                'facebook' => ['url', ''],
                'tiktok' => ['url', ''],
                'youtube' => ['url', ''],
                'whatsapp' => ['url', ''],
            ],
            // note: a line above the footer ("Made with love … in Dubai").
            'footer' => ['why' => ['enum', ['auto', 'customers', 'subscribers'], 'auto'], 'note' => ['text', 120, '']],
            'badges' => ['items' => ['badges', 4]],
        ];
    }

    /** A new block of $type with every prop at its default. */
    public static function make(string $type, array $props = []): array
    {
        $out = [];

        foreach (self::schema()[$type] ?? [] as $key => $spec) {
            $out[$key] = array_key_exists($key, $props) ? $props[$key] : self::defaultOf($spec);
        }

        return ['type' => $type, 'props' => $out];
    }

    /**
     * The blocks, made safe, plus every reason they were refused.
     *
     * ERRORS refuse the save (an unknown block, an unsafe link, no footer).
     * WARNINGS are choices still to make — the brand of a brand block, the
     * products of a hand-picked one: a draft may be saved with them, a send
     * may not start with them.
     *
     * @param  mixed  $blocks  anything the request carried
     * @param  list<string>  $errors  filled with one sentence per problem
     * @param  list<string>  $warnings  filled with one sentence per choice left
     * @return list<array{type:string, props:array<string,mixed>}>
     */
    public static function clean(mixed $blocks, ?array &$errors = null, ?array &$warnings = null): array
    {
        $errors = [];
        $warnings = [];

        if (! is_array($blocks) || ! array_is_list($blocks)) {
            $errors[] = 'The email has no blocks.';

            return [];
        }

        if (count($blocks) > self::MAX_BLOCKS) {
            $errors[] = 'An email can hold at most ' . self::MAX_BLOCKS . ' blocks.';
            $blocks = array_slice($blocks, 0, self::MAX_BLOCKS);
        }

        $schema = self::schema();
        $out = [];

        foreach ($blocks as $i => $block) {
            $n = $i + 1;
            $type = is_array($block) ? (string) ($block['type'] ?? '') : '';

            if (! isset($schema[$type])) {
                $errors[] = 'Block ' . $n . ' is not a kind of block the builder has ("' . mb_substr($type, 0, 40) . '").';

                continue;
            }

            $props = is_array($block['props'] ?? null) ? $block['props'] : [];
            $clean = [];

            foreach ($schema[$type] as $key => $spec) {
                $value = array_key_exists($key, $props) ? $props[$key] : self::defaultOf($spec);
                $problem = null;
                $clean[$key] = self::cleanValue($spec, $value, $problem);

                if ($problem !== null) {
                    $errors[] = self::LABELS[$type] . ' (block ' . $n . '): ' . $problem;
                }
            }

            $out[] = ['type' => $type, 'props' => $clean];
        }

        $types = array_column($out, 'type');
        $footers = array_keys($types, 'footer', true);

        if ($footers === []) {
            $errors[] = 'Every marketing email ends with the footer (the addresses and the unsubscribe link). Add it back.';
        } elseif (count($footers) > 1 || end($footers) !== count($out) - 1) {
            $errors[] = 'The footer must be the last block, and there is only one.';
        }

        foreach (array_keys($types, 'mini_header', true) as $at) {
            if ($at !== 0) {
                $errors[] = 'The mini header can only be the first block.';

                break;
            }
        }

        self::checkIds($out, $errors, $warnings);

        return $out;
    }

    /**
     * Ids that must exist, in one query per table whatever the block count.
     *
     * @param  list<array{type:string, props:array<string,mixed>}>  $blocks
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     */
    private static function checkIds(array $blocks, array &$errors, array &$warnings): void
    {
        $want = ['brands' => [], 'categories' => [], 'coupons' => [], 'products' => []];

        foreach ($blocks as $b) {
            $p = $b['props'];

            if (isset($p['brand_id']) && $p['brand_id'] !== null) {
                $want['brands'][] = (int) $p['brand_id'];
            }

            if (isset($p['category_id']) && $p['category_id'] !== null) {
                $want['categories'][] = (int) $p['category_id'];
            }

            if (isset($p['coupon_id']) && $p['coupon_id'] !== null) {
                $want['coupons'][] = (int) $p['coupon_id'];
            }

            foreach ((array) ($p['product_ids'] ?? []) as $id) {
                $want['products'][] = (int) $id;
            }
        }

        $words = ['brands' => 'brand', 'categories' => 'category', 'coupons' => 'coupon', 'products' => 'product'];

        foreach ($want as $table => $ids) {
            $ids = array_values(array_unique($ids));

            if ($ids === []) {
                continue;
            }

            try {
                $found = DB::table($table)->whereIn('id', $ids)->pluck('id')->map(fn ($v) => (int) $v)->all();
            } catch (\Throwable) {
                $found = [];
            }

            $missing = array_diff($ids, $found);

            if ($missing !== []) {
                $errors[] = 'A ' . $words[$table] . ' this email points at no longer exists (id ' . implode(', ', $missing) . ').';
            }
        }

        foreach ($blocks as $i => $b) {
            if (in_array($b['type'], ['product_row', 'product_grid'], true)) {
                $p = $b['props'];

                if ($p['fill'] === 'brand' && $p['brand_id'] === null) {
                    $warnings[] = self::LABELS[$b['type']] . ' (block ' . ($i + 1) . '): choose the brand.';
                }

                if ($p['fill'] === 'category' && $p['category_id'] === null) {
                    $warnings[] = self::LABELS[$b['type']] . ' (block ' . ($i + 1) . '): choose the category.';
                }

                if ($p['fill'] === 'hand_picked' && $p['product_ids'] === []) {
                    $warnings[] = self::LABELS[$b['type']] . ' (block ' . ($i + 1) . '): pick at least one product.';
                }
            }
        }
    }

    /** @param array<int, mixed> $spec */
    private static function defaultOf(array $spec): mixed
    {
        return match ($spec[0]) {
            'text', 'marks' => $spec[2],
            'bool' => $spec[1],
            'enum' => $spec[2],
            'int' => $spec[3],
            'url', 'image' => $spec[1],
            'brand', 'category', 'coupon' => $spec[1],
            'products', 'items' => [],
            'badges' => self::BADGE_DEFAULTS,
            default => null,
        };
    }

    /**
     * @param  array<int, mixed>  $spec
     */
    private static function cleanValue(array $spec, mixed $value, ?string &$problem): mixed
    {
        $problem = null;

        switch ($spec[0]) {
            case 'text':
            case 'marks':
                $s = is_scalar($value) ? (string) $value : '';
                // Control characters other than a newline in marks text are
                // dropped: a CR is how a header gets injected, and nothing
                // here is a header, but nothing here needs one either.
                $s = (string) preg_replace($spec[0] === 'marks' ? '/[\x00-\x09\x0B-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]/u', '', str_replace("\r\n", "\n", $s));
                $s = trim($s);

                if (mb_strlen($s) > $spec[1]) {
                    $problem = 'that text is longer than ' . $spec[1] . ' characters.';
                    $s = mb_substr($s, 0, $spec[1]);
                }

                if ($spec[0] === 'marks') {
                    foreach (self::linksIn($s) as $url) {
                        if (self::safeUrl($url) === null) {
                            $problem = 'a link in the text ("' . mb_substr($url, 0, 60) . '") is not a web address. Links must start with https://, mailto: or /.';
                        }
                    }
                }

                return $s;

            case 'bool':
                return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $spec[1];

            case 'enum':
                $s = is_scalar($value) ? (string) $value : '';

                // A select stores one of its own options or the default.
                return in_array($s, $spec[1], true) ? $s : $spec[2];

            case 'int':
                $n = filter_var($value, FILTER_VALIDATE_INT);

                return $n === false ? $spec[3] : max($spec[1], min($spec[2], (int) $n));

            case 'url':
            case 'image':
                $s = is_scalar($value) ? trim((string) $value) : '';

                if ($s === '') {
                    return '';
                }

                // The brand page of the chosen group's top brand, filled in at
                // send time ("Shop all Medicube"). A fixed token, not a URL.
                if ($spec[0] === 'url' && $s === self::TOP_BRAND_URL) {
                    return $s;
                }

                $ok = $spec[0] === 'image' ? self::safeImage($s) : self::safeUrl($s);

                if ($ok === null) {
                    $problem = $spec[0] === 'image'
                        ? 'the picture must be an https:// address or a file from the media library.'
                        : '"' . mb_substr($s, 0, 60) . '" is not a web address. Links must start with https://, http://, mailto: or /.';

                    return '';
                }

                return $s;

            case 'brand':
            case 'category':
            case 'coupon':
                $n = filter_var($value, FILTER_VALIDATE_INT);

                return $n === false || $n < 1 ? null : (int) $n;

            case 'products':
                $ids = [];

                foreach (is_array($value) ? $value : [] as $v) {
                    $n = filter_var($v, FILTER_VALIDATE_INT);

                    if ($n !== false && $n > 0 && ! in_array((int) $n, $ids, true)) {
                        $ids[] = (int) $n;
                    }
                }

                if (count($ids) > $spec[1]) {
                    $problem = 'at most ' . $spec[1] . ' products can be picked.';
                    $ids = array_slice($ids, 0, $spec[1]);
                }

                return $ids;

            case 'items':
                $items = [];

                foreach (array_slice(is_array($value) ? array_values($value) : [], 0, $spec[1]) as $cell) {
                    $cell = is_array($cell) ? $cell : [];
                    $p1 = $p2 = $p3 = $p4 = null;
                    $items[] = [
                        'image' => self::cleanValue(['image', ''], $cell['image'] ?? '', $p1),
                        'title' => self::cleanValue(['text', 80, ''], $cell['title'] ?? '', $p2),
                        'text' => self::cleanValue(['marks', 300, ''], $cell['text'] ?? '', $p3),
                        'href' => self::cleanValue(['url', ''], $cell['href'] ?? '', $p4),
                    ];
                    $problem ??= $p1 ?? $p2 ?? $p3 ?? $p4;
                }

                return $items;

            case 'badges':
                $chips = [];

                foreach (array_slice(is_array($value) ? array_values($value) : [], 0, $spec[1]) as $chip) {
                    $chip = is_array($chip) ? $chip : [];
                    $p1 = $p2 = null;
                    $icon = is_scalar($chip['icon'] ?? null) ? (string) $chip['icon'] : '';
                    $chips[] = [
                        // A select stores one of its own options or the default.
                        'icon' => isset(self::BADGE_ICONS[$icon]) ? $icon : 'sparkles',
                        'bold' => self::cleanValue(['text', 40, ''], $chip['bold'] ?? '', $p1),
                        'text' => self::cleanValue(['text', 60, ''], $chip['text'] ?? '', $p2),
                    ];
                    $problem ??= $p1 ?? $p2;
                }

                return $chips;
        }

        return null;
    }

    /* --------------------------------------------------------------- urls */

    /**
     * A link's address as it may become an href, or null.
     *
     * https, http and mailto as given; a site path ("/shop/") made absolute
     * on this shop; anything else — javascript:, data:, vbscript:, a
     * protocol-relative "//evil", a bare word — refused. Whitespace and
     * control characters inside the address are refused too: a browser
     * strips them, which is how "java\tscript:" gets past a naive check.
     */
    public static function safeUrl(string $url): ?string
    {
        $url = trim($url);

        if ($url === '' || mb_strlen($url) > 500 || preg_match('/[\x00-\x20\x7F<>"\'`\\\\]/', $url) === 1) {
            return null;
        }

        if (preg_match('#^https?://[^/]#i', $url) === 1 || preg_match('#^mailto:[^@\s]+@[^@\s]+$#i', $url) === 1) {
            return $url;
        }

        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return MailKit::url($url);
        }

        return null;
    }

    /** A picture's address, absolute, or null: http(s) or a site path only. */
    public static function safeImage(string $src): ?string
    {
        $url = self::safeUrl($src);

        return $url !== null && ! str_starts_with(strtolower($url), 'mailto:') ? $url : null;
    }

    /** @return list<string> every [label](url) address in $text */
    public static function linksIn(string $text): array
    {
        preg_match_all('/\[([^\]\n]{1,200})\]\(([^)\n]{1,500})\)/u', $text, $m);

        return $m[2] ?? [];
    }

    /* -------------------------------------------------------------- marks */

    /**
     * Text with its three marks, as HTML, every other character escaped.
     *
     * $href maps a link's checked address to the href printed (the click
     * tracker when sending, the address itself in a preview). A link whose
     * address fails safeUrl() prints its label as plain text. {first_name}
     * (or {first_name|fallback}) becomes the escaped name.
     *
     * @param  callable(string $url, string $label): string  $href
     */
    public static function marks(string $text, callable $href, array|string $firstName = '', string $linkColour = '#C13E63'): HtmlString
    {
        $out = '';
        $pos = 0;

        preg_match_all('/\[([^\]\n]{1,200})\]\(([^)\n]{1,500})\)/u', $text, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

        foreach ($m as $match) {
            $out .= self::inline(substr($text, $pos, $match[0][1] - $pos), $firstName);
            $label = $match[1][0];
            $url = self::safeUrl($match[2][0]);

            if ($url === null) {
                $out .= self::inline($label, $firstName);
            } else {
                $out .= '<a href="' . e($href($url, $label)) . '" style="color:' . e($linkColour) . ';text-decoration:underline;">' . self::inline($label, $firstName) . '</a>';
            }

            $pos = $match[0][1] + strlen($match[0][0]);
        }

        $out .= self::inline(substr($text, $pos), $firstName);

        return new HtmlString($out);
    }

    /** Plain text: marks removed, links as "label (url)", names merged. */
    public static function plain(string $text, array|string $firstName = '', ?callable $href = null): string
    {
        $text = (string) preg_replace_callback('/\[([^\]\n]{1,200})\]\(([^)\n]{1,500})\)/u', function ($m) use ($href) {
            $url = self::safeUrl($m[2]);

            return $url === null ? $m[1] : $m[1] . ' (' . ($href ? $href($url, $m[1]) : $url) . ')';
        }, $text);

        $text = (string) preg_replace('/\*\*([^*\n]+)\*\*/u', '$1', $text);
        $text = (string) preg_replace('/\*([^*\n]+)\*/u', '$1', $text);

        return self::mergeName($text, $firstName);
    }

    /**
     * The merge tags, as plain text:
     *
     *   {first_name}            the recipient's first name, or nothing
     *   {first_name|fallback}   … or the fallback ("Hi {first_name|there}")
     *   {top_brand}             the chosen group's top brand ("More Medicube")
     *
     * $vars is ['first_name' => …, 'top_brand' => …], or just the first name.
     *
     * @param  array<string, string>|string  $vars
     */
    public static function mergeName(string $text, array|string $vars): string
    {
        $vars = is_array($vars) ? $vars : ['first_name' => $vars];
        $name = trim((string) ($vars['first_name'] ?? ''));
        $brand = trim((string) ($vars['top_brand'] ?? ''));

        $text = (string) preg_replace_callback('/\{first_name(?:\|([^}\n]{0,40}))?\}/u', function ($m) use ($name) {
            return $name !== '' ? $name : trim((string) ($m[1] ?? ''));
        }, $text);

        return str_replace('{top_brand}', $brand !== '' ? $brand : 'our favourites', $text);
    }

    /** One run of text: escaped, then **bold** and *italic*, then <br>. */
    private static function inline(string $text, array|string $firstName): string
    {
        $html = e(self::mergeName($text, $firstName));
        $html = (string) preg_replace('/\*\*([^*\n]+)\*\*/u', '<b>$1</b>', $html);
        $html = (string) preg_replace('/\*([^*\n]+)\*/u', '<i>$1</i>', $html);

        return nl2br($html, false);
    }
}

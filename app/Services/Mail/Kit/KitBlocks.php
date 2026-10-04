<?php

declare(strict_types=1);

namespace App\Services\Mail\Kit;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Product;
use App\Support\Url;
use Illuminate\Support\HtmlString;

/**
 * The kit's blocks as DATA — what the Marketing Emails builder (m2) and the
 * transactional template builder's "+ Add a section" (e4) are made of. Lane EK,
 * docs/EMAILS-PLAN.md §2.2.
 *
 * A CLOSED LIST of types, each with its own prop schema:
 *
 *   mini_header  hero_image  heading  text  button  product_row  product_grid
 *   coupon  image  columns  divider  spacer  social  footer
 *
 * THERE IS NO RAW-HTML BLOCK, and nothing an admin types is printed raw:
 *
 *   - text allows three marks — **bold**, *italic*, [link](url) — and is
 *     rendered by marks(): every character escaped FIRST, then the three
 *     marks turned into <b>, <i> and a scheme-checked <a>;
 *   - every other string goes through {{ }} in a kit partial;
 *   - every URL goes through MailKit::url() (http(s), mailto: or a site path;
 *     javascript:, data: and //host are refused and the block that needed the
 *     URL is left out);
 *   - every choice is one of this class's own options or its default;
 *   - product and coupon blocks store IDS (or a fill rule), and the name,
 *     price, picture and link are read when the email is drawn — so a sold-out
 *     or unpublished product is dropped rather than advertised (§2.2).
 *
 * `footer` (addresses, Unsubscribe) is required in a campaign and always last
 * — problems() says so, and the save refuses.
 */
final class KitBlocks
{
    /** type => what the builder's palette calls it, in the approved mock's order. */
    public const TYPES = [
        'mini_header' => 'Mini header',
        'hero_image' => 'Hero image',
        'heading' => 'Heading',
        'text' => 'Text',
        'button' => 'Button',
        'product_row' => 'Product row',
        'product_grid' => 'Product grid',
        'coupon' => 'Coupon',
        'image' => 'Image',
        'columns' => 'Columns 2 / 3',
        'divider' => 'Divider',
        'spacer' => 'Spacer',
        'social' => 'Social links',
        'footer' => 'Footer + unsubscribe',
    ];

    /** The transactional editor's "+ Add a section" (e4 mock): Text · Image · Button · Coupon · Product row · Divider · Spacer. */
    public const SECTION_TYPES = ['text', 'image', 'button', 'coupon', 'product_row', 'divider', 'spacer'];

    public const MAX_BLOCKS = 40;

    /** How a product block fills itself (the integrator's list). */
    public const FILLS = [
        'newest' => 'New arrivals (newest first)',
        'best' => 'Best sellers',
        'sale' => 'On sale',
        'under' => 'Under a price',
        'sets' => 'Bundles & sets',
        'brand' => 'A brand',
        'category' => 'A category',
        'group_brand' => 'This group’s top brand (auto)',
        'picked' => 'Picked by hand',
    ];

    public const ICONS = ['none', 'heart', 'spark', 'gift', 'bag', 'star', 'bell', 'truck', 'clock', 'mail', 'box'];

    private const LIMITS = ['title' => 160, 'eyebrow' => 60, 'lead' => 600, 'text' => 4000, 'label' => 60, 'url' => 600,
        'alt' => 160, 'line' => 160, 'expires' => 120, 'cta' => 40, 'src' => 600];

    /**
     * Make a list of blocks safe to store. Unknown types and malformed rows
     * are dropped, never repaired into something the owner did not write.
     *
     * @return list<array<string, mixed>>
     */
    public static function clean(array $blocks): array
    {
        $out = [];

        foreach (array_slice(array_values($blocks), 0, self::MAX_BLOCKS) as $b) {
            $row = is_array($b) ? self::one($b) : null;

            if ($row !== null) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * What is wrong with a campaign's blocks, in words for the screen; [] when
     * it may be saved. Unknown types are named rather than silently dropped.
     *
     * @return list<string>
     */
    public static function problems(array $blocks, bool $campaign = true): array
    {
        $problems = [];

        foreach (array_values($blocks) as $i => $b) {
            if (! is_array($b) || ! isset(self::TYPES[$b['type'] ?? ''])) {
                $problems[] = 'Block ' . ($i + 1) . ' is not one of the builder\'s blocks.';
            }
        }

        if (count($blocks) > self::MAX_BLOCKS) {
            $problems[] = 'At most ' . self::MAX_BLOCKS . ' blocks.';
        }

        if ($campaign) {
            $types = array_map(static fn ($b) => is_array($b) ? ($b['type'] ?? '') : '', array_values($blocks));
            $footers = array_keys($types, 'footer', true);

            if ($footers === []) {
                $problems[] = 'The footer (addresses and Unsubscribe) is missing — every campaign carries it.';
            } elseif (count($footers) > 1 || end($footers) !== count($types) - 1) {
                $problems[] = 'The footer must be the last block, and there is only one.';
            }
        }

        return $problems;
    }

    /** What a block is called in a list: its type and the first words in it. */
    public static function label(array $block): string
    {
        $name = self::TYPES[$block['type'] ?? ''] ?? 'Block';
        $words = (string) ($block['title'] ?? $block['label'] ?? $block['text'] ?? $block['alt'] ?? '');
        $words = trim(preg_replace('/\s+/', ' ', str_replace(['**', '*'], '', $words)) ?? '');

        return $words === '' ? $name : $name . ' · ' . mb_strimwidth($words, 0, 40, '…');
    }

    /**
     * Draw blocks with the kit's partials. The footer block is NOT drawn here:
     * it sits below the card (the layout draws it with the unsubscribe link).
     *
     * $ctx: 'vars' => [tag => value] for {first_name} and {store};
     * 'group_brand_id' for the "This group's top brand" fill; 'products' is a
     * memo the caller may share between renders.
     */
    public static function render(array $blocks, array $k, array &$ctx = []): HtmlString
    {
        if ($k === []) {
            $k = MailKit::for([]);
        }

        $html = '';

        foreach (self::clean($blocks) as $b) {
            $html .= self::draw($b, $k, $ctx);
        }

        return new HtmlString($html);
    }

    /** One block's HTML (for the builder's selected-block frame). */
    public static function drawOne(array $block, array $k, array &$ctx = []): string
    {
        $clean = self::clean([$block]);

        return $clean === [] ? '' : self::draw($clean[0], $k, $ctx);
    }

    /** {first_name} and friends, as plain text (escaped later like any text). */
    public static function fill(string $text, array $vars): string
    {
        if ($vars === [] || ! str_contains($text, '{')) {
            return $text;
        }

        $map = [];

        foreach ($vars as $tag => $value) {
            $map['{' . $tag . '}'] = (string) $value;
        }

        // "Hi {first_name}," with no name known reads "Hi,".
        return (string) preg_replace('/ ([,.!?])/u', '$1', strtr($text, $map));
    }

    /**
     * Text with **bold**, *italic* and [link](url) — escaped first, then the
     * three marks, so nothing typed becomes markup the builder did not make.
     */
    public static function marks(string $text): HtmlString
    {
        $html = e($text);
        $html = (string) preg_replace_callback('/\[([^\]\n]{1,200})\]\(([^)\s]{1,600})\)/', static function (array $m): string {
            $url = MailKit::url(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5));

            return $url === null ? $m[1] : '<a href="' . e($url) . '" style="color:#C13E63;text-decoration:underline;">' . $m[1] . '</a>';
        }, $html);
        $html = (string) preg_replace('/\*\*([^*\n]{1,400})\*\*/', '<b>$1</b>', $html);
        $html = (string) preg_replace('/(?<![*\w])\*([^*\n]{1,400})\*(?![*\w])/', '<i>$1</i>', $html);

        return new HtmlString(nl2br($html, false));
    }

    /* ------------------------------------------------------------- internals */

    /** @return array<string, mixed>|null */
    private static function one(array $b): ?array
    {
        $type = (string) ($b['type'] ?? '');

        if (! isset(self::TYPES[$type])) {
            return null;
        }

        $t = static fn (string $f) => self::text($b[$f] ?? '', self::LIMITS[$f] ?? 200);
        $products = static fn (int $maxCount) => [
            'fill' => isset(self::FILLS[$b['fill'] ?? '']) ? (string) $b['fill'] : 'best',
            'ids' => array_values(array_slice(array_unique(array_filter(array_map('intval', (array) ($b['ids'] ?? [])), static fn (int $i) => $i > 0)), 0, 12)),
            'brand_id' => max(0, (int) ($b['brand_id'] ?? 0)),
            'category_id' => max(0, (int) ($b['category_id'] ?? 0)),
            'max_price' => max(0, min(100000, (int) ($b['max_price'] ?? 54))),
            'count' => max(1, min($maxCount, (int) ($b['count'] ?? min(4, $maxCount)))),
            'cta' => $t('cta') !== '' ? $t('cta') : 'Shop now',
            'sale' => (bool) ($b['sale'] ?? true),
        ];

        $row = match ($type) {
            'mini_header' => ['nav' => (bool) ($b['nav'] ?? true)],
            'hero_image', 'image' => ['src' => $t('src'), 'alt' => $t('alt'), 'url' => $t('url')],
            'heading' => [
                'icon' => in_array($b['icon'] ?? '', self::ICONS, true) ? (string) $b['icon'] : 'spark',
                'eyebrow' => $t('eyebrow'), 'title' => $t('title'), 'lead' => $t('lead'),
            ],
            'text' => [
                'text' => self::text($b['text'] ?? '', self::LIMITS['text'], true),
                'align' => in_array($b['align'] ?? '', ['left', 'center'], true) ? $b['align'] : 'center',
                'size' => in_array($b['size'] ?? '', ['normal', 'small'], true) ? $b['size'] : 'normal',
            ],
            'button' => ['label' => $t('label'), 'url' => $t('url')],
            'product_row' => $products(3) + ['cols' => 0],
            'product_grid' => $products(12) + ['cols' => in_array((int) ($b['cols'] ?? 2), [1, 2, 3], true) ? (int) $b['cols'] : 2],
            'coupon' => ['coupon_id' => max(0, (int) ($b['coupon_id'] ?? 0)), 'line' => $t('line'), 'expires' => $t('expires')],
            'columns' => [
                'cols' => in_array((int) ($b['cols'] ?? 2), [2, 3], true) ? (int) $b['cols'] : 2,
                'items' => array_values(array_map(static fn ($i) => [
                    'src' => self::text(is_array($i) ? ($i['src'] ?? '') : '', 600),
                    'title' => self::text(is_array($i) ? ($i['title'] ?? '') : '', 80),
                    'text' => self::text(is_array($i) ? ($i['text'] ?? '') : '', 300),
                    'url' => self::text(is_array($i) ? ($i['url'] ?? '') : '', 600),
                ], array_slice((array) ($b['items'] ?? []), 0, 3))),
            ],
            'spacer' => ['h' => max(4, min(80, (int) ($b['h'] ?? 24)))],
            default => [],   // divider, social, footer: nothing to set
        };

        return ['type' => $type] + $row;
    }

    private static function draw(array $b, array $k, array &$ctx): string
    {
        $vars = (array) ($ctx['vars'] ?? []);
        $v = static fn (string $s) => self::fill($s, $vars);

        switch ($b['type']) {
            case 'mini_header':
                return self::view('header', $k, ['nav' => $b['nav']]);

            case 'heading':
                if ($b['title'] === '' && $b['lead'] === '') {
                    return '';
                }

                return $b['icon'] === 'none'
                    ? self::view('title', $k, ['title' => $v($b['title']), 'lead' => $v($b['lead'])])
                    : self::view('hero', $k, ['icon' => $b['icon'], 'tone' => 'pink', 'eyebrow' => $v($b['eyebrow']), 'title' => $v($b['title']), 'lead' => $v($b['lead'])]);

            case 'text':
                return $b['text'] === '' ? '' : self::view('para', $k, [
                    'html' => self::marks($v($b['text'])), 'pad' => '16px 32px 0',
                    'size' => $b['size'] === 'small' ? 13 : 15, 'center' => $b['align'] === 'center',
                ]);

            case 'button':
                $href = MailKit::url($b['url']);

                return $href === null || $b['label'] === '' ? '' : self::view('button', $k, ['label' => $v($b['label']), 'href' => $href]);

            case 'hero_image':
            case 'image':
                $src = MailKit::image($b['src'], 600);

                return $src === null ? '' : self::view('image', $k, ['src' => $src, 'alt' => $v($b['alt']), 'href' => MailKit::url($b['url']),
                    'bleed' => $b['type'] === 'hero_image', 'h' => MailKit::heightFor($b['src'], 600)]);

            case 'product_row':
            case 'product_grid':
                $cards = self::products($b, $ctx);

                return $cards === [] ? '' : self::view('product-grid', $k, ['products' => $cards,
                    'cols' => $b['type'] === 'product_row' ? max(1, count($cards)) : $b['cols'], 'cta' => $v($b['cta'])]);

            case 'coupon':
                $code = self::coupon($b['coupon_id']);

                return $code === null ? '' : self::view('coupon', $k, ['code' => $code, 'line' => $v($b['line']), 'expires' => $v($b['expires'])]);

            case 'columns':
                $items = [];

                foreach ($b['items'] as $i) {
                    $items[] = ['img' => MailKit::image($i['src'], 400), 'title' => $v($i['title']), 'text' => $v($i['text']), 'href' => MailKit::url($i['url'])];
                }

                return $items === [] ? '' : self::view('columns', $k, ['items' => $items, 'cols' => $b['cols']]);

            case 'divider':
                return self::view('divider', $k, []);

            case 'spacer':
                return self::view('gap', $k, ['h' => $b['h']]);

            case 'social':
                return self::view('help', $k, []);
        }

        return '';   // footer: drawn below the card by the layout
    }

    /**
     * The product cards for one block, from the catalogue as it is now: only
     * products a shopper can see and buy (visible, in stock).
     *
     * @return list<array<string, mixed>>
     */
    public static function products(array $b, array &$ctx = []): array
    {
        $memo = md5((string) json_encode([$b, $ctx['group_brand_id'] ?? null]));

        if (isset($ctx['products'][$memo])) {
            return $ctx['products'][$memo];
        }

        $want = (int) $b['count'];

        try {
            $q = Product::query()->visible()->inStock()->with('brand');

            match ($b['fill']) {
                'picked' => $q->whereIn('id', $b['ids'] === [] ? [0] : $b['ids']),
                'brand' => $q->where('brand_id', $b['brand_id']),
                'group_brand' => $q->where('brand_id', (int) ($ctx['group_brand_id'] ?? 0)),
                'category' => $q->whereHas('categories', static fn ($c) => $c->where('categories.id', $b['category_id'])),
                'sale' => $q->whereNotNull('sale_price')->whereColumn('sale_price', '<', 'price'),
                'under' => $q->where(static fn ($w) => $w->where('price', '<=', $b['max_price'] * 100)->orWhere('sale_price', '<=', $b['max_price'] * 100)),
                'sets' => $q->where('type', 'set'),
                default => null,
            };

            match ($b['fill']) {
                'picked' => null,
                'newest' => $q->orderByDesc('created_at')->orderByDesc('id'),
                default => $q->orderByDesc('total_sales')->orderByDesc('id'),
            };

            // Read a few spare: a sale window or a price can rule a row out below.
            $rows = $q->limit(in_array($b['fill'], ['sale', 'under'], true) ? $want * 3 : $want)->get();
        } catch (\Throwable) {
            return [];
        }

        if ($b['fill'] === 'picked') {
            $pos = array_flip($b['ids']);
            $rows = $rows->sortBy(static fn ($p) => $pos[$p->id] ?? 99)->values();
        }

        $cards = [];

        foreach ($rows as $p) {
            $price = 0;
            $was = null;

            try {
                $price = $p->effectivePrice();
                $compare = $p->compareAtPrice();

                if ($b['fill'] === 'sale' && ! $p->isOnSale()) {
                    continue;
                }

                if ($b['fill'] === 'under' && ($price <= 0 || $price > $b['max_price'] * 100)) {
                    continue;
                }

                $was = $b['sale'] && $compare !== null && $compare > $price ? MailKit::money($compare) : null;
            } catch (\Throwable) {
                // A card with no price is still the card; a wrong price is not.
            }

            $cards[] = [
                'img' => MailKit::image($p->image, 400),
                'h' => MailKit::heightFor(is_string($p->image) ? $p->image : null, 200),
                'brand' => trim((string) ($p->brand?->name ?? '')),
                'name' => (string) $p->name,
                'price' => $price > 0 ? MailKit::money($price) : '',
                'was' => $was,
                'href' => Url::external('/product/' . rawurlencode((string) $p->slug) . '/'),
            ];

            if (count($cards) >= $want) {
                break;
            }
        }

        return $ctx['products'][$memo] = $cards;
    }

    /** A live coupon's code, read now; null when it is gone or expired. */
    public static function coupon(int $id): ?string
    {
        if ($id <= 0) {
            return null;
        }

        try {
            $c = Coupon::query()->find($id);
        } catch (\Throwable) {
            return null;
        }

        if ($c === null || ($c->expires_at !== null && \Illuminate\Support\Carbon::parse($c->expires_at)->isPast())) {
            return null;
        }

        return strtoupper((string) $c->code);
    }

    /** Ids a block names that do not exist, for the builder to say so. */
    public static function missingIds(array $blocks): array
    {
        $out = [];

        foreach (self::clean($blocks) as $i => $b) {
            if (in_array($b['type'], ['product_row', 'product_grid'], true)) {
                if ($b['fill'] === 'brand' && ! Brand::query()->whereKey($b['brand_id'])->exists()) {
                    $out[] = 'Block ' . ($i + 1) . ': pick a brand.';
                }

                if ($b['fill'] === 'category' && ! Category::query()->whereKey($b['category_id'])->exists()) {
                    $out[] = 'Block ' . ($i + 1) . ': pick a category.';
                }
            }

            if ($b['type'] === 'coupon' && self::coupon($b['coupon_id']) === null) {
                $out[] = 'Block ' . ($i + 1) . ': the coupon does not exist or has expired.';
            }
        }

        return $out;
    }

    private static function view(string $partial, array $k, array $data): string
    {
        return (string) view('emails.kit.' . $partial, ['k' => $k] + $data)->render();
    }

    private static function text(mixed $value, int $max, bool $multiline = false): string
    {
        $value = is_scalar($value) ? (string) $value : '';
        $value = (string) preg_replace($multiline ? '/[\x00-\x09\x0B-\x1F\x7F]/' : '/[\x00-\x1F\x7F]/', '', str_replace("\r\n", "\n", $value));

        return mb_substr(trim($value), 0, $max);
    }
}

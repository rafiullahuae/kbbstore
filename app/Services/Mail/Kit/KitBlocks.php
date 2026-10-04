<?php

declare(strict_types=1);

namespace App\Services\Mail\Kit;

use App\Models\Product;
use App\Support\Url;
use Illuminate\Support\HtmlString;

/**
 * The kit's blocks as DATA — what Email Marketing's builder (m2) and the
 * template editor's "+ Add a section" (e4) are made of. Lane EK.
 *
 * A block is a small array — ['type' => 'text', 'text' => '…'] — and the only
 * HTML that ever reaches an inbox is drawn by the kit's own partials in
 * resources/views/emails/kit/, the same ones every order email uses. There is
 * no "HTML" block and no field that is printed raw:
 *
 *   - every piece of text goes through {{ }} in a partial (or e() then nl2br()
 *     here, which is the same escape with the line breaks kept);
 *   - every URL goes through MailKit::url(): http(s) or mailto: as typed, a
 *     site path made absolute, anything else (javascript:, data:, //host) is
 *     refused and the block that needed it is left out;
 *   - every choice (icon, alignment, columns, how products are picked) is one
 *     of this class's own options or its default — a select stores one of its
 *     own options (CLAUDE.md rule 5).
 *
 * clean() is the gate on the way IN (the builder's save), render() on the way
 * OUT. render() calls clean() again, so a row written to the database by any
 * other route is held to the same rules.
 */
final class KitBlocks
{
    /** type => what the builder calls it. The palette is this list, in this order. */
    public const TYPES = [
        'image' => 'Hero image',
        'heading' => 'Heading',
        'text' => 'Text',
        'button' => 'Button',
        'products' => 'Product grid',
        'coupon' => 'Coupon',
        'divider' => 'Divider',
        'spacer' => 'Spacer',
        'social' => 'Social links',
    ];

    /** The template editor's "+ Add a section" list (e4): the same blocks, fewer of them. */
    public const SECTION_TYPES = ['text', 'image', 'button', 'coupon', 'products', 'divider', 'spacer'];

    public const MAX_BLOCKS = 40;

    public const FILLS = ['picked', 'brand', 'category', 'new', 'best', 'group_brand'];

    public const ORDERS = ['best', 'new', 'price_low'];

    /** Text limits, generous for a person and hopeless for a payload. */
    private const LIMITS = ['title' => 160, 'eyebrow' => 60, 'lead' => 600, 'text' => 4000, 'label' => 60, 'url' => 600,
        'alt' => 160, 'code' => 40, 'line' => 160, 'expires' => 120, 'cta' => 40];

    /**
     * Make a list of blocks safe to store. Unknown types and malformed rows are
     * dropped, never repaired into something the owner did not write.
     *
     * @return list<array<string, mixed>>
     */
    public static function clean(array $blocks): array
    {
        $out = [];

        foreach (array_slice(array_values($blocks), 0, self::MAX_BLOCKS) as $b) {
            if (! is_array($b) || ! isset(self::TYPES[$b['type'] ?? ''])) {
                continue;
            }

            $type = (string) $b['type'];
            $t = static fn (string $f) => self::text($b[$f] ?? '', self::LIMITS[$f] ?? 200);

            $row = match ($type) {
                'heading' => [
                    'icon' => isset(MailKit::ICONS[$b['icon'] ?? '']) ? (string) $b['icon'] : 'spark',
                    'eyebrow' => $t('eyebrow'), 'title' => $t('title'), 'lead' => $t('lead'),
                ],
                'text' => [
                    'text' => self::text($b['text'] ?? '', self::LIMITS['text'], true),
                    'align' => in_array($b['align'] ?? '', ['left', 'center'], true) ? $b['align'] : 'center',
                    'size' => in_array($b['size'] ?? '', ['normal', 'small'], true) ? $b['size'] : 'normal',
                ],
                'button' => ['label' => $t('label'), 'url' => self::text($b['url'] ?? '', self::LIMITS['url'])],
                'image' => [
                    'src' => self::text($b['src'] ?? '', self::LIMITS['url']), 'alt' => $t('alt'),
                    'url' => self::text($b['url'] ?? '', self::LIMITS['url']),
                    'bleed' => (bool) ($b['bleed'] ?? true),
                ],
                'products' => [
                    'fill' => in_array($b['fill'] ?? '', self::FILLS, true) ? $b['fill'] : 'best',
                    'ids' => array_values(array_slice(array_unique(array_filter(array_map('intval', (array) ($b['ids'] ?? [])), static fn (int $i) => $i > 0)), 0, 12)),
                    'brand_id' => max(0, (int) ($b['brand_id'] ?? 0)),
                    'category_id' => max(0, (int) ($b['category_id'] ?? 0)),
                    'count' => max(1, min(12, (int) ($b['count'] ?? 4))),
                    'cols' => max(1, min(3, (int) ($b['cols'] ?? 2))),
                    'order' => in_array($b['order'] ?? '', self::ORDERS, true) ? $b['order'] : 'best',
                    'cta' => $t('cta') !== '' ? $t('cta') : 'Shop now',
                    'sale' => (bool) ($b['sale'] ?? true),
                ],
                'coupon' => ['code' => strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', $t('code')) ?? ''), 'line' => $t('line'), 'expires' => $t('expires')],
                'spacer' => ['h' => max(4, min(80, (int) ($b['h'] ?? 24)))],
                default => [],
            };

            $out[] = ['type' => $type] + $row;
        }

        return $out;
    }

    /** What a block is called in a list: its type and the first words in it. */
    public static function label(array $block): string
    {
        $name = self::TYPES[$block['type'] ?? ''] ?? 'Block';
        $words = (string) ($block['title'] ?? $block['label'] ?? $block['text'] ?? $block['code'] ?? $block['alt'] ?? '');
        $words = trim(preg_replace('/\s+/', ' ', $words) ?? '');

        return $words === '' ? $name : $name . ' · ' . mb_strimwidth($words, 0, 40, '…');
    }

    /**
     * Draw blocks with the kit's partials.
     *
     * $ctx: 'vars' => [tag => value] for {first_name} and friends (escaped like
     * any other text), 'group_brand_id' => the brand a "This group's top brand"
     * grid fills from, 'products' => a memo the caller may share between
     * renders so a batch of recipients costs one catalogue read, not one each.
     */
    public static function render(array $blocks, array $k, array &$ctx = []): HtmlString
    {
        if ($k === []) {
            $k = MailKit::for([]);
        }

        $html = '';

        foreach (self::clean($blocks) as $b) {
            $html .= self::one($b, $k, $ctx);
        }

        return new HtmlString($html);
    }

    /** {first_name}-style tags replaced with plain text (escaped later, like any text). */
    public static function fill(string $text, array $vars): string
    {
        if ($vars === [] || ! str_contains($text, '{')) {
            return $text;
        }

        $map = [];

        foreach ($vars as $tag => $value) {
            $map['{' . $tag . '}'] = (string) $value;
        }

        $out = strtr($text, $map);

        // "Hi {first_name}," with no name known reads "Hi ,": close the gap.
        return (string) preg_replace('/\s+([,.!?])/u', '$1', $out);
    }

    /* ------------------------------------------------------------- internals */

    private static function one(array $b, array $k, array &$ctx): string
    {
        $vars = (array) ($ctx['vars'] ?? []);
        $v = static fn (string $s) => self::fill($s, $vars);

        switch ($b['type']) {
            case 'heading':
                if ($b['title'] === '' && $b['lead'] === '') {
                    return '';
                }

                return self::view('hero', $k, ['icon' => $b['icon'], 'tone' => 'pink', 'eyebrow' => $v($b['eyebrow']),
                    'title' => $v($b['title']), 'lead' => $v($b['lead'])]);

            case 'text':
                if ($b['text'] === '') {
                    return '';
                }

                return self::view('para', $k, [
                    'html' => new HtmlString(nl2br(e($v($b['text'])), false)),
                    'pad' => '16px 32px 0', 'size' => $b['size'] === 'small' ? 13 : 15, 'center' => $b['align'] === 'center',
                ]);

            case 'button':
                $href = MailKit::url($b['url']);

                return $href === null || $b['label'] === '' ? '' : self::view('button', $k, ['label' => $v($b['label']), 'href' => $href]);

            case 'image':
                $src = MailKit::image($b['src'], 600);

                if ($src === null) {
                    return '';
                }

                return self::view('image', $k, ['src' => $src, 'alt' => $v($b['alt']), 'href' => MailKit::url($b['url']),
                    'bleed' => $b['bleed'], 'h' => MailKit::heightFor($b['src'], 600)]);

            case 'products':
                $cards = self::products($b, $ctx);

                return $cards === [] ? '' : self::view('product-grid', $k, ['products' => $cards, 'cols' => $b['cols'], 'cta' => $v($b['cta'])]);

            case 'coupon':
                return $b['code'] === '' ? '' : self::view('coupon', $k, ['code' => $b['code'], 'line' => $v($b['line']), 'expires' => $v($b['expires'])]);

            case 'divider':
                return self::view('divider', $k, []);

            case 'spacer':
                return self::view('gap', $k, ['h' => $b['h']]);

            case 'social':
                return self::view('help', $k, []);
        }

        return '';
    }

    /**
     * The product cards for one block, from the catalogue as it is now: only
     * products a shopper can see and buy (visible, in stock), so an email never
     * sells something the shop cannot sell.
     *
     * @return list<array<string, mixed>>
     */
    public static function products(array $b, array &$ctx = []): array
    {
        $memo = md5((string) json_encode([$b, $ctx['group_brand_id'] ?? null]));

        if (isset($ctx['products'][$memo])) {
            return $ctx['products'][$memo];
        }

        try {
            $q = Product::query()->visible()->inStock()->with('brand');

            match ($b['fill']) {
                'picked' => $q->whereIn('id', $b['ids'] === [] ? [0] : $b['ids']),
                'brand' => $q->where('brand_id', $b['brand_id']),
                'group_brand' => $q->where('brand_id', (int) ($ctx['group_brand_id'] ?? 0)),
                'category' => $q->whereHas('categories', static fn ($c) => $c->where('categories.id', $b['category_id'])),
                default => null,
            };

            $order = $b['fill'] === 'new' ? 'new' : ($b['fill'] === 'best' ? 'best' : $b['order']);

            if ($b['fill'] !== 'picked') {
                match ($order) {
                    'new' => $q->orderByDesc('created_at')->orderByDesc('id'),
                    'price_low' => $q->orderBy('price')->orderBy('id'),
                    default => $q->orderByDesc('total_sales')->orderByDesc('id'),
                };
            }

            $rows = $q->limit($b['count'])->get();
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
        }

        return $ctx['products'][$memo] = $cards;
    }

    private static function view(string $partial, array $k, array $data): string
    {
        return (string) view('emails.kit.' . $partial, ['k' => $k] + $data)->render();
    }

    private static function text(mixed $value, int $max, bool $multiline = false): string
    {
        $value = is_scalar($value) ? (string) $value : '';
        // No control characters; line breaks only where a paragraph keeps them.
        $value = (string) preg_replace($multiline ? '/[\x00-\x09\x0B-\x1F\x7F]/' : '/[\x00-\x1F\x7F]/', '', str_replace("\r\n", "\n", $value));

        return mb_substr(trim($value), 0, $max);
    }
}

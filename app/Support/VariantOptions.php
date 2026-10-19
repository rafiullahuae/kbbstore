<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Translation\TranslationStore;

/**
 * A variable product's options as a PICKER: axes (Shade, Size) and the
 * variants each combination of values names. (Lane VPA)
 *
 * The owner chose "Card A" (dots + size chips under the name) and "Page A"
 * (round swatches + size pills) from docs/variant-options/index.html. Both
 * draw from this one shape, so the card, the page, the basket and the order
 * cannot disagree about what an option is called or what it costs.
 *
 * ── WHERE A SWATCH'S COLOUR COMES FROM, IN ORDER ────────────────────────────
 *
 *   1. the value's own `swatch_color`  Catalog -> Attributes -> (attribute) ->
 *                                      the term -> Swatch colour. Validated to
 *                                      #RRGGBB HERE as well as on the way in,
 *                                      because imported rows never passed the
 *                                      admin's validator and this value is
 *                                      printed into a style attribute.
 *   2. the value's `swatch_image`      same screen, Swatch image.
 *   3. the option's own photograph     a shade with a picture of itself and
 *                                      nothing typed: the picture IS the swatch.
 *   4. a neutral dot                   nothing at all known: a quiet beige dot,
 *                                      and the shade's NAME is in its label,
 *                                      its tooltip and the "Shade: …" line.
 *
 * ── WHICH AXIS IS A COLOUR ──────────────────────────────────────────────────
 *
 * One whose attribute is called colour / color / shade / tone / tint, or any
 * of whose values has a swatch colour or image. Everything else — size, ml,
 * volume, a scent — is drawn as pills, which is what Page A draws for size.
 *
 * ── WHEN THERE IS NO PICKER ─────────────────────────────────────────────────
 *
 * null, and the page keeps the rows it always drew, when the variants are
 * QUANTITY BUNDLES (a savings `tag` and no attribute values — "2-pack, save
 * 10%"), which the owner decides with Bundle section, not with a picker.
 *
 * A variant pinned to no value at all (a WooCommerce "custom attribute" the
 * export could not carry) still has to be choosable, so a set of variants that
 * cannot be laid out as a clean matrix becomes ONE axis, "Option", with each
 * variant a pill named by whatever values it does have.
 */
final class VariantOptions
{
    /** The neutral dot (4 above). Light enough to read as "a shade", not as a colour. */
    public const NEUTRAL = '#E9DED6';

    /** Arabic for the common axis names, when the owner has not typed his own. */
    private const AXIS_AR = [
        'color' => 'اللون', 'colour' => 'اللون', 'colors' => 'اللون', 'colours' => 'اللون',
        'shade' => 'الدرجة', 'shades' => 'الدرجة', 'tone' => 'الدرجة', 'tint' => 'الدرجة',
        'size' => 'الحجم', 'sizes' => 'الحجم', 'volume' => 'الحجم', 'option' => 'الخيار',
    ];

    private const COLOUR_WORDS = '/\b(colou?rs?|shades?|tones?|tints?|hues?)\b/i';

    /**
     * #RGB or #RRGGBB in, #RRGGBB out, anything else null. The ONLY way a
     * stored colour reaches a style attribute.
     */
    public static function hex(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $v = trim($value);

        if (preg_match('/^#([0-9a-fA-F]{6})$/', $v, $m) === 1) {
            return '#'.strtoupper($m[1]);
        }

        if (preg_match('/^#([0-9a-fA-F])([0-9a-fA-F])([0-9a-fA-F])$/', $v, $m) === 1) {
            return strtoupper('#'.$m[1].$m[1].$m[2].$m[2].$m[3].$m[3]);
        }

        return null;
    }

    /** An axis name in the page's language. */
    public static function axisName(int $id, string $english, ?string $locale = null): string
    {
        $locale ??= Locale::current();
        $english = trim($english) !== '' ? trim($english) : 'Option';

        if ($locale === Locale::DEFAULT) {
            return $english;
        }

        $own = $id > 0 ? TranslationStore::get($locale, 'attributes', $id, 'name') : null;

        if (is_string($own) && trim($own) !== '') {
            return $own;
        }

        return $locale === 'ar' ? (self::AXIS_AR[mb_strtolower($english)] ?? $english) : $english;
    }

    /** An option's own name in the page's language. */
    public static function valueName(int $id, string $english, ?string $locale = null): string
    {
        $locale ??= Locale::current();

        if ($locale === Locale::DEFAULT || $id <= 0) {
            return $english;
        }

        $own = TranslationStore::get($locale, 'attribute_values', $id, 'name');

        return is_string($own) && trim($own) !== '' ? $own : $english;
    }

    /**
     * Normalise loaded ProductVariant models (with attributeValues and their
     * attribute) into the row shape build() takes.
     *
     * @param  iterable<ProductVariant>  $variants
     * @return list<array<string, mixed>>
     */
    public static function rowsFromModels(iterable $variants): array
    {
        $rows = [];

        foreach ($variants as $v) {
            $values = [];

            if ($v->relationLoaded('attributeValues')) {
                foreach ($v->attributeValues as $av) {
                    $attr = $av->relationLoaded('attribute') ? $av->getRelation('attribute') : null;
                    $values[] = [
                        'id' => (int) $av->id,
                        'name' => (string) $av->name,
                        'color' => $av->getAttributes()['swatch_color'] ?? null,
                        'image' => $av->getAttributes()['swatch_image'] ?? null,
                        'pos' => (int) ($av->getAttributes()['position'] ?? 0),
                        'attr_id' => (int) $av->attribute_id,
                        'attr_name' => (string) ($attr?->name ?? ''),
                        'attr_slug' => (string) ($attr?->slug ?? ''),
                        'attr_pos' => (int) ($attr?->position ?? 0),
                    ];
                }
            }

            $rows[] = [
                'model' => $v,
                'id' => (int) $v->id,
                'tag' => $v->getAttributes()['tag'] ?? null,
                'values' => $values,
            ];
        }

        return $rows;
    }

    /**
     * The picker for one product, or null when it has none.
     *
     * Each row: ['model' => ProductVariant (raw attributes enough for
     * effectivePrice()/inStock()), 'id', 'tag', 'values' => [...]].
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{axes: list<array<string, mixed>>, variants: list<array<string, mixed>>, selected: int, synthetic: bool}|null
     */
    public static function build(Product $product, array $rows, ?string $locale = null): ?array
    {
        if ($rows === []) {
            return null;
        }

        $locale ??= Locale::current();
        $anyValues = false;
        $anyTag = false;

        foreach ($rows as $r) {
            $anyValues = $anyValues || $r['values'] !== [];
            $anyTag = $anyTag || (is_string($r['tag'] ?? null) && trim((string) $r['tag']) !== '');
        }

        // Quantity bundles: the rows the page has always drawn stay its own.
        if (! $anyValues && $anyTag) {
            return null;
        }

        // Axes, in the attribute's own order, then by first appearance.
        $axes = [];

        foreach ($rows as $r) {
            foreach ($r['values'] as $val) {
                $aid = (int) $val['attr_id'];
                $axes[$aid] ??= [
                    'id' => $aid,
                    'english' => $val['attr_name'] !== '' ? $val['attr_name'] : 'Option',
                    'slug' => $val['attr_slug'],
                    'pos' => $val['attr_pos'],
                    'order' => count($axes),
                    'values' => [],
                ];
                $axes[$aid]['values'][(int) $val['id']] ??= $val;
            }
        }

        // A clean matrix: every variant has exactly one value on every axis,
        // and no two variants name the same combination.
        $clean = $axes !== [];
        $seen = [];

        foreach ($rows as $r) {
            $byAxis = [];

            foreach ($r['values'] as $val) {
                if (isset($byAxis[(int) $val['attr_id']])) {
                    $clean = false;
                }

                $byAxis[(int) $val['attr_id']] = (int) $val['id'];
            }

            if (count($byAxis) !== count($axes)) {
                $clean = false;
            }

            ksort($byAxis);
            $key = implode(',', $byAxis);

            if (isset($seen[$key])) {
                $clean = false;
            }

            $seen[$key] = true;
        }

        $variants = [];

        foreach ($rows as $n => $r) {
            /** @var ProductVariant $m */
            $m = $r['model'];

            if (! $m->relationLoaded('product')) {
                $m->setRelation('product', $product);
            }

            $price = $m->effectivePrice();
            $reg = $m->price !== null ? (int) $m->price : $price;
            $image = LostPictures::usable(is_string($m->image) ? $m->image : null);

            $vals = [];

            foreach ($r['values'] as $val) {
                $vals[(int) $val['attr_id']] = (int) $val['id'];
            }

            $variants[] = [
                'id' => (int) $r['id'],
                'vals' => $vals,
                'price' => $price,
                'was' => $price < $reg ? $reg : null,
                'in' => $m->inStock(),
                'image' => is_string($image) && $image !== '' ? $image : null,
                'sku' => is_string($m->sku) && trim($m->sku) !== '' ? trim($m->sku) : null,
                'label' => implode(' / ', array_map(
                    static fn (array $val): string => self::valueName((int) $val['id'], (string) $val['name'], $locale),
                    $r['values'],
                )),
                'n' => $n,
            ];
        }

        $synthetic = ! $clean;

        if ($synthetic) {
            // One axis, "Option", one pill per variant.
            $values = [];

            foreach ($variants as $i => $v) {
                $values[] = [
                    'id' => $v['id'],
                    'name' => $v['label'] !== '' ? $v['label'] : __('store.product.option_fallback', ['number' => $i + 1]),
                    'fill' => null,
                    'img' => null,
                ];
                $variants[$i]['vals'] = [0 => $v['id']];
            }

            $outAxes = [[
                'id' => 0,
                'name' => self::axisName(0, 'Option', $locale),
                'kind' => 'size',
                'values' => $values,
            ]];
        } else {
            uasort($axes, static fn (array $a, array $b): int => [$a['pos'], $a['order']] <=> [$b['pos'], $b['order']]);
            $outAxes = [];

            foreach ($axes as $axis) {
                $colour = preg_match(self::COLOUR_WORDS, $axis['english'].' '.$axis['slug']) === 1;

                foreach ($axis['values'] as $val) {
                    $colour = $colour || self::hex($val['color']) !== null
                        || (is_string($val['image']) && trim($val['image']) !== '');
                }

                $values = array_values($axis['values']);
                usort($values, static fn (array $a, array $b): int => $a['pos'] <=> $b['pos']);
                $outValues = [];

                foreach ($values as $val) {
                    $fill = null;
                    $img = null;

                    if ($colour) {
                        $fill = self::hex($val['color']);

                        if ($fill === null) {
                            $own = LostPictures::usable(is_string($val['image']) ? trim($val['image']) : null);
                            $img = is_string($own) && $own !== '' ? $own : null;
                        }

                        if ($fill === null && $img === null) {
                            foreach ($variants as $v) {
                                if (($v['vals'][$axis['id']] ?? null) === (int) $val['id'] && $v['image'] !== null) {
                                    $img = $v['image'];
                                    break;
                                }
                            }
                        }

                        if ($fill === null && $img === null) {
                            $fill = self::NEUTRAL;
                        }
                    }

                    $outValues[] = [
                        'id' => (int) $val['id'],
                        'name' => self::valueName((int) $val['id'], (string) $val['name'], $locale),
                        'fill' => $fill,
                        'img' => $img,
                    ];
                }

                $outAxes[] = [
                    'id' => $axis['id'],
                    'name' => self::axisName($axis['id'], $axis['english'], $locale),
                    'kind' => $colour ? 'colour' : 'size',
                    'values' => $outValues,
                ];
            }

            // Colour first, as both designs draw it.
            usort($outAxes, static fn (array $a, array $b): int => ($a['kind'] === 'colour' ? 0 : 1) <=> ($b['kind'] === 'colour' ? 0 : 1));
        }

        $selected = 0;

        foreach ($variants as $i => $v) {
            if ($v['in']) {
                $selected = $i;
                break;
            }
        }

        return [
            'axes' => $outAxes,
            'variants' => $variants,
            'selected' => $selected,
            'synthetic' => $synthetic,
        ];
    }

    /**
     * Is THIS value sold out, given what the other axes are set to? The rule
     * the page's script repeats on every tap: a value is available when SOME
     * in-stock variant has it together with the current choice on every other
     * axis.
     *
     * @param  array<string, mixed>  $picker
     */
    public static function valueOut(array $picker, int $axisId, int $valueId, array $choice): bool
    {
        foreach ($picker['variants'] as $v) {
            if (! $v['in'] || ($v['vals'][$axisId] ?? null) !== $valueId) {
                continue;
            }

            $ok = true;

            foreach ($choice as $aid => $vid) {
                if ($aid !== $axisId && ($v['vals'][$aid] ?? null) !== $vid) {
                    $ok = false;
                    break;
                }
            }

            if ($ok) {
                return false;
            }
        }

        return true;
    }

    /** The CSS for one swatch's fill: a validated colour or a CSS-safe url(). */
    public static function swatchStyle(array $value, int $width = 400): string
    {
        if (is_string($value['fill'] ?? null) && self::hex($value['fill']) !== null) {
            return '--c:'.self::hex($value['fill']);
        }

        if (is_string($value['img'] ?? null) && $value['img'] !== '') {
            $css = CssUrl::value(ImageVariants::variantUrl($value['img'], $width));

            if ($css !== '') {
                return "--c:#fff;background-image:url('".$css."')";
            }
        }

        return '--c:'.self::NEUTRAL;
    }
}

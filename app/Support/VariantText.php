<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ProductVariant;

/**
 * One way to say which option a line is: "Shade: #13 Bright Beige".
 * (Lane VPA)
 *
 * ── THE SNAPSHOT ────────────────────────────────────────────────────────────
 *
 * `order_items.variant_attributes` used to be written as a bare list of value
 * names — `["#13 Bright Beige"]` — so every document said "#13 Bright Beige"
 * and nothing said what kind of thing that was. It is now written keyed by the
 * axis, `{"Shade": "#13 Bright Beige"}`, in English (the operator's language,
 * like `name`), and `variant_localised` beside it holds the same in the
 * language the order was placed in (OrderLocale::listen()).
 *
 * line() reads BOTH shapes. A list — every order placed before this, and every
 * WooCommerce import — prints exactly what it printed before, joined the way
 * its reader always joined it; only a keyed snapshot gains its "Shade: ".
 */
final class VariantText
{
    /**
     * The keyed snapshot of a variant's options in one language, or [] when
     * it has none. Reads the loaded relations only (attributeValues and their
     * attribute); a value whose axis was not loaded is keyed by position.
     *
     * @return array<int|string, string>
     */
    public static function snapshot(?ProductVariant $variant, ?string $locale = null): array
    {
        if ($variant === null || ! $variant->relationLoaded('attributeValues')) {
            return [];
        }

        $locale ??= Locale::DEFAULT;
        $values = $variant->attributeValues->sortBy(static function ($v) {
            $a = $v->relationLoaded('attribute') ? $v->getRelation('attribute') : null;

            return [(int) ($a?->position ?? 0), (int) $v->attribute_id];
        });

        $out = [];

        foreach ($values as $v) {
            $name = VariantOptions::valueName((int) $v->id, (string) $v->name, $locale);
            $attr = $v->relationLoaded('attribute') ? $v->getRelation('attribute') : null;

            if ($attr !== null && trim((string) $attr->name) !== '') {
                // Two values on one axis (an import can pin a variation to
                // both) are both kept, in order: "Size: 50ml / Rose".
                $key = VariantOptions::axisName((int) $attr->id, (string) $attr->name, $locale);
                $out[$key] = isset($out[$key]) ? $out[$key].' / '.$name : $name;
            } else {
                $out[] = $name;
            }
        }

        return $out;
    }

    /**
     * A snapshot (either shape) as one line of text. Keyed entries read
     * "Axis: value"; a plain list is joined exactly as it always was.
     */
    public static function line(mixed $attributes, string $separator = ', '): string
    {
        if (is_string($attributes)) {
            $attributes = json_decode($attributes, true);
        }

        if (! is_array($attributes)) {
            return '';
        }

        $parts = [];

        foreach ($attributes as $key => $value) {
            if (! is_scalar($value) || trim((string) $value) === '') {
                continue;
            }

            if (! is_string($key) || trim($key) === '') {
                $parts[] = trim((string) $value);

                continue;
            }

            /*
             * AN ARABIC AXIS ISOLATES ITS VALUE. "الدرجة: #13 Bright Beige" laid
             * out right to left printed the shade as "Bright Beige 13#": the
             * "#" and the digits took the paragraph's direction. U+2068/U+2069
             * (first-strong isolate) give the value its own direction in plain
             * text and HTML alike, with no markup; an English line is untouched.
             */
            $value = trim((string) $value);
            $parts[] = trim($key).': '.(preg_match('/\p{Arabic}/u', $key) === 1 ? "\u{2068}".$value."\u{2069}" : $value);
        }

        return implode($separator, $parts);
    }

    /**
     * What an order line says, for a reader in the customer's language: the
     * localised snapshot where the order has one, else the English.
     */
    public static function forOrderItem(object $item, bool $customer = true, string $separator = ', '): string
    {
        $localised = $customer ? ($item->variant_localised ?? null) : null;

        if (is_string($localised)) {
            $localised = json_decode($localised, true);
        }

        $use = is_array($localised) && $localised !== [] ? $localised : ($item->variant_attributes ?? null);

        return self::line($use, $separator);
    }

    /** A live basket line, in the page's language. */
    public static function forVariant(?ProductVariant $variant, string $separator = ' · '): string
    {
        if ($variant === null) {
            return '';
        }

        // Values not loaded here (the mini cart's own reload): the label the
        // page already worked out this request -- see VariantLabels.
        if (! $variant->relationLoaded('attributeValues')) {
            return (string) app(VariantLabels::class)->get((int) $variant->id);
        }

        return self::line(self::snapshot($variant, Locale::current()), $separator);
    }
}

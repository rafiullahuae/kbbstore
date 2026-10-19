<?php

declare(strict_types=1);

namespace App\Support;

/**
 * "Card A": the shade dots and size chips under a variable product's name.
 * (Lane VPA)
 *
 * The owner's choice from docs/variant-options/index.html — "Small shade dots
 * under the name (four, then +N) and slim size chips." Tapping a dot or a chip
 * moves the card's picture and price to that option where the option has its
 * own; a sold-out option is drawn faded and crossed, and can still be looked
 * at. The card's button is untouched: "View product" for a variable product,
 * exactly as before.
 *
 * Built as ONE STRING, printed at the start of the card's existing price line,
 * so a simple product's card is byte-for-byte what it was (the empty string
 * adds nothing) — the reasoning components/product-card.blade.php gives for
 * every other conditional piece of the tile.
 *
 * EVERYTHING THE TAP NEEDS IS IN THE CARD. `data-vpc` carries the options as
 * JSON (resources/js/kbb/variant-pick.js reads it once, on the first tap of
 * THAT card), prices pre-formatted by Money::format() on the server — markup
 * this server composed from constants and an escaped symbol, the same contract
 * pdp.js' `pricehtml` branch documents — and each option's picture with its
 * img-cache srcset. No request per tap, nothing measured.
 */
final class VariantCard
{
    /** Dots before "+N", as drawn. */
    public const MAX_DOTS = 4;

    /** Size chips before "+N": three keep the card one chip-row tall. */
    public const MAX_CHIPS = 3;

    /** @param array<string, mixed>|null $picker VariantOptions::build() */
    public static function html(?array $picker, int $productId = 0): string
    {
        if ($picker === null || $picker['synthetic']) {
            return '';
        }

        $colour = null;
        $size = null;

        foreach ($picker['axes'] as $axis) {
            if ($axis['kind'] === 'colour' && $colour === null) {
                $colour = $axis;
            } elseif ($axis['kind'] !== 'colour' && $size === null) {
                $size = $axis;
            }
        }

        if ($colour === null && ($size === null || count($size['values']) < 2)) {
            return '';
        }

        $sel = $picker['variants'][$picker['selected']] ?? null;
        $choice = $sel['vals'] ?? [];
        $axes = array_values(array_filter([$colour, $size]));

        $prices = [];
        $priceIndex = [];
        $images = [];
        $imageIndex = [];
        $rows = [];
        $html = static function (string $markup) use (&$prices, &$priceIndex): int {
            if (! isset($priceIndex[$markup])) {
                $priceIndex[$markup] = count($prices);
                $prices[] = $markup;
            }

            return $priceIndex[$markup];
        };

        foreach ($picker['variants'] as $v) {
            // Both figures at one precision that separates them -- the card's
            // own rule for a marked-down tile (components/product-card).
            $dp = $v['was'] !== null ? Money::decimalsToDistinguish($v['was'], $v['price']) : null;
            $img = -1;

            if ($v['image'] !== null) {
                if (! isset($imageIndex[$v['image']])) {
                    $imageIndex[$v['image']] = count($images);
                    $images[] = [$v['image'], ImageVariants::srcsetFor($v['image'])];
                }

                $img = $imageIndex[$v['image']];
            }

            $rows[] = [
                $v['id'],
                array_map(static fn (array $a): int => (int) ($v['vals'][$a['id']] ?? 0), $axes),
                $html(Money::format($v['price'], $dp)),
                $v['was'] !== null ? $html(Money::format($v['was'], $dp)) : -1,
                $v['in'] ? 1 : 0,
                $img,
            ];
        }

        $data = json_encode(
            ['v' => $rows, 'p' => $prices, 'i' => $images],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP,
        );

        $out = '<div class="vpc" data-pid="'.$productId.'" data-vpc="'.e((string) $data).'">';

        foreach ($axes as $i => $axis) {
            $isColour = $axis['kind'] === 'colour';
            $max = $isColour ? self::MAX_DOTS : self::MAX_CHIPS;
            $out .= '<div class="vpc-row" role="group" aria-label="'.e($axis['name']).'" data-ax="'.$i.'">';

            foreach (array_slice($axis['values'], 0, $max) as $val) {
                $on = ($choice[$axis['id']] ?? null) === $val['id'];
                $sold = VariantOptions::valueOut($picker, (int) $axis['id'], (int) $val['id'], $choice);
                $label = $axis['name'].': '.$val['name'].($sold ? ' — '.__('store.product.sold_out_tag') : '');

                $out .= '<button type="button" class="'.($isColour ? 'vpc-sw' : 'vpc-chip').($sold ? ' out' : '').'"'
                    .' data-v="'.(int) $val['id'].'" aria-pressed="'.($on ? 'true' : 'false').'"'
                    .' aria-label="'.e($label).'" title="'.e($label).'"'
                    .($isColour ? ' style="'.e(VariantOptions::swatchStyle($val)).'">' : '>'.e($val['name']))
                    .'</button>';
            }

            if (count($axis['values']) > $max) {
                $out .= '<span class="vpc-more">+'.(count($axis['values']) - $max).'</span>';
            }

            $out .= '</div>';
        }

        return $out.'</div>';
    }
}

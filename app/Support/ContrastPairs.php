<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Every colour the owner can move that Google's colour-contrast audit reads,
 * and the backgrounds it is drawn on.                               (Lane CT)
 *
 * One list, read by two things that must agree: the console's warning beside
 * each colour control (admin/partials/colour-contrast-guard), and the tests
 * that prove each shipped default clears WCAG AA. A background added here is
 * checked by both at once.
 *
 * `text`  — the colour IS the text; it must clear 4.5 against every listed
 *           background (the page's own #FDEFF3 included: that is the colour
 *           Lighthouse reads under anything sitting on the page itself).
 * `fill`  — the colour is UNDER the text; it must clear 4.5 against the label
 *           colour. A label that is itself a setting names that setting
 *           (`fill_fg`), so the button and its words are judged as a pair.
 *
 * Nothing here is printed unescaped: the partial json-encodes it.
 */
final class ContrastPairs
{
    /** WCAG 2.1 AA for body text. */
    public const AA = 4.5;

    /** The page itself, under the gradient, as Lighthouse measures it. */
    public const PAGE = '#FDEFF3';

    /**
     * input id or ProductStyles key => [what it is, kind, backgrounds or label].
     *
     * @var array<string, array{label: string, kind: 'text'|'fill', against?: list<string>, fill_fg?: string, fg?: string}>
     */
    public const PAIRS = [
        // Settings → Business details → Your brand colour. Pink text sits on
        // white, on the page, and on the flag bar's tint; white sits on pink.
        // White-on-X equals X-on-white, so the white row covers both.
        'set_brand_accent' => ['label' => 'Brand colour', 'kind' => 'text',
            'against' => ['#FFFFFF', self::PAGE, '#FDEFF4']],
        // Appearance → Product styles → Colour.
        'sale_colour' => ['label' => 'Sale badge', 'kind' => 'fill', 'fg' => '#FFFFFF'],
        'new_colour' => ['label' => 'New badge', 'kind' => 'fill', 'fg' => '#FFFFFF'],
        'cart_bg' => ['label' => 'Button background', 'kind' => 'fill', 'fill_fg' => 'cart_fg', 'fg' => '#FFFFFF'],
        'muted_colour' => ['label' => 'Secondary text', 'kind' => 'text',
            'against' => ['#FFFFFF', '#FFF0F4', '#FFFDF8', '#FFF8F5', self::PAGE]],
        'was_colour' => ['label' => 'Crossed-out price', 'kind' => 'text',
            'against' => ['#FFFFFF', '#FFF0F4', '#FFFDF8']],
        'save_colour' => ['label' => 'Savings line', 'kind' => 'text', 'against' => ['#E9F6EF']],
        'wa_foot_colour' => ['label' => 'Footer WhatsApp button', 'kind' => 'fill', 'fg' => '#FFFFFF'],
    ];

    /** The WCAG 2.1 contrast ratio of two #RRGGBB colours. */
    public static function ratio(string $a, string $b): float
    {
        $la = TitleHeader::luminance($a);
        $lb = TitleHeader::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /**
     * The worst ratio of `$hex` in the role `$key` names, and what it was
     * measured against. `$fg` is the label colour for a `fill` whose label is
     * a setting of its own.
     *
     * @return array{ratio: float, against: string}
     */
    public static function worst(string $key, string $hex, ?string $fg = null): array
    {
        $pair = self::PAIRS[$key];
        $against = $pair['kind'] === 'fill' ? [$fg ?? $pair['fg']] : $pair['against'];
        $worst = ['ratio' => INF, 'against' => $against[0]];

        foreach ($against as $bg) {
            $r = self::ratio($hex, $bg);

            if ($r < $worst['ratio']) {
                $worst = ['ratio' => $r, 'against' => $bg];
            }
        }

        return $worst;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Lane MG — a primary menu shaped like the owner's screenshot, for the
 * mega-menu tests and the preview seed: twelve top-level items, a mid-row
 * "All Brands" whose ninety brand links make nine columns, a "Skincare" near
 * the right end with three headed columns, a two-column "Sunscreens" just
 * before it, a single-column "Hair Care", a highlight pill and a badge.
 */
final class MegaMenuShape
{
    public const BRANDS = ['Anua', 'COSRX', 'Beauty of Joseon', 'Medicube', "d'Alba", 'Arencia', 'Round Lab', 'Torriden',
        'Skin1004', 'Isntree', 'Laneige', 'Innisfree', 'Some By Mi', 'Purito', 'Klairs', 'Missha', 'Etude', 'Banila Co',
        'Heimish', 'Mixsoon', 'Numbuzin', 'Abib', 'Haruharu Wonder', 'Goodal', 'Mediheal', 'Dr. Jart+', 'Sulwhasoo',
        'Hera', 'Rom&nd', 'Peripera', 'Clio', 'Amuse', 'Tirtir', 'Ma:nyo', 'Axis-Y', 'By Wishtrend', 'Benton',
        'Holika Holika', 'The Face Shop', 'Nature Republic', 'Tonymoly', 'A\'pieu', 'Aromatica', 'Dear Klairs',
        'Neogen', 'Pyunkang Yul', 'Iunik', 'Jumiso', 'Kaine', 'Hanyul', 'Illiyoon', 'Aestura', 'Bring Green',
        'Celimax', 'Dr. Ceuracle', 'Ample:N', 'Make P:rem', 'Manyo Factory', 'One-day\'s You', 'Papa Recipe',
        'Skinfood', 'Sioris', 'Thank You Farmer', 'VT Cosmetics', 'Wellage', 'Zeroid', 'Beplain', 'Biodance',
        'Dalba Italian', 'Elizavecca', 'Frudia', 'Huxley', 'I\'m From', 'Jayjun', 'Krave Beauty', 'Lagom',
        'Mamonde', 'Nacific', 'Ottie', 'Pestlo', 'Real Barrier', 'Snature', 'Too Cool For School', 'Uriage Korea',
        'Vely Vely', 'Whamisa', 'Xyzal Skin', 'Yuripibu', 'Zymogen', 'Atopalm'];

    /** @return list<array<string, mixed>> */
    public static function items(): array
    {
        $leaf = static fn (string $label, string $url = '/shop/'): array => [
            'label' => $label, 'url' => $url, 'icon' => null, 'highlight_color' => null, 'new_tab' => false, 'children' => [],
        ];
        $top = static fn (string $label, array $children = [], array $extra = []): array => array_merge([
            'label' => $label, 'url' => '/shop/', 'icon' => null, 'badge' => null, 'highlight_color' => null,
            'visibility' => 'always', 'new_tab' => false, 'columns' => null, 'children' => $children,
        ], $extra);

        $brands = array_map(static fn (string $b): array => $leaf($b, '/brand/'.strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $b)).'/'), self::BRANDS);

        $head = static fn (string $label, array $links): array => array_merge($leaf($label), [
            'children' => array_map(static fn (string $l): array => $leaf($l), $links),
        ]);

        $skincare = [
            $head('Cleanse', ['Cleansing oil', 'Foam cleanser', 'Cleansing balm', 'Micellar water']),
            $head('Treat', ['Toner', 'Essence', 'Serum', 'Ampoule', 'Spot care']),
            $head('Hydrate', ['Moisturizer', 'Sleeping mask', 'Eye cream']),
            $head('Protect', ['Sunscreen', 'Sun stick', 'Tone-up cream']),
            $head('Masks', ['Sheet masks', 'Wash-off masks', 'Pads']),
            $leaf('Skincare sets'),
        ];

        return [
            $top('Blog'),
            $top('Everything Under 54 AED', [], ['highlight_color' => '#E0567B']),
            $top('Beauty Devices'),
            $top('Hair Care', array_map($leaf, ['Shampoo', 'Conditioner', 'Hair mask', 'Scalp care', 'Hair oil'])),
            $top('All Brands', $brands),
            $top('Super Sale', [], ['badge' => 'HOT']),
            $top('Lip Care'),
            $top('Toners'),
            $top('Moisturizers'),
            // Third from the end and opening from its own start, so its
            // two-column panel would run past the row's end unclamped.
            $top('Sunscreens', array_map($leaf, ['SPF 50+', 'Sun sticks', 'Sun serums', 'Tone-up', 'Mineral', 'Chemical',
                'Kids', 'Body', 'Tinted', 'Sun cushions', 'Sun sprays', 'After-sun']), ['columns' => 2]),
            $top('Skincare', $skincare, ['columns' => 3]),
            $top('Gift Sets'),
        ];
    }
}

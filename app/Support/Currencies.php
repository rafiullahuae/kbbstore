<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The currency table behind Store -> Business Details.
 *
 * ONE source of truth. The admin screen renders its dropdown from
 * self::forSelect() and Money reads its defaults from here, so a currency is
 * described in exactly one place rather than half in a Blade file and half in
 * a formatter.
 *
 * WHY THIS LIST IS CURATED RATHER THAN EXHAUSTIVE
 *
 * Stripe settles in roughly 135 currencies. Shipping all of them would mean a
 * dropdown nobody can scan, 135 rows to keep correct, and a standing invitation
 * to pick a currency this shop cannot actually be paid in. What is here instead:
 *
 *   - every GCC currency (AED, SAR, QAR, KWD, BHD, OMR), because that is the
 *     store's own region and the one expansion anybody has actually asked for;
 *   - the majors a UAE shop realistically prices in for cross-border customers
 *     (USD, EUR, GBP, and the usual second tier);
 *   - nothing else. Adding a currency is one line here.
 *
 * DECIMALS are the ISO 4217 minor-unit exponent, and they are not all 2:
 * JPY and KRW have 0, and BHD/JOD/KWD/OMR/TND have 3. Money uses this number
 * for BOTH the minor-unit conversion and the printed decimals, so getting it
 * wrong misprices the store by a factor of ten -- see Money::minorExponent().
 *
 * SYMBOLS are the default only. The operator can override any of them from the
 * admin, which is the escape hatch for a currency whose symbol is contested
 * (the 2025 Saudi riyal mark has no assigned Unicode code point yet, so SAR
 * falls back to the older U+FDFC RIAL SIGN here).
 */
final class Currencies
{
    /**
     * The UAE dirham sign, U+20C3.
     *
     * Accepted by the Unicode Technical Committee in July 2025 and shipping in
     * Unicode 18.0 (September 2026). It is genuinely new, which means most
     * installed fonts have no glyph for it and it renders as tofu — an empty
     * box — on most devices today. That is a font problem, not an encoding
     * problem: the character is correct, and it is what belongs in the DOM for
     * copy-paste, screen readers and search engines.
     *
     * Money::symbolRender() is the pressure valve: switch it to `svg` and the
     * storefront draws the glyph instead of asking the font for it.
     */
    /*
     * Plain 'AED' rather than the U+20C3 dirham sign.
     *
     * U+20C3 is the correct character and ships in Unicode 18.0, but it was
     * tried on a real device and drew as an empty box: no installed font has
     * the glyph yet. A currency symbol that renders as tofu on a storefront is
     * worse than no symbol at all, so the default is the three letters every
     * device can already draw.
     *
     * The sign is still reachable -- AED_SIGN below, the Symbol field on Store
     * → Business Details, and the drawn-glyph rendering option -- so this flips
     * back to a single character the moment fonts catch up.
     */
    public const AED_SYMBOL = 'AED';

    /** The dirham sign itself, U+20C3, for when device fonts support it. */
    public const AED_SIGN = "\u{20C3}";

    /**
     * code => [name, symbol, decimals].
     *
     * Ordered deliberately: GCC first (this store's own market), then the
     * majors, then the rest alphabetically by code.
     */
    public const LIST = [
        // --- GCC ---------------------------------------------------------
        'AED' => ['name' => 'UAE Dirham',        'symbol' => self::AED_SYMBOL, 'decimals' => 2],
        'SAR' => ['name' => 'Saudi Riyal',       'symbol' => "\u{FDFC}",     'decimals' => 2],
        'QAR' => ['name' => 'Qatari Riyal',      'symbol' => "\u{FDFC}",     'decimals' => 2],
        'KWD' => ['name' => 'Kuwaiti Dinar',     'symbol' => 'د.ك',          'decimals' => 3],
        'BHD' => ['name' => 'Bahraini Dinar',    'symbol' => 'د.ب',          'decimals' => 3],
        'OMR' => ['name' => 'Omani Rial',        'symbol' => 'ر.ع.',         'decimals' => 3],

        // --- Majors ------------------------------------------------------
        'USD' => ['name' => 'US Dollar',         'symbol' => '$',            'decimals' => 2],
        'EUR' => ['name' => 'Euro',              'symbol' => '€',            'decimals' => 2],
        'GBP' => ['name' => 'British Pound',     'symbol' => '£',            'decimals' => 2],
        'JPY' => ['name' => 'Japanese Yen',      'symbol' => '¥',            'decimals' => 0],
        'CHF' => ['name' => 'Swiss Franc',       'symbol' => 'CHF',          'decimals' => 2],
        'CAD' => ['name' => 'Canadian Dollar',   'symbol' => 'CA$',          'decimals' => 2],
        'AUD' => ['name' => 'Australian Dollar', 'symbol' => 'A$',           'decimals' => 2],

        // --- Second tier, alphabetical -----------------------------------
        'BRL' => ['name' => 'Brazilian Real',    'symbol' => 'R$',           'decimals' => 2],
        'CNY' => ['name' => 'Chinese Yuan',      'symbol' => '¥',            'decimals' => 2],
        'DKK' => ['name' => 'Danish Krone',      'symbol' => 'kr',           'decimals' => 2],
        'EGP' => ['name' => 'Egyptian Pound',    'symbol' => 'E£',           'decimals' => 2],
        'HKD' => ['name' => 'Hong Kong Dollar',  'symbol' => 'HK$',          'decimals' => 2],
        'IDR' => ['name' => 'Indonesian Rupiah', 'symbol' => 'Rp',           'decimals' => 2],
        'ILS' => ['name' => 'Israeli Shekel',    'symbol' => '₪',            'decimals' => 2],
        'INR' => ['name' => 'Indian Rupee',      'symbol' => '₹',            'decimals' => 2],
        'JOD' => ['name' => 'Jordanian Dinar',   'symbol' => 'د.ا',          'decimals' => 3],
        'KRW' => ['name' => 'South Korean Won',  'symbol' => '₩',            'decimals' => 0],
        'MXN' => ['name' => 'Mexican Peso',      'symbol' => 'MX$',          'decimals' => 2],
        'MYR' => ['name' => 'Malaysian Ringgit', 'symbol' => 'RM',           'decimals' => 2],
        'NOK' => ['name' => 'Norwegian Krone',   'symbol' => 'kr',           'decimals' => 2],
        'NZD' => ['name' => 'New Zealand Dollar', 'symbol' => 'NZ$',         'decimals' => 2],
        'PHP' => ['name' => 'Philippine Peso',   'symbol' => '₱',            'decimals' => 2],
        'PKR' => ['name' => 'Pakistani Rupee',   'symbol' => '₨',            'decimals' => 2],
        'PLN' => ['name' => 'Polish Zloty',      'symbol' => 'zł',           'decimals' => 2],
        'SEK' => ['name' => 'Swedish Krona',     'symbol' => 'kr',           'decimals' => 2],
        'SGD' => ['name' => 'Singapore Dollar',  'symbol' => 'S$',           'decimals' => 2],
        'THB' => ['name' => 'Thai Baht',         'symbol' => '฿',            'decimals' => 2],
        'TRY' => ['name' => 'Turkish Lira',      'symbol' => '₺',            'decimals' => 2],
        'ZAR' => ['name' => 'South African Rand', 'symbol' => 'R',           'decimals' => 2],
    ];

    /** True when the code is one this store knows how to describe. */
    public static function has(string $code): bool
    {
        return isset(self::LIST[self::normalise($code)]);
    }

    /** One currency, or null. */
    public static function find(string $code): ?array
    {
        $code = self::normalise($code);

        return isset(self::LIST[$code]) ? self::LIST[$code] + ['code' => $code] : null;
    }

    /** Default display symbol for a code, or $fallback when it is not listed. */
    public static function symbolFor(string $code, string $fallback): string
    {
        return self::find($code)['symbol'] ?? $fallback;
    }

    /** ISO minor-unit exponent for a code, or $fallback when it is not listed. */
    public static function decimalsFor(string $code, int $fallback = 2): int
    {
        return self::find($code)['decimals'] ?? $fallback;
    }

    /**
     * The list in the shape the admin dropdown wants: a flat, ordered array of
     * {code, name, symbol, decimals}. Rendered into the admin script with
     * @json so the Blade file never restates a symbol or a decimal count.
     */
    public static function forSelect(): array
    {
        $out = [];

        foreach (self::LIST as $code => $row) {
            $out[] = [
                'code' => $code,
                'name' => $row['name'],
                'symbol' => $row['symbol'],
                'decimals' => $row['decimals'],
            ];
        }

        return $out;
    }

    private static function normalise(string $code): string
    {
        return strtoupper(trim($code));
    }
}

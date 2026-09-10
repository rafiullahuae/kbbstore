<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Money is stored as integer fils (AED x 100) everywhere, which removes float
 * drift from every total.
 *
 * Display matches the live store exactly: the Arabic dirham symbol immediately
 * before the number, no space, and no decimal places — WooCommerce is
 * configured with 0 decimals there, so د.إ3,112 rather than AED 3,112.00.
 */
final class Money
{
    /** The symbol WooCommerce renders for AED on the live site. */
    public const SYMBOL = 'د.إ';

    public static function toAed(int $fils): float
    {
        return round($fils / 100, 2);
    }

    public static function fromAed(float|int|string $aed): int
    {
        return (int) round(((float) $aed) * 100);
    }

    /** Bare number, no symbol — for inputs and data attributes. */
    public static function amount(int $fils, int $decimals = 0): string
    {
        return number_format(self::toAed($fils), $decimals);
    }

    /**
     * The full price, wrapped the way WooCommerce wraps it so the theme's
     * price styling applies unchanged.
     */
    public static function format(int $fils, int $decimals = 0): string
    {
        return '<span class="woocommerce-Price-amount amount">'
            . '<span class="woocommerce-Price-currencySymbol">' . self::SYMBOL . '</span>'
            . self::amount($fils, $decimals)
            . '</span>';
    }

    /** Plain text, for places that must not contain markup (title tags, JSON). */
    public static function plain(int $fils, int $decimals = 0): string
    {
        return self::SYMBOL . self::amount($fils, $decimals);
    }
}

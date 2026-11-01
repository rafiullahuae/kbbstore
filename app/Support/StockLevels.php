<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\SettingsService;

/**
 * Per-product low-stock levels and back orders, in one place. (Lane C)
 *
 * ── LOW STOCK ───────────────────────────────────────────────────────────────
 *
 * `products.low_stock_amount` is the product's own line; NULL means "the
 * default of the screen asking" (the owner app's Low stock at, the admin
 * catalogue's 15). Every list decides it IN SQL with COALESCE, so a list of
 * forty costs what a list of three does -- never a per-row lookup.
 *
 * ── BACK ORDERS ─────────────────────────────────────────────────────────────
 *
 * `backorders` (products and product_variants) = "keep selling below zero".
 * The row's stock_status stays 'instock', because every shop page reads
 * anything else as sold out. The API vocabulary the editors already speak is
 * kept: a posted stock_status of 'onbackorder' MEANS "allow back orders" and
 * is stored as backorders = 1 + 'instock' (see fromPosted()), and a row that
 * allows them is reported back as 'onbackorder' (see reported()), so the
 * selects round-trip unchanged.
 *
 * A product is ON back order -- the thing the admin shows in red -- when it
 * allows them and its counted stock is at or below zero (or it counts none).
 */
final class StockLevels
{
    /** Store -> Ecommerce -> General -> Stock & back orders. */
    public const DISPLAY_KEY = 'admin_backorder_display';

    public const DISPLAY_BOTH = 'both';

    public const DISPLAY_LABEL = 'label';

    public const DISPLAY_MINUS = 'minus';

    public const DISPLAYS = [
        self::DISPLAY_BOTH => "Both: red minus quantity and 'On back order'",
        self::DISPLAY_LABEL => "'On back order' label",
        self::DISPLAY_MINUS => 'Red minus quantity (e.g. −3)',
    ];

    /** The largest per-product level the editors accept. */
    public const MAX_LEVEL = 100000;

    /** The lowest stock figure a back-ordered product can be saved with. */
    public const MIN_STOCK = -1000000;

    /** One of DISPLAYS' keys, whatever is stored. */
    public static function display(): string
    {
        $v = app(SettingsService::class)->get(self::DISPLAY_KEY, self::DISPLAY_BOTH);

        return is_string($v) && isset(self::DISPLAYS[$v]) ? $v : self::DISPLAY_BOTH;
    }

    /**
     * "Low" for a list, as SQL over a table alias. The alias is always a
     * literal from the calling code, never input; the default is bound.
     *
     * @return array{0:string,1:array<int,int>}
     */
    public static function lowSql(string $t, int $default): array
    {
        self::guardAlias($t);

        return ["{$t}.manage_stock = 1 AND {$t}.stock > 0 AND {$t}.stock <= COALESCE({$t}.low_stock_amount, ?)", [$default]];
    }

    /** Add "is low" to a query (Eloquent or query builder). */
    public static function whereLow(object $q, string $t, int $default): object
    {
        [$sql, $bind] = self::lowSql($t, $default);

        return $q->whereRaw('('.$sql.')', $bind);
    }

    /** The same answer for a row already in hand (no query). */
    public static function isLow(mixed $manage, mixed $stock, mixed $own, int $default): bool
    {
        if (! (bool) $manage || $stock === null) {
            return false;
        }
        $at = $own === null ? $default : (int) $own;

        return (int) $stock > 0 && (int) $stock <= $at;
    }

    /** Allows back orders and is actually owing them. */
    public static function onBackorder(mixed $backorders, mixed $manage, mixed $stock): bool
    {
        return (bool) $backorders && (! (bool) $manage || $stock === null || (int) $stock <= 0);
    }

    /** The stock_status the editors are shown: 'onbackorder' when the row allows them. */
    public static function reported(mixed $backorders, mixed $stockStatus): string
    {
        return (bool) $backorders ? 'onbackorder' : (string) $stockStatus;
    }

    /**
     * A posted stock_status, as what is stored.
     *
     * @return array{stock_status:string, backorders:bool}
     */
    public static function fromPosted(string $posted): array
    {
        return $posted === 'onbackorder'
            ? ['stock_status' => 'instock', 'backorders' => true]
            : ['stock_status' => $posted, 'backorders' => false];
    }

    /** "−3" with a real minus sign, for the admin's red figure. */
    public static function figure(?int $stock): string
    {
        if ($stock === null) {
            return '';
        }

        return $stock < 0 ? "\u{2212}".abs($stock) : (string) $stock;
    }

    private static function guardAlias(string $t): void
    {
        if (preg_match('/^[a-z_]+$/', $t) !== 1) {
            throw new \InvalidArgumentException('Bad table alias.');
        }
    }
}

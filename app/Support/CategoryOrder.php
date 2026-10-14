<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A category's own starting order (2.60.501).
 *
 * The owner, on /collections/new-arrivals/: "in this category i need to view
 * the new added products first. as the collection name is New Arrivals."
 *
 * One column, `categories.default_sort`: NULL is the shop's default ("Featured"
 * -- the category's own Reorder, then featured, then name), and 'date' opens
 * the page newest first, exactly as a shopper choosing "Latest" in Sort by
 * does. A shopper's own ?orderby= still wins. Set from Catalog -> Categories
 * -> Edit -> Product order.
 */
final class CategoryOrder
{
    public const COLUMN = 'default_sort';

    /** The stored values; '' / NULL is the shop default. */
    public const MODES = ['date'];

    /** The order a category's page opens on: one of Facets::SORTS' keys. */
    public static function defaultFor(?object $category): string
    {
        $mode = $category?->{self::COLUMN} ?? null;

        return is_string($mode) && in_array($mode, self::MODES, true) ? $mode : 'featured';
    }

    /** A stored value: one of MODES, or NULL for the shop default. */
    public static function clean(mixed $value): ?string
    {
        return is_string($value) && in_array($value, self::MODES, true) ? $value : null;
    }

    /**
     * Whether the column exists yet: the admin asks, so a package applied
     * before its migration has run edits as it did instead of 500ing. The
     * storefront never asks -- it reads the attribute off the row it holds and
     * gets NULL, the shop default, when the column is absent.
     */
    public static function columnReady(): bool
    {
        try {
            return \Illuminate\Support\Facades\Schema::hasColumn('categories', self::COLUMN);
        } catch (\Throwable) {
            return false;
        }
    }
}

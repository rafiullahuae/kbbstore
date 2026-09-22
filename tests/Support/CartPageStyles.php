<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * The squeezed cart page's stylesheet and script, wherever they live.
 *
 * ── WHY THIS EXISTS ─────────────────────────────────────────────────────────
 *
 * Until the checkout needed the delivery-address sheet, all of it sat in one
 * file and three dozen assertions read that file directly. The sheet is a
 * partial now — `partials/address-sheet.blade.php` — because the cart and the
 * checkout have the SAME sheet over the SAME addresses, and a second copy
 * would be a second copy of the session key, the endpoints and the guest cap
 * that go with them.
 *
 * Every one of those assertions is still about the cart page: that the sheet
 * never scrolls, that the country list opens upward, that the docked rows
 * outrank it. None of them is about which file a rule is written in. So rather
 * than rewrite three dozen assertions to chase the move — or, worse, leave the
 * suite red and learn to ignore it — they read this, and it returns both
 * files.
 *
 * It is NOT a way to make a missing rule pass. A rule deleted from both files
 * is still absent from what this returns, which is the property every one of
 * those assertions actually depends on.
 */
final class CartPageStyles
{
    /** The two files that together make the cart page's styles and behaviour. */
    public const FILES = [
        'views/store/cart-squeeze.blade.php',
        'views/partials/address-sheet.blade.php',
    ];

    /** Both files, concatenated, with a newline between them. */
    public static function all(): string
    {
        return implode("\n", array_map(
            static fn (string $path) => (string) file_get_contents(resource_path($path)),
            self::FILES,
        ));
    }
}

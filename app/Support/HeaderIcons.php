<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The three marks on the right of the header: account, wishlist, cart.
 *
 * ONE SOURCE, because there were three and they disagreed.
 *
 *   - `partials/header.blade.php` drew its own inline SVGs: a hairline person
 *     outline, a heart whose two lobes are different sizes and end at (19,14)
 *     rather than closing on the point, and a basket that is a plain trapezoid
 *     with no handle.
 *   - `mhPreview()` on Appearance → Mobile Header drew no icons at all — two
 *     22px grey discs stood in for the pair.
 *   - `hdPreview()` on Appearance → Header drew the PHONE preview's icons as
 *     literal emoji: &#128100; &#9825; &#128722;. That trio is what the owner
 *     screenshotted and asked the storefront to match, and it is also why the
 *     account mark reads as "a dull grey filled person silhouette" — an emoji
 *     is painted by the platform's own font and ignores `color` entirely.
 *
 * So the emoji are what these paths translate, and they are SVG rather than
 * emoji for the reason the owner's next sentence gives: he wants the account
 * mark green once someone is signed in, and nothing can recolour a 👤.
 *
 * The storefront and both admin previews now read from here, so a change to an
 * icon is one edit and the screens cannot drift from the shop again.
 */
final class HeaderIcons
{
    /** Drawn filled, like the bust-in-silhouette it replaces. */
    private const ACCOUNT = '<path d="M12 12.4a4.2 4.2 0 1 0 0-8.4 4.2 4.2 0 0 0 0 8.4Z"/>'
        . '<path d="M3.8 20.6c0-3.7 3.7-6.2 8.2-6.2s8.2 2.5 8.2 6.2a1.1 1.1 0 0 1-1.1 1.1H4.9a1.1 1.1 0 0 1-1.1-1.1Z"/>';

    /** Drawn as an outline, like the white heart suit it replaces. */
    private const WISHLIST = '<path d="M12 20.6 4.3 12.9a4.6 4.6 0 0 1 6.5-6.5l1.2 1.2 1.2-1.2a4.6 4.6 0 0 1 6.5 6.5Z"/>';

    /** A trolley — the handle and the two wheels the old basket never had. */
    private const CART = '<path d="M2.8 4h2.4l2.5 10.8a1.7 1.7 0 0 0 1.7 1.3h7.9a1.7 1.7 0 0 0 1.6-1.3L20.9 8H6"/>'
        . '<circle cx="10" cy="20" r="1.4"/><circle cx="17.4" cy="20" r="1.4"/>';

    /**
     * The account mark.
     *
     * Filled rather than stroked, so `color` paints the whole silhouette and
     * the signed-in colour reads at 21px on a phone.
     */
    public static function account(): string
    {
        return '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">' . self::ACCOUNT . '</svg>';
    }

    public static function wishlist(): string
    {
        return self::stroked(self::WISHLIST);
    }

    public static function cart(): string
    {
        return self::stroked(self::CART);
    }

    /**
     * The same three, for the admin previews.
     *
     * Handed to the screen as data rather than pasted into it, so the preview
     * is drawn from the storefront's own paths. `@json()` escapes it.
     *
     * @return array<string, string>
     */
    public static function forPreview(): array
    {
        return [
            'account' => self::account(),
            'wishlist' => self::wishlist(),
            'cart' => self::cart(),
        ];
    }

    private static function stroked(string $inner): string
    {
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"'
            . ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $inner . '</svg>';
    }
}

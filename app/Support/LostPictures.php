<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\Import\MediaAudit;
use App\Services\Import\MediaSideloader;
use Illuminate\Support\Facades\DB;

/**
 * A picture the migration tried to bring across from the old site and could
 * not. Lane PX.
 *
 * =============================================================================
 * THE DEFECT, AS IT LOOKED ON THE SHOP
 * =============================================================================
 *
 * After the picture pass, a product whose photograph 404ed on the old site (or
 * was refused — a loopback address, an odd port, a name that could execute)
 * keeps the old address in `products.image`. Nothing else is honest: the row
 * needs a new picture and only the owner can choose one, and blanking it would
 * destroy the one clue to what it used to be. But the product page drew that
 * address straight into the main `<img>`. Measured in Chromium with the old
 * site switched off, at 390 and 1280: a white 390×390 / 612×612 frame holding
 * the browser's broken-image icon and the alt text "COSRX PX Missing Picture",
 * with the shop's own placeholder (the gradient and the brand caption it draws
 * for a product with no photograph) hidden underneath it.
 *
 * So a picture the sideloader's ledger records as FAILED or REFUSED is treated
 * by the storefront exactly as no picture at all: the product page and the
 * product card fall back to the placeholder they already draw. Server-side, so
 * there is no broken request and no script; and only for those rows, so every
 * page whose pictures came across renders byte-for-byte as before.
 *
 * A failure that later succeeds (the owner presses Fetch again) leaves the
 * ledger's FAILED state behind and the picture comes back on the next request.
 *
 * COST. Nothing for a local path (no host), nothing for this shop's own host
 * (an admin upload is stored absolute), and ONE query per request the first
 * time an address on another host is asked about — the set is read once and
 * kept on the container, which is per request under PHP-FPM and per test, so
 * it cannot go stale the way a process-level static does (CLAUDE.md,
 * `Setting::map()`).
 */
final class LostPictures
{
    private const MEMO = 'kbb.lost-pictures';

    /** The address, or null when it is a picture the migration could not bring. */
    public static function usable(?string $url): ?string
    {
        return self::isLost($url) ? null : $url;
    }

    public static function isLost(?string $url): bool
    {
        if (! is_string($url)) {
            return false;
        }

        $url = trim($url);
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return false;
        }

        if ((new MediaAudit)->isOwnHost($host)) {
            return false;
        }

        return isset(self::lost()[MediaSideloader::hash($url)]);
    }

    /** @return array<string, true> */
    private static function lost(): array
    {
        $app = app();

        if ($app->bound(self::MEMO)) {
            return $app->make(self::MEMO);
        }

        $lost = [];

        try {
            foreach (DB::table(MediaSideloader::ITEMS)
                ->whereIn('state', [MediaSideloader::FAILED, MediaSideloader::REFUSED])
                ->pluck('url_hash') as $hash) {
                $lost[(string) $hash] = true;
            }
        } catch (\Throwable) {
            // No ledger table yet (a package mid-apply): nothing is known lost.
            $lost = [];
        }

        $app->instance(self::MEMO, $lost);

        return $lost;
    }
}

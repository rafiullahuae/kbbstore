<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The four addresses the `brands` module owns — Lane EH.
 *
 * WHY THIS EXISTS AS A LIST RATHER THAN AS A REGEX SOMEWHERE.
 *
 * Switching the module off makes BrandController abort 404 for every action,
 * which covers the pages. It does not cover the LINKS TO them, and the nav is
 * full of them: the primary menu's "Brands" item points at
 * /korean-skincare-brands/ on every page of the shop. A module switched off
 * that leaves a dead link in the header has not left no trace — it has left the
 * worst kind, one the shopper finds by clicking.
 *
 * So two places now have to agree about what a brand address is: the gate that
 * refuses to serve them and the nav filter that stops linking to them. Two
 * copies of that answer would drift, and the drift would be invisible until
 * somebody clicked. One list, named here, used by both.
 *
 * These mirror routes/kbb-brands-blog.php exactly:
 *
 *   /korean-skincare-brands/          the directory  (BrandController::index)
 *   /korean-skincare-brands/{slug}/   a brand page   (BrandController::show)
 *   /brands/                          301 → the directory
 *   /brand/{slug}/                    301 → that brand's page
 *
 * The list is pinned by behaviour rather than by inspection: every one of the
 * four is asserted to 404 with the module off in
 * tests/Feature/ModulePortsOnOffTest.php, so a fifth brand URL added without
 * being added here shows up as a live link to a dead page in that test's
 * company rather than in production.
 */
final class BrandUrls
{
    /** @var list<string> */
    public const PREFIXES = [
        '/korean-skincare-brands/',
        '/brands/',
        '/brand/',
    ];

    /**
     * Does this menu item's URL land on a page the brands module serves?
     *
     * Compared after the staging base path is stripped, because a menu item
     * saved on the staging install carries `/kbb-upgrade` in front of it and a
     * raw str_starts_with would miss every one of them — the shape of bug that
     * makes a feature work locally and not on the server.
     */
    public static function matches(?string $url): bool
    {
        $path = trim((string) $url);

        if ($path === '') {
            return false;
        }

        // Absolute or relative, and with or without the base path.
        $path = (string) parse_url($path, PHP_URL_PATH);

        if ($path === '') {
            return false;
        }

        // Url::base() and not a second reading of KBB_BASE_PATH: that method
        // already resolves the config setting, falls back to the request's own
        // base, and memoises the answer. Deriving it again here would be a
        // second opinion about the same thing, and on staging the two could
        // disagree — Url::base() falls back to the REQUEST when the setting is
        // absent, and a re-read of the env would not.
        $base = trim(Url::base(), '/');

        if ($base !== '' && str_starts_with(ltrim($path, '/'), $base.'/')) {
            $path = '/'.substr(ltrim($path, '/'), strlen($base) + 1);
        }

        // A trailing slash is U-01's convention and every one of these carries
        // it; normalising means /brands and /brands/ answer the same here, as
        // they do at the router.
        $path = '/'.trim($path, '/').'/';

        foreach (self::PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}

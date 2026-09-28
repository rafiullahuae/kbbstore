<?php

declare(strict_types=1);

use App\Support\UrlScheme;

/**
 * The admin console does not show the owner an address the shop redirects away
 * from.
 *
 * ── WHY THIS IS A SEPARATE FILE AND NOT PART OF UrlSchemeTest ───────────────
 *
 * Lane URL moved `/product-category/…` to `/collections/…`,
 * `/korean-skincare-brands/…` to `/brands/…` and `/skincare-guide/` to
 * `/blog/`, and corrected every screen that PRINTS an address. It could not
 * correct five strings in resources/views/admin/app.blade.php, because that
 * file is the integrator's and a lane that edits it collides with every other
 * lane in the round. So it reported them instead:
 *
 *   the Mega Menu URL box's placeholder      /product-category/cleansers/
 *   Brands -> display mode, help text        /korean-skincare-brands/
 *   Brands -> slug, help text                /korean-skincare-brands/{slug}/
 *   Categories -> intro copy                 /product-category/…/
 *   Categories -> slug, help text            /product-category/…/
 *
 * None of them is a link, so nothing 404s and no shopper ever sees one. What
 * they do is TEACH THE OWNER THE WRONG ADDRESS — the slug help says "one
 * segment of /product-category/…/" while the page it describes now lives at
 * /collections/…/, and the Mega Menu box offers a retired address as the
 * example of what to type, which is how a menu row gets authored pointing at a
 * 301 that costs a hop on every page for ever.
 *
 * ── WHAT THIS PINS ─────────────────────────────────────────────────────────
 *
 * Not "those five are fixed" — that passes for exactly as long as nobody types
 * a sixth. The bases are read off UrlScheme's own constants, so retiring a
 * further address in future automatically brings this check with it.
 */
/*
 * NOT adminConsoleSource() — that name is already taken, by
 * AdminOrderNoteStyleTest, and PHP has one function namespace across the whole
 * Pest run. Two files declaring it is a FATAL that aborts the entire suite
 * rather than failing one test: "Cannot redeclare function", exit 1, 7000
 * tests not run. Caught here by running the full suite rather than the file.
 */
function adminShellWithoutComments(): string
{
    $src = (string) file_get_contents(resource_path('views/admin/app.blade.php'));

    /*
     * COMMENTS STRIPPED FIRST, and this is not decoration.
     *
     * This repository has now been bitten repeatedly by a source scan that
     * finds the comment explaining a fix and counts it as the defect — or,
     * worse, as the fix. The sentence above this function names all five
     * retired addresses; without this, the scan would read its own reasoning
     * and fail. Blade comments, block comments and `//` line comments, in that
     * order.
     */
    $src = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $src);
    $src = (string) preg_replace('#/\*.*?\*/#s', '', $src);

    return (string) preg_replace('#^\s*//.*$#m', '', $src);
}

it('names no retired address anywhere in the admin console', function () {
    $retired = [
        UrlScheme::LEGACY_COLLECTION_BASE => UrlScheme::COLLECTION_BASE,
        UrlScheme::LEGACY_BRAND_INDEX => UrlScheme::BRAND_BASE,
        UrlScheme::LEGACY_BLOG_INDEX => UrlScheme::BLOG_BASE,
    ];

    $src = adminShellWithoutComments();

    expect(strlen($src))->toBeGreaterThan(
        100000,
        'The admin console source came back tiny, which means the comment strip ate the file and '
        .'this check would pass on anything.'
    );

    /*
     * The scan proves it can see, on the SAME source it is about to clear. A
     * check that only ever reports zero is indistinguishable from a check that
     * cannot read, and this file replaced five real hits — so the new address
     * it replaced them with has to be findable here.
     */
    expect(substr_count($src, UrlScheme::COLLECTION_BASE))->toBeGreaterThan(
        0,
        'The console names no /collections/ address at all, so the scan is not reading the strings '
        .'this test is about.'
    );

    $offenders = [];

    foreach ($retired as $old => $new) {
        $n = substr_count($src, $old);

        if ($n > 0) {
            $offenders[] = sprintf('%s appears %d time(s) — it is now %s', $old, $n, $new);
        }
    }

    /*
     * MUTATION NOTE. Put `/product-category/cleansers/` back as the Mega Menu
     * URL box's placeholder and this names it. RUN — it is how the five were
     * confirmed gone rather than assumed.
     *
     * LEGACY_BRAND_BASE ('/brand/') is deliberately NOT in the list above: it
     * is a substring of the ordinary word "brand" followed by a slash and would
     * match '/brands/' itself, so a scan for it reports the fix as the defect.
     * The two brand addresses that can actually be typed by mistake are the
     * index and the slug form, and both carry the long legacy prefix.
     */
    expect($offenders)->toBe(
        [],
        "The admin console shows the owner an address the shop redirects away from. These are help "
        ."text and placeholders rather than links, so nothing 404s — what they do is teach the wrong "
        ."address, which is how a menu row gets authored pointing at a 301:\n\n  "
        .implode("\n  ", $offenders)."\n"
    );
});

<?php

declare(strict_types=1);

use App\Services\UgcPath;

/**
 * The two sanitisers in App\Services\UgcPath, and the vectors they exist for.
 *
 * §7 of docs/UGC-VIDEO-PLAN.md: `source_url` and `creator_url` become an
 * `href`, `poster_path` becomes a `src`, and `file_path` becomes a
 * `<video src>`. Every one is typed in by an operator, and /api/* on this shop
 * is unauthenticated, so what is stored is what an anonymous visitor may be
 * served.
 *
 * ── THE DEFECT THE DECODE EXISTS FOR ────────────────────────────────────────
 *
 * A browser resolves `jav&#x09;ascript:alert(1)` to a javascript URL. A
 * `str_starts_with($url, 'javascript:')` sees a string beginning "jav&#x09;"
 * and waves it through, so the check and the browser are reading two different
 * strings — which is the entire attack. App\Support\RichText::url() already
 * carries that argument in its docblock; the cases below drive the same vectors
 * through this module's copy so the two cannot drift apart silently.
 */

/* ═══════════════════════════════════════════════════════════ link() ═════ */

it('lets ordinary http and https links through unchanged', function (string $url) {
    expect(UgcPath::link($url))->toBe($url);
})->with([
    'https://www.instagram.com/p/Cabc123/',
    'http://tiktok.com/@layla.skin/video/7123',
    'https://extrabeauty.ae/product/anua-toner?utm_source=ig',
]);

it('refuses a javascript URL however it is spelt', function (string $url) {
    /*
     * MUTATION NOTE, AND IT CAME BACK GREEN — which is worth recording rather
     * than quietly deleting. Delete the html_entity_decode / preg_replace pair
     * from UgcPath::link() and every case here still passes. The reason is the
     * rule below it: link() refuses a URL with NO scheme at all, because every
     * field it guards is "the original post", which is always somewhere else.
     * `jav&#x09;ascript:` does not match `^[a-z][a-z0-9+.-]*:` — the entity's
     * `&` and `#` are outside that class — so it falls through to that refusal
     * instead of to the scheme test.
     *
     * So the decode is NOT what refuses these; the relative-URL rule is. It is
     * kept as the second lock, because the day somebody relaxes that rule to
     * allow a link into this shop, the decode is the only thing between
     * `jav&#x09;ascript:` and an href. RichText::url() DOES allow relative
     * URLs, which is exactly why the decode is load-bearing there and belt and
     * braces here.
     */
    expect(UgcPath::link($url))->toBeNull();
})->with([
    'javascript:alert(1)',
    'JaVaScRiPt:alert(1)',
    '  javascript:alert(1)',
    "jav\tascript:alert(1)",
    "jav\nascript:alert(1)",
    'jav&#x09;ascript:alert(1)',
    'java&Tab;script:alert(1)',
    '&#106;avascript:alert(1)',
    "java\u{0000}script:alert(1)",
]);

it('refuses the other schemes that run or leak', function (string $url) {
    expect(UgcPath::link($url))->toBeNull();
})->with([
    'data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==',
    'vbscript:msgbox(1)',
    'file:///etc/passwd',
    'ftp://example.test/clip.mp4',
    // mailto IS allowed by RichText, which guards prose. It is refused here on
    // purpose: every field this guards is "the original post", which is always
    // a web page.
    'mailto:someone@example.test',
]);

it('refuses a protocol-relative URL, which carries no scheme and is not relative', function () {
    /*
     * `//evil.test/x` has no scheme for the scheme test to find, and a browser
     * reads it as "same scheme, that host". Refused BEFORE the scheme test,
     * which would otherwise never see it.
     *
     * MUTATION NOTE, ALSO GREEN. Delete the str_starts_with($probe, '//')
     * guard and this still passes, for the same reason as the decode above:
     * `//evil.test` has no scheme, so it is refused by the relative-URL rule
     * rather than by this guard. Kept for the same reason, and named here so
     * nobody reads the guard as load-bearing when it is not.
     */
    expect(UgcPath::link('//evil.test/steal'))->toBeNull()
        ->and(UgcPath::link('/\\evil.test/steal'))->toBeNull();
});

it('refuses a relative link, because the original post is never on this shop', function () {
    expect(UgcPath::link('/product/anua-toner'))->toBeNull()
        ->and(UgcPath::link('somewhere'))->toBeNull()
        ->and(UgcPath::link('#anchor'))->toBeNull();
});

it('returns the original string, not the stripped probe', function () {
    /*
     * The probe has its whitespace and control characters removed so the scheme
     * can be read the way a browser reads it. Storing the probe would change
     * the address — a query string with a space in it is not the same URL — so
     * the probe decides and the original is kept.
     *
     * MUTATION NOTE. Return $probe instead of $url and this is red: the stored
     * link loses its spacing and its case. RUN.
     */
    $url = 'https://example.test/a%20b?q=Hello+World&x=Y';

    expect(UgcPath::link($url))->toBe($url);
});

it('treats an empty or whitespace-only link as nothing at all', function () {
    expect(UgcPath::link(null))->toBeNull()
        ->and(UgcPath::link(''))->toBeNull()
        ->and(UgcPath::link("   \t\n"))->toBeNull();
});

/* ═════════════════════════════════════════════════════════ stored() ═════ */

it('accepts a path exactly as this shop writes one', function (string $path) {
    expect(UgcPath::stored($path))->toBe($path);
})->with([
    '/uploads/ugc/clip-20270110-120000-abcdEFGH12.mp4',
    '/uploads/ugc/teaser-20270110-120000-abcdEFGH12.webm',
    '/uploads/ugc/poster-20270110-120000-abcdEFGH12.jpg',
    '/uploads/ugc/poster-20270110-120000-abcdEFGH12.webp',
]);

it('refuses anything that is not one segment under /uploads/ugc/', function (string $path) {
    /*
     * An allowlist of SHAPES, not a denylist of tricks — ReviewWall::photos()
     * is the precedent and its comment names the trap: Url::to() passes any
     * scheme through untouched, so the check has to happen before a path
     * becomes a URL rather than inside the thing that builds one.
     *
     * `..` is unrepresentable in the accepted alphabet, so traversal is not
     * refused by a rule that has to anticipate its spellings — it simply
     * cannot be written.
     *
     * MUTATION NOTE. Replace the final preg_match with
     * `! str_contains($name, '/')` — a plausible-looking "one segment" check —
     * and the .php, the double-dot and the query-string cases all go green.
     * RUN: 5 turned green.
     */
    expect(UgcPath::stored($path))->toBeNull();
})->with([
    '/uploads/ugc/../../.env',
    '/uploads/ugc/../reviews/photo.jpg',
    '/uploads/ugc/sub/dir/clip.mp4',
    '/uploads/reviews/photo.jpg',
    'uploads/ugc/clip.mp4',                       // no leading slash
    '//evil.test/uploads/ugc/clip.mp4',
    'https://evil.test/uploads/ugc/clip.mp4',
    '/uploads/ugc/shell.php',
    '/uploads/ugc/clip.mp4.php',
    '/uploads/ugc/clip.mp4?x=1',
    '/uploads/ugc/clip.mp4#frag',
    '/uploads/ugc/.htaccess',
    '/uploads/ugc/',
    '/uploads/ugc/clip.svg',
]);

it('refuses a backslash, which some path readers treat as a separator', function () {
    /*
     * MUTATION NOTE, GREEN and expected to be. Delete the
     * str_contains($path, '\\') guard and this still passes: a backslash is
     * not in the accepted alphabet, so the regexp refuses it anyway. The guard
     * is kept because it says so at the top rather than as a consequence of a
     * character class three lines down — and if the alphabet is ever widened,
     * it is the line that still holds. RUN: green.
     */
    expect(UgcPath::stored('/uploads/ugc/..\\..\\.env'))->toBeNull()
        ->and(UgcPath::stored("/uploads/ugc/clip\0.mp4"))->toBeNull();
});

it('treats an empty stored path as nothing at all', function () {
    expect(UgcPath::stored(null))->toBeNull()
        ->and(UgcPath::stored(''))->toBeNull();
});

/* ═══════════════════════════════════════════════════════ library() ═════ */

it('accepts a path the Media Library really writes', function (string $given, string $expected) {
    expect(UgcPath::library($given))->toBe($expected);
})->with([
    ['/uploads/seo/20270110-120000-abcdefgh.jpg', '/uploads/seo/20270110-120000-abcdefgh.jpg'],
    ['/uploads/products/anua-toner.webp', '/uploads/products/anua-toner.webp'],
    // What window.kbbPickMedia actually hands back: Media::urlFor() prefixes
    // site_url, so the caller never sees a bare path.
    ['https://extrabeauty.ae/uploads/brands/cosrx.png', '/uploads/brands/cosrx.png'],
    // ...and a shop living under a base path. Anything before /uploads/ is
    // dropped rather than refused, because KBB_BASE_PATH is a real deployment.
    ['https://easywebsol.com/kbb-upgrade/uploads/seo/share.jpg', '/uploads/seo/share.jpg'],
]);

it('refuses anything that is not one file in one library folder', function (string $path) {
    /*
     * library() is WIDER than stored() by exactly one thing — the folder — and
     * that is the only difference it is allowed to have. It decides what may be
     * COPIED IN once, from a picker the operator just used; stored() decides
     * what a COLUMN may hold and what this code may delete. Widening stored()
     * to this would mean a path column that can name any file in the web root,
     * which is the shape ReviewWall::photos() exists to refuse.
     *
     * MUTATION NOTE, AND IT CAME BACK GREEN. Remove the rawurldecode() and
     * every case here still passes: `%` is not in the accepted alphabet for
     * either segment, so `%2e%2e%2f…` is refused by the SHAPE rule before the
     * encoding matters at all. Recorded rather than quietly dropped, because
     * the decode is still the right order — it is what would hold if the
     * alphabet were ever widened to admit a percent sign, and a check run
     * before a decode is a check run on a different string from the one the
     * filesystem sees. Second lock, and named as one. RUN: green.
     */
    expect(UgcPath::library($path))->toBeNull();
})->with([
    '/uploads/seo/../../.env',
    '/uploads/seo/%2e%2e%2f%2e%2e%2f.env',
    '/uploads/../.env',
    '/uploads/seo/sub/deeper/x.jpg',
    '/etc/passwd',
    '/uploads/seo/shell.php',                 // refused by stored()'s own alphabet later,
    '../.env',
    'https://evil.test/../../etc/passwd',
    '/uploads/seo/',
    '/uploads//x.jpg',
    "/uploads/seo/x\0.jpg",
    '/uploads/seo/..\\..\\.env',
    'javascript:alert(1)',
    '',
]);

it('does not let a library path become a stored path', function () {
    /*
     * The two are deliberately different sizes, and this is the assertion that
     * keeps them that way: a file in /uploads/seo/ may be adopted, and it is
     * never what a column holds. UgcMedia::adopt() copies it into
     * /uploads/ugc/ under a name this code generated, and THAT is what is
     * stored.
     */
    $libraryPath = '/uploads/seo/20270110-120000-abcdefgh.jpg';

    expect(UgcPath::library($libraryPath))->toBe($libraryPath)
        ->and(UgcPath::stored($libraryPath))->toBeNull();
});

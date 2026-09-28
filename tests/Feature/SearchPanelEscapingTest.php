<?php

declare(strict_types=1);

/**
 * =============================================================================
 * THE SEARCH PANEL BUILT HTML OUT OF /api/search AND SET innerHTML
 * =============================================================================
 *
 * ── THE DEFECT ──────────────────────────────────────────────────────────────
 *
 * `resources/js/kbb/search.js` drew every suggestion row with a template
 * string. Four values arrived from the API and went in raw:
 *
 *     `<a data-sg-row href="${it.url}">`
 *     `<span class="si" style="${… url('${it.image}') …}">`
 *     `<div class="sgh">${g.label}</div>`
 *     `<a class="viewall" href="${data.all_url}">`
 *
 * A double quote in ANY of them closes the attribute it sits in, and the next
 * token is read as a new attribute — an event handler, on a panel every shopper
 * opens. **That is script injection, not the CSS-context kind the Blade
 * surfaces had**, and it is strictly the larger of the two: `e()` at least
 * escaped the double quote everywhere in Blade, and nothing escaped anything
 * here.
 *
 * `productRow()` had the smaller version of the same fault — `escapeHtml()`
 * around an address going into `url('…')`, which turns `'` into `&#39;` that
 * the HTML parser decodes back to a live quote BEFORE CSS reads the attribute.
 *
 * Found by Lane SX while fixing the Blade surfaces, in a file outside its own
 * ownership, and reported rather than touched. Nothing here was attacker-
 * controlled while the owner typed his own product names and image paths; the
 * WordPress import is about to write thousands of them from a database this
 * shop did not author, which is why it was fixed rather than filed.
 *
 * ── THE FIX, AND WHAT IT MEASURES ───────────────────────────────────────────
 *
 * `schemeIsServed()`, `safeHref()` and `cssUrl()` in that file are the
 * JavaScript twins of `App\Support\CssUrl`, answering the same questions in the
 * same order. Exercised in node against real attack strings:
 *
 *   input                              safeHref()                 cssUrl()
 *   /uploads/ugc/a.png                 unchanged                  unchanged
 *   /a.png" onerror="alert(1)          &quot; escaped             \&quot; escaped
 *   /a.png');background:url(//evil…    &#39; escaped              \&#39; \( \)
 *   javascript:alert(1)                #                          '' (gradient)
 *   jav&#x09;ascript:alert(1)          #                          '' (gradient)
 *   //evil.test/x.png                  #                          '' (gradient)
 *   /a.png\nbackground:red             harmless in an href        \a hex escape
 *
 * The first row is the display-parity argument: an address that was already
 * safe comes through byte-identical, so no picture that draws today stops
 * drawing.
 *
 * A refused href is `#` and NOT `''`, because an empty href is the current
 * page — a refused link would silently reload the shop instead of doing
 * nothing. A refused image is `''` so the caller falls to its gradient, never
 * `url('')`, which makes the browser fetch the page and try to decode it as a
 * picture.
 */
function searchPanelSource(): string
{
    return (string) file_get_contents(resource_path('js/kbb/search.js'));
}

/**
 * The file with every comment blanked.
 *
 * COMMENTS ARE STRIPPED FIRST, and this repo has now paid for that three times
 * in one week: a scan of raw source finds the sentence explaining the fix and
 * counts it as the fix. Block comments go first so a `//` inside one cannot
 * start a line comment, and the line-comment pattern requires the `//` to be
 * preceded by a line start or whitespace so it cannot eat `https://`.
 */
function searchPanelCode(): string
{
    $src = (string) preg_replace('~/\*.*?\*/~s', '', searchPanelSource());

    return (string) preg_replace('~(^|\s)//[^\n]*~m', '$1', $src);
}

/**
 * The escapers themselves, which now live in `resources/js/kbb/safe.js`.
 *
 * THEY MOVED, THEY DID NOT CHANGE. The sweep that grew out of this fix (Lane
 * JS, tests/Feature/StorefrontJsEscapingTest.php) found the same three contexts
 * in three more files, and four copies of a security helper is four things to
 * keep in step with App\Support\CssUrl rather than one. The definitions were
 * lifted verbatim; every call site in search.js is the call it was, which is
 * what the first case below still checks against search.js itself.
 *
 * Comments stripped for the same reason searchPanelCode() strips them.
 */
function searchEscaperCode(): string
{
    $src = (string) preg_replace('~/\*.*?\*/~s', '', (string) file_get_contents(resource_path('js/kbb/safe.js')));

    return (string) preg_replace('~(^|\s)//[^\n]*~m', '$1', $src);
}

it('escapes every value it interpolates into the suggestion markup', function () {
    /*
     * MUTATION NOTE, RUN: change `href="${safeHref(it.url)}"` back to
     * `href="${it.url}"` and this names it. Same for the image, the group
     * label, the view-all href and the price.
     */
    $code = searchPanelCode();

    // Every `${…}` that lands in markup must go through one of the three
    // escapers, or be a call this file made itself.
    $raw = [];

    if (preg_match_all('/\$\{\s*((?:it|p|g|data)\.[A-Za-z_]+)\s*\}/', $code, $m, PREG_SET_ORDER) > 0) {
        foreach ($m as $hit) {
            $raw[] = $hit[1];
        }
    }

    expect($raw)->toBe([], 'these API values reach innerHTML unescaped: ' . implode(', ', $raw));

    // And the escapers it calls are the shared ones, imported rather than
    // redefined — the panel cannot escape with a helper it does not have.
    expect(preg_match("/import \{[^}]*\bescapeHtml\b[^}]*\bsafeHref\b[^}]*\bcssUrl\b[^}]*\} from '\.\/safe\.js'/", $code))
        ->toBe(1, 'search.js no longer imports escapeHtml/safeHref/cssUrl from ./safe.js');
});

it('carries the three escapers, and they refuse a scheme this shop does not serve', function () {
    /*
     * MUTATION NOTE, RUN: delete schemeIsServed()'s `//` clause and a
     * protocol-relative address is drawn again; delete the entity decode and
     * `jav&#x09;ascript:` passes. Both were confirmed in node before this was
     * written.
     */
    $code = searchEscaperCode();

    foreach (['function schemeIsServed(', 'function safeHref(', 'function cssUrl('] as $fn) {
        expect(str_contains($code, $fn))->toBeTrue("{$fn} is gone from resources/js/kbb/safe.js");
    }

    // The order the browser resolves in: entities, then controls, then scheme.
    expect(preg_match('/schemeIsServed[\s\S]{0,700}?&#x[\s\S]{0,400}?startsWith\(\'\/\/\'\)/', $code))
        ->toBe(1, 'schemeIsServed no longer decodes entities before it refuses a protocol-relative address');

    // A refused href is '#', never '' — an empty href is the current page.
    expect(preg_match("/safeHref[\s\S]{0,200}?:\s*'#'/", $code))
        ->toBe(1, 'a refused href no longer falls to #, so a refused link reloads the shop');
});

it('escapes the CSS delimiters in one pass, not several', function () {
    /*
     * THE BUG THE SECOND PASS WOULD BE. Two replace() calls rescan what the
     * first one wrote, so a backslash pass followed by a quote pass turns
     * \' into \\' — an ESCAPED BACKSLASH followed by a LIVE QUOTE, which is
     * the injection rather than the fix. App\Support\CssUrl carries the same
     * reasoning for the same reason; these two must not drift.
     *
     * MUTATION NOTE, RUN: split the character class into two replace() calls
     * and this is red.
     */
    $code = searchEscaperCode();

    expect(preg_match('/replace\(\/\[\\\\\\\\\\\\\\\\\\x27"\(\)\]\/g/', $code) === 1
        || str_contains($code, "replace(/[\\\\'\"()]/g"))
        ->toBeTrue('cssUrl no longer escapes all five CSS delimiters in one pass');

    // And the control characters, because a raw newline ends a CSS string.
    expect(str_contains($code, 'u0000-\u001F'))
        ->toBeTrue('cssUrl no longer hex-escapes the control characters');
});

it('keeps the built asset in step with the source it was built from', function () {
    /*
     * A security fix that is only in `resources/` is a security fix the shop
     * does not have: the storefront loads the compiled bundle. This does not
     * re-run the build — it checks that the shipped bundle carries the fix, so
     * a package built from a stale `public/build` fails here rather than on the
     * shop.
     *
     * MUTATION NOTE, RUN: revert public/build to the pre-fix bundle and this is
     * red.
     */
    $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);

    expect($manifest)->toBeArray();

    $entry = null;

    foreach ($manifest as $key => $row) {
        if (str_contains((string) $key, 'kbb/app.js') || str_contains((string) ($row['src'] ?? ''), 'kbb/app.js')) {
            $entry = $row['file'] ?? null;
        }
    }

    expect($entry)->not->toBeNull('the manifest no longer names the storefront bundle');

    $bundle = (string) file_get_contents(public_path('build/' . $entry));

    expect(strlen($bundle))->toBeGreaterThan(1000, 'the built bundle is empty');

    /*
     * FINGERPRINTED ON WHAT SURVIVES MINIFICATION, WHICH IS NOT THE NAMES.
     *
     * The first cut of this looked for `schemeIsServed`, `safeHref` and
     * `cssUrl` in the bundle and went red against a bundle that DID carry the
     * fix — esbuild renames every local function, so a name is exactly the
     * thing that does not survive. Verified by grepping the built asset: zero
     * hits for all three, one hit each for the literals below.
     *
     * String and regex literals do survive, so the fingerprint is three of
     * them, each load-bearing and each absent before the fix:
     *
     *   &#x(              the entity decode, without which jav&#x09;ascript: passes
     *   startsWith("//")  the protocol-relative refusal
     *   toString(16)      the control-character hex escape
     */
    foreach (['&#x(', 'startsWith("//")', 'toString(16)'] as $mark) {
        expect(str_contains($bundle, $mark))
            ->toBeTrue("the shipped bundle does not carry `{$mark}` — it was built before the fix, "
                . 'so the shop does not have it however green the source tests are');
    }
});

<?php

declare(strict_types=1);

/**
 * =============================================================================
 * THE STOREFRONT'S JAVASCRIPT, SWEPT — THREE MORE HOLES AND ONE SHARED ESCAPER
 * =============================================================================
 *
 * `SearchPanelEscapingTest` records the first finding: the search panel put
 * four `/api/search` values into `innerHTML` with nothing around them. Nothing
 * about that was special to search.js, and it was found by accident by a lane
 * sent somewhere else. This is the sweep of the other seventeen files.
 *
 * ── WHAT THE SWEEP FOUND, AND WHAT IT LEFT ALONE ────────────────────────────
 *
 * Four holes, in four files, each one a value from outside this page's own
 * source reaching the DOM in a context its escaping did not cover:
 *
 *  1. mobile-nav.js — EVERY href in the mobile drawer was escaped and NONE was
 *     scheme-checked, and `link()` passes an absolute URL through by name. So
 *     `javascript:alert(1)` in a menu row's url — which contains no character
 *     an HTML escaper touches — arrived in the attribute intact and ran on
 *     tap. Four sites: the row link, the sub-panel link, the group link and
 *     "Shop all". The tree comes from `window.KBB.nav`, which the layout
 *     serialises out of `menu_items`.
 *
 *  2. cart.js — `data.error` was interpolated RAW into
 *     `<div class="cart-note err">…</div>` and set as innerHTML. It is the one
 *     value in that file that is not a server-rendered Blade fragment.
 *
 *  3. reviews.js — a review photo's address went into `<img src="…">` escaped
 *     and not scheme-checked. `reviews` is the one table on this shop whose
 *     rows are already written by the public.
 *
 *  4. pdp.js — the variant price came out of `data-price`, which is
 *     `Money::plain()` carrying the `currency_symbol` SETTING, and went into
 *     `innerHTML`. THE BLADE ESCAPE DOES NOT REACH THIS: `{{ }}` escapes for
 *     the attribute and the HTML parser decodes it again while building the
 *     attribute, so `dataset.price` hands the live characters back. It is the
 *     only `dataset.*` value anywhere under resources/js that reaches an HTML
 *     sink, and it is rule 5 — printed unescaped, and a setting rather than a
 *     constant.
 *
 * Everything else was read and recorded as safe with a reason, in a comment at
 * the site: the cart drawer/page fragments, every `*Html` value on the checkout
 * endpoints and `Money::format()`'s output (server-rendered Blade — escaping
 * them would print the tags), quick-view's `data.html` (the same), the slider
 * dots (a loop counter), `pdp.js`'s price block (a `data-pricehtml` attribute
 * this page's own Blade printed) and `reviews.js`'s photo previews, whose
 * address comes from `URL.createObjectURL()` and MUST NOT go through cssUrl()
 * — its allowlist is http/https and a blob: URL would be refused, blanking
 * every preview.
 *
 * No `eval`, no `new Function`, no `document.write`, no `setTimeout('string')`
 * anywhere under resources/js. Two `location.href` assignments, both built
 * from `window.KBB.routes` constants with `encodeURIComponent()` on the query.
 *
 * ── THE HELPERS, MEASURED IN NODE RATHER THAN ASSERTED ──────────────────────
 *
 * They live in ONE place now, `resources/js/kbb/safe.js`; search.js's four
 * definitions moved there unchanged and every call site is the call it was.
 * Run against real attack strings and against plain addresses:
 *
 *  input                            escapeHtml       safeHref  safeSrc  cssUrl
 *  /uploads/ugc/a.png               unchanged        unchanged unchanged unchanged
 *  /shop/?orderby=date              unchanged        unchanged unchanged unchanged
 *  https://cdn.test/a.png           unchanged        unchanged unchanged unchanged
 *  mailto:hi@shop.ae                unchanged        #  (LINK: unchanged)  ''  ''
 *  tel:+97141234567                 unchanged        #  (LINK: unchanged)  ''  ''
 *  /a.png" onerror="alert(1)        &quot; escaped   &quot;    &quot;    \&quot;
 *  /a.png');background:url(//evil…  &#39; escaped    &#39;     &#39;     \&#39; \( \)
 *  javascript:alert(1)              UNCHANGED ←      #         ''        ''
 *  jav&#x09;ascript:alert(1)        &amp; only  ←    #         ''        ''
 *  //evil.test/x.png                UNCHANGED ←      #         ''        ''
 *  /a.png\nbackground:red           unchanged        harmless  harmless  \a hex
 *
 * The three rows marked ← are the whole argument for the scheme gate: an HTML
 * escaper leaves a javascript: URL completely intact, because there is nothing
 * in it for an HTML escaper to escape.
 *
 * The first three rows are the display-parity argument: an address that was
 * already safe comes back byte-identical, so no link and no picture that works
 * today stops working. Measured the same way for the cart: all fifteen error
 * strings CartController, CartAddressController and CouponService can send go
 * through `escapeHtml()` unchanged, so that fix moves nothing on the page it
 * renders today either.
 *
 * A refused href is `#` and NOT `''` (an empty href is the current page, so a
 * refused link would reload the shop). A refused `src` is `''` AND THE CALLER
 * DRAWS NOTHING — `src="#"` and `src=""` both resolve against the document and
 * make the browser fetch this page and try to decode it as an image, so `#` is
 * the right refusal for a link and the wrong one for a picture.
 */

/** Every storefront JavaScript source, with comments blanked. */
function jsSource(string $file): string
{
    return (string) file_get_contents(resource_path('js/kbb/'.$file));
}

/**
 * The file with every comment blanked.
 *
 * COMMENTS ARE STRIPPED FIRST. This repo has paid for skipping it four times
 * in one week: a scan of raw source finds the sentence explaining the fix and
 * counts it as the fix — and the comments this lane wrote at each site name
 * `javascript:alert(1)`, `safeSrc` and `escapeHtml` in prose. Block comments
 * go first so a `//` inside one cannot start a line comment, and the
 * line-comment pattern requires the `//` to be preceded by a line start or
 * whitespace so it cannot eat `https://`.
 */
function jsCode(string $file): string
{
    $src = (string) preg_replace('~/\*.*?\*/~s', '', jsSource($file));

    return (string) preg_replace('~(^|\s)//[^\n]*~m', '$1', $src);
}

/** The storefront bundle the shop actually loads. */
function builtBundle(): string
{
    $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);

    expect($manifest)->toBeArray();

    $entry = null;

    foreach ($manifest as $key => $row) {
        if (str_contains((string) $key, 'kbb/app.js') || str_contains((string) ($row['src'] ?? ''), 'kbb/app.js')) {
            $entry = $row['file'] ?? null;
        }
    }

    expect($entry)->not->toBeNull('the manifest no longer names the storefront bundle');

    $bundle = (string) file_get_contents(public_path('build/'.$entry));

    expect(strlen($bundle))->toBeGreaterThan(1000, 'the built bundle is empty');

    return $bundle;
}

it('keeps the four escapers in exactly one place', function () {
    /*
     * THE FINISHED STATE, NOT THE ABSENCE OF ONE. Zero definitions is the
     * "somebody deleted the module" failure and two is the "somebody pasted it
     * back into their own file" failure — which is the shape this sweep found
     * four times over (search.js, reviews.js, mobile-nav.js and i18n.js each
     * had an HTML escaper of its own). Exactly one is the answer that is green
     * today and stays green when another lane adds a file that imports it.
     *
     * MUTATION NOTE, RUN: paste `function schemeIsServed(` back into search.js
     * and the count goes to 2 and this is red; delete safe.js and it goes to 0
     * and this is red.
     */
    $definitions = ['function escapeHtml(', 'function schemeIsServed(', 'function safeHref(', 'function safeSrc(', 'function cssUrl('];

    $counts = array_fill_keys($definitions, 0);

    foreach (glob(resource_path('js/kbb/*.js')) ?: [] as $path) {
        $code = jsCode(basename($path));

        foreach ($definitions as $needle) {
            $counts[$needle] += substr_count($code, $needle);
        }
    }

    foreach ($definitions as $needle) {
        expect($counts[$needle])->toBe(1, "`{$needle}` is defined {$counts[$needle]} times under resources/js/kbb — it belongs in safe.js and nowhere else");
    }

    // And it is the shared module that carries them.
    $safe = jsCode('safe.js');

    foreach ($definitions as $needle) {
        expect(str_contains($safe, $needle))->toBeTrue("safe.js no longer defines {$needle}");
    }
});

it('scheme-checks every href in the mobile navigation drawer', function () {
    /*
     * THE DEFECT ON THE SHOP: a menu row whose url was `javascript:alert(1)`
     * rendered as `<a href="javascript:alert(1)">` in the phone drawer and ran
     * on tap. The row was ESCAPED — `escape()` was around every one of the
     * four hrefs — and an HTML escaper touches no character in that string.
     *
     * MUTATION NOTE, RUN: change any `navHref(x.url)` back to
     * `escape(link(x.url))` and this names the site.
     */
    $code = jsCode('mobile-nav.js');

    expect(preg_match_all('/href="\$\{\s*escape\(\s*link\(/', $code))
        ->toBe(0, 'a mobile-drawer href is escaped but not scheme-checked again');

    // All four hrefs, and every one of them through the gate.
    expect(substr_count($code, 'href="${navHref('))
        ->toBe(4, 'the mobile drawer no longer builds all four of its hrefs through navHref()');

    // mailto: and tel: stay allowed, because the desktop nav's Url::to()
    // passes them through by name and the two navs render the same rows.
    expect(preg_match('/navHref\s*=\s*\(url\)\s*=>\s*safeHref\(link\(url\),\s*LINK_SCHEMES\)/', $code))
        ->toBe(1, 'the drawer no longer uses the operator-href allowlist, so a mailto: menu row is refused in the drawer while it still works in the desktop nav');
});

it('escapes the cart error before it becomes markup', function () {
    /*
     * THE DEFECT ON THE SHOP: `notices.innerHTML = `<div class="cart-note
     * err">${data.error}</div>``. Every error string the cart sends today is a
     * constant, which is why nothing had gone wrong — and nothing said it had
     * to stay one.
     *
     * MUTATION NOTE, RUN: drop the escapeHtml() and this is red.
     */
    $code = jsCode('cart.js');

    expect(preg_match('/cart-note err">\$\{\s*escapeHtml\(\s*data\.error\s*\)\s*\}/', $code))
        ->toBe(1, 'the cart notice no longer escapes data.error before setting innerHTML');

    // The server-rendered fragments are deliberately NOT escaped — escaping
    // them would print the tags instead of drawing the cart. Pinned so a later
    // reader does not "fix" them.
    foreach (['frag.outerHTML = data.drawer', 'inner.innerHTML = data.page'] as $fragment) {
        expect(str_contains($code, $fragment))
            ->toBeTrue("`{$fragment}` changed — those are whole Blade views and must stay raw");
    }
});

it('scheme-checks a review photo and draws nothing for a refused one', function () {
    /*
     * THE DEFECT ON THE SHOP: `<img src="${escapeHtml(u)}">` for every address
     * in a review's `imgs`. Escaped, never gated.
     *
     * A refused address draws NO <img>, which is what `.filter(Boolean)` is
     * for: `src="#"` and `src=""` both resolve against the document and make
     * the browser fetch this page and try to decode it as a picture.
     *
     * MUTATION NOTE, RUN: change `safeSrc(u)` back to `escapeHtml(u)` and drop
     * the filter, and this names both halves.
     */
    $code = jsCode('reviews.js');

    expect(preg_match('/\(r\.imgs \|\| \[\]\)\.map\(\(u\) => safeSrc\(u\)\)\.filter\(Boolean\)/', $code))
        ->toBe(1, 'a review photo address no longer goes through safeSrc(), or a refused one is no longer dropped');

    expect(preg_match('/<img src="\$\{\s*src\s*\}"/', $code))
        ->toBe(1, 'the review photo <img> no longer takes the already-gated value');

    // The photo PREVIEW on the submit sheet is a blob: URL and must stay out
    // of cssUrl(), whose allowlist would refuse it and blank the previews.
    expect(str_contains($code, 'url(\'${url}\') center/cover'))
        ->toBeTrue('the createObjectURL preview changed — a blob: address is browser-generated and cssUrl() would refuse it');
});

it('escapes the variant price a data attribute hands back before it becomes markup', function () {
    /*
     * THE DEFECT ON THE SHOP, AND THE SUBTLEST OF THE FOUR. `setPrice()` sets
     * innerHTML, and its one dynamic caller read `variant.dataset.price` — which
     * is `Money::plain()`, TEXT rather than markup, carrying the
     * `currency_symbol` SETTING, stored by the admin as free text.
     *
     * The Blade escape does NOT protect it. `data-price="{{ … }}"` escapes for
     * the attribute, and the HTML parser DECODES those entities while it builds
     * the attribute — so by the time `dataset.price` is read the live
     * characters are back, exactly the round trip `App\Support\CssUrl`'s header
     * records for `&#39;` inside a style attribute. Measured in node: a symbol
     * containing `<img src=x onerror=…>` comes out of the attribute identical
     * to what went in.
     *
     * This is rule 5 in CLAUDE.md — anything printed unescaped is a constant,
     * never a setting — and it is the only `dataset.*` value anywhere under
     * resources/js that reaches an HTML sink. Everything else is a number, a
     * key comparison, or textContent.
     *
     * The `pricehtml` branch stays raw deliberately: it means "the server
     * composed this as markup" and nothing emits it today.
     *
     * MUTATION NOTE, RUN: change the line back to
     * `variant.dataset.pricehtml || variant.dataset.price || ''` and this is
     * red on both assertions.
     */
    $code = jsCode('pdp.js');

    expect(preg_match("/dataset\.pricehtml \|\| escapeHtml\(variant\.dataset\.price \|\| ''\)/", $code))
        ->toBe(1, 'the variant price from a data attribute is no longer escaped before setPrice() writes it to innerHTML');

    // No OTHER data attribute may take the short route into markup.
    expect(preg_match_all('/innerHTML\s*=\s*[^;]*dataset\./', $code))
        ->toBe(0, 'a data attribute now reaches innerHTML in pdp.js without an escaper in front of it');
});

it('has no eval, no new Function and no document.write anywhere in the storefront', function () {
    /*
     * MUTATION NOTE, RUN: add `eval(data.x)` to any file under resources/js
     * and this names the file and the construct.
     */
    $found = [];

    foreach (glob(resource_path('js/kbb/*.js')) ?: [] as $path) {
        $code = jsCode(basename($path));

        foreach (['eval(', 'new Function(', 'document.write(', 'document.writeln(', 'innerText ='] as $construct) {
            if (str_contains($code, $construct)) {
                $found[] = basename($path).': '.$construct;
            }
        }
    }

    expect($found)->toBe([], 'a script-from-string construct reached the storefront: '.implode(', ', $found));
});

it('ships a bundle that carries the sweep, not only the source', function () {
    /*
     * A security fix that is only in `resources/` is a security fix the shop
     * does not have: the storefront loads the compiled bundle.
     *
     * FINGERPRINTED ON WHAT SURVIVES MINIFICATION, WHICH IS NOT THE NAMES.
     * SearchPanelEscapingTest records the first cut of this going red against
     * a bundle that DID carry the fix, because esbuild renames every local
     * function. Verified by grepping the built asset: `navHref`, `safeSrc` and
     * `escapeHtml` appear ZERO times in it; the marks below appear once each.
     *
     * MUTATION NOTE, RUN: `git stash` the rebuilt bundle (or point the
     * manifest back at app-WFomWa8b.js, the pre-sweep build) and all four
     * marks are missing.
     */
    $bundle = builtBundle();

    // The operator-href allowlist. It exists in the bundle ONLY because
    // mobile-nav.js asks for it, so it is the nav fix's fingerprint.
    expect(str_contains($bundle, '"mailto","tel"'))
        ->toBeTrue('the shipped bundle has no mailto/tel allowlist — it was built before the navigation fix');

    // The default allowlist the other three helpers gate on.
    expect(preg_match('/=\["http","https"\]/', $bundle))
        ->toBe(1, 'the shipped bundle has no http/https scheme allowlist');

    // The cart notice interpolates a CALL, not a bare member expression.
    // Before the fix this read `cart-note err">${X.error}` — no parenthesis.
    expect(preg_match('/cart-note err">\$\{[A-Za-z_$]+\(/', $bundle))
        ->toBe(1, 'the shipped bundle still interpolates the cart error raw');

    // The variant price goes through a CALL on the way out of its data
    // attribute. Before the fix this read `dataset.pricehtml||X.dataset.price`
    // — the property name survives minification, the escaper's name does not.
    expect(preg_match('/dataset\.pricehtml\|\|[A-Za-z_$]+\(/', $bundle))
        ->toBe(1, 'the shipped bundle still writes the variant price into innerHTML unescaped');

    // A refused review photo is dropped rather than drawn.
    expect(preg_match('/\.filter\(Boolean\)\.map\([\s\S]{0,40}<img src="/', $bundle))
        ->toBe(1, 'the shipped bundle still draws every review photo address without gating it');

    // And the three marks the search fix is pinned on are still there, so a
    // rebuild cannot quietly drop the fix this sweep grew out of.
    foreach (['&#x(', 'startsWith("//")', 'toString(16)'] as $mark) {
        expect(str_contains($bundle, $mark))
            ->toBeTrue("the shipped bundle no longer carries `{$mark}` from the original search-panel fix");
    }
});

it('refuses a hostile address and returns a plain one byte-identical, run in node', function () {
    /*
     * THE ONLY CASE HERE THAT EXECUTES THE HELPERS. The others read source and
     * read the bundle; this one imports safe.js and puts real attack strings
     * through it, so the table in this file's header is measured rather than
     * reasoned about.
     *
     * Skipped where node is absent — CLAUDE.md records that CI does not build
     * assets and the live host has no node — so this strengthens a developer
     * run without being able to fail an integrator's.
     *
     * MUTATION NOTE, RUN: change safe.js's scheme match to
     * `m ? true : true` and this is red on javascript:, on the entity-escaped
     * form and on the protocol-relative one at once.
     */
    if (trim((string) shell_exec('command -v node 2>/dev/null')) === '') {
        test()->markTestSkipped('node is not on this machine');
    }

    $module = resource_path('js/kbb/safe.js');

    // A module path cannot be a variable in a static import, so the probe is
    // written to a temporary file with the path substituted in.
    $probe = str_replace('__MODULE__', $module, <<<'JS'
    import { safeHref, safeSrc, cssUrl, escapeHtml, LINK_SCHEMES } from '__MODULE__';

    const out = {
        plain_href:   safeHref('/uploads/ugc/a.png'),
        plain_src:    safeSrc('/storage/reviews/9/x.jpg'),
        plain_css:    cssUrl('https://cdn.test/a.png'),
        plain_query:  safeHref('/shop/?orderby=date'),
        mailto_link:  safeHref('mailto:hi@shop.ae', LINK_SCHEMES),
        mailto_plain: safeHref('mailto:hi@shop.ae'),
        js_escape:    escapeHtml('javascript:alert(1)'),
        js_href:      safeHref('javascript:alert(1)'),
        js_href_link: safeHref('javascript:alert(1)', LINK_SCHEMES),
        js_src:       safeSrc('javascript:alert(1)'),
        js_css:       cssUrl('javascript:alert(1)'),
        entity_href:  safeHref('jav&#x09;ascript:alert(1)'),
        rel_href:     safeHref('//evil.test/x.png'),
        rel_src:      safeSrc('//evil.test/x.png'),
        attr_break:   safeHref('/a.png" onerror="alert(1)'),
        css_break:    cssUrl("/a.png');background:url(//evil.test/x)"),
        newline_css:  cssUrl('/a.png\nbackground:red'),
    };

    process.stdout.write(JSON.stringify(out));
    JS);

    $file = tempnam(sys_get_temp_dir(), 'kbbjs').'.mjs';
    file_put_contents($file, $probe);

    $raw = (string) shell_exec('node '.escapeshellarg($file).' 2>&1');
    @unlink($file);

    $got = json_decode($raw, true);

    expect($got)->toBeArray('node could not run the probe: '.$raw);

    // NOTHING THAT ALREADY WORKS MAY CHANGE — a plain address is returned
    // whole, so every link and picture on the shop today renders identically.
    expect($got['plain_href'])->toBe('/uploads/ugc/a.png');
    expect($got['plain_src'])->toBe('/storage/reviews/9/x.jpg');
    expect($got['plain_css'])->toBe('https://cdn.test/a.png');
    expect($got['plain_query'])->toBe('/shop/?orderby=date');
    expect($got['mailto_link'])->toBe('mailto:hi@shop.ae');

    // The whole argument for the gate: the HTML escaper leaves this untouched.
    expect($got['js_escape'])->toBe('javascript:alert(1)');

    // And the gate refuses it in all four contexts, however it is dressed up,
    // and on the wider operator allowlist too.
    expect($got['js_href'])->toBe('#');
    expect($got['js_href_link'])->toBe('#');
    expect($got['mailto_plain'])->toBe('#');
    expect($got['js_src'])->toBe('');
    expect($got['js_css'])->toBe('');
    expect($got['entity_href'])->toBe('#');
    expect($got['rel_href'])->toBe('#');
    expect($got['rel_src'])->toBe('');

    // Escaped rather than refused: the address still draws, neutralised.
    expect($got['attr_break'])->toBe('/a.png&quot; onerror=&quot;alert(1)');
    expect($got['css_break'])->toBe('/a.png\\&#39;\\);background:url\\(//evil.test/x\\)');
    expect($got['newline_css'])->toBe('/a.png\\a background:red');
});

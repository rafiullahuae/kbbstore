<?php

declare(strict_types=1);

use App\Models\AdminUser;

/**
 * The sidebar is complete when it is first drawn, not when the document ends.
 *
 * ── WHAT THE OWNER SAW ────────────────────────────────────────────────────
 *
 * A screenshot of the console part-way through a refresh: the sidebar showing
 * Appearance with ten rows -- Homepage, Product styles, Mobile Header, Section
 * dividers, Login / Register panel, Header, Mobile menu, Product page, Quantity
 * bundles, Product grid -- and not the eleven others that belong in it, most
 * visibly `Appearance -> Set`. Other parent menus were short of children in the
 * same way. His words: "under some parent menus some sub menues don't show".
 *
 * ── WHY, MEASURED RATHER THAN REASONED ────────────────────────────────────
 *
 * Twenty-one of this console's seventy-seven sidebar rows are not in NAV. Each
 * is contributed by a screen partial that calls window.kbbAddNavEntry when its
 * own script runs, and every one of those scripts is near the END of the
 * document, because a partial cannot call a function the console has not
 * defined yet. On the applied console, buildNav() ran at byte 731,657 of
 * 3,425,404 -- 21.4% -- and the last partial registered its row at byte
 * 3,388,358, which is 98.9%. A row therefore did not exist until 98.9% of a
 * 3.4 MB document had been downloaded and parsed.
 *
 * Reproduced in Chromium on a cold, cache-disabled load throttled to 2 Mbit/s
 * with the CPU at one quarter, against the store's real 2,419 products:
 *
 *   before   Appearance held 10 rows from 4,290ms; the sidebar completed
 *            (77 rows) at 15,994ms.
 *   after    the sidebar was complete at 3,546ms, with no partial state at all.
 *
 * ── WHAT THIS FILE PINS ───────────────────────────────────────────────────
 *
 * Not "LATE_NAV exists". Three relationships, each of which is the defect
 * coming back:
 *
 *   1. Every row a partial registers is also declared in LATE_NAV, and every
 *      LATE_NAV row is registered by a partial. Both directions: a new screen
 *      whose row is only in its partial is the original bug for that row, and
 *      a LATE_NAV row no partial claims is a row with no renderer.
 *   2. No sidebar row's earliest registration sits in the tail of the rendered
 *      document. This is the measurement above, written as an assertion: it is
 *      red on the code this lane replaced, for all twenty-one rows.
 *   3. LATE_NAV's ORDER reproduces the sidebar the console settles on today.
 *      kbbAddNavEntry inserts after the first anchor already present, so the
 *      order rows arrive in decides where they land; sorting the array would
 *      silently move rows.
 *
 * ── ▲ LANE AP: THE SIDEBAR IS NOW IN THE FIRST BYTES, NOT AT buildNav() ──
 *
 * The owner again: "upon hard refresh some menu items from the left panel
 * keeps missing ... must load everything instantly with all menu items."
 * buildNav() itself ran at 1.36 s on a throttled cold load (4x CPU, 2 Mbit/s),
 * with nothing in the sidebar before it, and #KBeautyBliss Spotted -- in
 * neither NAV nor LATE_NAV, because this file's reader could not see a partial
 * whose label is a variable -- arrived with its partial at 5.96 s.
 *
 * NAV and LATE_NAV are gone. App\Support\AdminNav is the one definition and
 * the server renders #nav from it, complete, before any script: measured on
 * the same profile, every row is in the frame at 578 ms and in the first
 * paint. The guarantees below are the old ones moved onto that source:
 *
 *   1. Every row a partial registers is declared in AdminNav, with its label
 *      and group, and every `late` row is one a partial registers.
 *   2. Every row is in the served document BEFORE the console's first script,
 *      inside the server-rendered <nav>.
 *   3. The rendered sidebar is the settled sidebar captured from Chromium,
 *      group by group, row by row.
 *
 * MUTATION NOTES (Lane AP), each one run:
 *   - delete the `spotted` row from AdminNav::GROUPS
 *        -> 'declares the same row the partial does' fails naming spotted.
 *   - print `<nav class="nav" id="nav"></nav>` instead of AdminNav::html()
 *        -> 'renders every row into the first bytes' fails.
 *   - swap 'setap' and 'productpage' in AdminNav::GROUPS
 *        -> 'renders the sidebar the console settled on' fails on Appearance.
 *
 * MUTATION NOTES from the LATE_NAV lane, kept for the history:
 *   - delete the `LATE_NAV.forEach(r=>kbbAddNavEntry(r));` line
 *        -> 'the rows a partial contributes are still only registered by the
 *            partial' fails, naming all 21 rows and their byte offsets.
 *   - move that line below the `LANE DA` deep-link block
 *        -> 'registers them before the first navigation' fails.
 *   - sort LATE_NAV alphabetically
 *        -> 'LATE_NAV is in the order the sidebar is built in' fails on
 *           Appearance, with setap moved from after productpage to after
 *           cartpanel.
 *   - change one label in LATE_NAV ('Set' -> 'Sets')
 *        -> 'declares the same row the partial does' fails naming setap.
 *   - rename #kbbDashWrap in renderDash only
 *        -> 'can tell the dashboard fallback from a screen that drew itself'
 *           fails.
 */
function navSidebarSrc(): string
{
    static $s = null;

    return $s ??= (string) file_get_contents(resource_path('views/admin/app.blade.php'));
}

/** The console exactly as the browser receives it, partials and all. */
function navSidebarRendered(): string
{
    static $html = null;

    if ($html !== null) {
        return $html;
    }

    $admin = AdminUser::create([
        'name' => 'Sidebar Timing Owner',
        'email' => 'sidebar-timing-owner@example.test',
        'password' => 'password-long-enough',
        'role' => 'owner',
    ]);

    return $html = test()->actingAs($admin, 'admin')->get('/admin')->assertOk()->getContent();
}

/** Every AdminNav row by id, with the section it is drawn in. */
function navSidebarLateNav(): array
{
    $out = [];
    foreach (\App\Support\AdminNav::GROUPS as $g) {
        foreach ($g['rows'] as $r) {
            $out[$r['id']] = ['label' => $r['label'], 'group' => $g['sec'], 'late' => ! empty($r['late'])];
        }
    }

    return $out;
}

/**
 * Every sidebar row a partial registers for itself, with the parameters it
 * passes. Both shapes count: kbbAddNavEntry, and the hand-rolled chains that
 * predate it -- the New Order screen still builds its button by hand, which is
 * exactly the `if (!anchor) return;` pattern kbbAddNavEntry was written to
 * replace, so it has to be read here too or it silently escapes this guard.
 */
function navSidebarPartialRows(): array
{
    $out = [];

    foreach (glob(resource_path('views/admin/partials/*.blade.php')) ?: [] as $file) {
        $body = (string) file_get_contents($file);
        $screen = null;
        if (preg_match("/(?:var|const)\s+SCREEN\s*=\s*'([^']+)'/", $body, $sm)) {
            $screen = $sm[1];
        }

        $at = strpos($body, 'kbbAddNavEntry({');
        if ($at !== false) {
            $chunk = substr($body, (int) $at, 900);
            $g = function (string $key) use ($chunk): ?string {
                return preg_match("/{$key}:\s*'((?:[^'\\\\]|\\\\.)*)'/", $chunk, $m) ? $m[1] : null;
            };
            $label = $g('label');
            // ▲ Lane AP: a label passed as the partial's own LABEL variable
            // (spotted-screen does this) was read as "no call here", which is
            // how that row escaped LATE_NAV and arrived at DOMContentLoaded.
            if ($label === null && preg_match("/label:\s*LABEL\b/", $chunk)
                && preg_match("/(?:var|const)\s+LABEL\s*=\s*'((?:[^'\\\\]|\\\\.)*)'/", $body, $lm)) {
                $label = $lm[1];
            }
            if ($label === null) {
                continue;   // the shared docblock in app.blade.php, not a call
            }
            preg_match("/after:\s*(\[[^\]]*\]|'[^']*')/", $chunk, $am);
            preg_match_all("/'([^']+)'/", $am[1] ?? '', $aa);
            $id = preg_match("/screen:\s*'([^']+)'/", $chunk, $im) ? $im[1] : $screen;

            $out[$id] = [
                'file' => basename($file),
                'label' => $label,
                'group' => $g('group'),
                'after' => $aa[1],
                'icon' => $g('icon'),
            ];
            continue;
        }

        /*
         * The hand-rolled shape: a <button class="nav-item"> with dataset.go,
         * inserted next to an existing row. Only when the function that builds
         * it is actually CALLED -- the coupon usage screen keeps its builder as
         * `addNavEntryDisabled()`, deliberately dead and deliberately readable,
         * beside an `addNavEntry()` that returns immediately. Reading the
         * builder without reading whether anything runs it reports a sidebar
         * row this console has not had since the two coupon entries were
         * merged into one.
         */
        $at = strpos($body, 'b.dataset.go = SCREEN');
        if ($screen === null || $at === false) {
            continue;
        }

        $before = substr($body, 0, (int) $at);
        if (! preg_match_all('/function\s+([A-Za-z_$][\w$]*)\s*\(/', $before, $fm)) {
            continue;
        }
        $builder = end($fm[1]);

        // Declared once, called at least once more.
        if (substr_count($body, $builder.'(') < 2 && ! str_contains($body, ', '.$builder.')')) {
            continue;
        }

        $chunk = substr($body, (int) $at - 900, 1400);
        if (preg_match("/querySelector\('#nav \[data-go=\"([a-z0-9-]+)\"\]'\)/i", $chunk, $am)
            && preg_match("/\+\s*'<span>([^<]+)<\/span>'|<span>([^<]+)<\/span>'/", $chunk, $lm)) {
            $out[$screen] = [
                'file' => basename($file),
                'label' => $lm[1] !== '' ? $lm[1] : ($lm[2] ?? ''),
                'group' => null,        // a hand-rolled chain names no group
                'after' => [$am[1]],
                'icon' => null,
            ];
        }
    }

    return $out;
}

it('declares the same row the partial does, in both directions', function () {
    /*
     * The anti-drift guard, moved onto App\Support\AdminNav (Lane AP). The
     * server draws every row from AdminNav, so a partial's own kbbAddNavEntry()
     * is a no-op that returns the row already there -- unless AdminNav does not
     * declare it, in which case the row is back to arriving with its partial,
     * at the end of the document. Compared both ways: a partial row AdminNav
     * lacks is that defect; an AdminNav `late` row no partial registers is a
     * sidebar entry with no renderer behind it.
     *
     * Label and group are compared, because those are what the owner reads and
     * where the row sits. The `after` anchors no longer decide anything: the
     * position is the row's place in AdminNav::GROUPS, pinned by the settled-
     * order case below.
     */
    $declared = navSidebarLateNav();
    $registered = navSidebarPartialRows();

    expect($declared)->not->toBeEmpty('AdminNav declares no rows')
        ->and($registered)->not->toBeEmpty('no partial registers a sidebar row any more, so this guard asserts nothing')
        ->and($registered)->toHaveKey('spotted');

    $why = [];

    foreach ($registered as $id => $r) {
        if (! isset($declared[$id])) {
            $why[] = sprintf(
                '  %-16s is registered by %s and is NOT in AdminNav, so its row does not exist until that'
                ."\n                   partial has been parsed -- the defect this file exists for.",
                $id, $r['file']
            );

            continue;
        }

        $d = $declared[$id];
        if ($d['label'] !== $r['label']) {
            $why[] = sprintf('  %-16s AdminNav says label "%s", %s says "%s"', $id, $d['label'], $r['file'], $r['label']);
        }
        if ($r['group'] !== null && $d['group'] !== $r['group']) {
            $why[] = sprintf('  %-16s AdminNav says group "%s", %s says "%s"', $id, $d['group'], $r['file'], $r['group']);
        }
        if (! $d['late']) {
            $why[] = sprintf('  %-16s is registered by %s but is not marked `late` in AdminNav, so a click before'
                .' its partial arrives is not replayed', $id, $r['file']);
        }
    }

    foreach ($declared as $id => $d) {
        // A `pending` row's partial has not merged yet (AdminNav says whose).
        if ($d['late'] && ! isset($registered[$id]) && empty(\App\Support\AdminNav::rows()[$id]['pending'])) {
            $why[] = sprintf(
                '  %-16s is a `late` row in AdminNav but no partial registers it, so the sidebar carries a row'
                ."\n                   whose screen nothing draws.",
                $id
            );
        }
    }

    expect($why === [])->toBeTrue(
        count($why)." sidebar row(s) disagree with the partial that owns them:\n".implode("\n", $why)."\n"
    );
});

it('renders every row into the first bytes of the console, before any of its scripts', function () {
    /*
     * THE MEASUREMENT, AS AN ASSERTION (Lane AP). Every row the account may
     * open is inside the server-rendered <nav id="nav">, exactly once, and that
     * <nav> closes before the console's first script block -- the 650 KB one
     * buildNav() used to wait for. Nothing is left for a script to add.
     */
    $html = navSidebarRendered();
    $total = strlen($html);

    $open = strpos($html, '<nav class="nav" id="nav"');
    $close = strpos($html, '</nav>', (int) $open);
    $script = strpos($html, 'const $=(s,r=document)=>r.querySelector(s);');

    expect($open)->not->toBeFalse('the console no longer prints a server-rendered <nav id="nav">')
        ->and($script)->not->toBeFalse('the console script\'s first line moved; this file cannot find it')
        ->and($close)->toBeLessThan($script, 'the sidebar closes after the console script starts');

    $nav = substr($html, (int) $open, (int) $close - (int) $open);
    $missing = [];
    foreach (array_keys(navSidebarLateNav()) as $id) {
        if (substr_count($nav, 'data-go="'.$id.'"') !== 1) {
            $missing[] = $id;
        }
    }

    expect($missing)->toBe([], 'rows missing from (or doubled in) the server-rendered sidebar: '.implode(', ', $missing));
    expect(100 * $close / $total)->toBeLessThan(15.0, sprintf(
        'the sidebar ends at byte %d of %d (%.1f%%) -- it belongs in the first bytes', $close, $total, 100 * $close / $total
    ));
});

it('marks the deep-linked row and opens its group in the markup itself', function () {
    /*
     * go() sets the highlight and opens the screen's group, but go() is in the
     * console script. The server knows ?go= and draws the mark itself, so a
     * deep link paints the right row lit before any script runs; the inline
     * script after the <nav> does the same for a #hash, which never reaches
     * the server.
     */
    $admin = AdminUser::create([
        'name' => 'Deep Link Owner', 'email' => 'deep-link-owner@example.test',
        'password' => 'password-long-enough', 'role' => 'owner',
    ]);
    $html = $this->actingAs($admin, 'admin')->get('/admin?go=setap')->assertOk()->getContent();

    expect($html)->toContain('<div class="nav-group open" data-sec="Appearance">')
        ->and($html)->toContain('<button class="nav-item on" data-go="setap">')
        ->and(substr_count($html, 'class="nav-item on'))->toBe(1)
        ->and(substr_count($html, 'class="nav-group open"'))->toBe(1);

    // An address naming no row falls back to Dashboard, which is what the boot
    // opens for it. The value is compared, never printed.
    $html = $this->get('/admin?go=%22%3E%3Cscript%3Ealert(1)%3C%2Fscript%3E')->assertOk()->getContent();
    expect($html)->toContain('<button class="nav-item on" data-go="dash">')
        ->and($html)->not->toContain('"><script>alert(1)</script>');
});

it('renders the sidebar the console settled on, group by group and row by row', function () {
    /*
     * Captured from Chromium on the code before Lane AP (tools/ap-settled.cjs
     * -> storage/ap-logs/settled-before-owner.json): what #nav held once
     * buildNav(), LATE_NAV and every partial had run. The server now renders
     * exactly this, so a cold load and a settled console are the same sidebar.
     * The JSON comparison in that harness also matched every row's icon markup
     * and classes byte for byte.
     */
    $settled = [
        // 'site-analytics' — Analytics (Lane AN), a top-level row under Dashboard.
        '' => ['dash', 'site-analytics'],
        // 'domainswitch' — Platform → Domain switch (Lane DW), after Site address.
        'Platform' => ['theme', 'users', 'settings', 'siteaddr', 'domainswitch', 'cache'],
        // App: added after the capture, at the owner's request (5 October).
        'App' => ['siteapp', 'ownerapp'],
        // 'notfoundpage' — Safety → 404 page (Lane NF), after Demo Content.
        'Safety' => ['debug', 'sandbox', 'democontent', 'notfoundpage'],
        'Catalog' => ['catalog', 'product-tabs', 'sets', 'product-editor', 'imageseo', 'routines', 'category-tree', 'brands-manager', 'pagination'],
        'Store' => ['modules', 'megamenu', 'ecommerce', 'tax', 'payship', 'shipping', 'import', 'orders',
            'order-new', 'coupon-editor', 'payments', 'paygw', 'security', 'firewall', 'analytics', 'search', 'seo', 'seokeywords',
            'store-settings', 'customers', 'quiz-leads'],
        'Emails' => ['emails', 'emails-sending', 'emails-customer', 'emails-branding', 'emails-sent', 'mail'],
        'Content' => ['posts', 'htmlblocks', 'media', 'ugcsections', 'instagram', 'igembeds'],
        'Translation' => ['tr-settings', 'tr-progress', 'tr-strings', 'tr-machine'],
        // 'comingsoon' — Appearance → Coming Soon page (Lane CS), last, after Site layout.
        'Appearance' => ['homepage', 'hpcontent', 'spotted', 'banners', 'gridsections', 'prodstyles', 'mobilehdr',
            'dividers', 'pagewash', 'wabutton', 'cartpanel', 'cartpage', 'checkoutpage', 'slimfooter', 'acctpanel',
            'header', 'mobilemenu', 'productpage', 'setap', 'bundles', 'layout', 'sitelayout', 'comingsoon'],
        'Pages' => ['pages-store', 'pages-user', 'pagebanners', 'pageheader'],
        // 'merchantfeed' — Growth & Marketing → Google Shopping feed (Lane SEO), after Meta & Facebook.
        'Growth & Marketing' => ['mkt-email', 'newsletter', 'labels', 'meta', 'merchantfeed', 'pixels', 'searchterms', 'carttracking', 'push'],
        'Reviews' => ['rev-all', 'rev-add', 'rev-assign', 'rev-io', 'rev-badge', 'rev-settings'],
        '/flat' => ['shopfilters', 'updates'],
    ];

    // Read back out of the RENDERED markup, so the assertion is about what
    // the browser receives rather than about the PHP array.
    $html = navSidebarRendered();
    $open = (int) strpos($html, '<nav class="nav" id="nav"');
    $nav = substr($html, $open, (int) strpos($html, '</nav>', $open) - $open);

    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="utf-8"?>'.$nav.'</nav>');
    $got = [];
    $flat = 0;
    foreach ($dom->getElementsByTagName('nav')->item(0)->childNodes as $el) {
        if (! $el instanceof DOMElement) {
            continue;
        }
        if (str_contains($el->getAttribute('class'), 'nav-group')) {
            foreach ((new DOMXPath($dom))->query('.//button[@data-go]', $el) as $b) {
                $got[$el->getAttribute('data-sec')][] = $b->getAttribute('data-go');
            }

            continue;
        }
        // Bare rows above the first group are the top ('' : Dashboard, and
        // Analytics under it since Lane AN); bare rows after one are '/flat'.
        $flat++;
        $got[array_diff(array_keys($got), ['']) === [] ? '' : '/flat'][] = $el->getAttribute('data-go');
    }

    expect(array_keys($got))->toBe(array_keys($settled));
    foreach ($settled as $group => $rows) {
        expect($got[$group])->toBe($rows, sprintf(
            "the %s menu is not the one the console settled on.\n  want %s\n  got  %s",
            $group === '' ? 'top' : $group, implode(' ', $rows), implode(' ', $got[$group])
        ));
    }

    // Core Updates is pinned to the foot, above Console.
    expect($nav)->toContain('<button class="nav-item nav-pinned" data-go="updates">');
});

it('can tell the dashboard fallback from a screen that drew itself', function () {
    /*
     * The hazard the fix creates and the thing that answers it.
     *
     * A row now exists before its renderer, so it can be clicked before it.
     * go() would answer that with renderDash() UNDER THE CLICKED SCREEN'S NAME
     * and no error -- the silent dashboard AdminNavAndIdsTest exists for,
     * reached by a click instead of an address.
     *
     * kbbNavClick replays the navigation once after DOMContentLoaded, and only
     * when renderDash() is what actually painted. The signal is renderDash's
     * own wrapper id: present only when the fallback drew, so a screen whose
     * partial had already arrived is never replayed and never drawn twice --
     * the double render Lane DA measured on 'rev-all'.
     *
     * Both halves are required here. Renaming the id in renderDash alone
     * leaves the guard testing for an element that never exists, which arms
     * the replay for EVERY late click; renaming it in kbbNavClick alone never
     * arms it at all.
     */
    $src = navSidebarSrc();

    expect(preg_match('/id="kbbFrameStartup"/', $src))
        ->toBe(1, 'frameStartupHTML() no longer marks its card, so a sidebar row clicked before the document is '
            .'parsed leaves the owner on "could not be loaded ... Reload the page." permanently -- reloading '
            .'reproduces it, because the address is not the cause');

    expect(preg_match('/\$\(.#content.\)\.innerHTML=`<div class="wrap" id="kbbDashWrap">/', $src))
        ->toBe(1, "renderDash() no longer marks its own wrapper with id=\"kbbDashWrap\", so kbbNavClick cannot tell "
            .'"the dashboard fell through" from "the right screen drew", and would replay over a screen that had '
            .'already painted');

    $fn = (string) preg_replace('#/\*.*?\*/#s', ' ', (string) substr($src, (int) strpos($src, 'function kbbNavClick(id){'), 1400));

    expect(str_contains($fn, "\$('#kbbDashWrap')"))->toBeTrue(
        'kbbNavClick no longer tests for the dashboard wrapper, so it either replays over screens that drew '
        .'themselves or never replays at all');
    expect(str_contains($fn, "document.readyState!=='loading'"))->toBeTrue(
        'kbbNavClick no longer stops once the document is parsed, so it arms a replay on every click for ever');
    expect(str_contains($fn, "\$('#kbbFrameStartup')"))->toBeTrue(
        'kbbNavClick no longer tests for the frame startup card, so clicking Store -> Orders while the document '
        .'is still arriving strands the console on "Orders could not be loaded" for good');
    foreach (['LATE_NAV_IDS.has(id)', 'LIVE_RENDERED.has(id)', 'LATE_RENDERED.has(id)'] as $set) {
        expect(str_contains($fn, $set))->toBeTrue(
            "kbbNavClick no longer arms from {$set}, so one of the three ways a click during load lands on the "
            .'wrong screen is unanswered. The three sets are the same question asked of three groups of ids: '
            .'can the go() that exists right now draw this one at all.');
    }

    // Every sidebar row goes through it: buildNav's own rows and the injected
    // ones. A row wired straight to go() skips the guard.
    expect(preg_match("/\\\$\\\$\('#nav \.nav-item'\)\.forEach\(b=>b\.onclick=\(\)=>kbbNavClick\(b\.dataset\.go\)\);/", $src))
        ->toBe(1, "buildNav()'s rows are wired straight to go() again, so clicking one of the declared rows before "
            .'its renderer arrives opens the dashboard under that row\'s name and stays there');

    expect(preg_match('/b\.onclick = \(\) => kbbNavClick\(screen\);/', $src))
        ->toBe(1, 'kbbAddNavEntry wires its injected rows straight to window.go again, so the same click before the '
            .'renderer arrives is unguarded');
});

it('starts the dashboard request in the first script block, not at 39% of the document', function () {
    /*
     * The owner's second symptom: "Loading the latest orders..." still
     * spinning. That line is renderDash()'s placeholder and hydrateDash()
     * replaces it from GET /admin-api/stats -- an endpoint that answers in
     * 44ms over 16 queries with no repeated shape. It was not slow, it was not
     * being CALLED: hydrateDash()'s boot call is at the foot of the second
     * script block, byte 1,337,459 of 3,438,661.
     *
     * Pinned as a relationship, not a number: the request must be started
     * before the block that contains hydrateDash() is reached.
     */
    $html = navSidebarRendered();
    $total = strlen($html);

    $early = strpos($html, 'window.__kbbStatsFirst = (function(){');
    $boot = strpos($html, "  if(cur==='dash') hydrateDash();");

    expect($early)->not->toBeFalse(
        'nothing starts /admin-api/stats early any more, so the dashboard cannot ask for its numbers until the '
        .'second script block has been parsed'
    );
    expect($boot)->not->toBeFalse("the dashboard's boot hydrate was renamed, so this file cannot locate it");

    expect($early)->toBeLessThan($boot, sprintf(
        'the early request starts at byte %d (%.1f%%), which is not before the boot hydrate at byte %d (%.1f%%)',
        $early, 100 * $early / $total, $boot, 100 * $boot / $total
    ));

    // And hydrateDash actually consumes it, rather than starting a second one.
    expect(str_contains(navSidebarSrc(), 'var first = window.__kbbStatsFirst; window.__kbbStatsFirst = null;'))
        ->toBeTrue('hydrateDash() no longer reads the request the head of the document started, so that request '
            .'is made twice and the tiles still wait for the second one');
});

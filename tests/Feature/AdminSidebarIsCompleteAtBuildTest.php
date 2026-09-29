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
 * MUTATION NOTES, each one run:
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

/** LATE_NAV, read from the literal, in declaration order. */
function navSidebarLateNav(): array
{
    $src = navSidebarSrc();
    $at = strpos($src, 'const LATE_NAV=[');

    expect($at)->not->toBeFalse('LATE_NAV is no longer a literal array, so this file cannot read it');

    $end = strpos($src, "\n];", (int) $at);
    $block = substr($src, (int) $at, (int) $end - (int) $at);

    preg_match_all(
        "/\{screen:'([^']+)',label:'([^']*)',group:'([^']*)',after:\[([^\]]*)\],icon:'([^']*)'\}/",
        $block,
        $m,
        PREG_SET_ORDER
    );

    $out = [];
    foreach ($m as $hit) {
        preg_match_all("/'([^']+)'/", $hit[4], $a);
        $out[$hit[1]] = ['label' => $hit[2], 'group' => $hit[3], 'after' => $a[1], 'icon' => $hit[5]];
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
     * The anti-drift guard. LATE_NAV is a COPY of each partial's own call, and
     * a copy drifts. Compared both ways: a partial whose row is missing here
     * is a row that goes back to arriving at 98.9% of the document, and a row
     * here that no partial claims is a sidebar entry with no renderer behind
     * it.
     *
     * The label, the group and the anchors are compared, because those are
     * what decide where the row lands and what it says. The New Order screen
     * is the one hand-rolled chain left and it names no group, so only its
     * label and anchor are comparable.
     */
    $declared = navSidebarLateNav();
    $registered = navSidebarPartialRows();

    expect($declared)->not->toBeEmpty('LATE_NAV is empty')
        ->and($registered)->not->toBeEmpty('no partial registers a sidebar row any more, so this guard asserts nothing');

    $why = [];

    foreach ($registered as $id => $r) {
        if (! isset($declared[$id])) {
            $why[] = sprintf(
                '  %-16s is registered by %s and is NOT in LATE_NAV, so its row does not exist until that'
                ."\n                   partial has been parsed -- the defect this file exists for.",
                $id, $r['file']
            );

            continue;
        }

        $d = $declared[$id];
        if ($d['label'] !== $r['label']) {
            $why[] = sprintf('  %-16s LATE_NAV says label "%s", %s says "%s"', $id, $d['label'], $r['file'], $r['label']);
        }
        if ($r['group'] !== null && $d['group'] !== $r['group']) {
            $why[] = sprintf('  %-16s LATE_NAV says group "%s", %s says "%s"', $id, $d['group'], $r['file'], $r['group']);
        }
        if ($r['after'] !== [] && $d['after'] !== $r['after']) {
            $why[] = sprintf(
                '  %-16s LATE_NAV anchors on [%s], %s anchors on [%s] -- the row would sit somewhere else',
                $id, implode(', ', $d['after']), $r['file'], implode(', ', $r['after'])
            );
        }
    }

    foreach (array_keys($declared) as $id) {
        if (! isset($registered[$id])) {
            $why[] = sprintf(
                '  %-16s is declared in LATE_NAV but no partial registers it, so the sidebar carries a row'
                ."\n                   whose screen nothing draws.",
                $id
            );
        }
    }

    expect($why === [])->toBeTrue(
        count($why)." sidebar row(s) disagree with the partial that owns them:\n".implode("\n", $why)."\n"
    );
});

it('registers the rows a partial contributes before the partials are parsed', function () {
    /*
     * THE MEASUREMENT, AS AN ASSERTION. This is the test that is red on the
     * code this lane replaced.
     *
     * For each row, find every byte offset in the RENDERED console at which
     * something registers it, and take the earliest. Before this lane, the
     * earliest was the partial's own call -- 44.2% to 98.9% of the document,
     * depending on the row. It must now be the declaration beside buildNav(),
     * which is in the first script block.
     *
     * The threshold is the FIRST partial's own registration, derived rather
     * than written as a percentage: every row must be registered before the
     * point at which partial-contributed rows used to start appearing. That
     * is what "the sidebar is complete when it is first drawn" means, and it
     * cannot drift as the document grows.
     */
    $html = navSidebarRendered();
    $total = strlen($html);

    $buildNav = strpos($html, "\nbuildNav();");
    expect($buildNav)->not->toBeFalse('buildNav() is no longer called, so the sidebar is built somewhere this file cannot find');

    $registerAt = strpos($html, 'LATE_NAV.forEach(r=>kbbAddNavEntry(r));');
    expect($registerAt)->not->toBeFalse(
        'nothing registers LATE_NAV in the rendered console, so every partial-contributed row is back to '
        .'appearing only when its own script runs -- which on this document is up to 98.9% of the way through it'
    );

    // Where the partials' own registrations begin: the first kbbAddNavEntry
    // call that carries a label, which only a real call site does.
    preg_match_all("/kbbAddNavEntry\(\{[\s\S]{0,600}?label:\s*'([^']*)'/", $html, $m, PREG_OFFSET_CAPTURE);
    $partialOffsets = array_map(fn ($hit) => $hit[1], $m[0]);

    expect($partialOffsets)->not->toBeEmpty('no partial registers a row in the rendered console any more');

    $firstPartial = min($partialOffsets);
    $lastPartial = max($partialOffsets);

    expect($registerAt)->toBeLessThan($firstPartial, sprintf(
        "the declared rows are registered at byte %d (%.1f%%) of a %d-byte document, which is AFTER the first\n"
        ."partial registers its own row at byte %d (%.1f%%). The last partial registers at byte %d (%.1f%%), and\n"
        ."every row between those two points is missing from the owner's sidebar until it is reached.",
        $registerAt, 100 * $registerAt / $total, $total,
        $firstPartial, 100 * $firstPartial / $total,
        $lastPartial, 100 * $lastPartial / $total
    ));

    // And it happens as part of building the sidebar, not somewhere else.
    expect($registerAt - $buildNav)->toBeLessThan(600, sprintf(
        'the declared rows are registered %d bytes after buildNav(), not immediately after it',
        $registerAt - $buildNav
    ));
});

it('registers them before the first navigation, so a deep link sees a complete sidebar', function () {
    /*
     * go() sets the sidebar highlight and opens the group the screen is in. If
     * the rows were registered after the deep-link boot, ?go=setap would paint
     * the screen with nothing marked in the menu.
     */
    $src = navSidebarSrc();
    $register = strpos($src, 'LATE_NAV.forEach(r=>kbbAddNavEntry(r));');
    $deepLink = strpos($src, 'LANE DA · deep links · BEGIN');

    expect($register)->not->toBeFalse('LATE_NAV is not registered in app.blade.php at all')
        ->and($deepLink)->not->toBeFalse('the deep-link boot region was renamed or removed')
        ->and($register)->toBeLessThan(
            $deepLink,
            'the partial-contributed rows are registered AFTER the deep-link boot navigates, so ?go= to one of '
            .'them opens its screen with no sidebar row marked and its group shut'
        );
});

it('keeps LATE_NAV in the order the sidebar is built in', function () {
    /*
     * kbbAddNavEntry inserts a row directly after the first of its `after`
     * anchors that is ALREADY in the claimed group, so the order rows arrive
     * in decides where they land. Sorting LATE_NAV -- the obvious tidy-up --
     * moves rows silently.
     *
     * The expectation below is the sidebar captured from Chromium before this
     * lane changed anything: 13 groups, 77 rows. `setap` is the one that
     * proves the point. It names 'cartpanel' first, but it registers before
     * cart-panel does, so it has always landed after 'productpage'; an
     * alphabetical LATE_NAV puts it after cartpanel instead and the Set row
     * moves four places up the Appearance menu.
     */
    $base = [
        'Platform' => ['theme', 'users', 'settings', 'siteaddr'],
        'Safety' => ['debug', 'sandbox', 'democontent'],
        'Catalog' => ['catalog'],
        'Store' => ['modules', 'megamenu', 'ecommerce', 'tax', 'payship', 'shipping', 'import', 'orders',
            'payments', 'analytics', 'search', 'seo', 'mail', 'store-settings', 'customers', 'quiz-leads'],
        'Content' => ['posts', 'htmlblocks', 'media'],
        'Translation' => ['tr-settings', 'tr-progress', 'tr-strings', 'tr-machine'],
        'Appearance' => ['homepage', 'prodstyles', 'mobilehdr', 'dividers', 'acctpanel', 'header',
            'mobilemenu', 'productpage', 'bundles', 'layout'],
        'Pages' => ['pages-store', 'pages-user'],
        'Growth & Marketing' => ['newsletter', 'labels', 'meta', 'pixels'],
        'Reviews' => ['rev-all', 'rev-add', 'rev-assign', 'rev-io', 'rev-badge', 'rev-settings'],
    ];

    // Captured from the browser on the code this lane started from.
    $settled = [
        'Platform' => ['theme', 'users', 'settings', 'siteaddr', 'cache'],
        'Catalog' => ['catalog', 'product-tabs', 'sets', 'product-editor', 'routines', 'category-tree', 'brands-manager'],
        'Store' => ['modules', 'megamenu', 'ecommerce', 'tax', 'payship', 'shipping', 'import', 'orders',
            'order-new', 'coupon-editor', 'payments', 'paygw', 'security', 'analytics', 'search', 'seo',
            'mail', 'store-settings', 'customers', 'quiz-leads'],
        'Content' => ['posts', 'htmlblocks', 'media', 'ugcsections', 'instagram'],
        'Appearance' => ['homepage', 'hpcontent', 'banners', 'prodstyles', 'mobilehdr', 'dividers',
            'cartpanel', 'cartpage', 'checkoutpage', 'slimfooter', 'acctpanel', 'header', 'mobilemenu',
            'productpage', 'setap', 'bundles', 'layout', 'sitelayout'],
    ];

    // kbbAddNavEntry's placement, steps 1 and 2. Steps 3 and 4 are its loud
    // failure paths and cannot be reached here: every group named exists.
    $nav = $base;
    foreach (navSidebarLateNav() as $id => $row) {
        $group = $row['group'];
        expect(isset($nav[$group]))->toBeTrue("LATE_NAV puts '{$id}' in a group NAV does not have: '{$group}'");

        $placed = false;
        foreach ($row['after'] as $anchor) {
            $i = array_search($anchor, $nav[$group], true);
            if ($i !== false) {
                array_splice($nav[$group], $i + 1, 0, [$id]);
                $placed = true;
                break;
            }
        }
        if (! $placed) {
            $nav[$group][] = $id;
        }
    }

    foreach ($settled as $group => $expected) {
        expect($nav[$group])->toBe($expected, sprintf(
            "the %s menu would not settle where it does today.\n  want %s\n  got  %s",
            $group, implode(' ', $expected), implode(' ', $nav[$group])
        ));
    }
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

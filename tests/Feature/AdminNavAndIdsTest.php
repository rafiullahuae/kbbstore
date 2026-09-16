<?php

declare(strict_types=1);

/**
 * One sidebar entry per screen, and no two elements sharing an id.
 *
 * The owner applied a package containing a brand-new coupon editor, opened the
 * sidebar item called "Coupons", got the old read-only usage report, and
 * reasonably told me the editor had not shipped. It had — under a second entry
 * called "Manage Coupons" directly beneath it. Two entries whose names do not
 * tell you which one does the thing is a worse outcome than the missing screen
 * it replaced.
 *
 * The second half is the same class of mistake one level down. While fixing
 * the above I added `id="cu-back"` to the usage screen without noticing the id
 * was already in use by the button that clears a coupon's detail view.
 * document.querySelector returns the FIRST match, so the existing handler would
 * have silently been given my button and that feature would have broken with no
 * error anywhere. Caught by reading, not by running — which is exactly why it
 * is pinned here.
 */
/**
 * Ids that legitimately appear twice in a source file because the two
 * occurrences are MUTUALLY EXCLUSIVE renders of the same screen — a form state
 * and a confirmation state, say. Only one is ever in the document, so
 * querySelector cannot pick the wrong one.
 *
 * A static scan cannot tell those apart from a real collision, so each is
 * listed by hand with its reason. Keep this list short: every entry is a place
 * the check has been switched off.
 */
const ALTERNATIVE_RENDERS = [
    // The New Order screen draws either the order form or, once the order has
    // been placed, the "created" panel. Never both.
    'manual-order-screen.blade.php#moScreen',
];

function adminScreenSources(): array
{
    return glob(base_path('resources/views/admin/partials/*.blade.php')) ?: [];
}

it('never renders the same element id twice in one admin screen', function () {
    $offenders = [];

    foreach (adminScreenSources() as $file) {
        $body = (string) file_get_contents($file);

        // Only literal id="..." in rendered markup. Ids built by interpolation
        // ('id="' + x + '"') are per-row and out of scope for a static check.
        preg_match_all('/\bid="([a-zA-Z][\w-]*)"/', $body, $m);

        $counts = array_count_values($m[1]);

        foreach ($counts as $id => $n) {
            if ($n > 1 && ! in_array(basename($file) . '#' . $id, ALTERNATIVE_RENDERS, true)) {
                $offenders[] = basename($file) . ": id=\"{$id}\" appears {$n} times";
            }
        }
    }

    expect($offenders)->toBe(
        [],
        "two elements share an id, so querySelector will hand the wrong one to its handler:\n  "
        . implode("\n  ", $offenders)
    );
});

it('gives the coupon screens exactly one sidebar entry between them', function () {
    /*
     * Specifically the pair that went wrong. The editor takes the "Coupons"
     * name and position; the usage report is reached from a link inside it and
     * registers nothing in the sidebar.
     */
    $editor = (string) file_get_contents(
        base_path('resources/views/admin/partials/coupon-editor-screen.blade.php')
    );
    $usage = (string) file_get_contents(
        base_path('resources/views/admin/partials/coupon-usage-screen.blade.php')
    );

    expect(str_contains($editor, "<span>Coupons</span>"))
        ->toBeTrue('the coupon editor no longer claims the "Coupons" sidebar entry');

    expect(str_contains($editor, '<span>Manage Coupons</span>'))
        ->toBeFalse('the second "Manage Coupons" sidebar entry is back');

    // The usage screen's registration must be inert, whatever it is called.
    expect(preg_match('/function addNavEntry\(\)\s*\{\s*return;/', $usage))
        ->toBe(1, 'the coupon usage screen is adding a sidebar entry again, which is the duplicate the owner hit');

    // And both directions between the two screens still exist.
    expect(str_contains($editor, "window.go('coupon-usage')"))
        ->toBeTrue('there is no way through to the usage report now that it has no sidebar entry');
    expect(str_contains($usage, "window.go('coupon-editor')"))
        ->toBeTrue('there is no way back from the usage report');
});

/* ═══════════════════ Lane BX · the rest of the sidebar ═══════════════════ */

/**
 * The sidebar, and whether it tells the owner the truth about itself.
 *
 * WHAT HAPPENED. A package added a second sidebar row called "Manage Coupons"
 * directly beneath the existing read-only "Coupons". The owner clicked
 * "Coupons", got the old report, and concluded the editor had never shipped. It
 * had. The menu was the whole bug.
 *
 * That is not one mistake, it is a class of mistake, and this console makes it
 * easy to repeat: screens register themselves by injecting a `nav-item` button
 * from inside their own partial, anchored relative to some other entry by
 * `[data-go="…"]`. Nothing central knows what the sidebar ends up containing,
 * so nothing can notice when two rows say almost the same thing, when a row
 * opens a page headed with a different name, when a row lands in a group that
 * makes no sense for it, or when a row quietly stops existing because the entry
 * it anchored to was renamed.
 *
 * So this file reads NAV, TITLES and every self-registering partial, assembles
 * the sidebar the owner will actually see, and asks four questions of it:
 *
 *   1. Is every `data-go` unique? Two rows with the same id are one row's
 *      worth of feature and two rows' worth of confusion.
 *   2. Is every label unique? Same, from the owner's side of the screen.
 *   3. Does every row point at a screen that exists?
 *   4. Does each row's group and label match the breadcrumb and title the page
 *      then shows? This is the coupon bug's general form: click one word,
 *      the page answers with another.
 *
 * Plus two the audit turned up and could not leave alone:
 *
 *   5. Can a screen silently remove itself from the sidebar? Every injected
 *      registration is `if (!anchor) return;` — a screen that vanishes, with no
 *      error anywhere, if the entry it anchors to is renamed.
 *   6. Does a tabbed screen say what its tabs are for? The owner asked this of
 *      Catalog; it is the same question on the other twelve.
 *
 * STRUCTURAL, because Pest has no JavaScript engine. The browser measurement
 * lives in the commit. Names here are prefixed `navAudit` on purpose:
 * AdminNavWalkTest.php declares `adminApp()`, `jsBlock()` and `navIds()` at
 * global scope in the same suite, and a second declaration is a fatal error.
 */

/*
 * ────────────────────────────── the allowlists ──────────────────────────────
 *
 * ALIASED_SCREENS — ids that legitimately reach a renderer named after a
 * DIFFERENT screen, so question 4 must not read the mismatch as a bug.
 *
 * There is exactly one, and it is the consolidation this audit made: 'blog' and
 * 'posts' were two sidebar rows that opened the same screen — go() routes both
 * to renderPosts() and FRAME_SRC mapped both to kbb-admin-blog.html. Clicking
 * "Blog" landed on a page headed "Posts", which is precisely how an owner
 * decides a screen was never built. 'posts' keeps the row; 'blog' keeps the id,
 * so #blog and ?go=blog still work, and its TITLES entry now names the screen
 * it actually opens rather than a second one that does not exist.
 *
 * Keep this honest: an entry belongs here only when the SAME screen is reached
 * under a second id on purpose. It is not a place to park a mismatch.
 */
const ALIASED_SCREENS = [
    'blog' => 'posts',
];

/*
 * NOT_IN_NAV — ids reachable from admin chrome that is not the `#nav` list, so
 * question 3's reverse (is anything unreachable?) has a home and question 1
 * does not trip over them.
 *
 * 'console' is the cog pinned below the sidebar (`.side-pin`) and the theme
 * button in the top bar. It is reachable; it is simply not a NAV row.
 */
const NOT_IN_NAV = ['console'];

/*
 * DEAD_ANCHORS — fallback anchors that name a sidebar row which has never
 * existed, or no longer does. Harmless only for as long as the SAME chain also
 * reaches a row that is in NAV, which the guard below re-checks rather than
 * assumes.
 *
 * This list had three entries and has one. It shrank because the thing it was
 * describing got fixed: the audit's recommendation was one shared helper
 * instead of five hand-rolled copies of the same anchor lookup, and
 * kbbAddNavEntry() in app.blade.php is that helper. A dead anchor used to be
 * worth writing down because it READ like a net when there was none under it.
 * The helper is a real net — the named group, then the sidebar root, and a
 * console error naming the screen if it gets that far — so an anchor that
 * exists only for decoration is now simply deleted where it is found.
 *
 *   'category-tree' => ['products']   dropped: the partial calls the helper
 *   'brands-manager' => ['products']  dropped: the partial calls the helper
 *
 * Neither ever matched anything; there has never been a 'products' row in this
 * console. brands-editor had inherited it by being modelled on category-tree,
 * which is what made the pattern, not the two files, the thing to fix.
 */
const DEAD_ANCHORS = [
    /*
     * The one that stays, and only because this lane may not touch the file
     * that would fix it.
     *
     * coupon-editor-screen.blade.php is owned by another lane right now (it is
     * being redesigned), so its registration is still hand-rolled and still
     * reads `coupon-usage || order-new || orders`. 'coupon-usage' went dead
     * when the coupon fix made the usage screen's own registration inert — the
     * fix created this entry. Nothing breaks: 'orders' is in NAV, and the
     * chain's live half still puts the editor beside it.
     *
     * The replacement call is written out in the lane report for the
     * integrator to apply; when it lands, `after` becomes
     * ['order-new', 'orders'] and this entry goes with it. The honesty guard
     * below fails the moment that happens, so it cannot be forgotten.
     */
    'coupon-editor' => ['coupon-usage'],
];

/*
 * FLAT_SECTIONS may not be listed here by hand — a section renders flat when it
 * holds one item and does not ask for `group:true`, and buildNav decides that,
 * not this file. See navAuditEntries().
 */

/*
 * TABBED_SCREENS — every function in app.blade.php that draws a tab strip,
 * mapped to the function that must carry the sentence explaining what the tabs
 * are and why they are one screen.
 *
 * Usually the same function. Payments is the exception: payTabBar() draws the
 * strip and renderPayments() writes the prose above it, deliberately, because
 * the warnings there are about gateways you are NOT looking at.
 *
 * The test asserts this map covers every tab strip in the file, so a screen
 * that grows tabs fails until someone writes the sentence.
 */
const TABBED_SCREENS = [
    'renderCatalog' => 'renderCatalog',
    'renderSeo' => 'renderSeo',
    'paintEcom' => 'paintEcom',
    'paintProdStyles' => 'paintProdStyles',
    'paintNewsletter' => 'paintNewsletter',
    'paintMobileHdr' => 'paintMobileHdr',
    'paintDividers' => 'paintDividers',
    'paintCartPanel' => 'paintCartPanel',
    'apPaint' => 'apPaint',
    'paintHeader' => 'paintHeader',
    'paintSiteSearch' => 'paintSiteSearch',
    'shTabs' => 'shTabs',
    'payTabBar' => 'renderPayments',
];

/* ──────────────────────────────── the parsing ──────────────────────────────── */

function navAuditSrc(): string
{
    static $s = null;

    return $s ??= (string) file_get_contents(resource_path('views/admin/app.blade.php'));
}

/** Slice a balanced block starting at the first $open at/after $from. */
function navAuditBlock(string $s, int $from, string $open = '{', string $close = '}'): string
{
    $i = strpos($s, $open, $from);
    if ($i === false) {
        return '';
    }
    $depth = 0;
    for ($j = $i, $n = strlen($s); $j < $n; $j++) {
        if ($s[$j] === $open) {
            $depth++;
        } elseif ($s[$j] === $close) {
            $depth--;
            if ($depth === 0) {
                return substr($s, $i, $j - $i + 1);
            }
        }
    }

    return '';
}

/** Body of `function NAME(` including braces, or '' when not declared. */
function navAuditFn(string $name): string
{
    $at = strpos(navAuditSrc(), 'function '.$name.'(');

    return $at === false ? '' : navAuditBlock(navAuditSrc(), $at);
}

/** Partials the console @includes, in include order — the order matters. */
function navAuditPartials(): array
{
    preg_match_all("/@include\('admin\.partials\.([a-z0-9-]+)'\)/", navAuditSrc(), $m);

    return $m[1];
}

function navAuditPartialSrc(string $name): string
{
    return (string) file_get_contents(resource_path('views/admin/partials/'.$name.'.blade.php'));
}

/**
 * The sidebar the owner sees: NAV's own rows, then every row a partial injects,
 * in the order the partials are included.
 *
 * Each row carries where it came from, which is what makes a failure message
 * point at a file rather than at a symptom.
 */
function navAuditEntries(): array
{
    static $out = null;
    if ($out !== null) {
        return $out;
    }

    $out = [];

    $nav = navAuditBlock(navAuditSrc(), strpos(navAuditSrc(), 'const NAV='), '[', ']');

    // `[^\[]*` rather than a bare comma: a section may carry `group:true`
    // between its name and its items.
    preg_match_all("/\{sec:'([^']+)'([^\[]*)items:\[(.*?)\]\},?\s*(?=\/\*|\{sec:|\]\s*;)/s", $nav, $secs, PREG_SET_ORDER);

    foreach ($secs as $sec) {
        preg_match_all("/\['([a-z0-9-]+)','((?:[^'\\\\]|\\\\.)*)'/i", $sec[3], $items, PREG_SET_ORDER);

        // buildNav renders a one-item section as a bare top-level link with no
        // .nav-group wrapper, unless it asks for `group:true`. A flat row shows
        // no group name, so there is nothing for a breadcrumb to contradict.
        $visibleGroup = count($items) > 1 || str_contains($sec[2], 'group:true');

        foreach ($items as $it) {
            $out[] = [
                'id' => $it[1],
                'label' => html_entity_decode($it[2], ENT_QUOTES | ENT_HTML5),
                'group' => $sec[1],
                'source' => 'NAV in app.blade.php',
                'visible_group' => $visibleGroup,
                'anchors' => [],
            ];
        }
    }

    foreach (navAuditPartials() as $partial) {
        $p = navAuditPartialSrc($partial);
        if (! str_contains($p, 'function addNavEntry()')) {
            continue;
        }

        $body = navAuditBlock($p, strpos($p, 'function addNavEntry()'));

        /*
         * An INERT registration adds no row, so it is not one.
         *
         * coupon-usage-screen.blade.php is the case: its addNavEntry() is a
         * bare `return;`, kept as a function rather than deleted so the call
         * sites and the reason stay visible. The screen is reached from a link
         * inside the coupon editor instead. Reading it as a row would give the
         * sidebar an entry with no label and no anchors — which is how this
         * check first reported it, and the report was wrong, not the code.
         *
         * A registration is live in one of two shapes now: the shared helper
         * (`kbbAddNavEntry({...})`, the supported one) or the hand-rolled
         * button a partial builds itself. Both are read here, because the
         * point of every guard below is the sidebar the owner actually sees,
         * and during the conversion both shapes are in the tree at once.
         */
        $usesHelper = str_contains($body, 'kbbAddNavEntry(');
        if (! $usesHelper && ! str_contains($body, 'nav-item')) {
            continue;
        }

        preg_match("/var SCREEN\s*=\s*'([^']+)'/", $p, $id);

        if ($usesHelper) {
            /*
             * The helper's options object says everything this audit needs,
             * in one place and in the partial's own words:
             *
             *   screen  the row's id (normally the SCREEN constant)
             *   label   what the owner reads
             *   group   the NAV section it joins — never one it invents
             *   after   preferred anchor id, or an ordered list of them
             */
            $call = navAuditBlock($body, strpos($body, 'kbbAddNavEntry('), '{', '}');

            preg_match("/screen:\s*'([^']+)'/", $call, $screenLit);
            preg_match("/label:\s*'((?:[^'\\\\]|\\\\.)*)'/", $call, $label);
            preg_match("/group:\s*'([^']+)'/", $call, $group);

            // `after` is one id or an ordered array of them; both reduce to a
            // list, which is what every anchor guard below already expects.
            $anchors = [];
            if (preg_match("/after:\s*(\[[^\]]*\]|'[^']*')/", $call, $after)) {
                preg_match_all("/'([a-z0-9-]+)'/i", $after[1], $ids);
                $anchors = $ids[1];
            }

            $out[] = [
                'id' => $screenLit[1] ?? ($id[1] ?? ''),
                'label' => html_entity_decode($label[1] ?? '', ENT_QUOTES | ENT_HTML5),
                'group' => $group[1] ?? '',
                'source' => 'partials/'.$partial.'.blade.php',
                'visible_group' => true,
                'anchors' => array_values(array_unique($anchors)),
                'helper' => true,
            ];

            continue;
        }

        preg_match("/<span>(.*?)<\/span>/", $body, $label);
        preg_match_all('/\[data-go="([a-z0-9-]+)"\]/', $body, $anchors);
        preg_match('/nav-group\[data-sec="([^"]+)"\]/', $p, $group);

        $out[] = [
            'id' => $id[1] ?? '',
            'label' => html_entity_decode($label[1] ?? '', ENT_QUOTES | ENT_HTML5),
            'group' => $group[1] ?? '',
            'source' => 'partials/'.$partial.'.blade.php',
            'visible_group' => true,
            'anchors' => array_values(array_unique($anchors[1])),
            'helper' => false,
        ];
    }

    return $out;
}

/** TITLES: id => [breadcrumb group, page title]. */
function navAuditTitles(): array
{
    $t = navAuditBlock(navAuditSrc(), strpos(navAuditSrc(), 'const TITLES='));
    preg_match_all("/'?([a-zA-Z0-9-]+)'?\s*:\s*\['([^']*)','((?:[^'\\\\]|\\\\.)*)'\]/", $t, $m, PREG_SET_ORDER);

    $out = [];
    foreach ($m as $hit) {
        $out[$hit[1]] = [
            html_entity_decode($hit[2], ENT_QUOTES | ENT_HTML5),
            html_entity_decode($hit[3], ENT_QUOTES | ENT_HTML5),
        ];
    }

    return $out;
}

/** Keys of a `const NAME={...}` map. */
function navAuditMapKeys(string $name): array
{
    $b = navAuditBlock(navAuditSrc(), strpos(navAuditSrc(), 'const '.$name.'='));
    preg_match_all("/'([a-z0-9-]+)'\s*:/i", $b, $m);

    return $m[1];
}

/**
 * Every id this console can actually put on the screen, from all five routes
 * into #content: go()'s dispatch object, the iframe maps, the live-wiring
 * override, the `p-` placeholders, and each partial's own SCREEN.
 */
function navAuditScreenIds(): array
{
    static $ids = null;
    if ($ids !== null) {
        return $ids;
    }

    $src = navAuditSrc();

    $at = strpos($src, '[id]||renderDash)()');
    $dispatch = navAuditBlock($src, strrpos(substr($src, 0, $at), '({') ?: 0);
    preg_match_all("/'?([a-zA-Z0-9-]+)'?\s*:\s*render[A-Za-z]+/", $dispatch, $d);

    $live = navAuditBlock($src, strpos($src, 'var _go = window.go;'), '{', '}');
    preg_match_all("/id\s*===\s*'([a-z0-9-]+)'/i", $live, $l);

    $screens = array_merge(
        $d[1],
        $l[1],
        navAuditMapKeys('FRAME_SRC'),
        navAuditMapKeys('REV_SRC'),
        array_keys(navAuditTitles()),
    );

    foreach (navAuditPartials() as $partial) {
        $p = navAuditPartialSrc($partial);
        preg_match_all("/var SCREENS?\s*=\s*'([^']+)'/", $p, $m);
        foreach ($m[1] as $one) {
            $screens[] = $one;
        }
        // review-bulk-screens declares `var SCREENS = [ADD, LIKES]`.
        preg_match_all("/var (?:ADD|LIKES)\s*=\s*'([^']+)'/", $p, $m2);
        foreach ($m2[1] as $one) {
            $screens[] = $one;
        }
    }

    return $ids = array_values(array_unique($screens));
}

/* ───────────────────────────────── the guards ───────────────────────────────── */

it('assembles a sidebar worth auditing', function () {
    expect(navAuditEntries())->not->toBeEmpty()
        ->and(count(navAuditEntries()))->toBeGreaterThan(40);

    // and the injected rows are in there, or the parse silently audits half a
    // sidebar and passes everything.
    $injected = array_filter(navAuditEntries(), fn ($e) => $e['source'] !== 'NAV in app.blade.php');
    expect(count($injected))->toBeGreaterThanOrEqual(5);
});

/* ─── 1. one row per id ─── */

it('gives every sidebar entry a data-go that nothing else claims', function () {
    $byId = [];
    foreach (navAuditEntries() as $e) {
        $byId[$e['id']][] = $e['source'];
    }

    $clashes = [];
    foreach ($byId as $id => $sources) {
        if (count($sources) > 1) {
            $clashes[] = sprintf('  data-go="%s" registered %d times: %s', $id, count($sources), implode(' + ', $sources));
        }
    }

    expect($clashes === [])->toBeTrue(
        count($clashes)." sidebar id(s) registered more than once — two rows, one screen:\n".implode("\n", $clashes)."\n"
    );
});

/* ─── 2. one row per label ─── */

it('gives every sidebar entry a label that nothing else uses', function () {
    /*
     * THE OWNER'S BUG, in the shape a test can hold. Two rows reading the same
     * thing is not a cosmetic problem: the owner clicks the first one, does not
     * find what they came for, and reports the feature missing.
     *
     * Compared case-insensitively and whitespace-collapsed, because "Manage
     * coupons" and "Manage  Coupons" are the same row to the person reading it.
     */
    $byLabel = [];
    foreach (navAuditEntries() as $e) {
        $key = strtolower((string) preg_replace('/\s+/', ' ', trim($e['label'])));
        $byLabel[$key][] = $e['id'].' ('.$e['source'].')';
    }

    $clashes = [];
    foreach ($byLabel as $label => $rows) {
        if (count($rows) > 1) {
            $clashes[] = sprintf("  \"%s\" appears %d times:\n    %s", $label, count($rows), implode("\n    ", $rows));
        }
    }

    expect($clashes === [])->toBeTrue(
        count($clashes)." sidebar label(s) used by more than one entry — the owner cannot tell which does what:\n".implode("\n", $clashes)."\n"
    );
});

/* ─── 3. every row opens something ─── */

it('points every sidebar entry at a screen that exists', function () {
    $screens = navAuditScreenIds();

    $dangling = [];
    foreach (navAuditEntries() as $e) {
        if (str_starts_with($e['id'], 'p-')) {
            continue;   // renderPlaceholder — an honest "isn't installed yet" card
        }
        if (! in_array($e['id'], $screens, true)) {
            $dangling[] = sprintf('  %-16s registered by %s opens nothing — no renderer, no frame, no partial claims it', $e['id'], $e['source']);
        }
    }

    expect($dangling === [])->toBeTrue(
        count($dangling)." sidebar entr(ies) point at a screen id that does not exist:\n".implode("\n", $dangling)."\n"
    );
});

/* ─── 4. the row and the page agree ─── */

it('opens a page whose title and breadcrumb match the row that was clicked', function () {
    /*
     * The coupon bug's general form. A row labelled one thing that opens a page
     * headed another leaves the owner certain they clicked the wrong entry —
     * and, the second time, certain the right entry does not exist.
     *
     * The group half is only checked for rows that render inside a visible
     * .nav-group. A one-item section draws no group header, so its breadcrumb
     * has nothing on screen to contradict.
     */
    $titles = navAuditTitles();
    $wrong = [];

    foreach (navAuditEntries() as $e) {
        if (str_starts_with($e['id'], 'p-')) {
            continue;
        }

        // Injected rows set #crumb and #ptitle in their own partial rather than
        // through TITLES; those strings are read straight out of the file.
        if ($e['source'] !== 'NAV in app.blade.php') {
            $p = navAuditPartialSrc(substr($e['source'], strlen('partials/'), -strlen('.blade.php')));
            preg_match("/if \(title\) title\.textContent = '([^']*)'/", $p, $pt);
            preg_match("/if \(crumb\) crumb\.textContent = '([^']*)'/", $p, $pc);

            if (($pt[1] ?? null) !== null && $pt[1] !== $e['label']) {
                $wrong[] = sprintf('  %-16s row says "%s", page title says "%s"  [%s]', $e['id'], $e['label'], $pt[1], $e['source']);
            }
            if (($pc[1] ?? null) !== null && $e['group'] !== '' && $pc[1] !== $e['group']) {
                $wrong[] = sprintf('  %-16s row sits in group "%s", breadcrumb says "%s"  [%s]', $e['id'], $e['group'], $pc[1], $e['source']);
            }

            continue;
        }

        $t = $titles[$e['id']] ?? null;
        if ($t === null) {
            $wrong[] = sprintf('  %-16s has no TITLES entry, so the page falls back to the breadcrumb "Platform / %s"', $e['id'], $e['id']);

            continue;
        }

        if ($t[1] !== $e['label']) {
            $wrong[] = sprintf('  %-16s row says "%s", page title says "%s"', $e['id'], $e['label'], $t[1]);
        }
        if ($e['visible_group'] && $t[0] !== $e['group']) {
            $wrong[] = sprintf('  %-16s row sits under "%s", breadcrumb says "%s"', $e['id'], $e['group'], $t[0]);
        }
    }

    expect($wrong === [])->toBeTrue(
        count($wrong)." sidebar entr(ies) open a page that names itself differently:\n".implode("\n", $wrong)."\n"
    );
});

it('keeps the ALIASED_SCREENS allowlist honest', function () {
    /*
     * An allowlist nobody rechecks is a second place for the bug to hide. Each
     * entry must still be a real second id for a screen that is really there:
     * the alias must route, the screen it aliases must have a sidebar row, and
     * the alias must NOT have one of its own — because a second row is the
     * thing the allowlist exists to record the removal of.
     */
    $rows = array_column(navAuditEntries(), 'id');
    $screens = navAuditScreenIds();

    foreach (ALIASED_SCREENS as $alias => $real) {
        expect(in_array($alias, $screens, true))
            ->toBeTrue("ALIASED_SCREENS lists '{$alias}', but nothing in the console routes that id any more — drop the entry");

        expect(in_array($real, $rows, true))
            ->toBeTrue("ALIASED_SCREENS says '{$alias}' is an alias of '{$real}', but '{$real}' has no sidebar row");

        expect(in_array($alias, $rows, true))
            ->toBeFalse("ALIASED_SCREENS says '{$alias}' is an alias of '{$real}', yet '{$alias}' has a sidebar row of its own — that is two rows for one screen, which is the bug");

        // and the alias must not advertise itself under a different name
        $t = navAuditTitles();
        if (isset($t[$alias], $t[$real])) {
            expect($t[$alias][1])->toBe(
                $t[$real][1],
                "TITLES['{$alias}'] titles the page differently from TITLES['{$real}'], but they are the same screen"
            );
        }
    }

    foreach (NOT_IN_NAV as $id) {
        expect(in_array($id, navAuditScreenIds(), true))
            ->toBeTrue("NOT_IN_NAV lists '{$id}', which no longer exists — drop the entry");
        expect(in_array($id, $rows, true))
            ->toBeFalse("NOT_IN_NAV lists '{$id}', but it has a NAV row now — drop the entry");
    }
});

/* ─── 5. no screen can quietly leave the sidebar ─── */

it('never lets a screen drop out of the sidebar because an anchor was renamed', function () {
    /*
     * FOUND BY THE AUDIT, not by a failure. Every self-registering partial used
     * to end its anchor lookup with `if (!anchor) return;`. Rename one NAV
     * entry and the screen anchored to it stops appearing — no error, no
     * console warning, nothing. The owner cannot tell that from the screen
     * never having shipped, which is exactly the conclusion they drew about
     * the coupon editor.
     *
     * The guard: each partial's anchor chain must contain at least one id that
     * is in NAV. Not merely one that happens to exist — an id injected by
     * another partial only exists if that partial was @included first, so a
     * chain resting on one is an ordering accident. coupon-editor anchors on
     * 'coupon-usage' (injected) and that is fine, because the chain also
     * reaches 'orders', which is in NAV and always there.
     *
     * kbbAddNavEntry() (section 7) changed the CONSEQUENCE, not the rule. A
     * row registered through the helper no longer vanishes when its anchors
     * go: it falls back to the group it names, then to the sidebar itself, and
     * reports. A row still registered by hand does vanish. So the report below
     * says which of the two a given screen is facing — both are bugs, and
     * calling the milder one "vanishes" would be the kind of message that
     * teaches a reader to distrust the test.
     */
    $navIds = [];
    foreach (navAuditEntries() as $e) {
        if ($e['source'] === 'NAV in app.blade.php') {
            $navIds[] = $e['id'];
        }
    }

    $fragile = [];
    foreach (navAuditEntries() as $e) {
        if ($e['source'] === 'NAV in app.blade.php') {
            continue;
        }

        $inNav = array_values(array_intersect($e['anchors'], $navIds));
        if ($inNav === []) {
            $fragile[] = sprintf(
                "  %-16s [%s]\n      anchors on %s — none of which is in NAV, so this row exists only by @include order\n      consequence: %s",
                $e['id'],
                $e['source'],
                $e['anchors'] === [] ? '(nothing)' : implode(', ', $e['anchors']),
                ($e['helper'] ?? false)
                    ? "registered through kbbAddNavEntry(), so the row survives — it lands at the end of the '".$e['group']."' group instead of where it means to be, and the console says so"
                    : 'registered by hand, so the row is GONE from the sidebar with no error anywhere'
            );
        }
    }

    expect($fragile === [])->toBeTrue(
        count($fragile)." screen(s) rest on an anchor that is not in NAV, so renaming one row moves or removes another:\n".implode("\n", $fragile)."\n"
    );
});

it('resolves every anchor a partial names to a row that will already be there', function () {
    // The other half: an anchor naming an id nothing registers is dead weight
    // that reads like a working fallback. `[data-go="products"]` sat in
    // category-tree for months; there has never been a 'products' entry.
    $known = [];
    $stale = [];

    foreach (navAuditEntries() as $e) {
        if ($e['source'] === 'NAV in app.blade.php') {
            $known[] = $e['id'];
        }
    }

    foreach (navAuditEntries() as $e) {
        if ($e['source'] === 'NAV in app.blade.php') {
            continue;
        }
        $allowed = DEAD_ANCHORS[$e['id']] ?? [];

        foreach ($e['anchors'] as $a) {
            if (! in_array($a, $known, true) && ! in_array($a, $allowed, true)) {
                $stale[] = sprintf('  %s anchors on [data-go="%s"], which nothing registers before it  [%s]', $e['id'], $a, $e['source']);
            }
        }
        $known[] = $e['id'];   // later partials may anchor on this one
    }

    expect($stale === [])->toBeTrue(
        count($stale)." anchor(s) name a sidebar row that does not exist at that point:\n".implode("\n", $stale)."\n"
    );
});

it('keeps the DEAD_ANCHORS allowlist honest', function () {
    /*
     * A dead anchor is tolerable only while the same chain also reaches a live
     * NAV row — otherwise the screen is one rename away from silently leaving
     * the sidebar and the allowlist is hiding that. So: every listed anchor
     * must still be dead (a fixed one should be dropped, not carried), the
     * partial must still name it, and the chain must still have a live half.
     */
    $entries = [];
    $navIds = [];
    foreach (navAuditEntries() as $e) {
        $entries[$e['id']] = $e;
        if ($e['source'] === 'NAV in app.blade.php') {
            $navIds[] = $e['id'];
        }
    }

    foreach (DEAD_ANCHORS as $screen => $anchors) {
        expect($entries)->toHaveKey($screen);

        foreach ($anchors as $a) {
            expect(in_array($a, $anchors, true) && ! in_array($a, $navIds, true))
                ->toBeTrue("DEAD_ANCHORS says '{$screen}' anchors on a dead '{$a}', but '{$a}' is a real NAV row now — drop the entry");

            expect(in_array($a, $entries[$screen]['anchors'], true))
                ->toBeTrue("DEAD_ANCHORS says '{$screen}' anchors on '{$a}', but it does not any more — drop the entry");
        }

        expect(array_intersect($entries[$screen]['anchors'], $navIds))
            ->not->toBeEmpty("'{$screen}' now has nothing but dead anchors — it will leave the sidebar without an error");
    }
});

/* ─── 6. tabs say what they are for ─── */

it('says on the page what every set of tabs is for', function () {
    /*
     * THE OWNER'S QUESTION, generalised. They asked it of Catalog — what are
     * these tabs, and why are they one screen — and the answer went onto that
     * page. Twelve other screens have tab strips and the question is the same
     * on all of them.
     *
     * Three devices count as an answer, all already used in this console:
     *   - `ectabs-hint`, a caption under the strip;
     *   - the active tab describing itself beside its fields (`.description`);
     *   - a page-head paragraph that says what the screen is and how it splits.
     * A bare strip of tab names is not an answer, which is the point.
     */
    $missing = [];

    foreach (TABBED_SCREENS as $strip => $explains) {
        $stripBody = navAuditFn($strip);
        expect($stripBody)->not->toBe('', "TABBED_SCREENS names {$strip}(), which app.blade.php does not declare");

        $body = navAuditFn($explains);

        $hasHint = str_contains($body, 'ectabs-hint');
        $hasPerTab = str_contains($body, '.description');
        $hasHead = (bool) preg_match('/page-head.{0,400}?<p[^>]*>(.{60,}?)<\/p>/s', $body);

        if (! $hasHint && ! $hasPerTab && ! $hasHead) {
            $missing[] = sprintf('  %s() draws a tab strip; %s() never says what the tabs are for', $strip, $explains);
        }
    }

    expect($missing === [])->toBeTrue(
        count($missing)." tabbed screen(s) show the owner a row of tab names and no explanation:\n".implode("\n", $missing)."\n"
    );
});

it('lists every tab strip in the file, so a new one cannot skip the explanation', function () {
    // Completeness. Without this the previous test only guards the twelve
    // screens someone remembered, and the thirteenth ships bare.
    $src = navAuditSrc();

    preg_match_all('/function\s+([A-Za-z_$][\w$]*)\s*\(/', $src, $decl, PREG_OFFSET_CAPTURE);
    $fns = [];
    foreach ($decl[1] as $i => $hit) {
        $fns[] = [$hit[0], $decl[0][$i][1]];
    }

    $enclosing = function (int $off) use ($fns): string {
        $best = '(top level)';
        foreach ($fns as [$name, $at]) {
            if ($at > $off) {
                break;
            }
            $best = $name;
        }

        return $best;
    };

    $found = [];
    foreach (['class="subtabs"', 'class="ectabs'] as $needle) {
        $at = 0;
        while (($at = strpos($src, $needle, $at)) !== false) {
            $found[$enclosing($at)] = true;
            $at += strlen($needle);
        }
    }

    $unlisted = array_values(array_diff(array_keys($found), array_keys(TABBED_SCREENS)));

    expect($unlisted === [])->toBeTrue(
        'tab strip(s) drawn by '.implode(', ', $unlisted).
        ' are not in TABBED_SCREENS — add them with the function that explains their tabs'
    );

    // and the reverse: a listed screen that no longer has tabs is stale
    $gone = array_values(array_diff(array_keys(TABBED_SCREENS), array_keys($found)));
    expect($gone === [])->toBeTrue(
        'TABBED_SCREENS still lists '.implode(', ', $gone).', which draw no tab strip any more — drop them'
    );
});

/* ─── the consolidations this audit made, pinned ─── */

it('keeps Blog and Posts to a single sidebar row', function () {
    /*
     * Two rows, one screen: go() sent both 'blog' and 'posts' to renderPosts()
     * and FRAME_SRC pointed both at kbb-admin-blog.html. "Blog" opened a page
     * headed "Posts". Nothing was deleted to fix it — the 'blog' id still
     * routes, so an old link or bookmark lands on the same screen.
     */
    $rows = array_column(navAuditEntries(), 'id');

    expect(in_array('posts', $rows, true))->toBeTrue('the Blog Posts row is gone entirely');
    expect(in_array('blog', $rows, true))->toBeFalse('Blog and Posts are two sidebar rows again, opening the same screen');

    // still routable, so consolidating the menu did not remove the feature
    $live = navAuditBlock(navAuditSrc(), strpos(navAuditSrc(), 'var _go = window.go;'), '{', '}');
    expect(str_contains($live, "id==='blog'"))->toBeTrue("go('blog') no longer reaches the posts screen — #blog and ?go=blog are now dead links");
});

it('tells the two Import / Export screens apart', function () {
    /*
     * 'import' read "Import / Export" and 'rev-io' read "Export / Import" —
     * the same two words, reversed. One migrates the whole WooCommerce store;
     * the other moves reviews. Both now name what they carry.
     */
    $labels = [];
    foreach (navAuditEntries() as $e) {
        $labels[$e['id']] = $e['label'];
    }

    expect($labels['import'] ?? '')->toBe('Store Import / Export');
    expect($labels['rev-io'] ?? '')->toBe('Review Import / Export');

    // and the screens themselves agree, or the row is honest and the page is not
    expect(str_contains(navAuditSrc(), '<h2>Store Import / Export</h2>'))
        ->toBeTrue('the Store Import / Export screen still heads itself "Import / Export"');

    $rio = navAuditPartialSrc('reviews-io-screen');
    expect(str_contains($rio, 'Review Import / Export'))
        ->toBeTrue('the reviews screen still heads itself "Export / Import"');
});

it('puts the content screens in a Content group, where their breadcrumbs already said they were', function () {
    // Media Library, HTML Blocks and Blog Posts each set the breadcrumb to
    // 'Content' while sitting in the Store group. The group exists now.
    $groups = [];
    foreach (navAuditEntries() as $e) {
        $groups[$e['id']] = $e['group'];
    }

    foreach (['posts', 'htmlblocks', 'media'] as $id) {
        expect($groups[$id] ?? '')->toBe('Content', "'{$id}' left the Content group");
    }
});

it('gives the Catalog group a real .nav-group for the two screens that inject into it', function () {
    /*
     * The product editor and Categories & Brands both set the breadcrumb to
     * 'Catalog' and both call `.nav-group[data-sec="Catalog"]` to open their
     * group. No such group existed, so the breadcrumb named something the
     * sidebar did not have and the expand was a silent no-op.
     *
     * It holds one built-in row, so it also needs `group:true` — buildNav draws
     * a one-item section as a bare link with no .nav-group at all, and the two
     * injected rows would land outside any group.
     */
    $nav = navAuditBlock(navAuditSrc(), strpos(navAuditSrc(), 'const NAV='), '[', ']');

    expect(str_contains($nav, "{sec:'Catalog',group:true,"))
        ->toBeTrue("the Catalog section lost `group:true`, so buildNav will flatten it and the injected Catalog rows will have no group to land in");

    expect(str_contains(navAuditFn('buildNav'), 'g.items.length===1 && !g.group'))
        ->toBeTrue('buildNav no longer honours `group:true`, so a one-item section renders flat however it is declared');

    $groups = [];
    foreach (navAuditEntries() as $e) {
        $groups[$e['id']] = $e['group'];
    }

    foreach (['catalog', 'product-editor', 'category-tree'] as $id) {
        expect($groups[$id] ?? '')->toBe('Catalog', "'{$id}' is no longer in the Catalog group");
    }
});

/* ─── 7. one shared helper, and no partial left registering by hand ───
 *
 * Written by the lane that built kbbAddNavEntry(). Section 5 above found the
 * weakness and could only describe it: five partials, five copies of "find an
 * anchor, build a button, insert it", and five copies of `if (!anchor) return;`
 * — so renaming ONE row in NAV silently removed a DIFFERENT screen from the
 * menu. Three of the five chains had already drifted onto anchors that do not
 * exist, and nothing anywhere said so.
 *
 * The fix is one helper in app.blade.php that every partial calls. These
 * guards are what stop the five copies coming back one file at a time.
 */

/*
 * HAND_ROLLED_PENDING — partials that still build their own sidebar row
 * because THIS lane may not edit them, not because hand-rolling is allowed.
 * Each is being redesigned by another lane right now, so a conflicting edit
 * here would be thrown away.
 *
 * The value is the replacement call, verbatim. It is written down rather than
 * described so the integrator can paste it in without re-deriving the group
 * and the anchor chain, and so that a reader can see exactly how much of each
 * body is the same four lines as every other one.
 *
 * The honesty guard below fails the moment one of these is converted, so the
 * entry cannot outlive the reason for it.
 */
const HAND_ROLLED_PENDING = [
    'coupon-editor-screen' => "window.kbbAddNavEntry({screen: SCREEN, label: 'Coupons', icon: <the existing path markup>, group: 'Store', after: ['order-new', 'orders']}) — then keep the two lines that remove the old 'coupon-usage' row, which the helper does not do",
    'manual-order-screen' => "window.kbbAddNavEntry({screen: SCREEN, label: 'New Order', icon: <the existing path markup>, group: 'Store', after: 'orders'})",
];

/** kbbAddNavEntry()'s body with comments stripped. */
function navAuditHelperCode(): string
{
    static $code = null;
    if ($code !== null) {
        return $code;
    }

    $body = navAuditFn('kbbAddNavEntry');

    // The helper's own comments quote `if (!anchor) return;` to explain what it
    // replaced. A bare substring match would read that explanation as a relapse.
    $code = (string) preg_replace('#/\*.*?\*/#s', ' ', $body);
    $code = (string) preg_replace('#//[^\n]*#', ' ', $code);

    return $code;
}

/** Partial name (no .blade.php) for an entry's source file. */
function navAuditPartialOf(array $entry): string
{
    return str_replace(['partials/', '.blade.php'], '', $entry['source']);
}

it('gives the console one shared way to add a sidebar row', function () {
    $code = navAuditHelperCode();

    expect($code)->not->toBe('', 'app.blade.php no longer declares kbbAddNavEntry() — every partial is back to hand-rolling its own sidebar row');

    expect(str_contains(navAuditSrc(), 'window.kbbAddNavEntry = kbbAddNavEntry;'))
        ->toBeTrue('kbbAddNavEntry is not on window, so the partials — which run in their own IIFEs — cannot reach it');

    // It builds the row, and only the row.
    expect(substr_count($code, 'createElement('))
        ->toBe(1, 'kbbAddNavEntry builds more than one element. It may build the row button and nothing else: a group invented here would be a second place that decides the sidebar shape, and NAV, TITLES and the breadcrumbs would all still be using the first one.');

    expect(str_contains($code, "createElement('button')"))
        ->toBeTrue('kbbAddNavEntry no longer builds a <button>, so an injected row is a different element from a built-in one and .nav-item styling and the go() click wiring will not match it');

    expect(str_contains($code, "b.className = 'nav-item'"))
        ->toBeTrue('kbbAddNavEntry no longer gives the row the nav-item class, so an injected row will not look like a sidebar row');

    // Drawn with ic(), the same helper navItemHTML() uses. This is the whole
    // point of having one function: the icon markup is not copied five times.
    expect(str_contains($code, 'ic(o.icon'))
        ->toBeTrue('kbbAddNavEntry no longer draws its icon with ic(), so an injected row and a NAV row are two copies of the icon markup again, free to drift');

    // It JOINS a group; it never makes one.
    expect(str_contains($code, '.nav-group[data-sec="\' + o.group + \'"] .nav-sub'))
        ->toBeTrue('kbbAddNavEntry no longer looks up the group a row names, so `group` does nothing and every injected row lands wherever its anchor happens to be');
});

it('refuses to add the same sidebar row twice', function () {
    /*
     * Every partial registers on DOMContentLoaded AND immediately if it loaded
     * after that event, so the second call is the normal path, not a fault. If
     * the helper stopped checking, the owner would get two identical rows —
     * which is the shape of the original complaint, two entries where one
     * screen was meant.
     */
    $code = navAuditHelperCode();

    $look = strpos($code, '[data-go="\' + screen');
    $make = strpos($code, 'createElement(');

    expect($look !== false)->toBeTrue('kbbAddNavEntry never looks for an existing row with this screen id');
    expect($make !== false && $look < $make)
        ->toBeTrue('kbbAddNavEntry builds the row before checking whether the sidebar already has one, so a partial that registers twice adds the row twice');

    // Scoped to the sidebar: a screen may draw its own [data-go] buttons inside
    // #content, and those are links, not rows. Matching one of those would make
    // the registration a no-op and the screen would have no row at all.
    expect(str_contains($code, 'nav.querySelector(\'[data-go="\' + screen'))
        ->toBeTrue('the duplicate check is no longer scoped to #nav, so a [data-go] button drawn inside a screen counts as a sidebar row and the real row is never added');
});

it('makes the helper report, not return silently, when it cannot place a row', function () {
    /*
     * THE POINT OF THE WHOLE EXERCISE.
     *
     * `if (!anchor) return;` did not fail — it succeeded at doing nothing. No
     * error, no warning, no row. The owner cannot tell that from a screen that
     * never shipped, and twice concluded exactly that.
     *
     * So every path out of this helper that does not leave a row in the sidebar
     * must say so, naming the screen, and the two degraded placements (wrong
     * section, or pinned to the bottom) must say so too.
     */
    $code = navAuditHelperCode();

    // 1. Nothing returns null without reporting first.
    $chunks = explode('return null;', $code);
    array_pop($chunks);   // the text after the last one

    expect($chunks)->not->toBeEmpty('kbbAddNavEntry no longer has a give-up path at all — if it cannot return null it is swallowing something else instead');

    foreach ($chunks as $i => $before) {
        expect(str_contains(substr($before, -500), 'console.error('))
            ->toBeTrue(sprintf(
                'kbbAddNavEntry has a `return null;` (#%d) with no console.error before it. That is the silent `if (!anchor) return;` this helper exists to replace: a screen leaves the sidebar and nothing anywhere says so.',
                $i + 1
            ));
    }

    // 2. Both degraded placements report as well. Four reports: no screen id,
    //    no #nav, group missing, nothing found at all.
    expect(substr_count($code, 'console.error('))
        ->toBeGreaterThanOrEqual(4, 'kbbAddNavEntry lost one of its reports. A row that landed in the wrong section, or got pinned to the bottom of the sidebar because its group is gone, is a failure the owner can see but not explain — the console error is the explanation.');

    // 3. Every report names the screen. "Could not place a row" is not
    //    actionable; "could not place product-editor" is.
    preg_match_all('/console\.error\((.*?)\);/s', $code, $m);
    foreach ($m[1] as $arg) {
        expect(str_contains($arg, 'screen'))
            ->toBeTrue('a kbbAddNavEntry console.error does not name the screen it is about, so the message says something is wrong without saying which screen is missing: '.trim(substr($arg, 0, 90)));
    }

    $named = 0;
    foreach ($m[1] as $arg) {
        if (str_contains($arg, "' + screen + '")) {
            $named++;
        }
    }
    expect($named)->toBeGreaterThanOrEqual(3, 'kbbAddNavEntry stopped interpolating the screen id into its reports — the messages no longer name the screen that went missing');

    // 4. And the last resort still ADDS the row. Reporting a failure is not a
    //    licence to leave the screen unreachable: visible and wrong beats
    //    invisible, which is the rule the product editor was fixed under.
    expect(str_contains($code, 'nav.appendChild(b)'))
        ->toBeTrue('kbbAddNavEntry lost its last-resort placement. A screen whose group and anchors are all gone is now invisible again, which is the bug, not the fix.');
});

it('registers every sidebar row through the one shared helper', function () {
    $offenders = [];

    foreach (navAuditEntries() as $e) {
        if ($e['source'] === 'NAV in app.blade.php' || ($e['helper'] ?? false)) {
            continue;
        }
        if (array_key_exists(navAuditPartialOf($e), HAND_ROLLED_PENDING)) {
            continue;
        }

        $offenders[] = sprintf(
            "  %-16s [%s]\n      builds its own <button>, its own copy of the icon markup and its own anchor lookup",
            $e['id'],
            $e['source']
        );
    }

    expect($offenders === [])->toBeTrue(
        count($offenders)." partial(s) register a sidebar row by hand instead of calling kbbAddNavEntry():\n".
        implode("\n", $offenders)."\n".
        "Each hand-rolled copy is a place `if (!anchor) return;` can come back, and a place the icon markup and the class name can drift.\n"
    );
});

it('keeps the HAND_ROLLED_PENDING allowlist honest', function () {
    // The allowlist exists because another lane owns those files today. The
    // moment one is converted the entry is a lie about the tree, and a reader
    // would believe there is still a hand-rolled registration to fix.
    $byPartial = [];
    foreach (navAuditEntries() as $e) {
        if ($e['source'] !== 'NAV in app.blade.php') {
            $byPartial[navAuditPartialOf($e)] = $e;
        }
    }

    foreach (HAND_ROLLED_PENDING as $partial => $replacement) {
        expect($byPartial)->toHaveKey($partial);

        expect(($byPartial[$partial]['helper'] ?? false) === false)
            ->toBeTrue("HAND_ROLLED_PENDING still lists '{$partial}', but it calls kbbAddNavEntry() now — drop the entry, and drop its DEAD_ANCHORS line with it");

        expect(str_contains($replacement, 'kbbAddNavEntry('))
            ->toBeTrue("HAND_ROLLED_PENDING lists '{$partial}' without the replacement call the integrator is supposed to apply");
    }
});

it('leaves no partial answering a missing anchor by disappearing', function () {
    /*
     * The line itself, in any spelling. manual-order wrote it as
     * `if (!orders) return;` and coupon-editor as `if (!anchor) return;`; they
     * are the same bug with a different variable name, so this matches the
     * shape rather than the word.
     */
    $silent = [];

    foreach (navAuditPartials() as $partial) {
        $p = navAuditPartialSrc($partial);
        if (! str_contains($p, 'function addNavEntry()')) {
            continue;
        }

        $body = navAuditBlock($p, strpos($p, 'function addNavEntry()'));

        // Comments stripped: several of these bodies explain the bug by quoting
        // the line that caused it, and a bare substring match reads an
        // explanation as a relapse.
        $code = (string) preg_replace('#/\*.*?\*/#s', ' ', $body);
        $code = (string) preg_replace('#//[^\n]*#', ' ', $code);

        if (! preg_match('/if\s*\(\s*!\s*[A-Za-z_$][\w$]*\s*\)\s*return\s*;/', $code, $hit)) {
            continue;
        }
        if (array_key_exists($partial, HAND_ROLLED_PENDING)) {
            continue;
        }

        $silent[] = sprintf('  %-28s %s', $partial.':', trim($hit[0]));
    }

    expect($silent === [])->toBeTrue(
        count($silent)." partial(s) still answer a missing anchor by removing themselves from the sidebar, silently:\n".
        implode("\n", $silent)."\n".
        "Call kbbAddNavEntry() instead: it falls back to the group, then to the sidebar itself, and reports what it could not find.\n"
    );
});

/*
 * TAB_EXPLANATION_PENDING — partials that draw a tab strip and do not yet say
 * what the tabs are for, listed only because this lane may not edit the file.
 * The value is the sentence to add, written out so the integrator can paste it
 * rather than invent a second answer to the owner's question.
 */
const TAB_EXPLANATION_PENDING = [
    'coupon-editor-screen' => 'Three tabs, one coupon: nothing is saved until you press Save, whichever tab you are looking at. <b>General</b> is the code itself, what it takes off and when it expires; <b>Usage restriction</b> is what it may be spent on — a minimum spend, particular products or categories; <b>Usage limits</b> is how many times it may be redeemed in total and per customer. A rule set on a tab you are not looking at still applies.',
];

it('says on the page what a partial screen\'s tabs are for, too', function () {
    /*
     * THE OWNER'S QUESTION AGAIN, one directory over.
     *
     * The two guards in section 6 read app.blade.php, because that is where
     * the twelve tab strips they were written for live. Screens that ship as
     * their own partial were simply not looked at, and both of the tab strips
     * down here turned out to be bare — the gap was invisible rather than
     * absent, which is the same failure mode in the audit itself.
     *
     * A tab strip is a <div class="…-tabs"> whose buttons carry data-tab. The
     * explanation is `ectabs-hint`, the caption class the console already uses
     * for this, so an explained strip looks the same wherever it is.
     */
    $bare = [];

    foreach (navAuditPartials() as $partial) {
        $p = navAuditPartialSrc($partial);

        if (! preg_match('/class="[a-z0-9-]*tabs"/', $p) || ! str_contains($p, 'data-tab="')) {
            continue;
        }
        if (str_contains($p, 'ectabs-hint')) {
            continue;
        }
        if (array_key_exists($partial, TAB_EXPLANATION_PENDING)) {
            continue;
        }

        $bare[] = sprintf('  %s draws a tab strip and never says what the tabs are, or why they are one screen', $partial);
    }

    expect($bare === [])->toBeTrue(
        count($bare)." partial screen(s) show the owner a row of tab names and no explanation:\n".
        implode("\n", $bare)."\n".
        "Add a <p class=\"ectabs-hint\"> under the strip, the same device the explained screens in app.blade.php use.\n"
    );
});

it('keeps the TAB_EXPLANATION_PENDING allowlist honest', function () {
    foreach (TAB_EXPLANATION_PENDING as $partial => $sentence) {
        $p = navAuditPartialSrc($partial);

        expect(preg_match('/class="[a-z0-9-]*tabs"/', $p))
            ->toBe(1, "TAB_EXPLANATION_PENDING lists '{$partial}', which draws no tab strip any more — drop the entry");

        expect(str_contains($p, 'ectabs-hint'))
            ->toBeFalse("TAB_EXPLANATION_PENDING still lists '{$partial}', but it explains its tabs now — drop the entry");

        expect(strlen($sentence) > 120)
            ->toBeTrue("TAB_EXPLANATION_PENDING gives '{$partial}' no usable sentence, so the entry records a gap without closing it");
    }
});

it('keeps the product editor out of the sidebar only over its own dead body', function () {
    /*
     * Kept from the lane that first fixed this one file, now expressed against
     * the helper the fix moved into. The ladder it describes — preferred
     * anchor, then the Catalog group, then the sidebar root — is no longer
     * written in product-editor-screen.blade.php; it is written once, in
     * kbbAddNavEntry(), and this is what pins it there.
     */
    $pe = navAuditPartialSrc('product-editor-screen');
    $body = navAuditBlock($pe, strpos($pe, 'function addNavEntry()'));

    expect(str_contains($body, 'kbbAddNavEntry('))
        ->toBeTrue('the product editor hand-rolls its sidebar row again');

    expect(str_contains($body, "group:  'Catalog'") || str_contains($body, "group: 'Catalog'"))
        ->toBeTrue('the product editor no longer names the Catalog group, so it can land in the Store group beside Orders again — which is not what it is');

    $code = navAuditHelperCode();

    expect(str_contains($code, 'sub.appendChild(b)'))
        ->toBeTrue('kbbAddNavEntry lost its fallback into the named group, so a renamed anchor moves the row out of the section its own breadcrumb claims');

    expect(str_contains($code, 'nav.appendChild(b)'))
        ->toBeTrue('kbbAddNavEntry lost its last-resort append — visible and wrong beats invisible');
});


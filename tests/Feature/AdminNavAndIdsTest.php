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
 * existed. Harmless only for as long as the SAME chain also reaches a row that
 * is in NAV, which the guard below re-checks rather than assumes.
 *
 * Listed rather than fixed because category-tree-screen.blade.php belongs to
 * another lane. It reads `[data-go="catalog"] || [data-go="products"]`; there
 * has never been a 'products' entry in this console, so the second half has
 * always been decoration. It reads like a working fallback, which is the only
 * reason it is worth writing down: the next person to rename 'catalog' will
 * believe there is a net under them.
 */
const DEAD_ANCHORS = [
    'category-tree' => ['products'],

    // And already copied once: brands-editor-screen.blade.php, which landed
    // after this audit began, anchors `category-tree || catalog || products`.
    // The live half is 'catalog', so the screen appears; the third is the same
    // decoration inherited from the file it was modelled on. Worth recording
    // precisely because it spread — the pattern is what needs fixing, in one
    // shared helper, not each copy of it.
    'brands-manager' => ['products'],

    // Created by the coupon fix itself, and worth knowing about. The editor
    // anchors `coupon-usage || order-new || orders`; making the usage screen's
    // registration inert turned the FIRST link of that chain into a dead one.
    // Nothing breaks — 'orders' is in NAV and the editor still lands beside it
    // — but the editor no longer sits where the comment above it says it does,
    // and the next person to read that chain will believe it does.
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
         */
        if (! str_contains($body, 'nav-item')) {
            continue;
        }

        preg_match("/var SCREEN\s*=\s*'([^']+)'/", $p, $id);
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
     * FOUND BY THE AUDIT, not by a failure. Every self-registering partial ends
     * its anchor lookup with `if (!anchor) return;`. Rename one NAV entry and
     * the screen anchored to it stops appearing — no error, no console warning,
     * nothing. The owner cannot tell that from the screen never having shipped,
     * which is exactly the conclusion they drew about the coupon editor.
     *
     * The guard: each partial's anchor chain must contain at least one id that
     * is in NAV. Not merely one that happens to exist — an id injected by
     * another partial only exists if that partial was @included first, so a
     * chain resting on one is an ordering accident. coupon-editor anchors on
     * 'coupon-usage' (injected) and that is fine, because the chain also
     * reaches 'orders', which is in NAV and always there.
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
                "  %-16s [%s]\n      anchors on %s — none of which is in NAV, so this row exists only by @include order",
                $e['id'],
                $e['source'],
                $e['anchors'] === [] ? '(nothing)' : implode(', ', $e['anchors'])
            );
        }
    }

    expect($fragile === [])->toBeTrue(
        count($fragile)." screen(s) can vanish from the sidebar without an error:\n".implode("\n", $fragile)."\n"
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

it('never lets the product editor answer a missing anchor by disappearing', function () {
    // The one injecting partial this lane owns. The rest still end in
    // `if (!anchor) return;` and are named in the lane report.
    $pe = navAuditPartialSrc('product-editor-screen');
    $body = navAuditBlock($pe, strpos($pe, 'function addNavEntry()'));

    // Comments stripped: the body explains the bug by quoting the line that
    // caused it, and a bare substring match reads its own explanation as a
    // relapse.
    $code = (string) preg_replace('#/\*.*?\*/#s', ' ', $body);
    $code = (string) preg_replace('#//[^\n]*#', ' ', $code);

    expect(preg_match('/if \(!anchor\) return;/', $code))
        ->toBe(0, 'the product editor can silently leave the sidebar again');

    expect(str_contains($body, '.nav-group[data-sec="Catalog"] .nav-sub'))
        ->toBeTrue('the product editor lost its fallback into the Catalog group');

    expect(str_contains($body, "querySelector('#nav')"))
        ->toBeTrue('the product editor lost its last-resort append — visible and wrong beats invisible');
});


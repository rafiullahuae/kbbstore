<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Support\AdminSearchIndex;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The sidebar search (Lane SR): a box at the top of the admin's left menu that
 * finds any page, tab, section or setting and opens it, lit up for a moment.
 *
 * THE OWNER: "one super top function to our left menu of the backend app. to
 * find anything in the menu ... give results with proper links to that
 * specific area / page, and highlight it for a second" -- and "the code must
 * not put load on admin side at all, must be secure, super light, optimized
 * and bugs free."
 *
 * Each case says what it would have looked like on the console if it were
 * missing, and how to make it go red.
 */
function srPartial(): string
{
    static $s = null;

    return $s ??= (string) file_get_contents(resource_path('views/admin/partials/admin-search.blade.php'));
}

function srApp(): string
{
    static $s = null;

    return $s ??= (string) file_get_contents(resource_path('views/admin/app.blade.php'));
}

/** The partial's script: everything between its <script> and </script>. */
function srScript(): string
{
    $p = srPartial();
    $a = strpos($p, '<script>');
    $b = strrpos($p, '</script>');

    expect($a)->not->toBeFalse()->and($b)->not->toBeFalse();

    return substr($p, $a, $b - $a);
}

/**
 * Every screen the console can route to: every sidebar row in
 * App\Support\AdminNav, the pinned Console row and the keys of `const TITLES={...}`. Derived, not listed, so a lane that adds a screen
 * cannot forget this test exists.
 *
 * @return list<string>
 */
function srSidebarIds(): array
{
    $src = srApp();

    // The sidebar's rows: App\Support\AdminNav (Lane AP), the one definition
    // the server renders #nav from -- the old NAV and LATE_NAV literals both.
    // A `pending` row's screen (and its search entry) arrives with its lane.
    $nav = [1 => array_keys(array_filter(\App\Support\AdminNav::rows(), fn ($r) => empty($r['pending'])))];
    $late = [1 => []];

    preg_match_all('/class="side-pin"><button class="nav-item" data-go="([a-z0-9-]+)"/', $src, $pin);

    // TITLES too: a partial that registers its own row with a variable id
    // (spotted, the email screens) cannot be read statically, but every id
    // that routes at all has a TITLES entry -- "an id that is not in TITLES
    // does not route" -- so this is the complete list.
    $titlesAt = strpos($src, 'const TITLES={');
    $titlesEnd = strpos($src, '};', (int) $titlesAt);
    expect($titlesAt)->not->toBeFalse()->and($titlesEnd)->not->toBeFalse();
    $titles = (string) preg_replace('#/\*.*?\*/#s', '', substr($src, $titlesAt, $titlesEnd - $titlesAt));
    preg_match_all("/[{,]\s*(?:'([a-z0-9-]+)'|([a-z][a-z0-9]*))\s*:\s*\[/", $titles, $t);

    $ids = array_values(array_unique(array_filter(array_merge($nav[1], $late[1], $pin[1], $t[1], $t[2]))));

    // A parse that found almost nothing would make the coverage case vacuous.
    expect(count($ids))->toBeGreaterThan(60);

    return $ids;
}

/** The text an admin screen could draw, with entities and JS escapes undone. */
function srAdminSourceText(): string
{
    static $s = null;

    if ($s !== null) {
        return $s;
    }

    $files = array_merge(
        glob(resource_path('views/admin/*.blade.php')) ?: [],
        glob(resource_path('views/admin/partials/*.blade.php')) ?: [],
        iterator_to_array((function () {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->getExtension() === 'php' && ! str_ends_with($f->getPathname(), 'AdminSearchIndex.php')) {
                    yield $f->getPathname();
                }
            }
        })(), false),
    );

    $text = '';
    foreach ($files as $f) {
        $text .= "\n".file_get_contents($f);
    }

    $text = preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', fn ($m) => mb_chr(hexdec($m[1]), 'UTF-8'), $text);
    $text = str_replace(["\\'", '\\"'], ["'", '"'], (string) $text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    return $s = (string) preg_replace('/\s+/u', ' ', $text);
}

/* ------------------------------------------------------------------------
 | 1. Wired once, in the sidebar
 |------------------------------------------------------------------------*/

it('includes the search partial exactly once, inside the sidebar above the menu', function () {
    /*
     * THE DEFECT: 0 is a search built and never wired -- no box anywhere and
     * the owner told it shipped. 2 draws two boxes with the same ids, and the
     * second one's listeners are bound to the first one's input: Ctrl+K
     * focuses one box while the other shows results.
     *
     * THIS CASE IS RED IN THE LANE'S WORKTREE AND THAT IS THE HANDSHAKE (the
     * same as CartPanelScreenTest's). The integrator greens it by replacing
     *     <nav class="nav" id="nav"></nav>
     * with the closing verbatim directive, the @include, the reopening verbatim
     * directive, and that same <nav> line -- see docs/SR-ADMIN-APP-BLOCKS.md.
     *
     * MUTATION: include it twice, or at the end of the file with the screens,
     * and this is red.
     */
    $app = srApp();
    $inc = "@include('admin.partials.admin-search')";

    expect(substr_count($app, $inc))->toBe(1, 'admin.partials.admin-search must be included exactly once in app.blade.php');

    $at = strpos($app, $inc);
    $side = strpos($app, '<aside class="side" id="side">');
    // The sidebar itself is server-rendered by App\Support\AdminNav (Lane AP).
    $nav = strpos($app, '\App\Support\AdminNav::html(');
    $after = substr($app, $at + strlen($inc), (int) strpos($app, "\n@verbatim", $at) - $at - strlen($inc));

    expect($side)->toBeLessThan($at)
        ->and($at)->toBeLessThan($nav)
        // The sidebar is inside the console's raw region: without @endverbatim
        // before it, and no @verbatim reopened before it, the @include is
        // printed to the page as text.
        ->and(substr($app, $at - 13, 13))->toBe("@endverbatim\n")
        ->and($after)->not->toContain('@verbatim');
});

it('renders the box, the results list and the index into the real console, once', function () {
    /*
     * THE DEFECT THIS LANE HIT: the partial's own header comment said the
     * words "@endverbatim / @verbatim". Blade pulls verbatim blocks out BEFORE
     * it strips comments, so that sentence opened a raw block: the console
     * printed the partial's source -- "{{-- THE SIDEBAR SEARCH" -- into the
     * sidebar as visible text and the box never existed.
     *
     * Rendered through the partial on its own so this holds in the lane's
     * worktree too; the wired console is covered by the next case once the
     * include lands. MUTATION: put either directive name back in a comment.
     */
    $html = view('admin.partials.admin-search')->render();

    expect($html)->not->toContain('{{--')
        ->and($html)->not->toContain('THE SIDEBAR SEARCH')
        ->and(substr_count($html, 'id="ksrIn"'))->toBe(1)
        ->and(substr_count($html, 'id="ksrList"'))->toBe(1)
        ->and(substr_count($html, '<script type="application/json" id="ksrIndex">'))->toBe(1);

    preg_match('#<script type="application/json" id="ksrIndex">(.*?)</script>#s', $html, $m);
    $data = json_decode($m[1] ?? '', true);

    expect($data)->toBeArray()->and($data)->toHaveKey('header');
});

it('puts the box in the sidebar of the console as served', function () {
    if (substr_count(srApp(), "@include('admin.partials.admin-search')") !== 1) {
        $this->markTestSkipped('Not wired yet: the include is the integrator\'s (see the first case, which is red until it is).');
    }

    $owner = AdminUser::create([
        'name' => 'Search Owner', 'email' => 'sr-owner@example.test',
        'password' => 'password-long-enough', 'role' => 'owner',
    ]);
    $html = $this->actingAs($owner, 'admin')->get('/admin')->assertOk()->getContent();

    $side = strpos($html, '<aside class="side" id="side">');
    $box = strpos($html, 'id="ksr"');
    $nav = strpos($html, '<nav class="nav" id="nav"');

    expect(substr_count($html, 'id="ksrIn"'))->toBe(1)
        ->and($side)->toBeLessThan($box)
        ->and($box)->toBeLessThan($nav)
        ->and($html)->not->toContain('{{-- THE SIDEBAR SEARCH');
});

/* ------------------------------------------------------------------------
 | 2. The index covers the menu, and every entry is real
 |------------------------------------------------------------------------*/

it('has an index entry for every screen the sidebar can draw', function () {
    /*
     * THE DEFECT: a lane adds a screen to NAV or LATE_NAV and nothing else.
     * The page itself is still found (screens come from the drawn sidebar),
     * but none of its tabs or settings are, and nobody notices because a
     * search for the screen's name still works.
     *
     * The fix when this goes red is one line in AdminSearchIndex::CURATED --
     * `'new-id' => []` at the least, or its tabs and labels -- or a
     * SCHEMA_SCREENS row if the screen is drawn from a SCHEMA/TABS pair.
     * MUTATION: delete 'layout' from CURATED and this names it.
     */
    $index = AdminSearchIndex::build();
    $missing = array_values(array_diff(srSidebarIds(), array_keys($index)));

    expect($missing)->toBe([], 'Screens with no AdminSearchIndex entry: '.implode(', ', $missing));
});

it('finds the places the owner named, in the words he would use', function () {
    /*
     * The brief's own examples, checked against the index the browser gets.
     * MUTATION: drop 'Space inside each card' from CURATED['layout'], or
     * HeaderSettings from SCHEMA_SCREENS, and this is red.
     */
    $has = function (string $screen, string $tab, string $label): bool {
        [$tabs, $items] = AdminSearchIndex::build()[$screen] ?? [[], []];
        $t = $tab === '' ? -1 : array_search($tab, $tabs, true);

        return $t !== false && in_array([$t, $label], $items, true);
    };

    expect($has('layout', '', 'Space inside each card'))->toBeTrue()
        ->and($has('header', 'Navigation', 'Text size'))->toBeTrue()
        ->and($has('mail', 'Mail server (SMTP)', 'SMTP host'))->toBeTrue()
        ->and($has('payments', 'Pay in 4 with Tabby', 'Merchant code'))->toBeTrue()
        ->and($has('store-settings', 'Business', 'WhatsApp number'))->toBeTrue()
        ->and(AdminSearchIndex::build()['payments'][0])->toContain('Pay in 4 with Tabby');

    // And the two settings 2.60.376 added, which nobody added to this index:
    // they arrive from HeaderSettings::SCHEMA and SiteLayout::TABS. MUTATION:
    // drop SiteLayout from SCHEMA_SCREENS and the Brand page tab is gone.
    expect($has('header', 'Navigation', 'How it fills the row'))->toBeTrue()
        ->and(AdminSearchIndex::build()['sitelayout'][0])->toContain('Brand page');
    // Lane QK10 moved Cart Tracking to the top of the sidebar (the owner, 9
    // October: "bring the Cart tracking page to the top third of the left
    // panel menu"). Its search entry travels with the id, tabs unchanged.
    expect(AdminSearchIndex::build()['carttracking'][0] ?? [])->toContain('Carts', 'Blocked', 'Settings')
        ->and(srSidebarIds())->toContain('carttracking');
});

it('lists only labels the admin actually draws', function () {
    /*
     * THE DEFECT: a lane renames "Space inside each card" to "Card padding".
     * Search still offers the old words; opening it lands on the screen and
     * finds nothing to light up, because the browser finds the place BY ITS
     * TEXT. Every curated label must still be in the admin source.
     *
     * When this goes red, update the label in AdminSearchIndex::CURATED (or
     * rerun tools/sr-search-crawl.cjs). MUTATION: change one curated label by
     * a letter.
     */
    $text = srAdminSourceText();
    $stale = [];

    foreach (AdminSearchIndex::CURATED as $screen => $byTab) {
        foreach ($byTab as $tab => $labels) {
            foreach (array_merge($tab === '' ? [] : [(string) $tab], $labels) as $label) {
                if (! str_contains($text, preg_replace('/\s+/u', ' ', $label))) {
                    $stale[] = "{$screen} / {$tab} / {$label}";
                }
            }
        }
    }

    expect($stale)->toBe([], "Curated labels no admin source draws any more:\n".implode("\n", $stale));
});

it('reads each schema screen from a real SCHEMA and TABS pair that the sidebar shows', function () {
    /*
     * THE DEFECT: a SCHEMA_SCREENS row pointing at a class that lost its TABS
     * (or at a screen id that left the sidebar) silently contributes nothing,
     * and every setting on that screen disappears from search.
     * MUTATION: map 'header' to a class with no TABS constant.
     */
    $ids = srSidebarIds();

    foreach (AdminSearchIndex::SCHEMA_SCREENS as $screen => $classes) {
        expect($ids)->toContain($screen);

        foreach ($classes as $class) {
            expect(defined($class.'::SCHEMA'))->toBeTrue($class.' has no SCHEMA')
                ->and(defined($class.'::TABS'))->toBeTrue($class.' has no TABS');

            $tabs = AdminSearchIndex::schemaTabs($class);
            $labels = array_merge(...array_map(fn ($t) => $t[1], $tabs));

            expect($tabs)->not->toBeEmpty()->and($labels)->not->toBeEmpty();
        }
    }

    // And a field a later lane adds to a mapped schema is searchable with
    // nobody touching the index: Header's "Text size" is not curated at all,
    // it arrives from HeaderSettings::SCHEMA.
    expect(AdminSearchIndex::CURATED['header']['Navigation'] ?? [])->not->toContain('Text size')
        ->and(AdminSearchIndex::build()['header'][1])->toContain([array_search('Navigation', AdminSearchIndex::build()['header'][0], true), 'Text size']);
});

/* ------------------------------------------------------------------------
 | 3. No load on the admin
 |------------------------------------------------------------------------*/

it('talks to no server: no fetch, no XHR, no beacon, no socket', function () {
    /*
     * THE OWNER: "must not put load on admin side at all". The index is in
     * the page; searching is memory. MUTATION: add a fetch() for suggestions
     * and this is red.
     */
    $js = srScript();

    foreach (['fetch(', 'XMLHttpRequest', 'sendBeacon', 'WebSocket', 'EventSource', 'import(', '$.ajax', '$.get(', 'axios', 'navigator.serviceWorker'] as $api) {
        expect($js)->not->toContain($api);
    }
});

it('builds its index lazily, on first use, and never polls', function () {
    /*
     * Opening the console costs two listeners and nothing else: the JSON is
     * parsed on the box's first focus (or the first shortcut), and waiting for
     * a late screen is one MutationObserver with a deadline, not a loop.
     * MUTATION: call build() at the bottom of the IIFE, or add a setInterval.
     */
    $js = srScript();

    expect($js)->not->toContain('setInterval')
        ->and($js)->toContain("input.addEventListener('focus', function () {\n    build();")
        ->and($js)->toContain('mo.disconnect()')
        ->and($js)->toContain('timeout = setTimeout(function () { end(null); }, ms);');

    // The only top-level call is the IIFE's own setup -- no build() before use.
    $tail = substr($js, (int) strrpos($js, "document.addEventListener('keydown'"));
    expect(substr_count($tail, 'build()'))->toBe(2);   // both inside kbbAdminSearch's test hooks
});

it('measures no layout', function () {
    /*
     * CLAUDE.md rule 4, and the same names the other guards forbid. The
     * target is brought into view with scrollIntoView and found by its text.
     * MUTATION: read el.offsetTop to scroll, and this is red.
     */
    foreach (['getBoundingClientRect', 'offsetWidth', 'offsetHeight', 'offsetTop', 'offsetLeft', 'offsetParent',
        'clientWidth', 'clientHeight', 'scrollHeight', 'scrollWidth', 'getComputedStyle', 'ResizeObserver',
        'IntersectionObserver', 'scrollY', 'innerHeight'] as $api) {
        expect(srPartial())->not->toContain($api);
    }

    expect(srScript())->toContain('scrollIntoView(');
});

it('caches the index JSON in the file store, under one key whose signature moves when a schema file changes', function () {
    /*
     * Building it means autoloading seventeen settings classes the console
     * does not otherwise need (13 ms cold, measured). So it is cached -- in
     * the FILE store by name, and as [signature, json] under ONE key:
     *
     *   * keyed on the files' mtimes, so a package that adds a setting is
     *     searchable on the next console load without anyone clearing
     *     anything. MUTATION: drop the signature check in json() and the stale
     *     entry planted below is served for ever -- red.
     *   * one key, overwritten, so applying a hundred packages leaves one file,
     *     not a hundred 50 KB ones.
     */
    $store = Cache::store('file');
    $store->forget(AdminSearchIndex::CACHE_KEY);
    $sig = AdminSearchIndex::signature();

    expect($sig)->toMatch('/^[0-9a-f]{12}$/');

    $json = AdminSearchIndex::json();

    expect($store->get(AdminSearchIndex::CACHE_KEY))->toBe([$sig, $json])
        ->and($json)->toBe(AdminSearchIndex::encode());

    // A package changed a schema: the old entry's signature no longer matches,
    // so the next load rebuilds and overwrites the same key.
    $store->forever(AdminSearchIndex::CACHE_KEY, ['000000000000', '{"stale":1}']);

    expect(AdminSearchIndex::json())->toBe($json)
        ->and($store->get(AdminSearchIndex::CACHE_KEY))->toBe([$sig, $json]);

    $file = app_path('Services/HeaderSettings.php');
    $was = filemtime($file);
    touch($file, $was + 7);
    clearstatcache();

    try {
        expect(AdminSearchIndex::signature())->not->toBe($sig);
    } finally {
        touch($file, $was);
        clearstatcache();
        $store->forget(AdminSearchIndex::CACHE_KEY);
    }
});

it('costs the console no database query, even where the default cache store is the database', function () {
    /*
     * THE DEFECT: config/cache.php defaults to the `database` store. Written
     * as Cache::rememberForever(), the index's cache read is one query on
     * every console load -- the opposite of "no load on the admin side at
     * all", and invisible, because it is fast. MUTATION: replace
     * Cache::store('file') with the default store and this counts 1+.
     */
    config(['cache.default' => 'database']);
    Cache::forgetDriver('database');
    Cache::store('file')->forget(AdminSearchIndex::CACHE_KEY);

    $queries = 0;
    DB::listen(function () use (&$queries) { $queries++; });

    AdminSearchIndex::json();   // cold: builds and writes
    AdminSearchIndex::json();   // warm: reads

    expect($queries)->toBe(0);

    Cache::store('file')->forget(AdminSearchIndex::CACHE_KEY);
});

/* ------------------------------------------------------------------------
 | 4. Secure by construction
 |------------------------------------------------------------------------*/

it('never writes markup from a label: textContent and created nodes only, no eval', function () {
    /*
     * Labels are code constants today, but the drawing path must not depend
     * on that. MUTATION: build a result row with innerHTML and this is red.
     */
    $js = srScript();

    foreach (['innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write', 'eval(', 'new Function', 'setTimeout("', "setTimeout('"] as $bad) {
        expect($js)->not->toContain($bad);
    }

    expect($js)->toContain('.textContent = ')
        ->and($js)->toContain('document.createTextNode(');
});

it('prints an index that cannot close its own script block', function () {
    /*
     * The JSON is printed raw, inside <script type="application/json">. A label
     * containing "</script>" would end the block and run whatever followed.
     * JSON_HEX_TAG makes that impossible for any label anyone ever writes.
     * MUTATION: drop JSON_HEX_TAG from encode().
     */
    $json = AdminSearchIndex::encode();

    expect($json)->not->toContain('<')
        ->and($json)->not->toContain('>')
        ->and(json_decode($json, true))->toBeArray();

    // The one raw print in the partial is this constant-built JSON, nothing else.
    preg_match_all('/\{!!(.*?)!!\}/s', srPartial(), $raw);
    expect(array_map('trim', $raw[1]))->toBe(['\App\Support\AdminSearchIndex::json()']);
});

it('derives the searchable screens from the drawn sidebar, so it hides what the sidebar hides', function () {
    /*
     * THE DEFECT: a search with its own screen list offers a screen the
     * sidebar does not show this admin. Here a screen is a result only if a
     * #nav (or pinned) row exists for it, and opening it clicks that row.
     * MUTATION: build the page list from the JSON's keys instead.
     */
    $js = srScript();

    expect($js)->toContain("document.querySelectorAll('#nav [data-go], .side-pin [data-go]')")
        ->and($js)->toContain('row.click()')
        ->and($js)->not->toContain('window.go(');
});

it('does not redraw the screen the admin is already on, so unsaved typing survives a jump', function () {
    /*
     * THE DEFECT: every console screen redraws from scratch on go(). Opening a
     * result by clicking its sidebar row unconditionally meant that, on the
     * Header screen with a half-typed support number, searching "how it fills
     * the row" and pressing Enter wiped the typing -- the search had become a
     * reset button. Measured in Chromium on the preview: a marker attribute on
     * the drawn screen survives a same-screen, same-tab jump only with this.
     *
     * Both halves are required: the row lit ("on") AND the title the screen's
     * TITLES entry, so a stale "on" costs a redraw at worst, never strands the
     * admin on the wrong screen. MUTATION: make the branch `if (false)` and
     * the browser marker is gone; drop the onScreen() half and this is red.
     */
    $js = srScript();

    expect($js)->toContain("if (row.classList.contains('on') && onScreen(it.s)) {")
        ->and($js)->toContain("side.classList.remove('open');")
        ->and($js)->toContain('T[1] === t.textContent');
});

/* ------------------------------------------------------------------------
 | 5. Keyboard and motion
 |------------------------------------------------------------------------*/

it('opens from anywhere with Ctrl/Cmd+K, and with "/" when nothing is being typed', function () {
    /*
     * MUTATION: drop the typing() check and "/" can no longer be typed into
     * any field in the console -- every product description loses its slashes.
     */
    $js = srScript();

    expect($js)->toContain("k === 'k' && (e.ctrlKey || e.metaKey)")
        ->and($js)->toContain("e.key === '/' && !e.ctrlKey && !e.metaKey && !e.altKey && !typing(e.target)")
        ->and($js)->toContain("tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || t.isContentEditable")
        ->and($js)->toContain("e.key === 'ArrowDown'")
        ->and($js)->toContain("e.key === 'Enter'")
        ->and($js)->toContain("e.key === 'Escape'")
        // Enter while an Arabic or CJK input method is still composing is
        // the IME's, not ours -- without this the first candidate opens.
        ->and($js)->toContain('if (e.isComposing || e.keyCode === 229) return;')
        // On a phone the sidebar is a drawer: the shortcut opens it first.
        ->and($js)->toContain("side.classList.add('open')")
        // A screen that binds its own Ctrl+K (a rich-text "insert link") and
        // calls preventDefault keeps it; the search does not steal the key.
        ->and($js)->toContain('if (e.defaultPrevented) return;');
});

it('glows in brand pink, and holds still for reduced motion', function () {
    /*
     * MUTATION: delete the reduced-motion block and an owner who has asked
     * his device for less motion gets a pulsing glow anyway.
     */
    $p = srPartial();

    expect($p)->toContain('@keyframes ksrGlow')
        ->and($p)->toContain('rgba(224,86,123,')
        ->and($p)->toMatch('/@media \(prefers-reduced-motion:reduce\)\{\s*\.ksr-flash\{animation:none;outline:2px solid #E0567B/')
        // And the scroll never animates, so there is no motion to reduce in it:
        // an instant jump, then the glow (see bring() for why not smooth).
        ->and(srScript())->toContain("behavior: 'auto'")
        ->and(srScript())->not->toContain("'smooth'");
});

it('lifts the sidebar above the page while the results are open', function () {
    /*
     * The owner, with a screenshot of Appearance → WhatsApp button: "the
     * search results on backend coming under the pages. plz make it on top on
     * every page." .side's backdrop-filter makes it a stacking context painted
     * before .main, so the results spilling past the sidebar went under each
     * screen's cards (measured in Chromium: elementFromPoint inside the panel
     * returned the page, 3 of 3 points). MUTATION: delete the classList.toggle
     * line and the second expectation is red; drop the min-width rule and the
     * first is.
     */
    $src = (string) file_get_contents(resource_path('views/admin/partials/admin-search.blade.php'));

    expect($src)->toContain('@media(min-width:881px){.side.ksr-up{position:relative;z-index:200}}')
        ->and($src)->toContain("if (side) side.classList.toggle('ksr-up', on);");
});

it('styles the search box before drawing it, so a cold load never paints a giant icon', function () {
    /*
     * The owner, 2.60.383: "on hard refresh the admin panel, a giant search
     * icon appears and instantly fixed". The <style> block came AFTER the
     * markup, so the first paint drew the icon with no size -- an SVG with no
     * width is 300x150. MUTATION: move the style block back below the markup
     * and the first expectation is red; drop width="16" and the second is.
     */
    $src = (string) file_get_contents(resource_path('views/admin/partials/admin-search.blade.php'));

    expect(strpos($src, '.ksr-ic{width:16px'))->toBeLessThan(strpos($src, '<svg class="ksr-ic"'))
        ->and($src)->toContain('<svg class="ksr-ic" width="16" height="16"')
        ->and($src)->toContain('hidden><svg width="14" height="14"');
});

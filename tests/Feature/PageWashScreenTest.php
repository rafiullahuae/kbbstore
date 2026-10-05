<?php

declare(strict_types=1);

use App\Services\PageWash;

/**
 * Appearance → Page background: the wiring, pinned at its FINISHED state.
 *                                                                    (Lane BG)
 *
 * ▲ EVERY COUNT BELOW IS `=== 1`, AND NONE OF THEM IS AN ABSENCE. CLAUDE.md
 * records three days lost in one day to lanes asserting
 * `expect($web)->not->toContain('my-routes.php')` to prove they had not wired
 * themselves up: that assertion is correct in the lane's worktree and goes RED
 * the moment the integrator does the one thing the lane asked for, and the only
 * way to green it as written is to UNMOUNT the feature.
 *
 * So these are red now and green after — and they stay real guards afterwards,
 * because both failing shapes are real failures. Zero is "built, never wired
 * up", which this repository keeps finding. Two registers the sidebar row twice
 * and wraps `window.go` around its own wrapper.
 *
 * docs/BG-ADMIN-APP-BLOCKS.md carries the three console edits, each with an
 * anchor verified to occur exactly once, and routes/page-wash-admin.php's own
 * header carries the fourth (the `require` in routes/web.php).
 */

/* ═══════════════════════════════════ the four integrator edits ═══ */

it('mounts the route file on the admin-api group exactly once', function () {
    $web = (string) file_get_contents(base_path('routes/web.php'));

    expect(substr_count($web, "require __DIR__.'/page-wash-admin.php';"))
        ->toBe(1, 'routes/page-wash-admin.php is not required exactly once by routes/web.php');
});

it('includes the screen on the console exactly once', function () {
    $app = (string) file_get_contents(resource_path('views/admin/app.blade.php'));

    expect(substr_count($app, "@include('admin.partials.page-wash-screen')"))
        ->toBe(1, 'the screen is not mounted on the console exactly once');
});

it('gives the screen a breadcrumb and a title, and arms its deep link', function () {
    /*
     * Block 3 without block 1 is the silent half-wired failure: the sidebar row
     * appears (the partial registers it itself), the screen draws, and
     * ?go=pagewash opens the DASHBOARD — because go() reads TITLES to decide
     * what it is looking at, and this screen's own render() refuses to paint
     * unless #ptitle already says "Page background".
     */
    $app = (string) file_get_contents(resource_path('views/admin/app.blade.php'));

    expect(substr_count($app, "'pagewash':['Appearance','Page background']"))
        ->toBe(1, 'pagewash has no TITLES entry, so a deep link to it opens the dashboard');

    preg_match('/const LATE_RENDERED\s*=\s*new Set\(\[([^\]]*)\]\);/', $app, $m);

    expect($m)->not->toBeEmpty('LATE_RENDERED could not be found in the console at all');
    expect(substr_count($m[1], "'pagewash'"))
        ->toBe(1, "pagewash is not armed in LATE_RENDERED exactly once");
});

it('declares its sidebar row where the sidebar is built, not where the partial is', function () {
    /*
     * LATE_NAV, and it is the newest of the console's rules.
     * AdminSidebarIsCompleteAtBuildTest measured it: twenty-one rows were
     * contributed by screen partials near the END of a 3.4 MB document, so
     * buildNav() ran at 21.4% of it and the last row arrived at 98.9% — the
     * owner's own words were "under some parent menus some sub menues don't
     * show". A row declared ONLY by its partial is that defect for that row.
     *
     * The copy has to agree with the partial's own call in label, group and
     * anchors, or the row lands in one place on a cold load and another once
     * the partial runs. That guard compares both directions and names the id;
     * this one is the finished-state count.
     */
    // ▲ Lane AP: the sidebar is server-rendered from App\Support\AdminNav, so
    // the row must be declared there, once, in Appearance, after Section
    // dividers -- the first anchor its partial names.
    $ids = [];
    foreach (\App\Support\AdminNav::GROUPS as $g) {
        foreach ($g['rows'] as $r) {
            $ids[] = $g['sec'].'/'.$r['id'];
        }
    }

    expect(count(array_keys($ids, 'Appearance/pagewash', true)))
        ->toBe(1, 'pagewash has no AdminNav row, so its sidebar entry does not exist until the partial is parsed');
    expect($ids[array_search('Appearance/pagewash', $ids, true) - 1])->toBe('Appearance/dividers');
    expect(\App\Support\AdminNav::rows()['pagewash']['label'])->toBe('Page background');
});

it('reaches every storefront document, each exactly once', function () {
    /*
     * SIX DOCUMENTS, not one layout. Five storefront pages carry their own
     * <html>, <head> and inline stylesheet and do not extend
     * layouts/store.blade.php at all — store/blog, store/post,
     * store/review-wall, store/skin-quiz and store/app — so nothing that rides
     * that layout has ever reached them. Measured, not assumed: before this
     * include the journal index rendered BYTE-IDENTICALLY under all four
     * treatments and tools/bg-sheet.cjs refused to arrange the row.
     *
     * MUTATION: delete the include from store/blog.blade.php and this fails by
     * name, which is the defect arriving again.
     */
    $documents = [
        'layouts/store.blade.php',
        'store/blog.blade.php',
        'store/post.blade.php',
        'store/review-wall.blade.php',
        'store/skin-quiz.blade.php',
        'store/app.blade.php',
    ];

    $wrong = [];

    foreach ($documents as $view) {
        $src = (string) file_get_contents(resource_path('views/'.$view));
        $src = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $src);
        $count = substr_count($src, "@include('partials.page-wash-css')");

        if ($count !== 1) {
            $wrong[] = sprintf('  %-32s includes the wash %d times', $view, $count);
        }
    }

    expect($wrong)->toBe([], "a storefront document does not carry the wash exactly once:\n"
        .implode("\n", $wrong)
        ."\n\n0 is a page the owner's background does not reach, which is what five of these"
        .' were before this lane. 2 emits the stylesheet twice into one head.');
});

/* ═════════════════════════════════════════ the screen itself ═══ */

it('registers one sidebar row, under Appearance, and no second one', function () {
    $src = (string) file_get_contents(resource_path('views/admin/partials/page-wash-screen.blade.php'));
    $app = (string) file_get_contents(resource_path('views/admin/app.blade.php'));

    expect(substr_count($src, 'kbbAddNavEntry('))->toBe(1);
    expect($src)->toContain("group: 'Appearance'")
        ->and($src)->toContain("label: 'Page background'")
        ->and($src)->toContain("var SCREEN = 'pagewash';")
        ->and($src)->toContain('screen: SCREEN,');

    /*
     * AND NOT ALSO IN THE NAV CONST. The partial adds the row, so a row in
     * app.blade.php's own list as well would give the owner the same row twice
     * — which is why docs/BG-ADMIN-APP-BLOCKS.md has three blocks and not four.
     */
    expect(substr_count($app, "['pagewash','Page background'"))->toBe(0);
});

it('wraps window.go once, and paints before it awaits anything', function () {
    /*
     * The condition LATE_RENDERED carries: a screen is safe to arm only if its
     * window.go calls render() BEFORE it awaits, so the replay's marker inside
     * #content is already destroyed by the time its task runs and nothing is
     * drawn twice. `rev-all` is the rule that fails it and is in neither armed
     * set.
     */
    $src = (string) file_get_contents(resource_path('views/admin/partials/page-wash-screen.blade.php'));

    expect(substr_count($src, 'window.go = function'))->toBe(1);
    expect(substr_count($src, 'var previousGo = window.go;'))->toBe(1);

    $body = substr($src, strpos($src, 'window.go = function'));
    $body = substr($body, 0, strpos($body, 'async function load()'));

    expect(strpos($body, 'render();'))->toBeLessThan(
        (int) strpos($body, 'load();'),
        'window.go awaits before it paints, which is what makes a screen unsafe to arm'
    );
});

it('opens on the preview, and every control it draws calls a real endpoint', function () {
    /*
     * The preview is the deliverable and the save is not: the owner asked to
     * see it first, so the screen must not open on a tab of sliders that write
     * to the shop.
     */
    $src = (string) file_get_contents(resource_path('views/admin/partials/page-wash-screen.blade.php'));

    /*
     * ▲ BOTH NEEDLES BELOW USED TO BE BARE `toContain`, AND BOTH WERE GREEN ON
     *   THE DEFECT THEY EXIST TO CATCH.              (Lane BG, round 4)
     *
     * Found with Lane PLC's tools/plc-needle-scan.sh, which records how many
     * times each needle occurs in the haystack it ran against: a needle that
     * occurs twice cannot distinguish the thing it names from the thing it does
     * not. These were `x2` and `x3`. Both were then settled the cheap way --
     * blank the thing under test and see whether the assertion notices:
     *
     *   toContain("open = 'preview'")
     *     `open = 'preview'` appears TWICE: once as the initialiser (which is
     *     the screen's opening tab, the thing under test) and once at the
     *     bottom of applyTabs() as the fallback for an `open` that is no longer
     *     in the tab list. MEASURED: changing the initialiser to
     *     `open = 'colour'` -- the screen now opens on a tab of sliders that
     *     write to the shop, which is the exact thing the case above forbids --
     *     left this file at 10 passed. The fallback satisfied the needle.
     *
     *   toContain('previewParam')
     *     appears THREE times: the declaration, the assignment from the
     *     endpoint, and the use. Only the middle one is the claim. MEASURED:
     *     deleting `previewParam = body.preview_param || 'kbbwash'` -- so the
     *     screen hard-codes the parameter and drifts from the shop's routes,
     *     which is what the comment below says it must not do -- left this file
     *     at 10 passed.
     *
     * So each now names the ONE occurrence that is the claim.
     */

    /*
     * THE INITIAL VALUE, read as the FIRST assignment to `open` in the file
     * rather than as "the string appears somewhere". Written this way the
     * fallback at the bottom of applyTabs() cannot stand in for it, and a lane
     * that adds an even earlier assignment has genuinely changed what the
     * screen opens on, so matching that one is correct rather than a loophole.
     */
    preg_match("/\bopen\s*=\s*'([a-z]+)'/", $src, $opens);

    expect($opens[1] ?? null)->toBe('preview',
        "the screen's first assignment to `open` is '".($opens[1] ?? 'none')."'. The owner asked"
        .' to see the preview before anything writes to the shop, so a tab of sliders must not be'
        .' what it opens on.');

    // Both endpoints, and no third path invented by the screen.
    preg_match_all("/api\('([^']+)'/", $src, $calls);
    expect(array_unique($calls[1]))->toBe(['/page-wash']);

    // The frames are built from the endpoint's own list, not from a hard-coded
    // set of paths that could drift from the shop's routes.
    expect($src)->toContain('body.preview_pages');

    /*
     * THE ASSIGNMENT, not the identifier. `previewParam` on its own is
     * satisfied by the declaration and by the use, neither of which says where
     * the value came from -- and where it came from is the whole claim.
     */
    expect(str_contains($src, 'previewParam = body.preview_param'))->toBeTrue(
        'the screen no longer takes the preview parameter from the endpoint, so the name it'
        .' builds frame URLs with can drift from the one the shop actually reads.');
});

it('draws every field the schema declares, and no control the endpoint would refuse', function () {
    $src = (string) file_get_contents(resource_path('views/admin/partials/page-wash-screen.blade.php'));

    /*
     * The fields are drawn from the endpoint's `tabs`, so there is no field
     * list in the screen to drift — but the four TYPES it can draw are hard
     * coded, and a schema that grows a fifth would silently render it as a text
     * box. This is the pin that says so.
     */
    $types = [];
    foreach (PageWash::SCHEMA as $field) {
        $types[$field[0]] = true;
    }

    expect(array_keys($types))->toBe(['bool', 'select', 'colour', 'range']);

    foreach (array_keys($types) as $type) {
        expect(str_contains($src, "f.type === '".$type."'"))
            ->toBeTrue('the screen cannot draw a '.$type.' field, so it would render as a text box');
    }
});

it('has no JavaScript anywhere on the storefront', function () {
    /*
     * Rule 4: this is a CSS effect and there is no script in it. The partial the
     * shop includes is a <style> element and nothing else, and the stylesheet
     * the service emits measures nothing — there is no element-measuring API to
     * forbid, because there is no script at all.
     */
    $partial = (string) file_get_contents(resource_path('views/partials/page-wash-css.blade.php'));

    expect($partial)->not->toContain('<script')
        ->and($partial)->not->toContain('addEventListener')
        ->and($partial)->not->toContain('getBoundingClientRect')
        ->and($partial)->not->toContain('offsetWidth');

    $css = app(PageWash::class)->css();
    expect($css)->toBe('');

    $service = (string) file_get_contents(app_path('Services/PageWash.php'));
    expect($service)->not->toContain('<script');
});

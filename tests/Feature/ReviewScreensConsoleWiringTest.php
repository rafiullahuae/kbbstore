<?php

declare(strict_types=1);

/**
 * The four Reviews screens must actually be wired into the admin console.
 *
 * WHY THIS IS A TEST AND NOT A COMMENT. Taking an id over from mountFrame() has
 * TWO halves, in two different parts of a 13,000-line Blade that several lanes
 * edit at once, and either half can be lost in a merge without anything else
 * failing:
 *
 *  1. the partial has to be INCLUDED, or the screen simply is not there and the
 *     console falls back to the "isn't installed yet" card;
 *  2. the id has to be in LIVE_RENDERED, or every visit fires a HEAD request
 *     for a standalone .html file this repo has never shipped — a guaranteed
 *     404 on every single visit, for markup that is painted over a moment
 *     later. That is the exact defect the LANE AV comment block above
 *     LIVE_RENDERED was written about.
 *
 * Losing half 2 is invisible: the screen still works. It just costs a failed
 * request every time anybody opens it, for ever. So it is pinned here.
 *
 * These are string assertions over a Blade file, which is a weak kind of test —
 * but the behaviour they stand for is exercised for real by the browser pass,
 * and this is what catches a bad merge in CI. tests/Feature/
 * AdminConsoleScriptParsesTest.php covers the other half of that weakness: that
 * the file it is matching strings in still parses as JavaScript.
 */
it('takes all four Reviews ids over from the iframe machinery, both halves', function () {
    $console = file_get_contents(base_path('resources/views/admin/app.blade.php'));

    expect($console)->not->toBeFalse();

    // Half 1: the partials are included.
    foreach ([
        'admin.partials.reviews-io-screen',
        'admin.partials.review-badges-screen',
        'admin.partials.review-capsule-screen',
        'admin.partials.review-assign-screen',
    ] as $partial) {
        expect($console)->toContain("@include('" . $partial . "')");
        expect(base_path('resources/views/' . str_replace('.', '/', $partial) . '.blade.php'))->toBeFile();
    }

    // Half 2: the ids are in LIVE_RENDERED. Matched inside that declaration
    // rather than anywhere in the file — every one of these ids also appears in
    // NAV, in TITLES and in REV_SRC, so a bare substring match would be true
    // even with the takeover entirely absent.
    expect(preg_match('/const LIVE_RENDERED\s*=\s*new Set\(\[(.*?)\]\)/s', $console, $m))->toBe(1);

    foreach (['rev-io', 'rev-badge', 'rev-capsule', 'rev-assign'] as $id) {
        expect($m[1])->toContain("'" . $id . "'");
    }
});

it('leaves the other lanes\' Reviews ids exactly as it found them', function () {
    $console = file_get_contents(base_path('resources/views/admin/app.blade.php'));

    // 'rev-add' and 'rev-likes' belong to another lane and this one decides
    // nothing about them — it neither takes them over nor asserts that they
    // stay untaken, which would be this lane blocking that one.
    //
    // What IS pinned is that all eight Reviews ids still have a REV_SRC entry
    // and a TITLES entry. Removing one of those, rather than shadowing it the
    // way Lane AX did for 'media', is how goTab() or a bookmark lands on a
    // screen with no title and no fallback at all.
    expect(preg_match('/const REV_SRC\s*=\s*\{(.*?)\};/s', $console, $m))->toBe(1);

    foreach ([
        'rev-all', 'rev-add', 'rev-likes', 'rev-assign',
        'rev-io', 'rev-badge', 'rev-capsule', 'rev-settings',
    ] as $id) {
        expect($m[1])->toContain("'" . $id . "'");
        expect($console)->toContain("'" . $id . "':['Reviews',");
    }
});

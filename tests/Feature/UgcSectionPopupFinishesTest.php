<?php

declare(strict_types=1);

/**
 * Two things the section popup left unfinished.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Both reported by the owner against a screenshot of the popup, and both were
 * defects of OMISSION rather than of logic — which is why the suite was green
 * with each of them in place.
 *
 * ── 1. "saving the cover step stucks. and nothing proceeding further." ──────
 *
 * The browser cut worked. The cover arrived and the Poster row showed 134 KB.
 * And the progress bar sat at **80% · Saving the cover** underneath it, for
 * good, because `cutCoverHere()` set that stage, handed the file to
 * dropPoster() and nothing ever moved it again.
 *
 * MY OVERSIGHT, and worth naming as one: the All-clips screen got the
 * completion and this screen did not. The same feature written twice, finished
 * once.
 *
 * It now finishes on the ADOPT CALL RETURNING — a real event — so 100% means
 * the cover is genuinely on the clip, not that some milliseconds passed.
 *
 * ── 2. "when hit save on popup, it should ... close the popup automatically" ─
 *
 * Saving every tab together was never the gap and already worked: the PUT
 * carries Details, Source and Placement, the call after it carries Products,
 * and Files writes on upload. What Save then did was RE-OPEN the dialog on the
 * row it had just saved, which reads as nothing having happened.
 *
 * ── WHY THESE READ THE SOURCE ───────────────────────────────────────────────
 *
 * Both live in browser JavaScript inside a Blade partial; there is no PHP entry
 * point and no bundler to import from. UgcInstantStepsTest and AdminNavAndIds-
 * Test read these same files for the same reason. What is pinned is the
 * BEHAVIOUR EXISTING — which is exactly what was missing.
 */
function sectionsScreenSource(): string
{
    return (string) file_get_contents(
        resource_path('views/admin/partials/ugc-sections-screen.blade.php')
    );
}

it('finishes the cover bar when the cover is actually adopted', function () {
    $src = sectionsScreenSource();

    // 100% is written in the adopt call's own success path...
    $adopt = strpos($src, "api('/ugc-videos/' + encodeURIComponent(target) + '/poster'");
    expect($adopt)->not->toBeFalse('the adopt call moved; this test is looking in the wrong place');

    $after = substr($src, (int) $adopt, 2600);

    expect($after)->toContain("cutStage = { pct: 100, words: 'Cover set' }")
        // ...and cleared afterwards, or the finished bar never leaves.
        ->toContain('cutStage = null')
        ->toContain('cutClear');
});

/*
 * MUTATION: delete the `cutStage = { pct: 100 ... }` block from the adopt
 * success path and this is red — which is the reported bug exactly: a cover
 * that arrives under a bar stuck at 80. RUN: red.
 */

it('does not leave the bar behind when the adopt fails', function () {
    $src = sectionsScreenSource();

    /*
     * A bar stuck at 80 under a REFUSAL is the same defect wearing a different
     * colour, and it is the half that is easy to forget: the success path is
     * the one being tested by hand.
     */
    $catch = strpos($src, '.catch(function (err) {');
    expect($catch)->not->toBeFalse();

    expect(substr($src, (int) $catch, 400))->toContain('cutStage = null');
});

it('closes the popup when the video is saved, rather than re-opening it', function () {
    $src = sectionsScreenSource();

    $from = strpos($src, 'async function saveVideo()');
    expect($from)->not->toBeFalse('saveVideo() is gone');

    $body = substr($src, (int) $from, (int) strpos($src, 'async function search()') - (int) $from);

    // Closed...
    expect($body)->toContain('editingVideo = null')
        // ...and the lists behind it refreshed, so the row he just edited is
        // not stale underneath the closed dialog.
        ->toContain('openSection(editing.id)')
        ->toContain('await load()')
        // ...and NOT re-opened. This is the line that made Save look inert.
        ->not->toContain('await openVideo(v.id)');
});

/*
 * MUTATION: put `await openVideo(v.id);` back and drop `editingVideo = null`,
 * and the last case is red on both halves. RUN: red.
 */

it('still saves every tab in the one press, which was never the gap', function () {
    $src = sectionsScreenSource();

    $from = strpos($src, 'async function saveVideo()');
    $body = substr($src, (int) $from, (int) strpos($src, 'async function search()') - (int) $from);

    /*
     * The fields from Details, Source and Placement in one PUT, then the
     * Products tab. Pinned so that "close the popup" is never implemented by
     * closing it EARLIER than the work finishes — which would turn a cosmetic
     * complaint into lost edits.
     */
    foreach (['title:', 'status:', 'source_platform:', 'creator_handle:',
        'rights_status:', 'published_at:'] as $field) {
        expect($body)->toContain($field);
    }

    $put = strpos($body, "'PUT'");
    $products = strpos($body, "/products'");
    $close = strpos($body, 'editingVideo = null');

    expect($put)->not->toBeFalse()->and($products)->not->toBeFalse();
    // The close comes after BOTH writes, never between them.
    expect($close > $put && $close > $products)->toBeTrue(
        'the popup closes before the save finishes, which would drop edits'
    );
});

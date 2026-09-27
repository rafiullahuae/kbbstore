<?php

declare(strict_types=1);

use App\Services\UgcTranscoder;

/**
 * Content → Shoppable video → All clips: the two-column editor, the upload
 * panel's two endings, and the cut offered where the upload finishes.
 *
 * ── WHY A SOURCE SCAN, AND WHERE IT IS NOT ONE ──────────────────────────────
 *
 * The screen is a JavaScript string builder inside a Blade partial that nothing
 * renders server-side, so the same reasoning UgcAddClipFlowTest sets out applies
 * and the same helper shape is reused: comments are stripped before any scan for
 * a behaviour, because this file and that screen both EXPLAIN in prose the
 * defects they fix — including by quoting the wrong sentence — and a scan of the
 * raw text would match the explanation.
 *
 * TWO CASES HERE ARE NOT SOURCE SCANS. The last one asks the SERVER what it
 * answers for `transcoder.available`, because the whole point of the gate on the
 * cut button is that it is the server's answer and not the screen's hope, and
 * the one below it reads the stylesheet's own breakpoint. What cannot be settled
 * in Pest at all — how many columns the browser resolves, and whether anything
 * overflows sideways — is measured in tests/browser/lane-v5-editor-cols.mjs and
 * the numbers are in docs/ugc-editor-cols-shots/measurements.json.
 */
function editorSource(): string
{
    return (string) file_get_contents(
        resource_path('views/admin/partials/ugc-library-screen.blade.php')
    );
}

/** The same text with the prose taken out: Blade comments, block comments, line comments. */
function editorCode(): string
{
    $src = editorSource();
    $src = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $src);
    $src = (string) preg_replace('#/\*.*?\*/#s', '', $src);
    $src = (string) preg_replace('#^\s*//.*$#m', '', $src);

    return $src;
}

/** Just the stylesheet, comments included: a breakpoint cannot hide in prose. */
function editorStyle(): string
{
    $src = editorSource();
    $from = (int) strpos($src, '<style>');

    return substr($src, $from, ((int) strpos($src, '</style>')) - $from);
}

/* ═══════════════════════════════════════════════════════ two columns ══ */

it('lays every step panel out in two columns at a desk and one on a phone', function () {
    /*
     * THE DEFECT. Every step panel was one tall column: on a 1280px desktop the
     * console's content column is 1032px wide and the whole form ran down the
     * left of it, which is the complaint the owner made about the payments
     * screen in as many words — a tall form with a dead right half — and then
     * made about this screen.
     *
     * Measured in Chromium, at the tip: `.ugs-cols` resolves to
     * "477.094px 477.094px" at 1280 and to a single "336px" track at 390, on
     * every one of the five steps, with documentElement.scrollWidth equal to
     * clientWidth at both widths. See lane-v5-editor-cols.mjs.
     *
     * WHAT IS PINNED HERE is the pair of declarations that produce those two
     * numbers, plus the fact that all five panels use the row. A test cannot
     * resolve a grid track; it can prove the breakpoint has not been deleted and
     * that no panel has quietly gone back to a single stack.
     *
     * MUTATION NOTE. Delete the `@media (min-width:900px)` line for .ugs-cols
     * and this is red on the two-track assertion. RUN: red —
     * "the two-column breakpoint is gone, so every step is one tall column".
     */
    $style = editorStyle();

    // One column by default: the phone is the base case, not the override.
    expect($style)->toContain('.ugs-cols{display:grid;gap:12px;grid-template-columns:1fr;');

    /* str_contains() and toBeTrue(), NOT toContain(): Pest's toContain() is
       variadic over NEEDLES, so a second argument meant as a failure message is
       silently asserted as a second substring and the case fails against its own
       explanation. It did, on the first run of this file. */
    expect(str_contains($style, '@media (min-width:900px){ .ugs-cols{grid-template-columns:minmax(0,1fr) minmax(0,1fr)} }'))
        ->toBeTrue('the two-column breakpoint is gone, so every step is one tall column');

    /*
     * And all five steps use it. Sliced per function rather than counted over
     * the whole file, so "five somewhere" cannot pass for "one in each": a
     * refactor that put two rows on step 2 and none on step 5 would keep any
     * total you cared to assert.
     */
    $code = editorCode();

    foreach ([
        'detailsPanel' => 'step 1',
        'mediaPanel' => 'step 2',
        'creditPanel' => 'step 3',
        'productsPanel' => 'step 4',
        'publishPanel' => 'step 5',
    ] as $fn => $step) {
        $from = strpos($code, 'function '.$fn.'(');
        expect($from)->not->toBeFalse("{$fn}() is gone");

        // As far as the next function declaration at the same indent.
        $next = strpos($code, "\n  function ", (int) $from + 1);
        $body = substr($code, (int) $from, $next === false ? null : $next - (int) $from);

        expect(str_contains($body, 'colsHTML('))
            ->toBeTrue("{$step} is a single column again: {$fn}() draws no two-column row");
    }
});

it('gives the loop preview a grid in the stylesheet rather than in a style attribute', function () {
    /*
     * THE DEFECT, and it is one AdminMobileOverflowTest's docblock names as a
     * cause it has already paid for: "a grid declared in an inline style
     * attribute that no media query could reach". The prose beside the loop tile
     * carried `style="flex:1 1 220px;min-width:0"`, so its layout could not be
     * changed at any width — there is no selector for it.
     *
     * MUTATION NOTE. Put the style attribute back in mediaPanel() and drop
     * .ugs-loopcols, and this is red twice over. RUN: red on both assertions.
     */
    $code = editorCode();
    $style = editorStyle();

    /*
     * Scoped to a style ATTRIBUTE rather than to the declaration: `.ugs-herotext`
     * is a perfectly good `flex:1 1 220px` in the stylesheet, where a media query
     * can reach it, and the first draft of this case failed on it.
     */
    expect(preg_match('/style="[^"]*flex:/', $code))
        ->toBe(0, 'something on this screen is laid out by an inline style attribute again');

    expect(str_contains($style, '@media (min-width:900px){ .ugs-loopcols{grid-template-columns:178px minmax(0,1fr)} }'))
        ->toBeTrue('the loop tile and its prose have lost their breakpoint');
});

/* ══════════════════════════════════════════ the cut, where the upload ends ══ */

it('offers the cut only where the server said it can cut, and only with a clip to cut from', function () {
    /*
     * THE OWNER'S ASK: "once upload the video complete, it should give option to
     * cut teaser and poster." The risk in granting it is a button that cannot
     * work: /derive on a box with no ffmpeg answers ok:true with a note saying
     * "This server has no ffmpeg", which is a press, a wait and a sentence that
     * reads like a failure. And a clip with no video has nothing to cut FROM —
     * the same endpoint answers "There is no uploaded clip to cut from yet."
     *
     * So the offer is gated on BOTH, out of the library payload's own
     * `transcoder.available`, which is UgcTranscoder::available() — see the last
     * case in this file, which checks the two really are the same answer.
     *
     * MUTATION NOTE. Take `transcoder.available` out of the gate — leave
     * `transcoder && v.id` — and this is red on the first assertion. RUN: red,
     * "the cut is offered without asking the server whether it can cut".
     */
    $code = editorCode();

    expect(str_contains($code, 'if (!(transcoder && transcoder.available && v.id)) {'))
        ->toBeTrue('the cut is offered without asking the server whether it can cut');

    // No clip on the row, no offer at all — not even the warm "instead" arm.
    expect(str_contains($code, "if (!clip) return '';"))
        ->toBeTrue('the cut is offered on a clip that has no video on it yet');

    /*
     * And where it cannot cut, it says what to do INSTEAD. "plainly" was the
     * ask: a warm section naming the one control that does the job, not a
     * disabled button.
     */
    expect(str_contains($code, 'Nothing can be cut on this server'))
        ->toBeTrue('a server with no ffmpeg is not told so on the step where it matters');

    expect(str_contains($code, '<b>What to do instead.</b> Press '))
        ->toBeTrue('the no-ffmpeg arm names no alternative');
});

it('puts the cut under the two file boxes instead of inside the cover box', function () {
    /*
     * THE DEFECT THIS FIXES. The derive button was the LAST child of the cover
     * slot, under a green "Choose from the Media Library" button and a drop
     * zone, on the right-hand column of the media step. The owner asked for the
     * cut to be offered when an upload finishes and did not know it was there.
     *
     * It is now its own section, drawn after the row that holds the two file
     * boxes, and it wears the loud tone while `freshClip` names the clip whose
     * video has just landed.
     *
     * Measured: at 1280 the cut section's top edge sits 12px below the bottom of
     * the file row, and it holds the only [data-ugs-derive] in the document.
     *
     * MUTATION NOTE. Move the button back into `coverBody` and this is red on
     * the ordering assertion. RUN: red — "the cut is back inside the cover box".
     */
    $code = editorCode();

    $from = (int) strpos($code, 'function mediaPanel(');
    $body = substr($code, $from, ((int) strpos($code, "\n  function creditPanel(")) - $from);

    $files = strpos($body, 'html += colsHTML(');
    $cut = strpos($body, 'html += cutHTML(v, clip);');

    expect($files)->not->toBeFalse('the media step no longer draws its two file boxes as a row');
    expect($cut)->not->toBeFalse('the media step no longer offers the cut at all');
    expect($cut > $files)
        ->toBeTrue('the cut is back inside the cover box rather than under both file boxes');

    // The cover box itself holds no cut button any more.
    $coverFrom = (int) strpos($body, 'var coverBody =');
    $coverTo = (int) strpos($body, 'html += colsHTML(');
    expect(str_contains(substr($body, $coverFrom, $coverTo - $coverFrom), 'data-ugs-derive'))
        ->toBeFalse('the cover box still carries its own cut button');

    /*
     * EXACTLY ONE in the whole screen. Two would be the shape this repo keeps
     * finding: a control moved rather than duplicated, and a second copy that
     * fires the same endpoint from a place nobody reviewed.
     */
    expect(substr_count($code, 'data-ugs-derive="1"'))
        ->toBe(1, 'the cut button is drawn in more than one place');
});

it('does not offer to do the cutting the upload has already done', function () {
    /*
     * THE TRAP. On a server that HAS ffmpeg the cutting is already finished by
     * the time step 2 is redrawn: UgcVideoController::media() calls
     * UgcTranscoder::derive() on the upload request for a clip and saves both
     * columns. A section that said "cut the cover and teaser from the video"
     * there would be offering work that is done, and pressing it would re-cut
     * two files that were already correct.
     *
     * So the section reads the ROW: with both files on it the head says they are
     * cut and the button says "again".
     *
     * MUTATION NOTE. Make `has` a constant false and this is red on the count.
     * RUN: red — 2 instead of 1, because the "not cut yet" wording is then the
     * only wording the screen can produce.
     */
    $code = editorCode();

    expect(str_contains($code, 'var has = !!(v.poster_path && v.teaser_path);'))
        ->toBeTrue('the cut section does not read what is already on the row');

    expect(str_contains($code, "'Cut them again from the video'"))
        ->toBeTrue('a clip that already has both files is offered a first cut');

    /*
     * Both wordings exist, and the row decides between them. Counted, because a
     * screen that can only ever say one of the two is the defect either way
     * round.
     */
    expect(substr_count($code, 'The cover and the teaser are cut'))
        ->toBe(1, 'the "already cut" wording is gone, or is written in two places');

    /*
     * TWO, and both are wanted: the section's own head and the button inside it
     * carry the same sentence, which is what makes the offer legible at a glance
     * on a phone where the head and the button are not on the same line. One
     * would mean the head or the button had lost it.
     */
    expect(substr_count($code, 'Cut the cover and teaser from the video'))
        ->toBe(2, 'the first-cut wording is no longer on both the section head and its button');
});

/* ══════════════════════════════════════════════════════ the upload bar ══ */

it('reads every figure in the upload panel off the upload itself', function () {
    /*
     * Rule 4's sibling, and the owner's word for it was "live": the percentage,
     * the bytes sent and the total are `e.loaded`, `e.total` and nothing else.
     * There is no timer, no easing toward 100 and no indeterminate variant —
     * a bar that sweeps while nothing is known is a bar that lies.
     *
     * Measured in flight against a 30.6 MB clip, throttled to 2600 KB/s:
     * 5% "1.5 MB of 30.6 MB sent", 33% "10.0 MB", 61% "18.6 MB", 83% "25.5 MB",
     * with the bar's own style width equal to the percentage at every sample.
     *
     * MUTATION NOTE. Replace the three assignments with
     * `upState.pct = Math.min(99, upState.pct + 5)` and this is red on the first
     * assertion. RUN: red.
     */
    $code = editorCode();

    foreach ([
        'upState.sent = e.loaded;',
        'upState.total = e.total;',
        'upState.pct = Math.round((e.loaded / e.total) * 100);',
    ] as $line) {
        expect(str_contains($code, $line))
            ->toBeTrue("the upload panel stopped reading its own progress event: {$line}");
    }

    // No animation on the bar: the only thing that moves it is a width.
    expect(preg_match('/\.ugs-progb\{[^}]*animation/', editorStyle()))
        ->toBe(0, 'the progress bar animates itself, which is an indeterminate bar in disguise');

    /*
     * THE HANDOVER IS ITS OWN EVENT. `xhr.upload.onload` fires when the last
     * byte has left, which on a box with ffmpeg is the start of two transcodes
     * the owner is waiting through. Before this the panel sat at 100% with the
     * word "Sending", which reads as a stall.
     *
     * MUTATION NOTE. Delete the xhr.upload.onload handler and this is red. RUN:
     * red — the panel can then never reach the 'server' stage.
     */
    /*
     * THE WHOLE ASSIGNMENT, not the property name. `substr_count($code,
     * 'xhr.upload.onload')` was the first version of this line and it is green
     * against `xhr.upload.onloadNEVER` — a rename that unhooks the handler
     * entirely still contains the shorter string. RUN: green, which is how this
     * line came to be written the longer way.
     */
    expect(substr_count($code, 'xhr.upload.onload = function () {'))
        ->toBe(1, 'the upload no longer says when the bytes have all arrived');

    expect(str_contains($code, "upState.stage = 'server';"))->toBeTrue();
});

it('leaves a finished upload on screen, whether it worked or not', function () {
    /*
     * THE DEFECT. `upState = null` was the first thing the response handler did,
     * on every path, and progressHTML() returned '' for a null state — so the
     * panel vanished the instant the request landed. A 30.6 MB upload finished
     * with no trace it had ever happened, which is indistinguishable from an
     * upload that was never sent, and a refused one left only a toast that
     * scrolls away.
     *
     * Both endings are now drawn from `upDone` and both stay until the next
     * upload: a tick with the size that arrived, or the server's own reason in
     * red with "nothing on the clip was changed".
     *
     * Measured: a 30.6 MB clip refused by the server's own limit drew
     * "clip-big.mp4 / not accepted / That file was not accepted. /
     * 30.6 MB — nothing on the clip was changed." and the panel was still there
     * afterwards, which is the screenshot in docs/ugc-editor-cols-shots.
     *
     * MUTATION NOTE. Delete the `if (!upDone) return '';` arm and the two
     * branches under it and this is red on the first assertion. RUN: red.
     */
    $code = editorCode();

    expect(str_contains($code, 'if (!upDone) return \'\';'))
        ->toBeTrue('progressHTML() draws no ending at all');

    // One success path, written in one place.
    expect(substr_count($code, 'upDone = { ok: true'))
        ->toBe(1, 'a successful upload leaves nothing on screen');

    /*
     * ── THIS PIN WAS ADVANCED ON PURPOSE, 27 September 2026 ─────────────────
     *
     * It counted `upDone = { ok: false` and required exactly 2, which were the
     * two failure paths that existed: the non-2xx answer and onerror. There are
     * now FIVE — a pre-flight refusal against this server's real ceiling, the
     * non-2xx answer, onerror, a cancel, and a cover refused for its size — and
     * the assertion went red for the best possible reason: the screen grew ways
     * of failing, which is exactly what this case exists to keep on screen.
     *
     * Counting the literal was the brittle half. Every one of the five now goes
     * through ONE writer, failUpload(), which is the only place `upDone = { ok:
     * false` appears — so the count below is 1 by construction, and what is
     * actually pinned is that no path writes an ending by hand and forgets the
     * clock the way a sixth copy would. The same argument
     * UpdateRunner::recordManifest() makes for update_releases.
     *
     * MUTATION NOTE. Write `upDone = { ok: false, ... }` inline in xhr.onerror
     * instead of calling failUpload() and this is red on the first assertion: 2
     * instead of 1. RUN: red.
     */
    expect(substr_count($code, 'upDone = { ok: false'))
        ->toBe(1, 'a failure ending is written somewhere other than the one writer');

    expect(str_contains($code, 'function failUpload(ending) {'))
        ->toBeTrue('there is no single writer for a failed upload');

    // Every way an upload can end badly, and each one of them leaves a panel.
    expect(substr_count($code, 'failUpload({'))
        ->toBe(5, 'one of the five ways an upload can fail leaves nothing on screen');

    // And the one writer really does all five things, the clock included: a dead
    // setInterval repainting a panel that is gone is the leak this shuts.
    $from = (int) strpos($code, 'function failUpload(ending) {');
    $writer = substr($code, $from, 420);

    foreach (['stopClock();', 'upState = null;', 'upXhr = null;', 'busy = false;', 'render();'] as $line) {
        expect(str_contains($writer, $line))->toBeTrue("failUpload() leaves {$line} undone");
    }

    // The refusal shows the SERVER's reason, through the same explainer the
    // toast uses, rather than one sentence for every status.
    expect(str_contains($code, "message: explain(err, 'That file was not accepted.')"))
        ->toBeTrue('a refused upload does not print the reason the server gave');
});

it('does not carry one clip\'s upload panel onto another clip', function () {
    /*
     * THE DEFECT THIS EXISTS FOR, and it is the one a per-screen state variable
     * always has. `upDone` and `freshClip` describe ONE clip. Left behind, a
     * green "clip-big.mp4 · 30.6 MB arrived whole" panel and a loud "Your video
     * is uploaded" section would sit on the step 2 of the NEXT clip the owner
     * opened, describing a file that is not on it — and the cut section would
     * offer to cut a video that clip does not have.
     *
     * Five places leave a clip: the screen's own entry point, starting a new
     * clip, opening a different tile, going back to the list, and deleting.
     *
     * MUTATION NOTE. Remove the forgetUpload() call from the data-ugs-open
     * handler and this is red: 4 instead of 5. RUN: red.
     */
    $code = editorCode();

    expect(str_contains($code, 'function forgetUpload() {'))->toBeTrue();

    expect(substr_count($code, 'forgetUpload();'))
        ->toBe(5, 'one of the five ways of leaving a clip keeps its upload panel');

    // And it really clears all three, not just the visible one.
    $from = (int) strpos($code, 'function forgetUpload() {');
    $body = substr($code, $from, 160);

    foreach (['upState = null;', 'upDone = null;', 'freshClip = null;'] as $line) {
        expect(str_contains($body, $line))->toBeTrue("forgetUpload() leaves {$line} behind");
    }
});

/* ══════════════════════════════════════ the gate is the server's answer ══ */

it('gates the cut on the same answer the server gives about its own ffmpeg', function () {
    /*
     * The one claim in this file that a source scan cannot settle: that
     * `transcoder.available`, which the screen gates the cut on, IS
     * UgcTranscoder::available() and not a different question with a similar
     * name. Asked of the service and of the payload shape together, so a rename
     * on either side is red here rather than on the owner's screen.
     *
     * NOT SKIPPED WHERE THERE IS NO ffmpeg, and that is the point: the suite's
     * own box has none, so this runs the false arm for free — the arm that draws
     * the "Nothing can be cut on this server" section the owner would actually
     * see. `toBeBool()` rather than `toBeFalse()` because a box that HAS ffmpeg
     * must not fail somebody else's run.
     *
     * MUTATION NOTE. Change the controller's payload key from `available` to
     * `can_cut` and this is red, because the screen reads `available`. RUN: red.
     */
    $transcoder = app(UgcTranscoder::class);

    expect($transcoder->available())->toBeBool();

    $controller = (string) file_get_contents(
        app_path('Http/Controllers/Admin/UgcVideoController.php')
    );

    /*
     * TWICE, and both are load-bearing: the library payload the screen gates on,
     * and /derive's own answer, which is how the endpoint tells an owner it could
     * not cut. Pinned as a count because `str_contains` was green against a
     * mutation that renamed only one of them — the screen's own one could be the
     * one renamed and the test would not have noticed. RUN: green, which is why
     * this counts.
     */
    expect(substr_count($controller, "'available' => \$this->transcoder->available(),"))
        ->toBe(2, 'the transcoder answer the screen gates on has been renamed or dropped');

    // And the screen reads exactly that key off exactly that object.
    expect(str_contains(editorCode(), "transcoder = body.transcoder || null;"))->toBeTrue();
    expect(str_contains(editorCode(), 'transcoder && transcoder.available && v.id'))->toBeTrue();
});

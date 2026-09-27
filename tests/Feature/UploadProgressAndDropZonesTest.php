<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\MediaUploadController;
use App\Http\Controllers\Admin\ReviewsIoApiController;
use App\Support\ServerUploadLimits;

/**
 * Lane P2 — every upload in the console reports real bytes, takes a dropped
 * file, and refuses one this server cannot accept BEFORE sending it.
 *
 * ── THE THREE DEFECTS, AS THEY LOOKED ON THE SHOP ───────────────────────────
 *
 *  1. THE SHARED MEDIA PICKER HAD NO DROP TARGET AT ALL. `window.kbbPickMedia`
 *     is the one dialog every image field in the console opens, and the only way
 *     to add a file was the Upload new button. Dropping a photograph on it did
 *     worse than nothing: nothing in this console prevents the default drop, so
 *     the browser NAVIGATED AWAY to the file and took whatever was half-typed
 *     behind the dialog with it.
 *
 *  2. THE PRODUCT EDITOR'S SHARE IMAGE WAS SILENT. Its three upload controls
 *     share one XHR helper, and that helper attaches `xhr.upload.onprogress`
 *     only `if (typeof onProgress === 'function')`. The share image called it
 *     with no callback, so no progress event was ever wired: the operator got a
 *     greyed-out form and nothing else while a 2 MB file went up. Its bars also
 *     had nowhere to go — the progress panel was rendered inside galleryView()
 *     only, so a MAIN image upload showed its bar in the gallery card next door,
 *     and at 390px the two cards stack, which put it below the fold of the
 *     button just pressed.
 *
 *  3. EVERY ONE OF THEM ADVERTISED A CEILING THIS SERVER WILL NOT HONOUR.
 *     `MediaUploadController` allows 5 MB and `ReviewsIoApiController` 4 MB,
 *     while the live box reads upload_max_filesize=2M inside post_max_size=8M.
 *     A 3 MB file is discarded by PHP's own rfc1867 handler (error=1, size=0),
 *     so `required|file` failed and the 422 said the file was not a file; a
 *     9 MB body is discarded before the router, and ValidatePostSize answers 413
 *     with a `message` and no `error` key, which these screens printed as
 *     "Upload failed (413)". Neither refusal named a size and both blamed a file
 *     that was fine. That is the owner's shoppable-video complaint, in three
 *     more places.
 *
 * Every assertion below carries the mutation that turns it red.
 */
function p2Src(string $partial): string
{
    return file_get_contents(base_path("resources/views/admin/partials/{$partial}.blade.php"));
}

/** The three screens this lane owns, by the name their partial goes by. */
function p2Screens(): array
{
    return [
        'media-picker' => p2Src('media-picker'),
        'product-editor-screen' => p2Src('product-editor-screen'),
        'reviews-io-screen' => p2Src('reviews-io-screen'),
    ];
}

/** A private constant, read rather than copied. */
function p2Constant(string $class, string $name): int
{
    return (int) (new ReflectionClass($class))->getConstant($name);
}

/**
 * The same source with its COMMENTS BLANKED, for every assertion of the form
 * "this must not appear".
 *
 * WHY THIS EXISTS, and it is not tidiness. The first version of this file
 * scanned the raw source, and four of its bans went red on its own prose: these
 * screens explain in comments exactly what they must not do -- "refusing against
 * effective_mb * 1048576 would refuse a file this server would have taken",
 * "data.limits.max_kb / 1024, which is twice what this server takes". A ban that
 * a warning about the defect can trip is a ban that punishes writing the warning
 * down, and this repo's whole style is writing it down.
 *
 * Block comments, docblocks, Blade comments and line comments, in that order.
 * The `://` guard keeps a URL in a string from being read as the start of one.
 */
function p2Code(string $src): string
{
    $src = preg_replace('~\{\{--.*?--\}\}~s', '', $src);
    $src = preg_replace('~/\*.*?\*/~s', '', $src);

    return preg_replace('~(?<!:)//[^\n]*~', '', $src);
}

/* ─────────────────────── 1. the ceiling is READ, not assumed ─────────────── */

it('renders each screen a ceiling read off this server, not a number it hoped for', function () {
    /*
     * THE WHOLE POINT OF THE LANE. The owner's words were "put on max limit,
     * without assuming anything". So the number is not in the markup — it is
     * ServerUploadLimits->describe() rendered into a JSON island at request
     * time, which means it is min(app cap, upload_max_filesize, post_max_size
     * less the multipart overhead) on whatever box is serving.
     *
     * MUTATION: hard-code any one of these islands to a literal (say
     * '{"effective_bytes":5242880}') and this goes red on a runner whose PHP
     * caps lower — which is every shared host this shop has ever run on.
     */
    $reader = app(ServerUploadLimits::class);

    foreach ([
        'media-picker' => ['mp-limits', p2Constant(MediaUploadController::class, 'MAX_BYTES')],
        'product-editor-screen' => ['peo-limits', p2Constant(MediaUploadController::class, 'MAX_BYTES')],
        'reviews-io-screen' => ['rio-limits', p2Constant(ReviewsIoApiController::class, 'UPLOAD_MAX_KB') * 1024],
    ] as $partial => [$id, $appCap]) {
        $html = view("admin.partials.{$partial}")->render();

        expect(preg_match('~<script type="application/json" id="'.$id.'">(.*?)</script>~s', $html, $m))
            ->toBe(1, "{$partial} renders no {$id} island, so it is back to guessing");

        $payload = json_decode($m[1], true);

        expect($payload)->toBeArray("{$id} is not valid JSON");

        $expected = $reader->describe($appCap);

        // Key for key, so a screen cannot print one number and refuse against
        // another. `capped_by` is one of REASONS and is switched on in markup.
        foreach (['app_bytes', 'effective_bytes', 'effective_label', 'capped_by', 'capped'] as $key) {
            expect($payload[$key])->toBe($expected[$key], "{$id}.{$key} disagrees with ServerUploadLimits");
        }

        expect($payload['server']['upload_max_filesize'])->toBe((string) ini_get('upload_max_filesize'));
        expect($payload['server']['post_max_size'])->toBe((string) ini_get('post_max_size'));

        expect(ServerUploadLimits::REASONS)->toContain($payload['capped_by']);
    }
});

it('keeps each screen\'s app cap equal to the controller constant it came from', function () {
    /*
     * The two app caps are PRIVATE constants, so each screen carries the number
     * as a literal in its Blade head. THIS is what makes that safe: change
     * MediaUploadController::MAX_BYTES to 8 MB and leave the views alone, and
     * this goes red naming the screen, instead of the console quietly promising
     * a ceiling the endpoint will refuse.
     *
     * MUTATION: edit `5 * 1024 * 1024` in media-picker.blade.php to
     * `4 * 1024 * 1024`. Red, on the first foreach entry.
     */
    $media = p2Constant(MediaUploadController::class, 'MAX_BYTES');
    $reviews = p2Constant(ReviewsIoApiController::class, 'UPLOAD_MAX_KB');

    // Sanity: the constants themselves are the ones this lane measured against.
    expect($media)->toBe(5 * 1024 * 1024)
        ->and($reviews)->toBe(4096);

    $expect = [
        'media-picker' => '$mpAppCap = 5 * 1024 * 1024;',
        'product-editor-screen' => 'describe(5 * 1024 * 1024)',
        'reviews-io-screen' => 'describe(4096 * 1024)',
    ];

    foreach (p2Screens() as $name => $src) {
        expect(str_contains($src, $expect[$name]))
            ->toBeTrue("{$name} no longer carries the app cap its endpoint enforces");
    }
});

it('refuses an oversized file against the exact byte ceiling, never a floored megabyte', function () {
    /*
     * effective_mb is FLOORED — 2,097,151 bytes is "1 MB". A pre-flight written
     * against effective_mb * 1048576 would therefore refuse files this server
     * would have taken, which is the same class of lie in the other direction.
     *
     * MUTATION: change capBytes() in any of the three to
     * `LIMITS.effective_mb * 1048576`. Red here.
     */
    foreach (p2Screens() as $name => $src) {
        $code = p2Code($src);

        expect(str_contains($code, 'LIMITS.effective_bytes'))
            ->toBeTrue("{$name} does not read the exact byte ceiling");

        // capBytes() itself, isolated: the floored megabyte must not be in it.
        expect(preg_match('/function capBytes\(\)\{?(.*?)\n  \}/s', $code, $m))
            ->toBe(1, "{$name} has no capBytes()");

        expect(str_contains($m[1], 'effective_mb'))
            ->toBeFalse("{$name} refuses against a floored megabyte again");

        // And the refusal happens before anything is sent.
        expect(preg_match('/(size|file\.size) > ceiling/', $code))
            ->toBe(1, "{$name} has no pre-flight against the ceiling");
    }
});

it('names the ini directive when the server is the thing capping, not just the number', function () {
    /*
     * "2 MB" alone is not actionable — the operator cannot tell whether to split
     * the file or raise a limit, or which limit. Every screen prints the
     * directive and the value the server spells it with, and the vocabulary is
     * CLOSED to the two ini names (rule 5): the markup switches on it.
     *
     * MUTATION: in cappedBy(), return `by` unguarded instead of the two-name
     * check. The ban assertion below goes red.
     */
    foreach (p2Screens() as $name => $src) {
        expect(str_contains($src, "by === 'upload_max_filesize' || by === 'post_max_size'"))
            ->toBeTrue("{$name} no longer bounds capped_by to the two names it prints");

        expect(str_contains($src, 'serverIni'))
            ->toBeTrue("{$name} never quotes the ini value the operator has to go and edit");
    }
});

/* ─────────────────────────── 2. the 413 is explained ────────────────────── */

it('explains a 413 instead of printing its status code', function () {
    /*
     * Laravel 11's global ValidatePostSize throws before the router, so its
     * response never reaches a controller and carries `message` and no `error`
     * key. MEASURED in Chromium against a 9 MB body on this box, that message is
     * exactly "The POST data is too large." — which is what the review importer
     * printed as its banner, word for word, and names no size, no ceiling and no
     * directive. It is the same sentence for a 9 MB file as for a 900 MB one, so
     * it is REPLACED rather than printed.
     *
     * MUTATION: delete the `status === 413` branch from failWords() in any of
     * the three. Red here.
     */
    foreach (p2Screens() as $name => $src) {
        expect(preg_match('/status === 413/', $src))
            ->toBe(1, "{$name} has no 413 branch, so an oversized body is a bare status code again");

        expect(str_contains($src, 'function failWords'))
            ->toBeTrue("{$name} lost its failure-wording helper");
    }
});

/* ──────────────────── 3. real bytes, patched in place ───────────────────── */

it('reports real bytes from a real XHR, and never fabricates a percentage', function () {
    /*
     * fetch cannot report upload progress — its request body is consumed
     * opaquely — so every uploader here is XMLHttpRequest. And the bar is capped
     * at 99 while sending on purpose: 100% has to mean "the server has
     * answered", because the answer can still be a 422 and a full bar beside a
     * refusal is how a screen loses the operator.
     *
     * MUTATION: drop the `e.lengthComputable` guard, or change
     * `Math.min(99, ...)` to `Math.min(100, ...)`. Red here.
     */
    foreach (p2Screens() as $name => $src) {
        expect(str_contains($src, 'xhr.upload.onprogress'))
            ->toBeTrue("{$name} has no upload progress at all");

        expect(str_contains($src, 'e.lengthComputable'))
            ->toBeTrue("{$name} would report a fabricated percentage for a chunked request");

        expect(str_contains($src, 'Math.min(99,'))
            ->toBeTrue("{$name} lets the bar reach 100% before the server has answered");

        // The gap between the last byte and the answer is its own stage rather
        // than a bar parked at 99% — see the is-server rules in each stylesheet.
        expect(str_contains($src, "stage('server')"))
            ->toBeTrue("{$name} no longer distinguishes 'sent' from 'accepted'");
    }
});

it('patches the bar in place and never re-renders the screen from a progress event', function () {
    /*
     * A full render per progress event would rebuild the product editor's
     * contenteditable panes dozens of times a second and throw away whatever
     * was being typed, rebuild the picker's grid and drop the operator's ticks,
     * and — worst — replace the CSV importer's file input, whose selection
     * cannot be restored from script. That last one is the defect
     * ReviewScreensRepaintTest already pins from the other side.
     *
     * MUTATION: call render() from the onProgress callback in
     * product-editor-screen. Red here.
     */
    foreach ([
        'media-picker' => ['paintUploads', 'paint('],
        'product-editor-screen' => ['paintUploads', 'render('],
        'reviews-io-screen' => ['paintUp', 'render('],
    ] as $name => [$patcher, $heavy]) {
        $src = p2Screens()[$name];

        expect(str_contains($src, "function {$patcher}("))
            ->toBeTrue("{$name} lost its in-place painter");

        // The onProgress body, isolated, must not reach for the heavy repaint.
        expect(preg_match('/onProgress: function\(p\)\{?(.*?)\n        \},/s', $src, $m))
            ->toBe(1, "{$name} has no onProgress callback to inspect");

        expect(str_contains($m[1], $heavy))
            ->toBeFalse("{$name} repaints the whole screen on every progress event");

        expect(str_contains($m[1], $patcher.'()'))
            ->toBeTrue("{$name} never repaints the bar it just moved");
    }
});

it('measures no layout, beyond the one call that was already here', function () {
    /*
     * Rule 4. Every bar this lane adds is a width in a percentage and a keyframe
     * on background-position; nothing it added asks the browser how wide or how
     * tall anything is.
     *
     * THE ONE EXCEPTION IS RECORDED RATHER THAN EXEMPTED. The product editor's
     * rich-text boxes have a "view HTML" toggle that sizes the source textarea to
     * the pane it replaces -- `Math.max(160, area.offsetHeight)` -- and it
     * predates this lane by a long way. It runs once, on a click, and there is no
     * calc() answer to "how tall is the box I am replacing". Deleting it to green
     * a test would break a control that works, which rule 1 forbids; so it is
     * pinned to ONE occurrence, on the line it is on, and a second one anywhere
     * fails.
     *
     * MUTATION: add `el.getBoundingClientRect()` anywhere in one of the three, or
     * a second offsetHeight to the editor. Red, naming the API and the file.
     */
    foreach (p2Screens() as $name => $src) {
        $code = p2Code($src);

        foreach ([
            'getBoundingClientRect', 'offsetWidth', 'offsetTop',
            'clientWidth', 'clientHeight', 'scrollWidth', 'getComputedStyle',
        ] as $api) {
            expect(str_contains($code, $api))
                ->toBeFalse("{$name} measures layout with {$api}; this project sizes with calc()");
        }

        $allowed = $name === 'product-editor-screen' ? 1 : 0;

        expect(substr_count($code, 'offsetHeight'))
            ->toBe($allowed, "{$name} measures a height with offsetHeight");
    }

    // And it is still the rich-text toggle, not something new wearing its name.
    expect(str_contains(
        p2Screens()['product-editor-screen'],
        "code.style.height = Math.max(160, area.offsetHeight) + 'px';"
    ))->toBeTrue('the one allowed measurement is no longer the rich-text source toggle');
});

/* ───────────────────────────── 4. drag and drop ─────────────────────────── */

it('gives every upload on all three screens a real drop target', function () {
    /*
     * Before this, the gallery's 140px dashed button was the ONLY file target in
     * these three files. Everywhere else a dropped file made the browser
     * navigate away to it, because the console prevents no default drop.
     *
     * MUTATION: remove the dropZone(...) call from any one screen. Red here.
     */
    foreach (p2Screens() as $name => $src) {
        expect(str_contains($src, 'function dropZone('))
            ->toBeTrue("{$name} has no drop-zone helper");

        expect(preg_match_all('/dropZone\((?!el, o\)|node, o\))/', $src))
            ->toBeGreaterThan(0, "{$name} defines a drop zone and never registers one");

        // preventDefault on dragover is what makes a node a valid target at all,
        // and it is also what stops the navigate-away.
        expect(str_contains($src, "node.addEventListener('dragover', over)"))
            ->toBeTrue("{$name} lost the dragover binding, so nothing is a drop target");
    }
});

it('registers all three of the product editor\'s controls as zones, exactly once each', function () {
    /*
     * PINNING THE FINISHED SHAPE, not the absence of one. Zero is the "built,
     * never wired" failure this repo keeps finding; two would bind the same card
     * twice and upload every dropped file twice.
     *
     * MUTATION: duplicate the '#peo-ogzone' entry in the zone list. Red.
     */
    $editor = p2Screens()['product-editor-screen'];

    foreach (['#peo-galzone', '#peo-mainzone', '#peo-ogzone'] as $sel) {
        expect(substr_count($editor, "['".$sel."'"))
            ->toBe(1, "{$sel} is registered {$sel} times other than once");
    }

    // And each card really carries that id, once.
    foreach (['peo-galzone', 'peo-mainzone', 'peo-ogzone'] as $id) {
        expect(substr_count($editor, 'id="'.$id.'"'))
            ->toBe(1, "{$id} appears more than once in the markup, or not at all");
    }

    /*
     * And each zone says "each" only where there IS more than one. The gallery
     * is multiple; the main image and the share image take one file, and "up to
     * 2 MB each" under a single-file control reads as though there were a second
     * limit somewhere. Read off a 1280px screenshot, not reasoned.
     *
     * MUTATION: pass `true` to the share image's capSentence(). Red here.
     */
    expect(substr_count($editor, 'capSentence(true)'))
        ->toBe(1, 'the gallery no longer says the ceiling is per file, or a single-file zone does');

    expect(substr_count($editor, 'capSentence(false)'))
        ->toBe(2, 'the main image and the share image do not both print a singular ceiling');
});

it('honours the accept list on a drop, because the browser does not', function () {
    /*
     * A browser applies an <input accept> to its own file dialog and to NOTHING
     * else, so a drop bypasses it entirely. Without this, dropping a .zip on the
     * product gallery would post it to an image endpoint and dropping a .jpg on
     * the review importer would post it to the CSV parser.
     *
     * The image zones repeat 'image/*' and the CSV zone repeats its own list.
     * AdminMediaPickerEverywhereTest pins the INPUT attributes; this pins that
     * the drop path was given the same rule.
     *
     * MUTATION: delete the `accept:` line from the picker's dropZone call. Red.
     */
    $picker = p2Screens()['media-picker'];
    $editor = p2Screens()['product-editor-screen'];
    $io = p2Screens()['reviews-io-screen'];

    expect(str_contains($picker, "accept: 'image/*'"))->toBeTrue('the picker drops any file type');
    expect(str_contains($editor, "accept: 'image/*'"))->toBeTrue('the editor drops any file type');
    expect(str_contains($io, "accept: '.csv,.txt,text/csv,text/plain'"))
        ->toBeTrue('the CSV importer drops any file type');

    foreach (p2Screens() as $name => $src) {
        expect(str_contains($src, 'function matchesAccept('))
            ->toBeTrue("{$name} never checks a dropped file against its accept list");
    }
});

it('never lets a dragged file be read as a reorder, or a reorder as a file', function () {
    /*
     * THE DEFECT, PRECISELY. The product editor's gallery tiles called
     * e.preventDefault() on ANY dragover. So dragging a JPEG from the desktop
     * over an existing thumbnail lit the thumbnail up green — promising a drop —
     * and the drop handler then found `from === null` and returned, doing
     * nothing at all. Refusing a file drag on the tile is what lets the event
     * reach the card's zone, which uploads it.
     *
     * The shoppable-video screen paid for this lesson first; this is the same
     * guard on the same kind of collision.
     *
     * MUTATION: delete `|| carriesFiles(e)` from the tile's dragover. Red here.
     */
    $editor = p2Screens()['product-editor-screen'];

    expect(str_contains($editor, 'function carriesFiles('))
        ->toBeTrue('the editor can no longer tell a file drag from a panel drag');

    // The tile handlers, isolated: both of them refuse a file.
    expect(preg_match_all('/if \(from === null \|\| carriesFiles\(e\)\) return;/', $editor))
        ->toBeGreaterThanOrEqual(2, 'a gallery tile lights up for a dragged photograph again');

    // And the file zones refuse a drag that is not carrying files.
    expect(str_contains($editor, 'if (!carriesFiles(e)) return;'))
        ->toBeTrue('a dragged panel now lights up the file zones');
});

it('tears a zone down before binding the next one, on the screens that re-render', function () {
    /*
     * The product editor and the CSV importer both replace #content wholesale,
     * and the editor does it on every keystroke that marks the form dirty. A
     * zone bound to the previous render's node is a listener, plus the element
     * it closes over, that never goes.
     *
     * The picker is the exception BY CONSTRUCTION — its dialog is built once and
     * kept for the life of the document, which is what MediaPickerWiringTest
     * pins from the other side — so it binds once and never tears down.
     *
     * MUTATION: delete the teardown loop from the editor's bind(). Red here.
     */
    expect(str_contains(p2Screens()['product-editor-screen'], 'zones.forEach(function(teardown)'))
        ->toBeTrue('the editor leaks a drop zone per render');

    expect(str_contains(p2Screens()['reviews-io-screen'], 'if (zone) { try { zone(); } catch (e) {} zone = null; }'))
        ->toBeTrue('the CSV importer leaks a drop zone per render');

    expect(str_contains(p2Screens()['media-picker'], 'return function teardown()'))
        ->toBeTrue('the picker cannot hand a teardown back, so it does not implement the contract');
});

/* ─────────────────── 5. the shared kit, and the guards ──────────────────── */

it('prefers the console\'s shared uploader and drop zone, and works without them', function () {
    /*
     * upload-kit.blade.php is another lane's file and is not in this branch.
     * Every call site is guarded the way this console already guards
     * window.kbbPickMedia, so these screens work on their own and hand over the
     * moment the kit is on the page.
     *
     * PINNING THE FINISHED SHAPE: the guard is what has to be there, in both
     * directions. An UNGUARDED window.kbbUpload( call would throw on this branch
     * and on any install where the kit has not shipped yet.
     *
     * MUTATION: delete `typeof window.kbbUpload === 'function' &&` — or make
     * send() call window.kbbUpload unconditionally. Red here.
     */
    foreach (['media-picker', 'product-editor-screen'] as $name) {
        $src = p2Screens()[$name];

        expect(str_contains($src, "if (typeof window.kbbUpload === 'function') return window.kbbUpload(o);"))
            ->toBeTrue("{$name} does not prefer the shared uploader");

        expect(str_contains($src, 'return localUpload(o);'))
            ->toBeTrue("{$name} has no fallback, so it needs a file it does not own to work");

        /* Every mention of the global in the CODE is on the guard line: the typeof
           test and the call it guards, and nothing else anywhere. */
        expect(substr_count(p2Code($src), 'window.kbbUpload'))
            ->toBe(2, "{$name} reaches for window.kbbUpload somewhere unguarded");
    }

    foreach (p2Screens() as $name => $src) {
        expect(str_contains($src, "if (typeof window.kbbDropZone === 'function') return window.kbbDropZone(node, o);"))
            ->toBeTrue("{$name} does not prefer the shared drop zone");

        expect(substr_count(p2Code($src), 'window.kbbDropZone'))
            ->toBe(2, "{$name} reaches for window.kbbDropZone somewhere unguarded");
    }
});

it('keeps the CSV importer on its own transport, and says why in the file', function () {
    /*
     * THE ONE PLACE THE SHARED CONTRACT CANNOT SERVE. kbbUpload reports a
     * failure as { status, message, retryable } — the parsed response body is
     * not in it. A 422 FROM THIS ENDPOINT IS THE REPORT: "no rating column",
     * the per-row rejections with their reasons, the counts. Routing this screen
     * through a callback that carries only `message` would replace a table of
     * three hundred rejected rows with one sentence, silently.
     *
     * So the divergence is deliberate, one function wide, and RECORDED — and
     * this test is what keeps it recorded. The moment onFail carries the body,
     * rioUpload becomes send() and this test is the thing to delete.
     *
     * MUTATION: delete the comment naming the reason, or route run() through
     * window.kbbUpload. Red either way.
     */
    $io = p2Screens()['reviews-io-screen'];

    expect(str_contains($io, 'function rioUpload('))
        ->toBeTrue('the CSV importer has no uploader of its own');

    expect(str_contains($io, 'window.kbbUpload'))
        ->toBeTrue('the reason the importer does not use the shared uploader is no longer written down');

    // The body really is carried through, which is the whole reason.
    expect(str_contains($io, 'body: body,'))
        ->toBeTrue('a failure no longer carries the response body');

    expect(str_contains($io, 'if (f.body && (f.body.message || f.body.rejects))'))
        ->toBeTrue('a 422 is no longer rendered as the report');
});

/* ─────────────── 6. the share image: the silent path, fixed ─────────────── */

it('sends the share image through the same reporting queue as the other two', function () {
    /*
     * THE DEFECT. `upload(file, onProgress)` attaches xhr.upload.onprogress only
     * `if (typeof onProgress === 'function')`, and the share image's handler
     * called `await upload(ogFile.files[0])` — one argument. So the one upload
     * path on the screen with no callback was the one upload path with no
     * progress event wired at all, and the operator saw the form go grey.
     *
     * MUTATION: change the ogFile change handler back to calling upload()
     * directly. Red on the first two assertions.
     */
    $editor = p2Screens()['product-editor-screen'];

    expect(str_contains($editor, "takeFiles(ogFile.files, 'og')"))
        ->toBeTrue('the share image is off the reporting queue again');

    // There is no second, callback-less uploader left to fall back into.
    expect(preg_match('/\bawait upload\(/', p2Code($editor)))
        ->toBe(0, 'a callback-less upload() call is back on the screen');

    /*
     * And the bars land in the card that was just clicked. Each of the three
     * panels is rendered exactly once — zero is "built, never mounted", two
     * would draw the same rows twice and Stop the wrong row.
     */
    foreach (['main', 'gallery', 'og'] as $where) {
        expect(substr_count($editor, "uploadPanelHTML('{$where}')"))
            ->toBe(1, "the {$where} progress panel is not rendered exactly once");
    }

    // Rows are tagged with the control that started them, which is what puts
    // them in the right card.
    expect(str_contains($editor, "where: where"))
        ->toBeTrue('an upload row no longer knows which control started it');
});

it('keeps a gallery batch sequential, with a bar and a Stop per file', function () {
    /*
     * The gallery is the only multi-file control. Parallel uploads were
     * rejected: six photographs over one domestic uplink share the bandwidth, so
     * six bars crawl together and none finishes until nearly all do — and it
     * would scramble gallery ORDER, which is the order customers see. An
     * aggregate bar was rejected too: it hides which file failed, and that is
     * the only actionable part of a failed run.
     *
     * MUTATION: replace the `await` in the loop with Promise.all over the list.
     * Red on the first assertion.
     */
    $editor = p2Screens()['product-editor-screen'];

    expect(preg_match('/for \(var i = 0; i < list\.length; i\+\+\) \{.*?await sendOne\(row, list\[i\]\)/s', $editor))
        ->toBe(1, 'the gallery batch is no longer sequential');

    expect(str_contains(p2Code($editor), 'Promise.all'))
        ->toBeFalse('the batch went parallel');

    // One Stop per row, one for the rest of the batch, and a per-row cancel that
    // reaches the request.
    expect(str_contains($editor, 'data-upx="'))->toBeTrue('a row can no longer be stopped');
    expect(str_contains($editor, 'data-upstop="'))->toBeTrue('a batch can no longer be stopped');
    expect(str_contains($editor, 'u.handle.cancel()'))
        ->toBeTrue('Stop no longer aborts the request it belongs to');

    /*
     * One bad file does not abandon the rest — the loop `continue`s. An earlier
     * version broke on the first failure, so choosing six images where the third
     * was oversized silently dropped four, five and six.
     */
    expect(preg_match('/paintUploads\(\);\s*continue;/', $editor))
        ->toBe(1, 'a refused file abandons the rest of the batch again');
});

it('settles the queue when Stop is pressed, from both ends', function () {
    /*
     * FOUND IN CHROMIUM, NOT IN A TEST, AND IT HUNG THE SCREEN. Cancelling is
     * not a failure, so the shared contract reports it as onStage('cancelled')
     * and never through onFail — and the first version of this queue resolved
     * its promise only from onDone and onFail. Pressing Stop therefore aborted
     * the request, painted the row "Stopped", and then awaited a promise nothing
     * would ever settle. Measured at 1280px and 390px: three gallery rows all
     * reading "Stopped", `busy` stuck true, no render after them, and the editor
     * greyed out until the page was reloaded. The review importer did the same
     * thing with `working` and both of its buttons disabled.
     *
     * BOTH ENDS, deliberately. The 'cancelled' stage is the belt; stopOne()
     * calling settle() itself is the braces, so a transport that forgets to
     * report the abort cannot wedge the queue either. Resolving a promise twice
     * is a no-op, so they cannot disagree.
     *
     * MUTATION: delete the `st === 'cancelled'` branch from one onStage AND the
     * `settle()` call from its stopper — either alone leaves the other working,
     * which is the whole point. Red on the pair below.
     */
    foreach ([
        // screen => [the stopper, how the stopper calls it, where it is assigned]
        'media-picker' => ['stopOne', 'u.settle', 'row.settle'],
        'product-editor-screen' => ['stopOne', 'u.settle', 'row.settle'],
        'reviews-io-screen' => ['stopUp', 'up.settle', 'up.settle'],
    ] as $name => [$stopper, $call, $assigned]) {
        $code = p2Code(p2Screens()[$name]);

        // The belt: the cancelled stage resolves.
        expect(preg_match("/if \(st === 'cancelled'\)/", $code))
            ->toBe(1, "{$name} never settles its queue from the cancelled stage");

        // The braces: the stopper resolves too, and does it AFTER cancelling.
        expect(preg_match('/function '.$stopper.'\(\w*\)\{?(.*?)\n  \}/s', $code, $m))
            ->toBe(1, "{$name} has no {$stopper}()");

        expect(str_contains($m[1], $call.'()'))
            ->toBeTrue("{$name}'s {$stopper}() aborts the request and leaves the queue awaiting it");

        expect(strpos($m[1], 'cancel()'))
            ->toBeLessThan(strpos($m[1], $call.'()'), "{$name} settles before it cancels");

        // And the settler is assigned before the request is sent, because Stop
        // can arrive on the very next tick.
        expect(preg_match('/'.preg_quote($assigned, '/').' = function\(\)\{ resolve/', $code))
            ->toBe(1, "{$name} does not hand its row a settler");
    }
});

it('leaves the list of what failed on screen, with the reason on each line', function () {
    /*
     * The panel used to be emptied unconditionally at the end of a run, so a
     * batch in which the third of six photographs was refused ended with a
     * banner reading "2 of 6 images could not be uploaded" and no way to find
     * out which two. Successes disappear; failures stay until cleared.
     *
     * MUTATION: change the filter back to `uploads = [];`. Red here.
     */
    $editor = p2Screens()['product-editor-screen'];

    expect(str_contains($editor, "uploads = uploads.filter(function(x){ return x.state !== 'done'; });"))
        ->toBeTrue('the editor throws away the list of failures again');

    expect(str_contains($editor, 'data-upclear="'))
        ->toBeTrue('there is no way to dismiss the leftover rows');

    // The picker does the same thing for the same reason.
    expect(str_contains(p2Screens()['media-picker'], "uploads = uploads.filter(function(u){ return u.state !== 'done'; });"))
        ->toBeTrue('the picker throws away the list of failures again');
});

/* ────────────── 7. the CSV importer keeps the file it was given ─────────── */

it('makes a dropped CSV the chosen one, rather than the file it silently replaces', function () {
    /*
     * THE TRAP THIS WOULD HAVE WALKED INTO. run() reads
     * `(input && input.files && input.files[0]) || chosen` — the live input
     * FIRST, because that is where a new choice normally comes from, and
     * ReviewScreensRepaintTest pins that order. So choosing file A through the
     * dialog and then DROPPING file B would have imported A while the caption
     * under the box read B.
     *
     * Emptying the input on a drop is what makes the dropped file the chosen
     * one, and it is the only safe direction: a selection cannot be restored
     * from script, so the input can be cleared but never re-filled.
     *
     * MUTATION: delete the `live.value = ''` line. Red here.
     */
    $io = p2Screens()['reviews-io-screen'];

    expect(str_contains($io, '(input && input.files && input.files[0]) || chosen'))
        ->toBeTrue('run() no longer prefers a fresh pick over the held file');

    expect(preg_match('/onFiles: function\(files\)\{?(.*?)\n        \}\n      \}\);/s', $io, $m))
        ->toBe(1, 'the CSV drop handler cannot be isolated');

    expect(str_contains($m[1], "live.value = ''"))
        ->toBeTrue('a dropped CSV can be overruled by a file chosen before it');

    expect(str_contains($m[1], 'chosen = f;'))
        ->toBeTrue('a dropped CSV is never held, so the first repaint loses it');
});

it('prints the ceiling the server keeps on the import card, not the one the app allows', function () {
    /*
     * The line read "Up to 25,000 rows and 4 MB" — `data.limits.max_kb / 1024`,
     * which is ReviewsIoApiController::UPLOAD_MAX_KB and nothing else, and twice
     * what this box takes. The ROW limit still comes from the server payload,
     * because 25,000 rows genuinely is the app's own rule; only the size half
     * moved to the measured ceiling.
     *
     * MUTATION: put `esc(num(data.limits.max_kb / 1024)) + ' MB'` back. Red.
     */
    $io = p2Screens()['reviews-io-screen'];

    expect(str_contains(p2Code($io), 'data.limits.max_kb'))
        ->toBeFalse('the import card advertises the app cap again');

    expect(str_contains($io, "esc(num(data.limits.max_rows)) + ' rows'"))
        ->toBeTrue('the row limit is no longer read from the server');

    expect(str_contains($io, 'var size = capSentence();'))
        ->toBeTrue('the import card no longer prints a measured ceiling');
});

it('says "reading the file" rather than parking a full bar through the server phase', function () {
    /*
     * A CSV is small and goes up in under a second; the SERVER phase is the long
     * one. ReviewCsvImport writes up to 25,000 rows in batches, and a bar that
     * reaches 100% and then sits there for eight seconds reads as a hung screen —
     * which is the whole reason the bar on this screen earns its place at all.
     *
     * MUTATION: change the 'server' label to `'100%'`. Red here.
     */
    $io = p2Screens()['reviews-io-screen'];

    expect(preg_match("/if \(up\.state === 'server'\) return 'Reading the file/", $io))
        ->toBe(1, 'the server phase reads as a finished upload again');

    expect(str_contains($io, 'is-server'))
        ->toBeTrue('the server phase has no visual state of its own');
});

/* ─────────────────────── 8. nothing existing disturbed ─────────────────── */

it('keeps every upload control these screens already had, and adds no new file input', function () {
    /*
     * Rule 1. AdminMediaPickerEverywhereTest scans for <input type="file"> and
     * fails on a new image-accepting one that is not on its allowlist, and reads
     * each accept attribute as literal source text. Every zone here reuses an
     * input that was already on the allowlist; not one was added.
     *
     * MUTATION: add `<input type="file" id="peo-dropfile" accept="image/*">` to
     * the editor. Red here AND in AdminMediaPickerEverywhereTest, by name.
     */
    $counts = [
        'media-picker' => ['mp-file' => 1],
        'product-editor-screen' => ['peo-mainfile' => 1, 'peo-galfile' => 1, 'peo-ogfile' => 1],
        'reviews-io-screen' => ['rio-file' => 1],
    ];

    foreach ($counts as $name => $ids) {
        $src = p2Screens()[$name];

        expect(preg_match_all('/<input[^>]*type="file"/', $src))
            ->toBe(count($ids), "{$name} gained or lost a file input");

        foreach ($ids as $id => $times) {
            expect(substr_count($src, 'id="'.$id.'"'))->toBe($times, "{$id} is not there exactly once");
        }
    }

    // The accept attributes the scanner reads, unchanged and still literal.
    expect(str_contains(p2Screens()['media-picker'], '<input type="file" id="mp-file" accept="image/*" multiple hidden>'))
        ->toBeTrue('the picker\'s accept attribute moved or stopped being a literal');
    expect(str_contains(p2Screens()['reviews-io-screen'], 'accept=".csv,.txt,text/csv,text/plain"'))
        ->toBeTrue('the CSV importer\'s accept attribute moved or stopped being a literal');
});

it('still posts to the one upload endpoint each screen already used', function () {
    /*
     * A second upload path is the trap this repo has avoided twice: the one that
     * drifts is always the one carrying the content-type, size and SVG rules.
     * The transport changed on the CSV importer; the endpoint did not, on any of
     * the three.
     *
     * MUTATION: point any of these at a new path. Red here, and on the picker
     * also in MediaPickerWiringTest, whose regex requires every apiBase() call
     * in that file to be a /media one.
     */
    expect(substr_count(p2Screens()['media-picker'], "apiBase() + '/media/upload'"))->toBe(1);
    expect(substr_count(p2Screens()['product-editor-screen'], "apiBase() + '/media/upload'"))->toBe(1);
    expect(substr_count(p2Screens()['reviews-io-screen'], "apiBase() + '/reviews-io/import'"))->toBe(1);

    // And the CSRF header every write in this console carries. There is no
    // csrf-token meta tag here; the cookie is the only source.
    foreach (p2Screens() as $name => $src) {
        expect(str_contains($src, "setRequestHeader('X-XSRF-TOKEN', cookie('XSRF-TOKEN'))"))
            ->toBeTrue("{$name} sends an upload with no CSRF token");
    }
});

it('escapes every operator string and every ini value it prints', function () {
    /*
     * Rule 5. The two ini strings come off the host rather than out of this
     * application, and the file names come off the operator's disk, so both are
     * treated as untrusted: all four JSON_HEX flags on the way out of Blade, and
     * esc() before anything reaches innerHTML.
     *
     * MUTATION: drop JSON_HEX_TAG from any island, or print u.name without
     * esc(). The first is caught here; the second by reading the row builders,
     * which is why they are asserted rather than the flags alone.
     */
    foreach (p2Screens() as $name => $src) {
        expect(preg_match('/JSON_HEX_TAG \| JSON_HEX_AMP \| JSON_HEX_APOS \| JSON_HEX_QUOT/', $src))
            ->toBe(1, "{$name}'s island is not fully hex-escaped");

        expect(str_contains($src, 'function esc('))
            ->toBeTrue("{$name} lost its escaper");
    }

    // The file name and the state label, both through esc(), in every row.
    expect(str_contains(p2Screens()['media-picker'], "esc(u.name)"))->toBeTrue();
    expect(str_contains(p2Screens()['product-editor-screen'], "esc(u.name)"))->toBeTrue();
    expect(str_contains(p2Screens()['reviews-io-screen'], "esc(up.name)"))->toBeTrue();

    foreach (p2Screens() as $name => $src) {
        expect(preg_match('/esc\((stateLabel\(u\)|upLabel\(\))\)/', $src))
            ->toBe(1, "{$name} prints an upload's state without escaping it");
    }
});

it('gives every bar an aria value a screen reader can read', function () {
    /*
     * A bar that is only a width is a fact only a sighted operator has. Every
     * track carries role=progressbar and an aria-valuenow that paintUploads()
     * moves with the width.
     *
     * MUTATION: delete the setAttribute('aria-valuenow', ...) line from one
     * painter. Red here.
     */
    foreach (p2Screens() as $name => $src) {
        expect(str_contains($src, 'role="progressbar" aria-valuemin="0" aria-valuemax="100"'))
            ->toBeTrue("{$name}'s bar is not announced as a progress bar");

        expect(str_contains($src, "setAttribute('aria-valuenow', String(pct))"))
            ->toBeTrue("{$name} moves the bar without moving the number read aloud");
    }
});

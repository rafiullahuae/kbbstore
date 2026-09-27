<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\UgcVideo;
use App\Support\ServerUploadLimits;
use Tests\Support\UgcAdminRoutes;

/**
 * The shared upload kit, and the two screens that send files through it.
 *
 * ── WHAT THE OWNER REPORTED, AND WHAT IT TURNED OUT TO BE ───────────────────
 *
 * He photographed an upload panel reading
 *
 *     anua mist spray 2.mp4                                    72%
 *     6.0 MB of 8.4 MB sent    Sending to the server. 20s so far.
 *
 * and said the bar was STICKING at 72%. It was not stuck. Measured against a
 * real Chromium and a server reading the request body at a fixed byte rate, on
 * his own 8.4 MB file:
 *
 *   - `xhr.upload.onprogress` fires when the socket buffer drains, in strides of
 *     about 1.6 MB, so the gap between two events is 2,610 ms at 600 KB/s,
 *     5,624 ms at 300 KB/s and 16,764 ms at 100 KB/s. Between events the bar is
 *     EXACTLY still. At his rate that is a five-and-a-half-second freeze, over
 *     and over, on a healthy upload.
 *   - `loaded` counts bytes handed to the KERNEL, not bytes the server took: a
 *     server that read the headers and then never read the body still saw the
 *     browser credit 4,079,616 bytes — 46% — while the application had consumed
 *     ZERO.
 *   - so `xhr.upload.onload` is not "it has arrived". It fired at 12.6 s when the
 *     server had about 4.3 MB of 8.4 MB, and at 37.2 s on a 100 KB/s link for a
 *     transfer that finished near 86 s. The panel's "All of it has arrived. The
 *     server is checking the file" was FALSE by up to 49 seconds.
 *   - and the old fixed 20-second stall threshold is BELOW the legitimate
 *     16,764 ms gap, so on a slow link the screen accused a working upload and
 *     told the owner to cancel it.
 *
 * The full traces are in docs/UPLOAD-LIMITS.md §8.
 *
 * ── WHY A SOURCE SCAN, AND WHERE IT IS NOT ONE ──────────────────────────────
 *
 * Both screens are JavaScript string builders inside Blade partials that nothing
 * renders server-side, so the reasoning UgcEditorColumnsTest sets out applies and
 * the same helper shape is reused: PROSE IS STRIPPED BEFORE ANY SCAN FOR A
 * BEHAVIOUR, because this file and those screens both explain the defects in
 * comments — quoting the wrong sentences verbatim — and a scan of the raw text
 * would match the explanation instead of the code.
 *
 * The cases that are NOT source scans ask the server: the `limits` block on the
 * endpoint the sections screen actually calls, and the multipart arithmetic that
 * decides the ceiling. What cannot be settled in Pest — whether the bar moves,
 * what it says in flight, and whether anything overflows sideways — is measured
 * in tests/browser/lane-p1-upload-kit.mjs, with the numbers in
 * docs/upload-kit-shots/measurements.json.
 */
function kitSource(): string
{
    return (string) file_get_contents(
        resource_path('views/admin/partials/upload-kit.blade.php')
    );
}

/** The same text with the prose taken out: Blade comments, block comments, line comments. */
function stripProse(string $src): string
{
    $src = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $src);
    $src = (string) preg_replace('#/\*.*?\*/#s', '', $src);

    return (string) preg_replace('#^\s*//.*$#m', '', $src);
}

function kitCode(): string
{
    return stripProse(kitSource());
}

function sectionsSource(): string
{
    return (string) file_get_contents(
        resource_path('views/admin/partials/ugc-sections-screen.blade.php')
    );
}

function sectionsCode(): string
{
    return stripProse(sectionsSource());
}

/**
 * The body of uploadHTML() on the sections screen, brace-matched.
 *
 * Written because a scan for the strings a function CONTAINS cannot see what it
 * RETURNS — see the case that needs it, where the mutation survived a test built
 * only out of toContain().
 */
function uploadHtmlBody(string $code): string
{
    $at = strpos($code, 'function uploadHTML');

    if ($at === false) {
        return '';
    }

    $open = strpos($code, '{', $at);

    if ($open === false) {
        return '';
    }

    $depth = 0;
    $len = strlen($code);

    for ($i = $open; $i < $len; $i++) {
        if ($code[$i] === '{') {
            $depth++;
        } elseif ($code[$i] === '}') {
            $depth--;

            if ($depth === 0) {
                return substr($code, $open, $i - $open + 1);
            }
        }
    }

    return '';
}

function librarySource(): string
{
    return (string) file_get_contents(
        resource_path('views/admin/partials/ugc-library-screen.blade.php')
    );
}

function libraryCode(): string
{
    return stripProse(librarySource());
}

/* ------------------------------------------------------------- the contract */

it('puts both halves of the kit on window, once each', function () {
    /*
     * Lane P2 writes three other screens against these two names at the same
     * time as this lane, so they are a contract and not an implementation
     * detail. ONCE EACH is the assertion that matters: a partial included twice
     * would define kbbUpload twice and the second copy would close over its own
     * state, which is how a screen ends up with two uploads fighting over one
     * panel.
     */
    $code = kitCode();

    expect(substr_count($code, 'window.kbbUpload = kbbUpload'))->toBe(1)
        ->and(substr_count($code, 'window.kbbDropZone = kbbDropZone'))->toBe(1);
});

it('has two screens that call the kit and nothing of their own left behind', function () {
    /*
     * THE HALF OF "WIRED UP" THIS LANE OWNS, and it is asserted unconditionally
     * because both of these files are this lane's to get right.
     *
     * A screen that calls window.kbbUpload while ALSO keeping its own transport is
     * the shape that makes a fix look applied and behave as though it were not:
     * the old code path is still reachable and still wrong.
     */
    foreach ([sectionsCode(), libraryCode()] as $screen) {
        expect($screen)->toContain('window.kbbUpload(')
            ->and($screen)->not->toContain('new XMLHttpRequest');
    }

    /* And the partial the integrator has to include really exists and really
       defines what those calls need. */
    expect(is_file(resource_path('views/admin/partials/upload-kit.blade.php')))->toBeTrue();
});

it('is included in the console exactly once, and before the screens that call it', function () {
    /*
     * PINS THE FINISHED STATE, not the absence of it. Zero is the "built, never
     * wired up" shape this repo keeps finding; two defines the globals twice, so
     * the second copy closes over its own state and two uploads fight over one
     * panel. Both are real failures.
     *
     * ── WHY THIS ONE SKIPS RATHER THAN FAILS IN THIS LANE'S WORKTREE ─────────
     *
     * resources/views/admin/app.blade.php is not this lane's file — the
     * integrator adds the one @include line — so the assertion below cannot be
     * green here on the day it is written. It is written as a SKIP-UNTIL-WIRED
     * rather than as a red test for the reason CLAUDE.md gives: a lane must not
     * hand over a failing suite, and it must not pin the ABSENCE of its own
     * wiring either, because that assertion goes red the moment the integrator
     * does the one thing the lane asked for.
     *
     * The moment the include lands this becomes a hard assertion and stays one.
     * The test above it is the unconditional half, and it is the half that covers
     * everything this lane can actually control.
     *
     * THE ORDER IS LOAD-BEARING IN ONE DIRECTION ONLY. Every call to
     * window.kbbUpload happens inside an event handler, long after every partial
     * has parsed, so a later include would still work at runtime. What must not
     * happen is the kit being left out while a screen calls it. The include
     * sitting before its callers is how the console already orders media-picker
     * against the screens that use kbbPickMedia, and it is what the integrator
     * was asked for.
     */
    $app = (string) file_get_contents(resource_path('views/admin/app.blade.php'));
    $line = "@include('admin.partials.upload-kit')";

    if (! str_contains($app, $line)) {
        $this->markTestSkipped(
            'app.blade.php does not include the upload kit yet. Add '.$line.' immediately '
            .'before @include(\'admin.partials.ugc-library-screen\') — it must come before both '
            .'UGC screens, which call window.kbbUpload. This test arms itself once that line '
            .'is there.'
        );
    }

    expect(substr_count($app, $line))->toBe(1);

    $kitAt = strpos($app, $line);
    $sectionsAt = strpos($app, "@include('admin.partials.ugc-sections-screen')");
    $libraryAt = strpos($app, "@include('admin.partials.ugc-library-screen')");

    expect($kitAt)->toBeLessThan($sectionsAt)
        ->and($kitAt)->toBeLessThan($libraryAt);
});

/* ------------------------------------- the four measured defects, each pinned */

it('sends with XMLHttpRequest, because fetch cannot report upload progress', function () {
    /*
     * THE DEFECT: the sections screen uploaded with `fetch`, which has no
     * upload-progress event at all, so it could not have had a bar however much
     * it wanted one. That is why the owner watched a disabled panel for thirty
     * seconds with no percentage.
     *
     * MUTATION: swap `new XMLHttpRequest()` for a fetch in the kit and the first
     * expectation is red. Put a `fetch(` back into the sections screen's
     * uploader and the third is red.
     */
    $kit = kitCode();

    expect($kit)->toContain('new XMLHttpRequest()')
        ->and($kit)->toContain('xhr.upload.onprogress')
        /* The handover must be its own event and not a percentage threshold. */
        ->and($kit)->toContain('xhr.upload.onload');

    /* And the screen that used fetch no longer builds its own transport. */
    $sections = sectionsCode();

    expect($sections)->toContain('window.kbbUpload(')
        ->and($sections)->not->toContain('new XMLHttpRequest');
});

it('never fakes, eases or interpolates a percentage', function () {
    /*
     * A bar that sweeps while nothing is known is a bar that lies, and the
     * cheapest way to make a stalled upload look alive is to ease the number
     * toward 100. The kit's percentage is Math.round(loaded/total) off the event
     * and nothing else.
     *
     * MUTATION: add a setInterval that nudges `t.loaded` upward and the last
     * expectation is red, because the only writes to `loaded` are from the event
     * and the handover.
     */
    $kit = kitCode();

    expect($kit)->toContain('Math.round(this.loaded / this.total * 100)');

    /* No indeterminate mode dressed as a number, anywhere in either screen. */
    foreach (['indeterminate', 'Math.random'] as $forbidden) {
        expect(kitCode())->not->toContain($forbidden)
            ->and(sectionsCode())->not->toContain($forbidden);
    }

    /* `loaded` is written in exactly two places: the progress event and the
       handover. A third writer is an interpolator. */
    expect(substr_count($kit, 't.loaded ='))->toBe(2);
});

it('separates the elapsed clock from the quiet clock', function () {
    /*
     * THE DEFECT LANE U1 SHIPPED AND A SCREENSHOT CAUGHT: one clock meant a
     * 23-second upload read "1s so far", because "time since the last byte
     * moved" is 0 or 1 BY DEFINITION while bytes are moving. The elapsed total is
     * the honest number while sending; the quiet interval is the honest number
     * only once it IS a wait.
     *
     * MUTATION: make elapsed() return quietFor() and the sending sentence loses
     * its "Ns so far" — which the browser test asserts against a real upload,
     * and which this pins structurally: the two must be computed from different
     * origins.
     */
    $kit = kitCode();

    /* elapsed() counts from the start of the request... */
    expect($kit)->toContain('Date.now() - this.startedAt')
        /* ...and quietFor() from the last thing that moved, which is a different
           origin and the whole point. */
        ->and($kit)->toContain('this.handoverAt')
        ->and($kit)->toContain('Date.now() - since');

    /* Both appear in the sending sentence's own composition, so a screen printing
       `text` gets the elapsed total and not the quiet interval. */
    expect($kit)->toContain("var el = this.elapsed();");
});

it('does not claim the file has arrived when the last byte has only left the browser', function () {
    /*
     * THE MEASURED LIE. xhr.upload.onload fires when the last byte enters the
     * kernel buffer. On a 100 KB/s link it fired at 37.2 s for a transfer the
     * server did not finish receiving until roughly 86 s, and the panel said
     * "All of it has arrived. The server is checking the file" for all 49 of
     * those seconds.
     *
     * MUTATION: put the words "has arrived" back into stageText()'s server branch
     * and the second expectation is red.
     */
    $kit = kitCode();

    expect($kit)->toContain('has left your browser')
        ->and($kit)->toContain('still taking delivery');

    /* The false sentence is gone from the code of BOTH screens. The library
       screen's prose still quotes it, deliberately, which is why prose is
       stripped before this scan. */
    expect($kit)->not->toContain('All of it has arrived')
        ->and(libraryCode())->not->toContain('All of it has arrived')
        ->and(sectionsCode())->not->toContain('All of it has arrived');
});

it('calibrates the stall threshold instead of fixing it at twenty seconds', function () {
    /*
     * THE DEFECT, AND IT IS THE WORST OF THE FOUR because it advised the owner to
     * destroy working work. A fixed 20-second threshold is below the LEGITIMATE
     * 16,764 ms gap measured at 100 KB/s, and far below the ~33 s a 50 KB/s link
     * produces, so the panel announced "nothing has moved for 20s — if it is
     * stuck, Cancel and try again" during a healthy upload.
     *
     * stallAfter() computes it from the stride and the speed THIS upload is
     * producing. With the measured 1.65 MB stride that is 25 s at 600 KB/s, 49 s
     * at 100 KB/s and 99 s at 50 KB/s.
     *
     * MUTATION: replace stallAfter()'s body with `return 20;` and the first two
     * expectations are red. Delete the floor and a fast link never warns at all.
     */
    $kit = kitCode();

    expect($kit)->toContain('this.step / bps')
        ->and($kit)->toContain('STALL_MULT * expected')
        /* A floor above every legitimate gap measured, and a ceiling so a very
           slow link still eventually gets told. */
        ->and($kit)->toContain('var STALL_FLOOR = 25')
        ->and($kit)->toContain('var STALL_CEIL = 120');

    /* And no screen keeps a threshold of its own any more — one copy, or the fix
       reaches one screen and not the other. */
    expect(libraryCode())->not->toContain('STALL_AFTER')
        ->and(sectionsCode())->not->toContain('STALL_AFTER');
});

it('computes speed from a window that excludes the socket-buffer burst', function () {
    /*
     * MEASURED: 4,046,848 bytes were credited 103–104 ms after send() in EVERY
     * run at EVERY rate, because that is the socket buffer and not the link.
     * Averaged from the start of the upload that is 39 MB/s and an ETA of zero
     * seconds — a number the next repaint contradicts. From the second sample on
     * the same window reads 279–309 KB/s against a true 300 KB/s and 101.6 KB/s
     * against a true 100 KB/s: between 1.6% and 7% out.
     *
     * MUTATION: change `this.samples.slice(1)` to `this.samples.slice(0)` and the
     * first expectation is red; the browser test then records an ETA of "about 1
     * seconds" on the first event of a throttled upload.
     */
    $kit = kitCode();

    expect($kit)->toContain('this.samples.slice(1)')
        /* Two usable samples, far enough apart to be a rate rather than one
           buffer drain seen twice. */
        ->and($kit)->toContain('if (usable.length < 2) return null')
        ->and($kit)->toContain('SPEED_MIN_SPAN_MS')
        /* And where it cannot answer it says nothing rather than guessing. */
        ->and($kit)->toContain('return null');
});

it('offers Try again only where a second press could work', function () {
    /*
     * 429 is a throttle that clears and 5xx is a server that fell over; both are
     * worth another try. 413 and 422 are not — the request was too big for this
     * server, or the bytes were refused on their merits — and both fail
     * identically the second time. A Try again beside "this server accepts at
     * most 9.9 MB" is a button whose only outcome is the message above it.
     *
     * MUTATION: change retryable() to `return true` and this is red.
     */
    expect(kitCode())->toContain('return status === 429 || status >= 500');

    /* The button is drawn only where the ending kept the file. */
    expect(sectionsCode())->toContain('upDone.retry')
        ->and(sectionsCode())->toContain('f.retryable ? file : null');
});

it('handles 413 separately, because Laravel answers it before any controller', function () {
    /*
     * THE DEFECT THAT MADE EVERY REFUSAL READ THE SAME. Laravel 11's
     * Illuminate\Http\Middleware\ValidatePostSize is in the GLOBAL stack and
     * throws PostTooLargeException before the router runs, so no controller
     * composes the body: it carries `message` and has NO `error` key. Every
     * screen in this console read `body.error`, got undefined, and printed its
     * fallback — which is why "That file was not accepted." was shown for files
     * that were perfectly fine.
     *
     * MUTATION: delete the `status === 413` branch from the kit's explain() and
     * this is red; the browser test then shows the generic fallback for a 413.
     */
    $kit = kitCode();

    expect($kit)->toContain('status === 413')
        ->and($kit)->toContain('post_max_size')
        /* The server's own composed sentence is still preferred where there is
           one — App\Support\UploadArrival writes exactly that key. */
        ->and($kit)->toContain("typeof body.error === 'string'");
});

it('hands the response body to onFail, so a screen can relearn the ceiling', function () {
    /*
     * A REGRESSION THIS LANE NEARLY SHIPPED, caught by reading the call sites
     * rather than by a test — which is why there is now a test.
     *
     * The upload endpoint returns its `limits` block WITH every refusal it
     * composes, and ugc-library-screen.blade.php has used that since
     * App\Support\ServerUploadLimits was written: a screen proved wrong about
     * the ceiling corrects itself in the same round trip that proved it wrong.
     * The kit's first draft passed only {status, message, retryable} to onFail,
     * so `f.body.limits` was undefined forever and that behaviour was silently
     * gone. Rule 1: nothing that already works may change.
     *
     * MUTATION: drop `body` from the fail() signature, or stop passing it at the
     * non-2xx call site, and this is red.
     */
    $kit = kitCode();

    expect($kit)->toContain('function fail(status, message, retryable, body)')
        ->and($kit)->toContain('body: body || null')
        /* And the one ending that HAS a response really passes it. */
        ->and($kit)->toContain('retryable(xhr.status), body)');

    /* Both screens read it back, so the refresh actually happens. */
    expect(libraryCode())->toContain('f.body && f.body.limits');
});

it('says so rather than throwing when the kit is not on the page', function () {
    /*
     * THE ONE WIRING MISTAKE THAT CAN HAPPEN, made survivable.
     *
     * window.kbbUpload is defined by a partial the integrator includes. If that
     * line is dropped, or a stale compiled view survives a package — the exact
     * thing this release's clear_caches migration exists to prevent — then
     * calling it raises "kbbUpload is not a function" INSIDE a click handler.
     * Nothing catches that: `busy` stays true and the screen is locked with no
     * message, which is the shape of a hang and is what the owner would report.
     *
     * Both screens check first, unlock, and name the fix. The console already
     * does exactly this for window.kbbPickMedia.
     *
     * MUTATION: delete either guard and this is red.
     */
    foreach ([sectionsCode(), libraryCode()] as $screen) {
        expect($screen)->toContain("typeof window.kbbUpload !== 'function'")
            /* Unlocked, or the guard swaps a TypeError for a quieter lock. */
            ->and($screen)->toContain('The uploader is not loaded on this page.');
    }

    /* The drop zones are optional in the same way and already checked. */
    expect(sectionsCode())->toContain("typeof window.kbbDropZone !== 'function'");
});

it('reads the CSRF token from the cookie, because this console has no meta tag', function () {
    /*
     * There is no <meta name="csrf-token"> in the admin. Reading one returns null
     * and the request comes back 419, which reads on screen as a mysterious
     * refusal of a good file — the exact fault ugc-sections-screen.blade.php
     * records in its own cookie() docblock, where it cost the owner every write
     * on the screen.
     *
     * MUTATION: swap the header for a meta read and this is red.
     */
    $kit = kitCode();

    expect($kit)->toContain("setRequestHeader('X-XSRF-TOKEN'")
        ->and($kit)->toContain("cookie('XSRF-TOKEN')")
        ->and($kit)->not->toContain('csrf-token');
});

/* --------------------------------------------------------------- the ceiling */

it('stops advertising 64MB on the screen the owner photographed', function () {
    /*
     * THE DEFECT, IN ONE STRING LITERAL. The Files tab printed 'Up to 64MB.'
     * 64 MB is UgcMedia::MAX_BYTES[KIND_CLIP] — the APP's cap — and on the live
     * box the real ceiling is 9.9 MB, because post_max_size is 10M while
     * upload_max_filesize is already 100M. The line was wrong by a factor of six
     * and a half, in the one place he reads before choosing a file.
     *
     * MUTATION: put 'Up to 64MB' back in modalBodyHTML() and this is red.
     */
    $sections = sectionsCode();

    expect($sections)->not->toContain('Up to 64MB')
        ->and($sections)->not->toContain('up to 64MB')
        /* It prints the server's own number instead, and there is deliberately
           no numeric fallback to fall back to. */
        ->and($sections)->toContain('capNote(')
        ->and($sections)->toContain('effective_label');
});

it('answers the ceiling on the endpoint the sections screen actually calls', function () {
    /*
     * THE REASON THAT SCREEN HAD A LITERAL AT ALL: index() has carried `limits`
     * since ServerUploadLimits was written, but the sections screen never calls
     * index() — it opens one clip through show() and nothing else — so it had no
     * number to print.
     *
     * NOT A SOURCE SCAN. This asks the server.
     *
     * MUTATION: remove `'limits' => $this->limits()` from show() and this is red.
     */
    UgcAdminRoutes::wire($this->app);

    $video = UgcVideo::create([
        'title' => 'A clip', 'slug' => 'a-clip-'.uniqid(), 'caption' => '', 'status' => 'draft',
        'source_platform' => 'upload', 'rights_status' => 'pending',
        'rights_evidence' => '', 'position' => 0,
    ]);

    $admin = AdminUser::create([
        'name' => 'Kit owner',
        'email' => 'kit-owner-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);

    $body = $this->actingAs($admin, 'admin')
        ->getJson('/admin-api/ugc-videos/'.$video->id)
        ->assertOk()
        ->json();

    expect($body)->toHaveKey('limits')
        ->and($body['limits'])->toHaveKeys(['clip', 'poster', 'teaser', 'server'])
        ->and($body['limits']['clip'])->toHaveKeys([
            'effective_bytes', 'effective_label', 'app_mb', 'capped', 'capped_by',
        ])
        /* capped_by is printed into markup that switches on it, so it is one of
           the closed vocabulary and never anything else. */
        ->and($body['limits']['clip']['capped_by'])->toBeIn(ServerUploadLimits::REASONS);
});

it('leaves room for the multipart body inside post_max_size', function () {
    /*
     * NOT A SOURCE SCAN, and the arithmetic the coordinator asked to have checked
     * against a real body rather than trusted.
     *
     * MEASURED in Chromium with the owner's exact filename and field name: an
     * 8,808,038-byte file becomes an 8,808,327-byte multipart body — 289 bytes of
     * overhead. A 255-character filename costs 523, and a 255-character filename
     * plus four fields including a 200-character value costs 1,008. The overhead
     * is per-part and FIXED; it does not scale with the file.
     *
     * So MULTIPART_OVERHEAD = 4096 is correct with four times the margin it
     * needs, and his file fitting inside a 10M post_max_size with 1.6 MB to spare
     * is why this was never the post_max_size fault it looked like.
     *
     * MUTATION: set MULTIPART_OVERHEAD to 0 and the second expectation is red —
     * a file of exactly post_max_size cannot fit in a request that carries it.
     */
    $limits = ServerUploadLimits::of(perFile: 104857600, perRequest: 10485760);

    $ceiling = $limits->ceiling(67108864);

    /* The binding limit is post_max_size less the overhead, not the file limit. */
    expect($ceiling)->toBe(10485760 - ServerUploadLimits::MULTIPART_OVERHEAD)
        ->and($ceiling)->toBeLessThan(10485760)
        ->and($limits->reason(67108864))->toBe(ServerUploadLimits::BY_PER_REQUEST);

    /* The owner's 8.4 MB file, and the body Chromium really built from it. */
    $file = 8808038;
    $body = 8808327;

    expect($file)->toBeLessThan($ceiling)
        ->and($body - $file)->toBeLessThan(ServerUploadLimits::MULTIPART_OVERHEAD)
        ->and($body)->toBeLessThan(10485760);
});

/* ------------------------------------------------------- the drop zone itself */

it('keeps every accept a literal where the media-picker guard can read it', function () {
    /*
     * AdminMediaPickerEverywhereTest reads these FILES rather than the rendered
     * page, and treats a <input type=file> with no visible accept as an image
     * picker that should have gone through the shared Media Library.
     * Concatenating the attribute makes it invisible to that guard, which is a
     * defect this screen has already been reported for once.
     *
     * MUTATION: build either accept by concatenation and this is red.
     */
    $sections = sectionsCode();

    expect($sections)->toContain('accept="video/mp4,video/webm,video/quicktime"')
        ->and($sections)->toContain('accept="video/mp4,video/webm"')
        /* The poster's drop zone takes images and has NO file input, so the
           picker stays the only way to choose one from disk.

           MATCHED AS A FILE INPUT, not as the bare string 'accept="image/':
           data-ugx-accept="image/jpeg,..." CONTAINS that substring, so the naive
           scan failed on the very attribute it was written to allow. What is
           forbidden is an <input type="file"> carrying an image accept, which is
           what AdminMediaPickerEverywhereTest itself looks for. */
        ->and($sections)->toContain('data-ugx-accept="image/jpeg,image/png,image/webp"');

    expect(preg_match('/<input[^>]*type="file"[^>]*accept="image\//i', $sections))->toBe(0);
});

it('refuses a drag that is not carrying files, so a reorder does not light up a zone', function () {
    /*
     * A DEFECT THIS REPO HAS ALREADY PAID FOR. The clip editor's tagged product
     * list is reorderable by dragging and shares a document with the upload
     * zones: without this check the zones lit up green while somebody reordered
     * products, promising a drop that would then do nothing at all.
     * ugc-library-screen.blade.php guards its own delegated handlers with the row
     * it is dragging; a reusable zone cannot see that variable, so it asks the
     * drag what it carries.
     *
     * MUTATION: make carriesFiles() return true unconditionally and this is red.
     */
    $kit = kitCode();

    expect($kit)->toContain('function carriesFiles')
        ->and($kit)->toContain("=== 'files'")
        /* Refused on the dragover AND on the drop; one without the other is a
           zone that highlights and then swallows. */
        ->and(substr_count($kit, 'carriesFiles(e)'))->toBeGreaterThanOrEqual(3);
});

it('states a wrong file rather than swallowing it, and keeps the click path', function () {
    /*
     * A zone that silently ignores a wrong file is indistinguishable from a zone
     * that is broken, and the owner re-drops it. And the drop must be an ADDITION
     * to choosing: binding a click handler in the kit would have hijacked the
     * <label> that already opens the file dialog.
     *
     * MUTATION: delete the `said(...)` call in the reject branch and this is red.
     */
    $kit = kitCode();

    expect($kit)->toContain('is not a file this box takes')
        /* No click handler on the zone at all — the label still does that. */
        ->and($kit)->not->toContain("addEventListener('click'")
        /* Keyboard reachable, forwarded to whatever the caller already wired, so
           there is one behaviour and not two. */
        ->and($kit)->toContain("el.addEventListener('keydown', onKey)")
        ->and($kit)->toContain('el.click()');
});

it('tears a zone down before mounting a fresh one', function () {
    /*
     * THE DIALOG BODY IS REPLACED WHOLESALE on every repaint, so the zones are
     * new nodes each time. Without running the previous teardowns, every repaint
     * adds another set of listeners and one drop fires the uploader as many times
     * as the tab has been opened — which on the Files tab means the same file
     * uploaded three or four times over.
     *
     * MUTATION: delete the zoneOffs.forEach at the top of mountZones() and this
     * is red.
     */
    $sections = sectionsCode();

    expect($sections)->toContain('function mountZones')
        ->and($sections)->toContain('zoneOffs.forEach')
        ->and($sections)->toContain('zoneOffs = []')
        /* And the teardown runs when the dialog closes, not only on a repaint. */
        ->and(substr_count($sections, 'zoneOffs.forEach'))->toBeGreaterThanOrEqual(2);
});

/* ------------------------------------------------------------ the panel's end */

it('keeps both endings on screen, success and failure alike', function () {
    /*
     * THE DEFECT: the sections screen threw the panel away the instant the
     * request landed — no bar, no tick, no reason, just a toast — so a refused
     * upload and a finished one looked identical, and a stalled one looked like
     * both. A panel that vanishes when the request lands is indistinguishable
     * from a stall.
     *
     * MUTATION: make uploadHTML() return '' when upDone is set and this is red.
     */
    $sections = sectionsCode();

    expect($sections)->toContain('function uploadHTML')
        ->and($sections)->toContain('if (!upDone)')
        ->and($sections)->toContain('upDone.ok')
        ->and($sections)->toContain('ugx-up is-bad')
        /* The panel is drawn into the Files tab, or none of the above is
           reachable. */
        ->and($sections)->toContain('+ uploadHTML()');

    /*
     * ── AND THE SHAPE OF THE FUNCTION, WHICH IS WHAT ACTUALLY CATCHES IT ─────
     *
     * THE FIRST DRAFT OF THIS CASE DID NOT ASSERT ANYTHING. The mutation it
     * claims — "make uploadHTML() return '' when upDone is set" — was run and
     * came back GREEN, because every string above survives an early return added
     * underneath them. A scan for the strings a function contains says nothing
     * about what it returns.
     *
     * uploadHTML() has exactly ONE bail-out, and it is the one case with nothing
     * to draw: no upload in flight and no ending yet. A SECOND `return ''` is
     * precisely the mutation, and there is no honest reason for one — every other
     * path returns a panel.
     *
     * The behaviour itself — the panel still being in the DOM after a refusal —
     * is asserted against a real failed upload in
     * tests/browser/lane-p1-upload-kit.mjs, because that is a question about a
     * live document and not about source text.
     */
    $body = uploadHtmlBody($sections);

    expect($body)->not->toBe('')
        ->and(substr_count($body, "return ''"))->toBe(1);
});

it('measures no layout anywhere in the kit', function () {
    /*
     * RULE 4. This project sizes with calc() and the kit writes exactly one
     * number into a style — a percentage the upload event handed over — which is
     * a write and not a read.
     *
     * MUTATION: add a getBoundingClientRect() call anywhere in the kit and this
     * is red.
     */
    /*
     * kitCode() AND NOT kitSource(): the docblock promises in prose that none of
     * these APIs appears, naming every one of them, so scanning the raw text
     * matches the promise instead of the code. Stripping the comments first is
     * the same reasoning UgcEditorColumnsTest sets out, and the first draft of
     * this case failed on its own docblock.
     */
    $kit = kitCode();

    foreach ([
        'getBoundingClientRect', 'offsetWidth', 'offsetHeight', 'clientWidth',
        'clientHeight', 'scrollWidth', 'scrollHeight', 'getComputedStyle',
        'requestAnimationFrame',
    ] as $api) {
        expect($kit)->not->toContain($api);
    }
});

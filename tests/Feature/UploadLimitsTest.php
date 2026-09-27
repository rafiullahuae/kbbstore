<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\UgcVideo;
use App\Services\ImportConsole\ImportWorkspace;
use App\Services\UgcMedia;
use App\Support\ServerUploadLimits;
use App\Support\UploadArrival;
use Illuminate\Http\UploadedFile;
use Tests\Support\UgcAdminRoutes;

/**
 * What this server will really accept, and what the shop says about it.
 *
 * ── THE DEFECT, IN THE OWNER'S WORDS ────────────────────────────────────────
 *
 * He uploaded an 8.4 MB .mp4 at Content → Shoppable video → All clips → step 2.
 * The progress bar reached 100%, then a red panel said "That file was not
 * accepted. 8.4 MB — nothing on the clip was changed." His file was fine.
 *
 * The live shop reads upload_max_filesize=2M and post_max_size=8M while that
 * screen advertised "MP4 or WebM, up to 64 MB", which is
 * UgcMedia::MAX_BYTES[KIND_CLIP] and nothing else. The application had never
 * asked PHP what PHP would take.
 *
 * ── THE TWO MODES, REPRODUCED AGAINST A BARE php -S BEFORE ANY CODE CHANGED ──
 *
 * With upload_max_filesize=2M and post_max_size=8M, which is what this box has:
 *
 *   A. 3 MB file, whole body under post_max_size:
 *      CONTENT_LENGTH=3146044  count($_POST)=1  count($_FILES)=1
 *      $_FILES['file']['error']=1 (UPLOAD_ERR_INI_SIZE)  ['size']=0
 *
 *   B. 9 MB file, whole body over post_max_size:
 *      CONTENT_LENGTH=9437499  count($_POST)=0  count($_FILES)=0
 *      PHP discards the entire body. Nothing is left to validate.
 *
 * ── ONE CORRECTION TO THE DIAGNOSIS HANDED TO THIS LANE ─────────────────────
 *
 * The brief said mode B reaches $request->validate() and comes back 422. It does
 * not. Illuminate\Http\Middleware\ValidatePostSize is in Laravel 11's GLOBAL
 * middleware and throws PostTooLargeException — 413 — before the router runs, so
 * the controller is never entered. The case below sends exactly that request and
 * asserts the 413, which is the measurement rather than the argument. It matters
 * twice: the screen has to explain a 413 and not only a 422, and the 413's body
 * carries no `error` key for it to print, which is why the console fell through
 * to its generic sentence.
 *
 * It also does NOT close mode B, which is the second thing that matters. Its
 * guard is `$request->server('CONTENT_LENGTH') > $max`, so a request with no
 * CONTENT_LENGTH — a chunked body — walks past it while PHP still throws the
 * body away. UploadArrival is what closes that, and there is a case for it.
 */

/** An owner, so these cases measure the limits and not the capability map. */
function limitsAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'Upload Limits Owner',
        'email' => 'ul-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

/** The screen's source with the prose taken out, the way the UGC suite reads it. */
function limitsScreen(): string
{
    $src = (string) file_get_contents(
        resource_path('views/admin/partials/ugc-library-screen.blade.php')
    );
    $src = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $src);
    $src = (string) preg_replace('#/\*.*?\*/#s', '', $src);

    return (string) preg_replace('#^\s*//.*$#m', '', $src);
}

/* ═══════════════════════════════════════════════════ the shorthand parser ══ */

it('parses the ini shorthand the way PHP itself parses it', function () {
    /*
     * A naive (int) cast on '8M' is 8, and a screen built on that would advertise
     * eight BYTES. The two strange cases are measured, not read off the manual:
     * a real `php -S` with upload_max_filesize='1.5M' refused a 1.5 MiB file and
     * accepted a 1,048,000-byte one, and with upload_max_filesize='8MB' it
     * refused a 1 MB file — 'B' is not a multiplier, so the suffix is dropped and
     * the ceiling really is eight bytes.
     *
     * MUTATION NOTE. Replace the body of bytes() with `return (int) $shorthand ?:
     * null;` and this is red on the very first row. RUN: red.
     */
    expect(ServerUploadLimits::bytes('2M'))->toBe(2097152)
        ->and(ServerUploadLimits::bytes('8M'))->toBe(8388608)
        ->and(ServerUploadLimits::bytes('512K'))->toBe(524288)
        ->and(ServerUploadLimits::bytes('1G'))->toBe(1073741824)
        // Case does not matter to PHP and must not matter here.
        ->and(ServerUploadLimits::bytes('2m'))->toBe(2097152)
        ->and(ServerUploadLimits::bytes('64k'))->toBe(65536)
        // A plain byte count, which is legal and which some hosts really write.
        ->and(ServerUploadLimits::bytes('1048576'))->toBe(1048576)
        // Whitespace, which a php.ini edited by hand frequently carries.
        ->and(ServerUploadLimits::bytes('  4M  '))->toBe(4194304);

    // MEASURED: 'B' is not a multiplier and the whole suffix is dropped.
    expect(ServerUploadLimits::bytes('8MB'))->toBe(8);

    // MEASURED: the fraction goes, the multiplier stays.
    expect(ServerUploadLimits::bytes('1.5M'))->toBe(1048576);
});

it('treats 0 and -1 as no limit at all, for both directives', function () {
    /*
     * MEASURED four ways against a real `php -S`, each with a 1,048,000-byte
     * upload that arrived with error=0: upload_max_filesize=0,
     * upload_max_filesize=-1, post_max_size=0, post_max_size=-1. PHP's rfc1867
     * handler guards its comparison with `> 0` and so does ValidatePostSize, so a
     * non-positive value is an ABSENT ceiling.
     *
     * THE DEFECT THIS SHUTS. Read as a number, 0 is a ceiling of nothing: the
     * screen would advertise "0 MB", the pre-flight would refuse every file, and
     * a server with uploads deliberately unthrottled would be the one server on
     * which this feature could not be used at all.
     *
     * MUTATION NOTE. Change the last line of bytes() to `return $value;` and this
     * is red on the first assertion. RUN: red.
     */
    foreach (['0', '-1', '', '   ', 'unset', 'M'] as $value) {
        expect(ServerUploadLimits::bytes($value))->toBeNull("'{$value}' was read as a ceiling");
    }

    expect(ServerUploadLimits::bytes(null))->toBeNull();

    // And no limit anywhere means the app's own cap is the only one left.
    $unlimited = ServerUploadLimits::of(null, null);

    expect($unlimited->ceiling(64 * 1024 * 1024))->toBe(64 * 1024 * 1024)
        ->and($unlimited->reason(64 * 1024 * 1024))->toBe(ServerUploadLimits::BY_APP);
});

/* ═════════════════════════════════════════════════════════ the arithmetic ══ */

it('caps a clip at the smallest of the three real limits, and names which one', function () {
    /*
     * min(app cap, upload_max_filesize, post_max_size less the multipart
     * overhead). The live shop's own numbers are the first row, and the answer is
     * 2 MB rather than 64 — which is the whole bug in one assertion.
     *
     * post_max_size gets the subtraction because it bounds the WHOLE body: the
     * `kind` field, the boundaries and the part headers go inside it too, so a
     * file exactly post_max_size big cannot fit in a request carrying it. That
     * arithmetic is mode B.
     *
     * MUTATION NOTE. Drop the `- self::MULTIPART_OVERHEAD` from ceiling() and
     * this is red on the third row: an 8 MB post_max_size then reports a ceiling
     * of exactly 8388608, which is a request that cannot be built. RUN: red.
     */
    $clip = UgcMedia::MAX_BYTES[UgcMedia::KIND_CLIP];

    // The live shop: 2M per file is the binding limit.
    $live = ServerUploadLimits::of(2097152, 8388608);

    expect($live->ceiling($clip))->toBe(2097152)
        ->and($live->reason($clip))->toBe(ServerUploadLimits::BY_PER_FILE)
        ->and($live->describe($clip)['effective_mb'])->toBe(2)
        // The app's own number survives beside it, so the screen can say which
        // of the two the owner is looking at.
        ->and($live->describe($clip)['app_mb'])->toBe(64)
        ->and($live->describe($clip)['capped'])->toBeTrue();

    // A generous box: nothing caps the clip but the app, and the screen says 64.
    $generous = ServerUploadLimits::of(128 * 1024 * 1024, 256 * 1024 * 1024);

    expect($generous->ceiling($clip))->toBe($clip)
        ->and($generous->reason($clip))->toBe(ServerUploadLimits::BY_APP)
        ->and($generous->describe($clip)['effective_mb'])->toBe(64)
        ->and($generous->describe($clip)['capped'])->toBeFalse();

    // post_max_size binding, and the overhead is what makes it bind.
    $bodyBound = ServerUploadLimits::of(64 * 1024 * 1024, 8388608);

    expect($bodyBound->ceiling($clip))->toBe(8388608 - ServerUploadLimits::MULTIPART_OVERHEAD)
        ->and($bodyBound->reason($clip))->toBe(ServerUploadLimits::BY_PER_REQUEST)
        // FLOORED and never rounded: 7.996 MB must not print as 8 MB, or the
        // screen is advertising a size the request cannot carry all over again.
        ->and($bodyBound->describe($clip)['effective_mb'])->toBe(7)
        ->and($bodyBound->describe($clip)['effective_label'])->toBe('7.9 MB');

    // A post_max_size under the overhead is a box on which no multipart upload
    // can succeed. 0, honestly, rather than a negative number.
    expect(ServerUploadLimits::of(null, 1024)->ceiling($clip))->toBe(0);

    // Every reason the screen may be handed is one it knows how to print.
    foreach ([$live, $generous, $bodyBound] as $reader) {
        expect(ServerUploadLimits::REASONS)->toContain($reader->reason($clip));
    }
});

it('says a sub-megabyte ceiling in kilobytes rather than as 0 MB', function () {
    /*
     * A ceiling of 512K floored to megabytes is 0, and "up to 0 MB" tells an
     * operator nothing except that the screen is broken. effective_mb stays the
     * floored integer the screen already reads; effective_label is what it shows.
     *
     * MUTATION NOTE. Delete the sub-megabyte arm of label() and this is red. RUN:
     * red — the label comes back '0 MB'.
     */
    $tiny = ServerUploadLimits::of(524288, 8388608);
    $described = $tiny->describe(UgcMedia::MAX_BYTES[UgcMedia::KIND_CLIP]);

    expect($described['effective_bytes'])->toBe(524288)
        ->and($described['effective_mb'])->toBe(0)
        ->and($described['effective_label'])->toBe('512 KB');
});

/* ════════════════════════════════════ what the screen is told, and prints ══ */

it('advertises the size this server will really take, not the size the app allows', function () {
    /*
     * THE DEFECT. `limits.clip_mb` was UgcMedia::MAX_BYTES divided by a megabyte
     * and nothing else, so it said 64 on a server that takes 2M and the screen
     * printed it. It is now the effective ceiling, which is why the screen's own
     * markup did not have to learn a new key to stop lying.
     *
     * MUTATION NOTE. Change UgcVideoController::limits() to send
     * `(int) (UgcMedia::MAX_BYTES[$kind] / 1048576)` for the flat keys and this
     * is red on the first assertion whenever the server is the binding limit.
     * RUN: red on this box, where upload_max_filesize is 2M.
     */
    UgcAdminRoutes::wire($this->app);
    $this->actingAs(limitsAdmin(), 'admin');

    $reader = new ServerUploadLimits;
    $body = $this->getJson('/admin-api/ugc-videos')->assertOk()->json();

    foreach (UgcMedia::KINDS as $kind) {
        $expected = $reader->describe(UgcMedia::MAX_BYTES[$kind]);

        expect($body['limits'][$kind.'_mb'])->toBe($expected['effective_mb'])
            ->and($body['limits'][$kind.'_mb'])->toBeLessThanOrEqual(
                (int) floor(UgcMedia::MAX_BYTES[$kind] / 1048576)
            )
            // The app's own cap is still on the record beside the real one, which
            // is what lets the screen say "the server is the limit, not the shop"
            // instead of quietly showing a smaller number.
            ->and($body['limits'][$kind]['app_mb'])->toBe((int) floor(UgcMedia::MAX_BYTES[$kind] / 1048576))
            ->and($body['limits'][$kind]['capped_by'])->toBeIn(ServerUploadLimits::REASONS);
    }

    // The clip's app cap is still 64 MB. Nothing about this lane relaxed it.
    expect($body['limits']['clip']['app_mb'])->toBe(64);

    // And the ini values themselves come down, because a screen that says "raise
    // it" has to say which line to raise.
    expect($body['limits']['server']['upload_max_filesize'])->toBe((string) ini_get('upload_max_filesize'))
        ->and($body['limits']['server']['post_max_size'])->toBe((string) ini_get('post_max_size'));
});

it('explains the smaller number instead of just showing it', function () {
    /*
     * THE HALF OF THE FIX THAT IS NOT ARITHMETIC. Making the number honest turns
     * "up to 64 MB" into "up to 2 MB", which on its own reads as the shop having
     * got worse and tells the owner nothing he can act on. serverCapHTML() draws
     * a note that names both ini directives, both values, and where to change
     * them — and draws NOTHING on a server that is not the binding limit, so a
     * properly configured host is not warned about a problem it does not have.
     *
     * MUTATION NOTE. Change the first line of serverCapHTML() to `return '';` and
     * this is red on the first assertion. RUN: red.
     */
    $code = limitsScreen();

    expect(str_contains($code, 'function serverCapHTML() {'))->toBeTrue();

    // It is mounted exactly once, in the media step, above the drop zones. Zero
    // is the "built, never wired up" shape; two is the note drawn twice.
    expect(substr_count($code, 'html += serverCapHTML();'))->toBe(1);

    // The gate: nothing is drawn unless the server really is the ceiling.
    expect(str_contains($code, "if (cappedBy('clip') === '') return '';"))->toBeTrue();

    // It names both directives by the name the operator will search for.
    expect(str_contains($code, 'upload_max_filesize'))->toBeTrue()
        ->and(str_contains($code, 'post_max_size'))->toBeTrue();

    // The pre-flight refuses against the EXACT ceiling, not the floored megabyte
    // count, so it cannot refuse a file the server would have taken.
    expect(str_contains($code, 'var ceiling = capBytes(kind);'))->toBeTrue()
        ->and(str_contains($code, 'if (file.size > ceiling) {'))->toBeTrue();

    // And the ini values are escaped on the way into the markup, like every other
    // value this screen prints. Rule 5.
    expect(str_contains($code, "esc(serverIni('upload_max_filesize'))"))->toBeTrue()
        ->and(str_contains($code, "esc(serverIni('post_max_size'))"))->toBeTrue();
});

it('tells a slow server apart from a stalled one, and offers a way out of both', function () {
    /*
     * "I need the smooth and reliable upload progress." Lane V5 rebuilt this bar
     * and got the hard half right — real percentages off xhr.upload.onprogress, a
     * second stage from xhr.upload.onload, endings that stay on screen. Three
     * things it could not do, each of which is what a stuck upload looks like:
     *
     *   1. AFTER THE HANDOVER NOTHING MOVES, BY DESIGN. The bar sits at 100% and
     *      says "the server is checking the file", and that sentence was
     *      identical at two seconds and at four minutes. A clock is the only
     *      thing that separates them.
     *   2. BYTES STOP MOVING ON A DROPPED CONNECTION WITH NO EVENT AT ALL. The
     *      panel stayed at whatever percentage it had reached, wearing the word
     *      "Sending", which is exactly what a healthy slow upload looks like.
     *   3. THERE WAS NO WAY OUT. No cancel, so an upload that would never finish
     *      held the screen until a reload; and no retry, so the commonest next
     *      action after a dropped connection was to go and find the file again.
     *
     * MEASURED IN CHROMIUM at 1280 and 390, throttled to 60 KB/s so the numbers
     * were real: "Sending to the server. 6s so far." at 19%, "23s so far." at
     * 72%, then Cancel → "You stopped that upload, so nothing was sent and
     * nothing on the clip was changed." with a Try again beside it.
     * docs/upload-limit-shots has both.
     *
     * MUTATION NOTE. Delete the startClock() call from sendFile() and this is red
     * on the first assertion. RUN: red.
     */
    $code = limitsScreen();

    expect(str_contains($code, 'startClock();'))->toBeTrue('the panel has no clock')
        ->and(str_contains($code, 'function stalledFor() {'))->toBeTrue();

    // The two intervals are different numbers and the screen knows which is which:
    // since the handover in the server stage, since the last byte moved in the send
    // stage. Using one for both made a 23-second upload read "1s so far".
    expect(str_contains($code, "upState.stage === 'server' ? (upState.serverAt || 0) : (upState.moved || 0)"))
        ->toBeTrue('the clock measures the same interval in both stages');

    expect(str_contains($code, 'upState.moved = upState.secs || 0;'))->toBeTrue()
        ->and(str_contains($code, 'upState.serverAt = upState.secs || 0;'))->toBeTrue();

    // The server stage says how long it has been, and what to make of a long one.
    expect(str_contains($code, 'That is longer than usual.'))->toBeTrue();

    // A send that has stopped moving says SO, rather than wearing the same word as
    // a healthy one.
    expect(str_contains($code, 'but nothing has moved for'))->toBeTrue();

    // A way out of both, and the abort goes through the one ending writer.
    expect(str_contains($code, 'data-ugs-upcancel'))->toBeTrue('an upload cannot be cancelled')
        ->and(str_contains($code, 'xhr.onabort = function () {'))->toBeTrue(
            'a cancelled upload leaves the bar frozen and the screen locked'
        )
        ->and(str_contains($code, 'data-ugs-upretry'))->toBeTrue('a failed upload cannot be retried');

    /*
     * AND THE RETRY IS OFFERED ONLY WHERE IT COULD WORK. 429 is the endpoint's own
     * twelve-a-minute throttle and 5xx is a server that fell over; both are worth
     * one more press. A 413 or a 422 would fail identically, and a button whose
     * only outcome is the message above it is a lie.
     */
    expect(str_contains($code, 'retry: (xhr.status === 429 || xhr.status >= 500) ? file : null'))
        ->toBeTrue('a retry is offered for a failure a retry cannot fix');

    // The panel's own attribute is NOT data-ugs-up, which this screen already uses
    // for "move this product up": the delegated listener matches the nearest
    // ancestor, so a panel named that turns every click on the bar into a reorder
    // of the tagged product list with an index of NaN.
    expect(str_contains($code, 'data-ugs-upbox'))->toBeTrue()
        ->and(str_contains($code, "querySelector('[data-ugs-up]')"))->toBeFalse();
});

it('finishes an upload onto the clip it was sent to, not whatever is open', function () {
    /*
     * THE DEFECT. Every handler in sendFile() used `editing.id`, which is whatever
     * clip is open when the response lands rather than the one the file was sent
     * to. Pressing Back mid-upload sets `editing` to null and the success handler
     * ran `freshClip = editing.id` — a TypeError inside an XHR callback, which
     * unlocks nothing and leaves the screen with busy still true. Opening a
     * DIFFERENT clip instead was quieter and worse: that clip's step 2 drew a
     * green "arrived whole" panel for a file that is on another row.
     *
     * MUTATION NOTE. Put `editing.id` back in place of `target` in xhr.onload and
     * this is red on the second assertion. RUN: red.
     */
    $code = limitsScreen();

    expect(str_contains($code, 'var target = editing.id;'))->toBeTrue();

    // The request, the ending and the reload all name the same captured id.
    expect(str_contains($code, "encodeURIComponent(target) + '/media'"))->toBeTrue()
        ->and(str_contains($code, 'var here = editing && editing.id === target;'))->toBeTrue()
        ->and(str_contains($code, "if (kind === 'clip') freshClip = target;"))->toBeTrue();
});

/* ══════════════════════════════════════════════════ mode B, end to end ══ */

it('answers 413 before the controller when the whole body is over post_max_size', function () {
    /*
     * MODE B, THROUGH THE REAL STACK, and the correction to this lane's brief.
     *
     * The brief said this reaches $request->validate() and comes back 422. It
     * does not: ValidatePostSize is global middleware in Laravel 11 and throws
     * PostTooLargeException before the router matches, so the controller is never
     * entered and the answer is 413.
     *
     * The request built here is what PHP hands the application in mode B — a
     * multipart POST whose body is gone, with the CONTENT_LENGTH that was sent
     * still on it. Both facts came off a real `php -S`: count($_POST) and
     * count($_FILES) were 0 and CONTENT_LENGTH was 9437499.
     *
     * MUTATION NOTE. There is nothing in this lane's code to mutate — this case
     * measures Laravel. It is here because the FRONT END depends on it: without a
     * 413 branch in explain(), this status prints "That file was not accepted",
     * which is the sentence the owner was shown.
     */
    UgcAdminRoutes::wire($this->app);
    $this->actingAs(limitsAdmin(), 'admin');

    $video = UgcVideo::create(['slug' => 'modeb-'.uniqid(), 'title' => 'Mode B']);

    $perRequest = (new ServerUploadLimits)->perRequest();

    if ($perRequest === null) {
        // A runner with no post_max_size cannot be in mode B at all, and saying
        // so beats asserting something untrue about it.
        expect(true)->toBeTrue();

        return;
    }

    $response = $this->call(
        'POST',
        '/admin-api/ugc-videos/'.$video->id.'/media',
        [], [], [],
        [
            'CONTENT_TYPE' => 'multipart/form-data; boundary=----kbbmodeb',
            'CONTENT_LENGTH' => (string) ($perRequest + 1048576),
            'HTTP_ACCEPT' => 'application/json',
        ]
    );

    expect($response->getStatusCode())->toBe(413);

    // And the row is untouched: nothing was written by a request PHP threw away.
    expect($video->fresh()->file_path)->toBeNull();

    /*
     * THE SCREEN'S SIDE OF IT. A 413 body from Laravel's own handler carries
     * `message`, never `error`, so explain() has to compose the sentence itself
     * — and it has the numbers to do it because they came down with the library.
     */
    $code = limitsScreen();

    expect(str_contains($code, 'if (e && e.status === 413) {'))->toBeTrue(
        'a 413 still reads as "that file was not accepted"'
    );
});

it('names the real numbers when PHP discards a body with no content length', function () {
    /*
     * THE GAP ValidatePostSize LEAVES, and the reason UploadArrival exists rather
     * than a middleware tweak. Its guard is
     * `$request->server('CONTENT_LENGTH') > $max`, so a chunked body — which a
     * proxy can produce from a browser request that had a length — walks straight
     * past it. PHP still throws the body away, and before this lane validate()
     * then reported a missing file and the screen blamed the operator's video.
     *
     * The request below is mode B with no CONTENT_LENGTH: exactly what that gap
     * looks like.
     *
     * MUTATION NOTE. Delete the `if ($arrived !== null)` block from
     * UgcVideoController::upload() and this is red — the answer is 422 with
     * "The file field is required." RUN: red.
     */
    UgcAdminRoutes::wire($this->app);
    $this->actingAs(limitsAdmin(), 'admin');

    $video = UgcVideo::create(['slug' => 'chunked-'.uniqid(), 'title' => 'Chunked']);

    if ((new ServerUploadLimits)->perRequest() === null) {
        expect(true)->toBeTrue();

        return;
    }

    $response = $this->call(
        'POST',
        '/admin-api/ugc-videos/'.$video->id.'/media',
        [], [], [],
        [
            'CONTENT_TYPE' => 'multipart/form-data; boundary=----kbbchunked',
            'HTTP_ACCEPT' => 'application/json',
        ]
    );

    $response->assertStatus(413);

    $body = $response->json();

    expect($body['ok'])->toBeFalse()
        ->and($body['reason'])->toBe('post_max_size')
        // The sentence names the directive to change and the number it is at,
        // and says the file itself is fine — which is the thing the owner was
        // never told.
        ->and($body['error'])->toContain('post_max_size')
        ->and($body['error'])->toContain((string) ini_get('post_max_size'))
        ->and($body['error'])->toContain('the file itself is fine')
        // And it carries the real ceiling back, so the panel that shows this
        // refusal stops advertising a size this server will not take.
        ->and($body['limits']['clip_mb'])->toBe(
            (new ServerUploadLimits)->describe(UgcMedia::MAX_BYTES[UgcMedia::KIND_CLIP])['effective_mb']
        );

    expect($video->fresh()->file_path)->toBeNull();
});

/* ══════════════════════════════════════════════════ mode A, end to end ══ */

it('blames upload_max_filesize rather than the file when PHP refuses one file', function () {
    /*
     * MODE A. PHP hands over $_FILES['file'] with error=1 and size=0, so the
     * file is "there" as a handle to nothing. `required` and `file` both fail —
     * isValidFileInstance() calls isValid(), and the temporary path is empty —
     * and the answer was 422 with a message about the file.
     *
     * The UploadedFile below carries UPLOAD_ERR_INI_SIZE, which is exactly what
     * Symfony's FileBag builds from that $_FILES entry.
     *
     * MUTATION NOTE. Delete the `if ($arrived !== null)` block from
     * UgcVideoController::upload() and this is red: the answer becomes the
     * validator's own "The file field is required." RUN: red.
     */
    UgcAdminRoutes::wire($this->app);
    $this->actingAs(limitsAdmin(), 'admin');

    $video = UgcVideo::create(['slug' => 'modea-'.uniqid(), 'title' => 'Mode A']);

    $path = tempnam(sys_get_temp_dir(), 'modea');
    file_put_contents($path, '');

    $refused = new UploadedFile($path, 'holiday.mp4', 'video/mp4', UPLOAD_ERR_INI_SIZE, true);

    $response = $this->post('/admin-api/ugc-videos/'.$video->id.'/media', [
        'kind' => 'clip',
        'file' => $refused,
    ], ['Accept' => 'application/json']);

    $response->assertStatus(422);

    $body = $response->json();

    expect($body['reason'])->toBe('upload_max_filesize')
        ->and($body['error'])->toContain('upload_max_filesize')
        // It says which of the two is the limit in as many words, because the
        // owner's next question is whether to re-encode or to change a setting.
        ->and($body['error'])->toContain('the server is the limit here and not the shop')
        ->and($body['error'])->toContain('nothing was');

    expect($video->fresh()->file_path)->toBeNull();

    @unlink($path);
});

it('does not 500 on a kind that arrives as an array', function () {
    /*
     * The arrival check reads `kind` BEFORE validation, which is the point — but
     * it is therefore reading a value nothing has bounded yet. `kind[]=clip`
     * makes Request::input() an array, and (string) on an array is a PHP error:
     * a 500 on the one endpoint whose whole job this round is answering
     * honestly. It falls back to the clip's cap and lets the validator refuse it.
     *
     * MUTATION NOTE. Drop the is_string() guard from UgcVideoController::upload()
     * and this is red with "Array to string conversion". RUN: red.
     */
    UgcAdminRoutes::wire($this->app);
    $this->actingAs(limitsAdmin(), 'admin');

    $video = UgcVideo::create(['slug' => 'arraykind-'.uniqid(), 'title' => 'Array kind']);

    $path = tempnam(sys_get_temp_dir(), 'ak');
    file_put_contents($path, 'x');

    $response = $this->post('/admin-api/ugc-videos/'.$video->id.'/media', [
        'kind' => ['clip'],
        'file' => new UploadedFile($path, 'clip.mp4', 'video/mp4', UPLOAD_ERR_INI_SIZE, true),
    ], ['Accept' => 'application/json']);

    expect($response->getStatusCode())->toBeLessThan(500);

    @unlink($path);
});

it('calls a server fault a server fault instead of a bad file', function () {
    /*
     * NO_TMP_DIR, CANT_WRITE and EXTENSION are the server being broken, and all
     * three came out of this endpoint as "that file was not accepted" — which
     * sends somebody to re-encode a video over a fault no re-encode can touch.
     *
     * MUTATION NOTE. Remove the `default` arm from UploadArrival::fileError() and
     * this is red. RUN: red — a null verdict falls through to validate() and 422.
     */
    UgcAdminRoutes::wire($this->app);
    $this->actingAs(limitsAdmin(), 'admin');

    $video = UgcVideo::create(['slug' => 'cantwrite-'.uniqid(), 'title' => 'Cant write']);

    $path = tempnam(sys_get_temp_dir(), 'cw');
    file_put_contents($path, 'x');

    $response = $this->post('/admin-api/ugc-videos/'.$video->id.'/media', [
        'kind' => 'clip',
        'file' => new UploadedFile($path, 'clip.mp4', 'video/mp4', UPLOAD_ERR_CANT_WRITE, true),
    ], ['Accept' => 'application/json']);

    $response->assertStatus(500);

    expect($response->json()['reason'])->toBe('server')
        ->and($response->json()['error'])->toContain('server fault')
        ->and($response->json()['error'])->toContain('The file is fine');

    @unlink($path);
});

it('leaves a file PHP delivered whole to the content checks, unchanged', function () {
    /*
     * THE OTHER HALF OF RULE 1, and the case that proves this lane did not turn
     * every refusal into a lecture about php.ini. A file that arrives intact and
     * is genuinely wrong still gets UgcMedia's own message, which is good and was
     * not touched: it reads the BYTES and says renaming will not help.
     *
     * MUTATION NOTE. Make UploadArrival::check() return a verdict for
     * UPLOAD_ERR_OK and this is red — the answer stops naming the format list.
     * RUN: red.
     */
    UgcAdminRoutes::wire($this->app);
    $this->actingAs(limitsAdmin(), 'admin');

    $video = UgcVideo::create(['slug' => 'real-'.uniqid(), 'title' => 'Real refusal']);

    $path = tempnam(sys_get_temp_dir(), 'notvid');
    file_put_contents($path, "<?php echo 'x'; ?>".str_repeat('A', 400));

    $response = $this->post('/admin-api/ugc-videos/'.$video->id.'/media', [
        'kind' => 'clip',
        'file' => new UploadedFile($path, 'clip.mp4', null, null, true),
    ], ['Accept' => 'application/json']);

    $response->assertStatus(422);

    expect($response->json()['error'])->toContain('MP4 or WebM')
        ->and($response->json()['error'])->toContain('not its name')
        // And it is NOT dressed up as a server limit.
        ->and($response->json())->not->toHaveKey('reason');

    @unlink($path);
});

/* ═════════════════════════════════════════ one reader, and Import unmoved ══ */

it('reads the two upload directives in one place, and does not move the import screen', function () {
    /*
     * ImportConsole\ImportWorkspace::serverLimits() has carried the docblock
     * "What this PHP install will actually accept, which is often less than we
     * allow" since long before this lane, and Store → Store Import / Export
     * really does print those numbers today. The lesson existed; it had never
     * been applied to the video screen, which is the whole defect.
     *
     * So the two ini reads are delegated to ServerUploadLimits and there is one
     * reader rather than a second. What serverLimits() RETURNS is unchanged, key
     * for key and value for value, because the import screen reads it out of
     * resources/views/admin/app.blade.php and that file is the integrator's.
     *
     * MUTATION NOTE. Change the delegation to send the parsed byte counts instead
     * of the ini strings and this is red on the first assertion. RUN: red — the
     * import screen would start printing "2097152" where it says "2M".
     */
    $limits = app(ImportWorkspace::class)->serverLimits();

    expect($limits['upload_max_filesize'])->toBe((string) ini_get('upload_max_filesize'))
        ->and($limits['post_max_size'])->toBe((string) ini_get('post_max_size'))
        ->and($limits['max_execution_time'])->toBe((int) ini_get('max_execution_time'))
        ->and($limits['memory_limit'])->toBe((string) ini_get('memory_limit'))
        ->and($limits)->toHaveKey('our_max_bytes')
        // The same five keys and no more: the screen reads two of them by name
        // and a sixth would be a change to a payload this lane does not own.
        ->and(array_keys($limits))->toBe([
            'upload_max_filesize', 'post_max_size', 'max_execution_time',
            'memory_limit', 'our_max_bytes',
        ]);

    // And the shared reader agrees with the ini it is standing in front of.
    expect((new ServerUploadLimits)->raw()['upload_max_filesize'])
        ->toBe((string) ini_get('upload_max_filesize'));
});

it('resolves the reader and the arrival check out of the container', function () {
    /*
     * Both are constructor dependencies of UgcVideoController, so a constructor
     * signature the container cannot satisfy is a 500 on a screen rather than a
     * failing test. ServerUploadLimits takes three defaulted arguments precisely
     * so this stays true.
     */
    expect(app(ServerUploadLimits::class))->toBeInstanceOf(ServerUploadLimits::class)
        ->and(app(UploadArrival::class))->toBeInstanceOf(UploadArrival::class);

    // The container's instance is the live one, not a test seam.
    expect(app(ServerUploadLimits::class)->raw()['post_max_size'])
        ->toBe((string) ini_get('post_max_size'));
});

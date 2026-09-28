<?php

declare(strict_types=1);

use App\Services\UgcTranscoder;

/**
 * THE SCREEN SAYS WHICH SERVER THIS IS, AND WHAT TO DO ABOUT IT — Lane UG.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * The owner: *"the 2-3 seconds clip is not generating automatically when upload
 * the video."*
 *
 * ── WHAT WAS PROVED, RATHER THAN SUSPECTED ─────────────────────────────────
 *
 * The upload path is not broken. Driven through App\Services\UgcClipIntake on
 * this container, with a real H.264 mp4 and ffmpeg on PATH, one upload produced
 * `teaser_path` (a 36,049-byte file on disk beside a 49,594-byte clip),
 * `poster_path`, `duration_ms` 6000 and the dimensions — with no notes. So
 * derive() IS called on the upload request and DOES cut a teaser wherever it
 * can run at all.
 *
 * It cannot run on the owner's server. `UgcTranscoder::available()` is
 * `blocker(canSpawn(), binary()) === null`, and on that Cloudways box
 * /usr/bin/ffmpeg exists while `proc_open` is in the PHP-FPM pool's
 * disable_functions — reproduced and written up in docs/SERVER-PROC-OPEN.md §1.
 *
 * ── SO THE DEFECT IS WHAT THE SCREEN SAID ABOUT IT ─────────────────────────
 *
 * The chip at the top of Content → Shoppable video → All clips read,
 * hard-coded, `No ffmpeg here — you choose the cover`, on EVERY server that
 * could not cut. On his that is false, and it sent him to install a program he
 * already had. UgcTranscoder::blocker() was added to stop exactly that mistake
 * and the screen was still making it one layer further out, because it had a
 * bool and no way to ask which of the two it meant.
 *
 * And the way out was in a file on a branch. The rail needs no teaser file at
 * all — it loops the first seconds of the clip itself — and the covers can be
 * cut on a schedule, because the command line on that same machine IS allowed
 * to start ffmpeg. Neither fact was anywhere he would ever read it.
 */

it('tells the two ways a server cannot cut apart, as a key and not only as a paragraph', function () {
    /*
     * MUTATION NOTE. Make reason() return REASON_NO_SPAWN before it checks the
     * binary — i.e. swap the two ifs — and the first expectation is red: a box
     * with NO ffmpeg would be told to go and change a PHP setting that would
     * still leave nothing to run. RUN: red.
     *
     * The arm that matters is the third: ffmpeg present, unreachable. That is
     * the owner's Cloudways box (docs/SERVER-PROC-OPEN.md §1) and no test on
     * this container could reach it without a pure function to ask.
     */
    $t = app(UgcTranscoder::class);

    expect($t->reason(true, null))->toBe(UgcTranscoder::REASON_NO_FFMPEG);
    expect($t->reason(false, null))->toBe(UgcTranscoder::REASON_NO_FFMPEG);
    expect($t->reason(false, '/usr/bin/ffmpeg'))->toBe(UgcTranscoder::REASON_NO_SPAWN);
    expect($t->reason(true, '/usr/bin/ffmpeg'))->toBeNull();

    // blocker() is now defined in terms of reason(), so the two cannot drift.
    expect($t->blocker(false, '/usr/bin/ffmpeg'))->toBe(UgcTranscoder::NOTE_NO_SPAWN);
    expect($t->blocker(true, null))->toBe(UgcTranscoder::NOTE_NO_FFMPEG);
    expect($t->blocker(true, '/usr/bin/ffmpeg'))->toBeNull();
});

it('stops the clips list telling an owner with ffmpeg that he has none', function () {
    /*
     * THE DEFECT. The chip at the top of Content → Shoppable video → All clips
     * read, hard-coded, `No ffmpeg here — you choose the cover`, on EVERY server
     * that could not cut. On the owner's box ffmpeg is installed and PHP-FPM is
     * not allowed to start it, so the first sentence of the first screen sent him
     * to install a program he already had.
     *
     * MUTATION NOTE. Put the literal back —
     *   return '<span class="ugs-chip is-warm">No ffmpeg here — you choose the cover</span>';
     * — and this is red on the first expectation. RUN: red.
     */
    $screen = file_get_contents(resource_path('views/admin/partials/ugc-library-screen.blade.php'));

    $code = preg_replace('#/\*.*?\*/#s', '', $screen);
    $code = preg_replace('#\{\{--.*?--\}\}#s', '', (string) $code);

    expect(str_contains((string) $code, 'No ffmpeg here — you choose the cover'))->toBeFalse(
        'the chip no longer guesses which of the two faults a server has'
    );

    // Both keys the server can send have words of this screen's own.
    expect(str_contains((string) $code, 'no_ffmpeg:'))->toBeTrue('the chip has words for a missing ffmpeg');
    expect(str_contains((string) $code, 'no_spawn:'))->toBeTrue('the chip has words for an ffmpeg PHP may not start');
    expect(str_contains((string) $code, 'transcoder.reason'))->toBeTrue('it reads the server\'s key');
});

it('puts the remedy on the screen, once, where an owner with SSH can act on it', function () {
    /*
     * The owner's first complaint is that the teaser is not cut on upload. On his
     * server it cannot be, and until now nothing on this screen said what to do:
     * the reason lived one clip and one step deep in the editor, and the fix
     * lived only in docs/SERVER-PROC-OPEN.md, which is a file on a branch.
     *
     * MUTATION NOTE. Delete the `html += cutRemedyHTML();` line from listHTML()
     * and this is red on the last expectation. Delete the cron line from
     * cutRemedyHTML() and it is red on the third. RUN: red on each.
     *
     * NO SERVER PATH IS PRINTED, and that is asserted rather than trusted:
     * HealthApiController and this controller's own probeNote() both strip
     * base_path() out of everything that reaches a screen.
     */
    $screen = file_get_contents(resource_path('views/admin/partials/ugc-library-screen.blade.php'));

    $code = preg_replace('#/\*.*?\*/#s', '', $screen);
    $code = preg_replace('#\{\{--.*?--\}\}#s', '', (string) $code);
    $code = (string) $code;

    // It leads with the fact that nothing is broken, because nothing is.
    expect(str_contains($code, 'Every tile still loops'))->toBeTrue(
        'the note leads with the loop working, not with the fault'
    );
    expect(str_contains($code, 'proc_open'))->toBeTrue('it names the setting actually in the way');
    expect(str_contains($code, 'php artisan schedule:run'))->toBeTrue('it carries the one-line remedy');
    expect(str_contains($code, 'Cron Job Management'))->toBeTrue('it names where on Cloudways to paste it');
    expect(str_contains($code, 'base_path'))->toBeFalse('no server path reaches the screen');
    expect(substr_count($code, 'html += cutRemedyHTML();'))->toBe(1,
        'the remedy is drawn on the clips list exactly once'
    );
});

it('sends the reason key with the library, beside the sentence', function () {
    /*
     * MUTATION NOTE. Remove the `reason` key from the transcoder probe in
     * Admin\UgcVideoController::index() and this is red. RUN: red — and on the
     * shop the chip falls back to its neutral words, which is the designed safe
     * arm rather than a wrong diagnosis.
     */
    $controller = file_get_contents(app_path('Http/Controllers/Admin/UgcVideoController.php'));

    expect(str_contains($controller, "'reason' => \$this->transcoder->reason("))->toBeTrue(
        'the library payload carries the machine-readable reason'
    );
    expect(str_contains($controller, "'reason' => null,"))->toBeTrue(
        'and the probe\'s safe default carries the key too, so the screen never reads undefined'
    );
});

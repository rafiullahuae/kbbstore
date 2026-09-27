<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\UgcVideoController;
use App\Models\AdminUser;
use App\Models\UgcVideo;
use App\Services\UgcMedia;
use App\Services\UgcTranscoder;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\Process\Process;
use Tests\Support\UgcAdminRoutes;

/**
 * The 8.4 MB clip that uploaded fine and came back "Server Error".
 *
 * ── WHAT THE OWNER SAW, 27 SEPTEMBER 2026 ───────────────────────────────────
 *
 * Content → Shoppable video → All clips → step 2. An 8.4 MB .mp4, on a server
 * whose effective ceiling is 9.9 MB (upload_max_filesize=100M,
 * post_max_size=10M). The pre-flight let it through, the bar reached 100%, the
 * bytes were delivered whole, and the panel went red:
 *
 *     anua mist spray 2.mp4                                  not accepted
 *     Server Error   8.4 MB — nothing on the clip was changed.
 *     [Try again]    The file is still on your computer …
 *
 * Then, in his words: "it worked upon refresh and the video went to draft auto."
 * The clip was at /uploads/ugc/clip-20260927-121844-epAit3WIWg.mp4, playing, at
 * 8.4 MB, badged `draft · No cover yet · no permission yet`.
 *
 * So the upload SUCCEEDED and the answer was a 500, and every word after the
 * comma in that panel was false. Something WAS changed. He re-sent 8.4 MB over a
 * ~300 KB/s uplink for a file that was already stored.
 *
 * ── THE DIAGNOSIS, AND WHICH HALF OF IT IS MEASURED HERE ────────────────────
 *
 * UgcVideoController::upload() saves the row and THEN calls
 * UgcTranscoder::derive(). derive() built its Symfony Process OUTSIDE the try
 * that guarded it, and Process's constructor throws before anything else:
 *
 *     if (!\function_exists('proc_open')) {
 *         throw new LogicException('The Process class relies on proc_open, ...');
 *     }
 *
 * `catch (ProcessException)` cannot see a LogicException raised by a
 * constructor it never reached, so it escaped derive(), escaped upload() with the
 * row already committed, and reached Laravel as {"message":"Server Error"}.
 * That body is not a guess: Illuminate\Foundation\Exceptions\Handler::
 * convertExceptionToArray() returns exactly `['message' => 'Server Error']` for
 * any non-HTTP exception when app.debug is off, which his shop is. And
 * upload-kit.blade.php's explain() prefers `body.message` over its own 5xx
 * sentence, so that string is what the red panel printed, word for word.
 * Every observed fact follows: the 500, the stored clip, the missing cover
 * (poster_path is written AFTER derive() returns, and it never returned), and
 * that it is deterministic rather than intermittent.
 *
 * CONFIRMED HERE, by measurement, in a child PHP with proc_open switched off:
 *   • the old eight lines throw LogicException straight past their own guard
 *   • ffmpeg is still a real executable, so binary() answers and available()
 *     used to answer TRUE — the screen promised a cover this box cannot cut
 *   • the fixed code answers false, cuts nothing, and throws nothing
 *
 * NOT CONFIRMED, and only the log line from his shop can settle it: that
 * proc_open is what his host disables, rather than some other fault in that
 * layer. It does not change the fix. The two candidates it displaces are both
 * measured below as well — see the max_execution_time case, which does NOT fire
 * during a child process on this container's build.
 *
 * ── WHY EVERY CASE BELOW IS ABOUT THE SAME ONE PROPERTY ─────────────────────
 *
 * A DERIVATIVE MAY NEVER COST AN UPLOAD, and before this round that was a
 * sentence in a comment rather than a property of the code — the exact shape
 * CLAUDE.md records for UpdateRunner::recordManifest(), whose docblock claimed a
 * guarded write could not fail an update and cost three hours. So the pins here
 * are on the outcome, not on the wording.
 */

/* ────────────────────────────────────────────────────────── the fixtures ── */

/** Real ISO-BMFF bytes. UploadedFile::fake() types files by NAME and is no use here. */
function u5Mp4(): string
{
    return pack('N', 32).'ftyp'.'isom'.pack('N', 512).'isomiso2avc1mp41'.str_repeat("\x00", 256);
}

function u5File(string $name, string $bytes): \Illuminate\Http\UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'u5up');
    file_put_contents($path, $bytes);

    return new \Illuminate\Http\UploadedFile($path, $name, null, null, true);
}

function u5Owner(): AdminUser
{
    return AdminUser::create([
        'name' => 'Upload 500 Owner',
        'email' => 'u5-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

/** Everything in public/uploads/ugc right now. */
function u5Stored(): array
{
    $dir = public_path(UgcMedia::DIR);

    return is_dir($dir) ? array_values(array_diff(scandir($dir) ?: [], ['.', '..'])) : [];
}

/**
 * Point the log at a file of this case's own, and hand back its path.
 *
 * THE POINT OF READING A REAL FILE rather than spying on the facade: the claim
 * being tested is that the owner can read this at Safety → Debug & Monitor →
 * "Open the error log →", and that button serves the tail of the file the
 * `single` channel writes. A spy would pass with nothing on disk.
 */
function u5LogFile(): string
{
    $path = tempnam(sys_get_temp_dir(), 'u5log');

    config([
        'logging.default' => 'single',
        'logging.channels.single.driver' => 'single',
        'logging.channels.single.path' => $path,
        'logging.channels.single.level' => 'debug',
    ]);

    // So the next Log call builds a LogManager that has read the config above.
    app()->forgetInstance('log');
    Facade::clearResolvedInstance('log');

    return $path;
}

afterEach(function () {
    foreach (u5Stored() as $name) {
        foreach (['clip-', 'teaser-', 'poster-'] as $prefix) {
            if (str_starts_with($name, $prefix)) {
                @unlink(public_path(UgcMedia::DIR.'/'.$name));
            }
        }
    }
});

/* ══════════════════════════════ the root cause, in a child PHP ══════════ */

it('reproduces the owner’s 500 on a PHP that cannot start a program, and does not repeat it', function () {
    /*
     * THE MEASUREMENT THIS WHOLE ROUND RESTS ON, run rather than reasoned about.
     *
     * A child PHP with `disable_functions=proc_open` and a REAL ffmpeg beside a
     * real ffprobe — which is the owner's box as far as this code can tell it
     * apart from any other: the binaries exist, and PHP may not start them.
     *
     * The child reports four things:
     *   old       what derive()'s old eight lines do — construction above the
     *             try, `catch (ProcessException)` under it
     *   available what the screen was being told before anything was uploaded
     *   new       whether the shipped code can still throw out of the one public
     *             method that used to construct a Process unguarded
     *
     * ── THE TWO DEFENCES ARE MEASURED SEPARATELY, AND HERE IS WHY ──────────
     *
     * They are redundant on purpose, and redundancy hides mutations: with only
     * `new` probed, moving `new Process` back above the try was GREEN (canSpawn()
     * answered first, so nothing was constructed) and removing the canSpawn
     * short-circuit was GREEN too (the guard caught it). Measured, both of them,
     * and both green — so the probe that forces canSpawn() true was added, and it
     * reaches the guard with a Process really being built.
     *
     * MUTATION NOTE, all RUN.
     *   available() back to `return $this->binary() !== null;`
     *     RUN: red — available came back true, which is the line that told the
     *     owner this server would cut him a cover.
     *   `new Process(...)` back above the try in durationMs()
     *     RUN: red on `guard` — THREW:Symfony\…\LogicException, which is the
     *     owner's 500, reproduced.
     *   `new Process($command)` back above the try in run()
     *     RUN: red on `derive` — THREW:Symfony\…\LogicException. Measured only
     *     after the first attempt at this case came back GREEN: run() is
     *     unreachable behind blocker(), so nothing got near it until canSpawn()
     *     was forced true here.
     *   the canSpawn() short-circuit removed from durationMs()
     *     RUN: GREEN, and correctly so — the guard below it gives the identical
     *     answer. That line is clarity and one less process launched, not a
     *     defence with an observable of its own, and this note says so rather
     *     than pretending otherwise.
     */
    $dir = sys_get_temp_dir().'/u5ff-'.uniqid();
    mkdir($dir, 0755, true);

    foreach (['ffmpeg', 'ffprobe'] as $name) {
        file_put_contents($dir.'/'.$name, "#!/bin/sh\nexit 0\n");
        chmod($dir.'/'.$name, 0755);
    }

    // A web root of the child's own, with a stored clip in it, so derive() has
    // something real to be asked about and writes nothing into this worktree.
    $clip = 'clip-20260927-121844-epAit3WIWg.mp4';
    mkdir($dir.'/public/'.UgcMedia::DIR, 0755, true);
    file_put_contents($dir.'/public/'.UgcMedia::DIR.'/'.$clip, u5Mp4());

    $script = $dir.'/probe.php';
    file_put_contents($script, <<<'CHILD'
        <?php
        require $argv[1];
        putenv('KBB_FFMPEG='.$argv[2]);

        $t = new App\Services\UgcTranscoder();
        $out = [
            'proc_open' => function_exists('proc_open'),
            'can_spawn' => $t->canSpawn(),
            'binary' => $t->binary(),
            'available' => $t->available(),
        ];

        // derive()'s OLD shape, verbatim: construction above the try.
        try {
            $p = new Symfony\Component\Process\Process([$argv[2]]);
            $p->setTimeout(60);
            try {
                $p->run();
                $out['old'] = 'ran';
            } catch (Symfony\Component\Process\Exception\ExceptionInterface $e) {
                $out['old'] = 'caught:'.get_class($e);
            }
        } catch (Throwable $e) {
            $out['old'] = 'ESCAPED:'.get_class($e);
        }

        // The shipped shape, through the public method that built one unguarded.
        try {
            $t->durationMs($argv[2]);
            $out['new'] = 'returned';
        } catch (Throwable $e) {
            $out['new'] = 'THREW:'.get_class($e);
        }

        /*
         * AND THE GUARD ON ITS OWN, reached by forcing canSpawn() true so the
         * short-circuit above cannot answer first. A Process really is
         * constructed here, on a PHP that cannot start one. Without this probe
         * the two defences are only ever measured together, and either one alone
         * keeps the box safe — so a mutation of either was green.
         */
        $forced = new class extends App\Services\UgcTranscoder {
            public function canSpawn(): bool { return true; }
        };

        try {
            $forced->durationMs($argv[2]);
            $out['guard'] = 'returned';
        } catch (Throwable $e) {
            $out['guard'] = 'THREW:'.get_class($e);
        }

        /*
         * AND THE POSTER/TEASER RUNNER, which is a different method with the
         * same shape and was NOT covered by the two probes above: run() is only
         * reached once blocker() has said the box is fine, so nothing on this
         * container ever gets there with a broken process layer. derive() needs
         * public_path(), so the child builds the smallest Application that
         * answers it — no config, no database, no kernel.
         */
        $app = new Illuminate\Foundation\Application($argv[3]);
        $app->usePublicPath($argv[3].'/public');

        try {
            $report = $forced->derive(new App\Models\UgcVideo([
                'file_path' => '/uploads/ugc/'.$argv[4],
            ]));
            $out['derive'] = 'returned';
            $out['derive_notes'] = implode(' ', $report['notes']);
        } catch (Throwable $e) {
            $out['derive'] = 'THREW:'.get_class($e);
        }

        echo json_encode($out);
        CHILD);

    $child = new Process([
        PHP_BINARY, '-d', 'disable_functions=proc_open',
        $script, base_path('vendor/autoload.php'), $dir.'/ffmpeg', $dir, $clip,
    ]);
    $child->setTimeout(60);
    $child->run();

    $report = json_decode(trim($child->getOutput()), true);

    // A child that did not report is a test that measured nothing, so say so
    // rather than asserting against null.
    expect($report)->toBeArray($child->getOutput().$child->getErrorOutput());

    // The box: proc_open gone, ffmpeg still right there on the disk.
    expect($report['proc_open'])->toBeFalse()
        ->and($report['can_spawn'])->toBeFalse()
        ->and($report['binary'])->toBe($dir.'/ffmpeg');

    /*
     * THE DEFECT, MEASURED. Not "caught:…" — ESCAPED, because the constructor
     * throws outside the try and its LogicException is not what that catch was
     * written for. This is the line that became "Server Error".
     */
    expect($report['old'])
        ->toBe('ESCAPED:Symfony\Component\Process\Exception\LogicException');

    /*
     * THE FIX, MEASURED, in both halves. The screen is told the truth before
     * anything is uploaded, and the code that used to construct a Process here
     * returns instead of throwing.
     */
    expect($report['available'])->toBeFalse('the screen is still promised a cover this box cannot cut')
        ->and($report['new'])->toBe('returned')
        /*
         * THE GUARD, MEASURED BY ITSELF. `guard` forces canSpawn() true so a
         * Process is genuinely constructed on a PHP that cannot start one — the
         * exact line that threw — and the \Throwable catch swallows it.
         */
        ->and($report['guard'])->toBe('returned')
        /*
         * AND run(), the poster/teaser runner, likewise: derive() comes back
         * with a report rather than an exception, and says what it could not do.
         */
        ->and($report['derive'])->toBe('returned')
        ->and($report['derive_notes'])->toContain('could not read a poster frame');
})->skip(PHP_OS_FAMILY === 'Windows', 'needs a POSIX shell for the stub binaries');

/* ══════════════════════════════ the candidates, told apart ══════════════ */

it('does not blame max_execution_time on a build where it cannot be the cause', function () {
    /*
     * The other candidate, and the reason this file does not chase it: a fatal
     * from the execution limit is NOT a catchable exception, so it would explain
     * a 500 that no try/catch could stop. It is measured rather than assumed,
     * because the answer depends on how PHP was compiled.
     *
     * On this container — PHP 8.4, non-ZTS, no --enable-zend-max-execution-timers
     * — the limit counts CPU time, and a sleeping child spends none of it. A
     * 2-second limit with an 8-second child: the script lives.
     *
     * Where those timers ARE compiled in the same limit is wall clock and the
     * candidate is real, which is why UgcTranscoder now bounds every child
     * against what PHP has left instead of always asking for 60 seconds.
     *
     * MUTATION NOTE. There is nothing to mutate in the application here — this
     * case is a measurement that keeps the diagnosis honest, and it is the
     * timeoutSeconds() case below that pins the guard. Said out loud because a
     * test that asserts about the platform and not about this code should admit
     * which it is.
     */
    $script = tempnam(sys_get_temp_dir(), 'u5tl');
    file_put_contents($script, <<<'CHILD'
        <?php
        require $argv[1];
        set_time_limit(2);
        $t0 = microtime(true);
        $p = new Symfony\Component\Process\Process(['/bin/sh', '-c', 'sleep 6']);
        $p->setTimeout(60);
        $p->run();
        echo json_encode(['alive' => true, 'seconds' => round(microtime(true) - $t0, 1)]);
        CHILD);

    $child = new Process([PHP_BINARY, '-d', 'max_execution_time=2', $script, base_path('vendor/autoload.php')]);
    $child->setTimeout(60);
    $child->run();

    $report = json_decode(trim($child->getOutput()), true);

    expect($report)->toBeArray($child->getOutput().$child->getErrorOutput())
        ->and($report['alive'])->toBeTrue()
        // Six seconds of child under a two-second limit. The limit did not fire.
        ->and($report['seconds'])->toBeGreaterThan(5.0);

    @unlink($script);
})->skip(PHP_OS_FAMILY === 'Windows', 'needs a POSIX shell');

/* ══════════════════════════════ the two pure functions ═════════════════ */

it('tells "no ffmpeg" apart from "ffmpeg it may not start"', function () {
    /*
     * THE OWNER WAS TOLD NEITHER, and the two have completely different
     * remedies — install a program, or change a PHP directive. His clip was
     * badged "No cover yet" with no way to find out which of the two he was
     * looking at.
     *
     * blocker() is pure for exactly this reason: the arm that matters is
     * (cannot spawn, ffmpeg IS present), which is his server and which no
     * in-process test on this container could otherwise reach.
     *
     * MUTATION NOTE. Make blocker() `return $binary === null ? NOTE_NO_FFMPEG :
     * null;` — the old question, asked of the binary alone. RUN: red here on the
     * (false, '/usr/bin/ffmpeg') arm, AND red on the child-process case above,
     * because that is the same lie reaching the screen as `available: true`.
     */
    $t = app(UgcTranscoder::class);

    expect($t->blocker(true, '/usr/bin/ffmpeg'))->toBeNull();

    // His box. ffmpeg on the disk, PHP not allowed to start it.
    expect($t->blocker(false, '/usr/bin/ffmpeg'))->toBe(UgcTranscoder::NOTE_NO_SPAWN);

    /*
     * AND THE OTHER WAY ROUND, ffmpeg missing, which is what this suite's own box
     * is. Reported first even when proc_open is also gone: naming the directive
     * on a box with nothing to run would send somebody to edit a setting that
     * would still leave nothing to run.
     */
    expect($t->blocker(true, null))->toBe(UgcTranscoder::NOTE_NO_FFMPEG)
        ->and($t->blocker(false, null))->toBe(UgcTranscoder::NOTE_NO_FFMPEG);

    // Two different sentences, and the first keeps the words UgcTranscoderTest
    // reads it for.
    expect(UgcTranscoder::NOTE_NO_SPAWN)->not->toBe(UgcTranscoder::NOTE_NO_FFMPEG)
        ->and(UgcTranscoder::NOTE_NO_FFMPEG)->toContain('no ffmpeg')
        ->and(UgcTranscoder::NOTE_NO_SPAWN)->toContain('proc_open');
});

it('never asks a child for more time than PHP has left, and starts none when there is none', function () {
    /*
     * The explicit execution-limit guard. TIMEOUT is 60 and a host with
     * max_execution_time=30 would fatal at 30 on a build whose timer is wall
     * clock, so the ask is bounded by what is left, less a margin.
     *
     * MUTATION NOTE. Make timeoutSeconds() `return self::TIMEOUT;` — the old
     * unconditional 60. RUN: red, 60 where 24 was expected.
     */
    $t = app(UgcTranscoder::class);

    // No limit — CLI, and any pool with max_execution_time=0. Nothing moves, and
    // that is why this suite and a normally-configured box are unaffected.
    expect($t->timeoutSeconds(0, 0.0))->toBe(60)
        ->and($t->timeoutSeconds(-1, 12.0))->toBe(60);

    // A 30-second limit with a second spent: 30 − 1 − 5.
    expect($t->timeoutSeconds(30, 1.0))->toBe(24);

    // A generous limit is still bounded by TIMEOUT.
    expect($t->timeoutSeconds(600, 0.0))->toBe(60);

    // And the arm that refuses to start anything: 10 − 0 − 5 is under the floor.
    expect($t->timeoutSeconds(10, 0.0))->toBeNull()
        ->and($t->timeoutSeconds(30, 26.0))->toBeNull();
});

/* ══════════════════════════════ the endpoint, end to end ═══════════════ */

/**
 * A transcoder that throws exactly what the owner's server threw.
 *
 * This is why UgcTranscoder is no longer `final`, and the precedent is
 * ServerMailTransport, which ServerMailDefaultTest extends the same way. The
 * message is Symfony's own, verbatim.
 */
function u5ThrowingTranscoder(): UgcTranscoder
{
    return new class extends UgcTranscoder
    {
        public function derive(UgcVideo $video, bool $remakePoster = false, bool $remakeTeaser = false): array
        {
            throw new \Symfony\Component\Process\Exception\LogicException(
                'The Process class relies on proc_open, which is not available on your PHP installation.'
            );
        }
    };
}

it('answers 200 with the clip stored when the cut throws, instead of 500 over a saved row', function () {
    /*
     * THE HEADLINE. The owner's request, with the throw put back where it was.
     *
     * Before this round: 500, {"message":"Server Error"}, a row with file_path on
     * it and a screen insisting nothing had changed. After: the upload is what it
     * actually was — a success — with a note saying the cover could not be cut
     * here, which is the state this screen already draws as "No cover yet".
     *
     * MUTATION NOTE. Narrow the catch around `$this->transcoder->derive()` in
     * UgcVideoController::writeUpload() to ProcessFailedException, so the
     * LogicException escapes it exactly as it escaped the old
     * `catch (ProcessException)`. RUN: red — 500 instead of 200, over a row that
     * had already been saved. That is the owner's bug, reproduced.
     */
    UgcAdminRoutes::wire($this->app);
    $this->actingAs(u5Owner(), 'admin');
    app()->instance(UgcTranscoder::class, u5ThrowingTranscoder());
    $log = u5LogFile();

    $video = UgcVideo::create(['slug' => 'u5-'.uniqid(), 'title' => 'Anua mist spray 2']);

    $response = $this->post('/admin-api/ugc-videos/'.$video->id.'/media', [
        'kind' => 'clip',
        'file' => u5File('anua mist spray 2.mp4', u5Mp4()),
    ]);

    $response->assertStatus(200)->assertJson(['ok' => true]);

    // The row says what is true: the clip is on it.
    $fresh = $video->fresh();
    expect($fresh->file_path)->toStartWith('/'.UgcMedia::DIR.'/clip-')
        ->and(is_file(public_path(ltrim((string) $fresh->file_path, '/'))))->toBeTrue()
        ->and($fresh->poster_path)->toBeNull()
        // The already-supported state the screen badges "No cover yet".
        ->and($fresh->mediaState())->toBe(UgcVideo::MEDIA_NONE);

    /*
     * AND HE IS TOLD WHY, in the notes the panel prints — including that
     * re-uploading is not the remedy, which is the half that cost him the
     * re-send.
     */
    $notes = implode(' ', (array) $response->json('notes'));
    expect($notes)->toContain('could not cut the cover')
        ->and($notes)->toContain('Re-uploading the video will not change this');

    // And it is written where he can read it without SSH.
    expect((string) file_get_contents($log))
        ->toContain(UgcVideoController::LOG_MARKER)
        ->toContain('LogicException');
});

it('says the video WAS saved when the failure comes after the row is committed', function () {
    /*
     * THE SENTENCE THAT WAS FALSE. The kit prints `body.error` verbatim, and what
     * it printed was Laravel's "Server Error" beside the screen's own "nothing on
     * the clip was changed". Something had been changed.
     *
     * The throw here is on the SECOND save — the commit — so the clip branch's
     * own save has already landed. Whatever else the answer says, it may not say
     * nothing changed.
     *
     * MUTATION NOTE. In uploadFailed(), make `$what` unconditionally the
     * 'Nothing on the clip was changed' arm — the sentence the owner was shown.
     * RUN: red, on both the presence of the true clause and the absence of the
     * false one.
     */
    UgcAdminRoutes::wire($this->app);
    $this->actingAs(u5Owner(), 'admin');
    $log = u5LogFile();

    $video = UgcVideo::create(['slug' => 'u5c-'.uniqid(), 'title' => 'Commit fails']);

    $saves = 0;
    UgcVideo::saving(function () use (&$saves) {
        $saves++;

        // 1 is the clip branch's own save; 2 is the commit after the cut.
        if ($saves === 2) {
            throw new \RuntimeException('simulated: SQLSTATE[HY000] disk I/O error');
        }
    });

    $response = $this->post('/admin-api/ugc-videos/'.$video->id.'/media', [
        'kind' => 'clip',
        'file' => u5File('clip.mp4', u5Mp4()),
    ]);

    $response->assertStatus(500)->assertJson(['ok' => false, 'stored' => true, 'stage' => 'commit']);

    $error = (string) $response->json('error');
    expect($error)->toContain('WAS saved onto this clip')
        ->and($error)->not->toContain('Nothing on the clip was changed')
        // And it names the stage, so a cut fault and a database fault are not the
        // same "Server Error" with the same remedy.
        ->and($error)->toContain('saving the clip');

    // The row really does carry the clip, so the sentence above is true.
    expect($video->fresh()->file_path)->toStartWith('/'.UgcMedia::DIR.'/clip-');

    /*
     * AND THE FILE IS STILL THERE. The catch cleans up only what the row does not
     * name; deleting this one would make the clip a broken <video src>.
     *
     * MUTATION NOTE. Remove `$uncommitted = [];` from the clip branch after its
     * save, so the catch still believes the clip is an orphan. RUN: red — the
     * file is gone and the row points at nothing, which is worse than the bug
     * being fixed.
     */
    expect(is_file(public_path(ltrim((string) $video->fresh()->file_path, '/'))))->toBeTrue();

    expect((string) file_get_contents($log))->toContain(UgcVideoController::LOG_MARKER);
});

it('leaves no orphan behind when the failure comes before the row is committed', function () {
    /*
     * The other half of the same question, and the one that was quietly costing
     * bytes: UgcMedia::store() has already written the file when the row write
     * fails, and nothing names it afterwards — not the clip's deletion, which
     * walks the three columns, and not the replace path, which only forgets the
     * value it overwrites. One file per failed attempt, for ever.
     *
     * MUTATION NOTE. Remove `$uncommitted[] = (string) $result['path'];` from
     * upload(). RUN: red on the directory comparison — one clip-*.mp4 left
     * behind that no column names.
     */
    UgcAdminRoutes::wire($this->app);
    $this->actingAs(u5Owner(), 'admin');
    u5LogFile();

    $video = UgcVideo::create(['slug' => 'u5o-'.uniqid(), 'title' => 'Record fails']);
    $before = u5Stored();

    UgcVideo::saving(function () {
        throw new \RuntimeException('simulated: the row could not be written');
    });

    $response = $this->post('/admin-api/ugc-videos/'.$video->id.'/media', [
        'kind' => 'clip',
        'file' => u5File('clip.mp4', u5Mp4()),
    ]);

    $response->assertStatus(500)->assertJson(['ok' => false, 'stored' => false, 'stage' => 'record']);

    // HERE the claim is true, and it is made.
    expect((string) $response->json('error'))->toContain('Nothing on the clip was changed');

    // Nothing written, nothing left behind.
    expect(u5Stored())->toBe($before)
        ->and($video->fresh()->file_path)->toBeNull();
});

it('never answers a bare Laravel "Server Error" on the upload path', function () {
    /*
     * THE PROPERTY THE OWNER ACTUALLY NEEDS, stated as itself: whatever goes
     * wrong, the body carries a sentence this endpoint composed. The kit's
     * explain() prefers `body.error`, falls back to `body.message`, and Laravel's
     * unhandled-exception body is `{"message":"Server Error"}` with no `error` key
     * at all — which is why "Server Error" reached his screen in the first place.
     *
     * MUTATION NOTE. Narrow upload()'s outer catch to ProcessFailedException, so
     * the throw leaves the controller the way it used to. RUN: red — and red in
     * the shape that says most: Laravel's test harness RETHROWS an unhandled
     * exception, so the case dies on the RuntimeException itself with no body to
     * inspect at all. In production that same escape is the
     * {"message":"Server Error"} the kit printed. Two other cases go red with
     * it, which is the point: without this catch there is no composed answer
     * anywhere on the path.
     */
    UgcAdminRoutes::wire($this->app);
    $this->actingAs(u5Owner(), 'admin');
    u5LogFile();

    $video = UgcVideo::create(['slug' => 'u5s-'.uniqid(), 'title' => 'Anything at all']);

    UgcVideo::saving(function () {
        throw new \RuntimeException('simulated');
    });

    $body = (array) $this->post('/admin-api/ugc-videos/'.$video->id.'/media', [
        'kind' => 'clip',
        'file' => u5File('clip.mp4', u5Mp4()),
    ])->json();

    expect($body['error'] ?? null)->toBeString()
        ->and($body['error'])->not->toBe('Server Error')
        ->and(strlen((string) $body['error']))->toBeGreaterThan(80)
        // It points him at the one place that holds the detail.
        ->and($body['error'])->toContain('Debug & Monitor');

    expect($body['message'] ?? 'absent')->not->toBe('Server Error');
});

it('offers the manual cut button the same protection as the upload', function () {
    /*
     * The second way into derive(), and before this round the second way to a
     * bare "Server Error" — one button press on a clip that was already fine.
     *
     * MUTATION NOTE. Narrow the catch around derive() in
     * UgcVideoController::derive() to ProcessFailedException. RUN: red — the
     * LogicException escapes and the button 500s, which is what it did before
     * this round.
     */
    UgcAdminRoutes::wire($this->app);
    $this->actingAs(u5Owner(), 'admin');
    app()->instance(UgcTranscoder::class, u5ThrowingTranscoder());
    u5LogFile();

    $video = UgcVideo::create([
        'slug' => 'u5d-'.uniqid(),
        'title' => 'Cut again',
        'file_path' => '/'.UgcMedia::DIR.'/clip-20260927-121844-epAit3WIWg.mp4',
    ]);

    $response = $this->post('/admin-api/ugc-videos/'.$video->id.'/derive');

    $response->assertStatus(200)->assertJson(['ok' => true]);

    expect(implode(' ', (array) $response->json('notes')))->toContain('could not cut the cover');
});

it('tells the truth about a cover upload too, and its own failure handler cannot throw', function () {
    /*
     * THE SAME FALSE SENTENCE, ONE KIND OVER. The flag that decides whether the
     * answer may say "nothing was changed" was originally about the CLIP alone,
     * so a throw after a POSTER had been committed would have told the owner his
     * cover had not been saved when it had. It is about the row now, for all
     * three kinds.
     *
     * The throw is put on the `retrieved` event, which is what `fresh()` fires —
     * a failure AFTER the save, which is the only place this branch lives. It
     * then fires again inside the failure handler's own `fresh()`, which is the
     * second thing this case pins: a handler that throws while reporting is
     * CLAUDE.md's updater entry exactly, where the second defect made the first
     * unrecoverable.
     *
     * MUTATION NOTE, both RUN.
     *   `$committed = true;` after the commit put back to
     *   `$committed || $kind === UgcMedia::KIND_CLIP`
     *     RUN: red — stored came back false and the sentence claimed the cover
     *     had not been saved.
     *   safeCard() calling `$this->card($video->fresh())` unguarded
     *     RUN: red — the handler throws, nothing is answered at all and Pest
     *     reports the RuntimeException instead of a body.
     */
    UgcAdminRoutes::wire($this->app);
    $this->actingAs(u5Owner(), 'admin');
    u5LogFile();

    $video = UgcVideo::create(['slug' => 'u5p-'.uniqid(), 'title' => 'Cover only']);

    $reads = 0;
    UgcVideo::retrieved(function () use (&$reads) {
        $reads++;

        // 1 is the controller's own find(). Everything after it is a fresh()
        // on a row that has already been written.
        if ($reads >= 2) {
            throw new \RuntimeException('simulated: the row could not be read back');
        }
    });

    $response = $this->post('/admin-api/ugc-videos/'.$video->id.'/media', [
        'kind' => 'poster',
        'file' => u5File('cover.png', (function () {
            $im = imagecreatetruecolor(360, 640);
            ob_start();
            imagepng($im);
            imagedestroy($im);

            return (string) ob_get_clean();
        })()),
    ]);

    // Answered, not thrown — which is what proves safeCard() swallowed the
    // second failure instead of becoming it.
    $response->assertStatus(500)->assertJson(['ok' => false, 'stored' => true])
        // It could not read the row back, so it says nothing about it rather
        // than guessing.
        ->assertJson(['video' => null]);

    expect((string) $response->json('error'))
        ->toContain('cover image itself WAS saved')
        ->and((string) $response->json('error'))->not->toContain('Nothing on the clip was changed');

    // And it really was saved, so the sentence is true.
    expect(UgcVideo::withoutEvents(fn () => UgcVideo::find($video->id))->poster_path)
        ->toStartWith('/'.UgcMedia::DIR.'/poster-');
});

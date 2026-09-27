<?php

declare(strict_types=1);

use App\Models\UgcVideo;
use App\Services\UgcDerivedFiles;
use App\Services\UgcMedia;

/**
 * `php artisan ugc:cut-covers` — the route to a cover on a host whose WEB PHP
 * may not start a program.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * This is not a hypothetical host. It is the shop this was written for, and the
 * evidence is its own log, five times across seven hours:
 *
 *     LogicException: The Process class relies on proc_open, which is not
 *     available on your PHP installation.
 *       #0 UgcTranscoder.php(303): Process->__construct()
 *       #1 UgcTranscoder.php(224): UgcTranscoder->durationMs()
 *       #2 UgcVideoController.php(285): UgcTranscoder->derive()
 *
 * ── THE FACT THE WHOLE COMMAND TURNS ON ─────────────────────────────────────
 *
 * `php -i | grep disable_functions` on that same machine, over SSH, answers
 * **"no value"**. The CLI does not disable it; only the PHP-FPM pool does. The
 * two readings looked contradictory until the SAPI difference was the answer —
 * and that difference is a door that is open.
 *
 * So the covers are reachable today, on that server, with no change to it at
 * all. The command is the way through.
 */
function cucClip(array $attributes = []): UgcVideo
{
    return UgcVideo::create(array_merge([
        'slug' => 'cut-'.uniqid(),
        'title' => 'Needs a cover',
        'status' => 'draft',
        'file_path' => '/uploads/ugc/clip-'.uniqid().'.mp4',
        'creator_handle' => '@someone',
    ], $attributes));
}

/**
 * Point the transcoder at a binary that is not there, for the length of one
 * test.
 *
 * THIS HELPER EXISTS BECAUSE THE TEST BELOW WAS WRONG. It used to rely on the
 * container simply not having ffmpeg, with a comment saying so. That passed
 * for exactly as long as no machine running this suite had ffmpeg installed —
 * and the moment one did, it went red, having asserted nothing about the code
 * the whole time. A test whose premise is "what happens to be installed here"
 * is a test that reports on the machine, not on the software.
 *
 * `binary()` reads KBB_FFMPEG through env(), whose default repository includes
 * the putenv adapter, so this reaches it. Restored in a finally, because a
 * leaked value would follow every later test in the file.
 */
function cucWithoutFfmpeg(callable $body): void
{
    $was = getenv('KBB_FFMPEG');
    putenv('KBB_FFMPEG=/nonexistent/ffmpeg');

    try {
        $body();
    } finally {
        $was === false ? putenv('KBB_FFMPEG') : putenv('KBB_FFMPEG='.$was);
    }
}

it('says why it cannot cut, rather than failing fifty clips one at a time', function () {
    cucClip();

    /*
     * A run that cannot cut must say so ONCE, before any work, rather than
     * attempting every clip and printing the same failure per row. Fifty clips
     * is fifty identical errors and a minute of waiting for them.
     */
    cucWithoutFfmpeg(function () {
        $this->artisan('ugc:cut-covers')
            ->expectsOutputToContain('This machine cannot cut anything.')
            ->assertExitCode(1);
    });
});

/*
 * MUTATION: drop the `available()` guard at the top of handle() and this is red
 * — the command proceeds and reports per clip instead of once. RUN: red.
 */
it('names the two worlds apart, so the operator is not sent to the wrong fix', function () {
    $transcoder = app(\App\Services\UgcTranscoder::class);

    /*
     * The sentences come from the transcoder, so the command cannot drift from
     * what the admin screen says. "No ffmpeg" sends you to install one;
     * "installed but PHP may not start it" sends you to a PHP setting. Telling
     * an owner the first when it is the second costs him an afternoon.
     */
    expect($transcoder->blocker(true, null))->toContain('ffmpeg')
        ->and($transcoder->blocker(false, '/usr/bin/ffmpeg'))->not->toBe($transcoder->blocker(true, null));

    // And a box that can do both is not blocked at all.
    expect($transcoder->blocker(true, '/usr/bin/ffmpeg'))->toBeNull();
});

/*
 * MUTATION: make `clips()` ignore the poster filter and this is red — the
 * dry run lists the clip that already has one.
 */
it('leaves alone a clip that already has a cover, so it is safe to run twice', function () {
    $bare = cucClip(['title' => 'No cover here']);
    cucClip(['title' => 'Already covered', 'poster_path' => '/uploads/ugc/poster-'.uniqid().'.jpg']);

    /*
     * Asserted through --dry-run because this container cannot cut: the
     * SELECTION is what this case is about, and it is observable without a
     * transcoder. The refusal arm returns before the listing, so the command is
     * driven with a transcoder that says yes.
     */
    $this->mock(\App\Services\UgcTranscoder::class, function ($mock) {
        $mock->shouldReceive('available')->andReturn(true);
    });

    $this->artisan('ugc:cut-covers --dry-run')
        ->expectsOutputToContain('1 clip(s) to cut.')
        ->expectsOutputToContain('No cover here')
        ->doesntExpectOutputToContain('Already covered')
        ->assertExitCode(0);

    // And it really changed nothing.
    expect($bare->fresh()->poster_path)->toBeNull();
});

/*
 * THE SHARED WRITER, which is the reason this command did not become a fourth
 * copy of the same six column assignments.
 *
 * ── DRIVEN WITH THE REAL UgcMedia AND REAL FILES, NOT A MOCK ───────────────
 *
 * Not a choice of taste: UgcMedia is `final`, so Mockery refuses it. That turns
 * out to be the better test anyway — the property worth pinning is that the
 * replaced file LEAVES THE DISK, and a mock would only prove a method was
 * called. tests/bootstrap.php redirects public_path() into a per-run tree, so
 * these files are this run's own.
 *
 * MUTATION: delete the two `forget()` calls from UgcDerivedFiles::apply() and
 * this is red — the replaced poster survives on disk with nothing naming it,
 * which is exactly the drift the section-intake copy had already suffered.
 * RUN: red.
 */
function cucFile(string $name): string
{
    $dir = public_path('uploads/ugc');

    if (! is_dir($dir)) {
        @mkdir($dir, 0o755, true);
    }

    file_put_contents($dir.'/'.$name, 'x');

    return '/uploads/ugc/'.$name;
}

it('takes the file it replaces off the disk, on every path that cuts', function () {
    $old = cucFile('poster-old-'.uniqid().'.jpg');
    $new = cucFile('poster-new-'.uniqid().'.jpg');

    $clip = cucClip(['poster_path' => $old]);

    expect(is_file(public_path(ltrim($old, '/'))))->toBeTrue('the fixture did not write');

    app(UgcDerivedFiles::class)->apply($clip, [
        'poster' => $new,
        'teaser' => null,
        'width' => 360,
        'height' => 640,
        'duration_ms' => 4000,
        'notes' => [],
    ]);

    expect(is_file(public_path(ltrim($old, '/'))))->toBeFalse('the replaced poster is still on disk')
        ->and($clip->poster_path)->toBe($new)
        ->and($clip->width)->toBe(360)
        ->and($clip->duration_ms)->toBe(4000);
});

/*
 * MUTATION: change `$derived['width'] ?? $video->width` to a bare assignment
 * and this is red. A probe that could not read the dimensions returns null, and
 * writing that null throws away a size the shop already knew and is using to
 * hold the tile's shape while the video loads.
 */
it('keeps a size it already knew when the probe could not read one', function () {
    $clip = cucClip();
    $clip->width = 720;
    $clip->height = 1280;

    app(UgcDerivedFiles::class)->apply($clip, [
        'poster' => cucFile('poster-'.uniqid().'.jpg'),
        'teaser' => null,
        'width' => null,
        'height' => null,
        'duration_ms' => null,
        'notes' => [],
    ]);

    expect($clip->width)->toBe(720)->and($clip->height)->toBe(1280);
});

/*
 * MUTATION: remove the two `$wrote &&` calls and this is red. The upload path
 * tracks every file written and not yet committed so a throw in between deletes
 * the bytes rather than stranding them in public/uploads/ugc with no column
 * naming them — the owner's failed retries made several.
 */
it('tells the caller about each file as it lands, for the orphan sweep', function () {
    $clip = cucClip();

    $poster = cucFile('poster-'.uniqid().'.jpg');
    $teaser = cucFile('teaser-'.uniqid().'.mp4');

    $seen = [];

    app(UgcDerivedFiles::class)->apply($clip, [
        'poster' => $poster,
        'teaser' => $teaser,
        'width' => null, 'height' => null, 'duration_ms' => null, 'notes' => [],
    ], function (string $path) use (&$seen) { $seen[] = $path; });

    expect($seen)->toBe([$poster, $teaser]);
});

/*
 * ═══════════════════════════════════════════════════════════════════════════
 * THE ONE WRONG ANSWER THIS COMMAND CAN GIVE, and how it was found.
 *
 * Running the command for real against seeded clips, it reported every one of
 * them as "The stored clip is missing from the server at /uploads/ugc/...".
 * The files were there. What was not there was the DIRECTORY the process was
 * looking in: bootstrap/app.php ends in usePublicPath(), whose chain is
 * evaluated while the application is being built — BEFORE .env is read — so
 * this shop's public path comes from $KBB_PUBLIC_PATH in the real environment,
 * or bootstrap/public-path.php, or a hardcoded fallback belonging to an older
 * server that no longer exists.
 *
 * That matters here more than anywhere else in the application, because this is
 * the one entry point that runs under a DIFFERENT SAPI from the shop. A
 * KBB_PUBLIC_PATH set in an FPM pool (`env[KBB_PUBLIC_PATH] = ...`) is read by
 * the web and not by the command line. The shop would be serving every one of
 * those files while this command swore they were gone — and the operator would
 * go hunting for uploads that were never lost.
 *
 * So the command names the directory it looked in. One line, and the wrong
 * answer becomes the right question.
 * ═══════════════════════════════════════════════════════════════════════════
 */
it('names the directory it looked in when every file is missing', function () {
    // Both rows point at files that were never written. This is the shape a
    // public-path mismatch takes: the rows are fine, the disk this process can
    // see is not.
    cucClip(['file_path' => '/uploads/ugc/never-written-a.mp4']);
    cucClip(['file_path' => '/uploads/ugc/never-written-b.mp4']);

    $this->artisan('ugc:cut-covers')
        ->expectsOutputToContain('0 cut, 2 could not be.')
        ->expectsOutputToContain('check WHERE this looked')
        // The ACTUAL resolved directory, not a description of one. An operator
        // comparing it against their web root is the whole point; a sentence
        // that says "check your public path" without saying what it is leaves
        // them exactly where they started.
        ->expectsOutputToContain(public_path(UgcMedia::DIR))
        ->assertExitCode(1);
});

/*
 * MUTATION: drop the `$missing === $clips->count()` half of the condition in
 * CutUgcCovers::handle() and THIS is the test that goes red, because the hint
 * then prints on a run that cut most of its clips. RUN: red — "Output contains
 * 'check WHERE this looked'".
 *
 * That half matters: one clip whose file genuinely did go missing is not a
 * configuration problem, and six lines about public paths under an otherwise
 * good run is the kind of noise that gets output ignored.
 */
it('stays quiet about the directory when one clip is present but unreadable', function () {
    /*
     * cucFile writes one byte, so this row's file EXISTS and is not a video.
     * That is precisely the row this test needs: it fails to cut, like the
     * other one, and it fails for a reason that is not "the directory is
     * wrong". The hint is tied to "every one of them was missing", and one row
     * that is present is enough to withhold it -- whatever became of it after.
     */
    cucClip(['file_path' => cucFile('present-but-not-a-video-'.uniqid().'.mp4')]);
    cucClip(['file_path' => '/uploads/ugc/never-written-'.uniqid().'.mp4']);

    $this->artisan('ugc:cut-covers')
        ->doesntExpectOutputToContain('check WHERE this looked')
        ->run();
});

/*
 * ═══════════════════════════════════════════════════════════════════════════
 * THE SCHEDULED RUN — how a cover gets cut without anybody typing anything.
 *
 * The owner asked, in as many words: "i want to have auto get the poster from
 * the video." On his host the web request cannot do it, and this command can,
 * so the answer is to run this command on a schedule — one crontab line for
 * `schedule:run`, and routes/console.php decides the rest.
 *
 * That turns one detail of the command into load-bearing behaviour: a machine
 * that CANNOT cut must not report a failure every minute forever. A cron that
 * mails its owner 1,440 times a day trains him to filter the mail, and the
 * filter then hides the failure that mattered.
 * ═══════════════════════════════════════════════════════════════════════════
 */
it('treats a machine that cannot cut as a no-op when the scheduler runs it', function () {
    cucClip();

    cucWithoutFfmpeg(function () {
        $this->artisan('ugc:cut-covers --unattended')
            // STILL SAYS SO -- `schedule:run` by hand has to tell the truth,
            // and the sentence is the transcoder's own so it cannot drift.
            ->expectsOutputToContain('Nothing cut:')
            // ...but exit 0, which is the only part cron reads.
            ->assertExitCode(0);
    });
});

/*
 * MUTATION: delete the `if ($this->option('unattended'))` arm and this is red
 * with exit code 1 -- the cron-failure-every-minute shape. RUN: red.
 *
 * AND THE OTHER HALF, which is the one a careless fix would break: a person who
 * TYPES the command must still be told, loudly, that it cannot run. The case
 * near the top of this file ("says why it cannot cut") covers that, and it
 * passes the flag NOT at all -- so the two arms are pinned independently and a
 * change that silenced both would still go red.
 */
it('keeps the scheduled entry pointed at the unattended flag', function () {
    /*
     * Read out of the file rather than out of the Schedule, because what is
     * being pinned is that the SCHEDULED invocation carries --unattended. A
     * schedule registered without it would exit non-zero every minute on a host
     * with no ffmpeg, which is exactly the defect the arm above prevents, and
     * nothing else in this suite would notice.
     */
    $console = (string) file_get_contents(base_path('routes/console.php'));

    expect($console)->toContain("Schedule::command('ugc:cut-covers --limit=20 --unattended')")
        // A long transcode must not hold schedule:run, and a killed process
        // must not hold the lock forever.
        ->toContain('->withoutOverlapping(10)')
        ->toContain('->runInBackground()');
});

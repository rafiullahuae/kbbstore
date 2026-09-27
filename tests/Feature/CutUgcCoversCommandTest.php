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

it('says why it cannot cut, rather than failing fifty clips one at a time', function () {
    cucClip();

    /*
     * This container has no ffmpeg, so the command takes the refusal arm — and
     * that IS the case worth pinning here. A run that cannot cut must say so
     * ONCE, before any work, rather than attempting every clip and printing the
     * same failure per row.
     */
    $this->artisan('ugc:cut-covers')
        ->expectsOutputToContain('This machine cannot cut anything.')
        ->assertExitCode(1);
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

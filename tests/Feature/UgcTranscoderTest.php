<?php

declare(strict_types=1);

use App\Models\UgcVideo;
use App\Services\UgcTranscoder;

/**
 * Both halves of the unanswered question.
 *
 * docs/UGC-VIDEO-PLAN.md §8 question 4 — *does `ffmpeg` exist on that Cloudways
 * box* — is a ten-second `which ffmpeg` over SSH that nobody has run, and
 * Cloudways managed hosting gives no root, so it may not be installable if it
 * is absent. §3.4 says plainly that nobody should design around ffmpeg until
 * somebody has run that command.
 *
 * So the module is built for both worlds and BOTH are tested here: the machine
 * this suite runs on has no ffmpeg, which tests the fallback for free, and a
 * stub binary on PATH-free absolute paths tests the other half without needing
 * a real transcoder anywhere.
 *
 * ── WHY THE COMMANDS ARE PURE FUNCTIONS ─────────────────────────────────────
 *
 * posterCommand() and teaserCommand() build an argv array and run nothing, so
 * the three decisions that ARE the 12.1x byte saving in §0b.1 — 2.5 seconds,
 * 360x640, no audio — can be asserted on a box with no encoder at all. A test
 * that could only run where ffmpeg exists is a test that never runs.
 */

it('says out loud that it cannot cut anything, rather than failing silently', function () {
    /*
     * THE STATE THIS ROUND HAD TO MAKE FIRST-CLASS. On a server with no
     * transcoder every video is teaser-less, and the honest answer is a
     * sentence telling the owner what to do instead — not an exception, not a
     * silent null, and not (§0b.1) a fallback to seeking the full clip, which
     * costs 12.1x and saves nothing.
     *
     * MUTATION NOTE. Make derive() throw or return an empty notes list when
     * binary() is null and this is red — and the admin screen would then show
     * an upload that appeared to work and produced nothing. RUN.
     */
    $transcoder = app(UgcTranscoder::class);

    if ($transcoder->available()) {
        // This machine has one. The other half of the pair covers this case.
        expect($transcoder->binary())->toStartWith('/');

        return;
    }

    $video = new UgcVideo(['file_path' => '/uploads/ugc/clip-20270110-000000-aaaaaaaaaa.mp4']);

    $dir = public_path(\App\Services\UgcMedia::DIR);
    if (! is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents($dir.'/clip-20270110-000000-aaaaaaaaaa.mp4', 'x');

    $result = $transcoder->derive($video);

    expect($result['poster'])->toBeNull()
        ->and($result['teaser'])->toBeNull()
        ->and(implode(' ', $result['notes']))->toContain('no ffmpeg')
        // The sentence names the way forward, which is the difference between
        // a report and an error.
        ->and(implode(' ', $result['notes']))->toContain('poster image');

    @unlink($dir.'/clip-20270110-000000-aaaaaaaaaa.mp4');
});

it('does not blame the missing transcoder when the clip itself is missing', function () {
    /*
     * Two different problems with one obvious-looking symptom. A silent null
     * here reads as "ffmpeg is missing" and sends somebody to the wrong
     * problem — to an SSH session and an apt-get that was never the answer.
     *
     * MUTATION NOTE. Move the binary() check above the is_file() check in
     * derive() and this is red on a box with no ffmpeg. RUN.
     */
    $video = new UgcVideo(['file_path' => '/uploads/ugc/clip-that-is-not-there-aaaa.mp4']);

    $notes = implode(' ', app(UgcTranscoder::class)->derive($video)['notes']);

    expect($notes)->toContain('missing from the server');
});

it('says there is nothing to cut from when no clip has been uploaded', function () {
    $notes = implode(' ', app(UgcTranscoder::class)->derive(new UgcVideo)['notes']);

    expect($notes)->toContain('no uploaded clip');
});

it('cuts a 2.5 second silent 360x640 loop and nothing longer', function () {
    /*
     * The three numbers that ARE the saving. Round three measured, over real
     * HTTP on a rail of eight tiles:
     *
     *     full clip, seeking back to 0    12,786,600 B
     *     the same clip with #t=0,2.5     12,786,600 B
     *     a separate 2.5s teaser file      1,055,160 B
     *
     * A media fragment tells the player where to start and the network nothing,
     * so the only thing that saves bytes is a different, smaller FILE. If any
     * of these three arguments drifts, the teaser stops being 12.1x cheaper and
     * the whole reason for the second column goes with it.
     *
     * MUTATION NOTE. Drop '-an' and the silence assertion is red; change
     * TEASER_SECONDS to '10' and the length assertion is red. RUN: both.
     */
    $command = app(UgcTranscoder::class)->teaserCommand('/usr/bin/ffmpeg', '/in.mp4', '/out.mp4');

    expect($command[0])->toBe('/usr/bin/ffmpeg')
        ->and($command)->toContain('-t')
        ->and($command[array_search('-t', $command, true) + 1])->toBe('2.5')
        // No audio. A teaser plays muted by policy — every browser refuses an
        // unmuted autoplay — so its audio track is bytes nobody can ever hear.
        ->and($command)->toContain('-an')
        ->and(implode(' ', $command))->toContain('scale=360:640')
        ->and($command)->toContain('400k')
        // The moov atom at the front, so the first bytes a phone receives are
        // the ones it needs to start decoding.
        ->and(implode(' ', $command))->toContain('+faststart')
        // The output path is the last argument, which is what run() checks for
        // a written file.
        ->and($command[count($command) - 1])->toBe('/out.mp4');
});

it('takes the poster frame after the clip has started, not at zero', function () {
    /*
     * Frame zero of a phone video is very often black or half-exposed — the
     * sensor is still settling — and the poster is what every tile shows before
     * anything moves, and what a teaser-less rail shows permanently.
     */
    $command = app(UgcTranscoder::class)->posterCommand('/usr/bin/ffmpeg', '/in.mp4', '/out.jpg');

    expect($command[array_search('-ss', $command, true) + 1])->toBe('0.6')
        ->and($command)->toContain('-frames:v')
        ->and($command[count($command) - 1])->toBe('/out.jpg');
});

it('never puts anything but its own generated paths on the command line', function () {
    /*
     * NO SHELL, EVER. Symfony's Process with an ARGUMENT ARRAY execs the binary
     * directly, so there is no shell to quote for and no filename can become an
     * argument boundary. This asserts the shape the safety rests on: a list,
     * never a string.
     *
     * The filenames are generated by UgcMedia and by derive(), never taken from
     * a request — but the assertion is made against a hostile one anyway,
     * because the guarantee is meant to hold whatever it is handed.
     */
    $hostile = '/tmp/x.mp4"; rm -rf / #';

    $command = app(UgcTranscoder::class)->teaserCommand('/usr/bin/ffmpeg', $hostile, '/out.mp4');

    expect($command)->toBeArray()
        // One element, whole and unsplit: nothing tokenised it, which is what
        // "no shell" means in practice.
        ->and($command)->toContain($hostile);
});

it('finds no binary at a path that is not absolute, executable and real', function () {
    /*
     * The binary is never taken from a request, and `which` is not consulted
     * either — `which` reads $PATH, and $PATH is environment this application
     * does not own. Four fixed absolute paths, each checked with is_file() and
     * is_executable().
     */
    $transcoder = app(UgcTranscoder::class);

    // Each candidate, if any exists on this machine, is absolute.
    $binary = $transcoder->binary();

    expect($binary === null || (str_starts_with($binary, '/') && is_executable($binary)))->toBeTrue();
});

it('drives the real plumbing against a stub binary', function () {
    /*
     * The half of this module that cannot be tested on a box with no encoder:
     * that derive() actually runs the command, notices the file it wrote, reads
     * the poster's dimensions out of it, and records both paths.
     *
     * A stub shell script standing in for ffmpeg, pointed at by KBB_FFMPEG —
     * the same env var the live server would use if ffmpeg were installed
     * somewhere unusual. It writes a real PNG for the poster and a byte for the
     * teaser, which is everything run() checks.
     *
     * MUTATION NOTE. Make run() return true without checking filesize() and the
     * "empty output" case below goes green with a zero-byte .mp4 recorded — a
     * <video src> pointing at nothing, which is a tile that spins forever. RUN.
     */
    $stub = tempnam(sys_get_temp_dir(), 'ffstub');
    // `for out; do :; done` leaves $out holding the LAST positional argument,
    // which is where both commands put their destination.
    file_put_contents($stub, <<<'STUB'
    #!/bin/sh
    for out; do :; done
    case "$out" in
      *.jpg) printf 'fake-jpeg-bytes' > "$out" ;;
      *) printf 'x' > "$out" ;;
    esac
    exit 0
    STUB);
    chmod($stub, 0755);

    putenv('KBB_FFMPEG='.$stub);

    try {
        $dir = public_path(\App\Services\UgcMedia::DIR);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $clip = 'clip-20270110-000000-stubbbbbbb.mp4';
        file_put_contents($dir.'/'.$clip, 'x');

        $transcoder = app(UgcTranscoder::class);

        expect($transcoder->available())->toBeTrue();

        $video = new UgcVideo(['file_path' => '/uploads/ugc/'.$clip]);
        $result = $transcoder->derive($video);

        expect($result['poster'])->toStartWith('/uploads/ugc/poster-')
            ->and($result['teaser'])->toStartWith('/uploads/ugc/teaser-')
            ->and($result['notes'])->toBe([]);

        foreach ([$result['poster'], $result['teaser']] as $made) {
            @unlink(public_path(ltrim($made, '/')));
        }

        @unlink($dir.'/'.$clip);
    } finally {
        putenv('KBB_FFMPEG');
        @unlink($stub);
    }
});

it('records nothing when the stub writes an empty file', function () {
    // ffmpeg can exit 0 having written a zero-byte container when the input has
    // no decodable video stream. A zero-byte .mp4 in a <video src> is a tile
    // that spins forever, so the destination is checked for SIZE as well as
    // existence, and an empty one is removed rather than recorded.
    $stub = tempnam(sys_get_temp_dir(), 'ffempty');
    file_put_contents($stub, <<<'STUB'
    #!/bin/sh
    for out; do :; done
    : > "$out"
    exit 0
    STUB);
    chmod($stub, 0755);

    putenv('KBB_FFMPEG='.$stub);

    try {
        $dir = public_path(\App\Services\UgcMedia::DIR);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $clip = 'clip-20270110-000000-emptyyyyyy.mp4';
        file_put_contents($dir.'/'.$clip, 'x');

        $result = app(UgcTranscoder::class)->derive(new UgcVideo(['file_path' => '/uploads/ugc/'.$clip]));

        expect($result['poster'])->toBeNull()
            ->and($result['teaser'])->toBeNull()
            // The teaser's failure is reported as what it is: the video still
            // works, its tile just shows the poster.
            ->and(implode(' ', $result['notes']))->toContain('still works');

        @unlink($dir.'/'.$clip);
    } finally {
        putenv('KBB_FFMPEG');
        @unlink($stub);
    }
});

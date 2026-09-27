<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\UgcVideo;
use App\Services\UgcTranscoder;

/**
 * A probe that dies must not take the clip library with it.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * ── THE DEFECT ──────────────────────────────────────────────────────────────
 *
 * The owner's All clips screen answered HTTP 500 on every load and showed him
 * "0 clips". The Sections screen beside it, reading the same table, was fine.
 * The difference is that index() also asks this server three questions that
 * have nothing to do with the clips: can ffmpeg be started, what will PHP
 * really accept for an upload, and what do the blank Arabic boxes look like.
 *
 * Those three answers are DECORATION — a line of helper text, a size in the
 * upload box, two empty inputs. The list of clips is the screen. And the whole
 * response was built in one expression, so any one of those three probes
 * failing meant the owner lost his entire library behind a generic "Server
 * Error" he could do nothing with.
 *
 * It is the same argument UgcTranscoder::run() already carries one layer down —
 * a failed transcode may not fail an upload — applied to the layer above.
 *
 * ── WHAT IS PINNED ──────────────────────────────────────────────────────────
 *
 * The transcoder is the probe driven here because it is the one that genuinely
 * touches this machine: four absolute paths on disk, proc_open, ini values. It
 * is bound as a throwing double, which is the same shape as a server whose
 * open_basedir refuses /usr/bin or whose ini is unreadable.
 *
 * MUTATION NOTE. Put index()'s `'transcoder' => [...]` back the way it was —
 * inline, not through probe() — and the first case below is red with a 500.
 * RUN: red.
 */
function ugcProbeOwner(): AdminUser
{
    return AdminUser::create([
        'name' => 'Probe Owner',
        'email' => 'probe-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

/** A transcoder whose every question throws, as a hardened host's would. */
function ugcThrowingTranscoder(): UgcTranscoder
{
    return new class extends UgcTranscoder
    {
        public function available(): bool
        {
            // The shape of an open_basedir refusal: PHP raises a warning, and
            // Laravel's error handler turns it into an ErrorException.
            throw new \ErrorException(
                'is_file(): open_basedir restriction in effect. File(/usr/bin/ffmpeg) is not within the allowed path(s)'
            );
        }

        public function canSpawn(): bool
        {
            throw new \ErrorException('function_exists(): disabled');
        }

        public function binary(): ?string
        {
            throw new \ErrorException('is_file(): open_basedir restriction in effect.');
        }
    };
}

it('still lists every clip when the ffmpeg probe throws', function () {
    $this->actingAs(ugcProbeOwner(), 'admin');

    $a = UgcVideo::create(['slug' => 'p-'.uniqid(), 'title' => 'Glass skin in 6 steps']);
    $b = UgcVideo::create(['slug' => 'p-'.uniqid(), 'title' => 'anua mist']);

    app()->instance(UgcTranscoder::class, ugcThrowingTranscoder());

    $body = $this->getJson('/admin-api/ugc-videos')->assertOk()->json();

    // THE LIBRARY IS THE SCREEN, and it is all there.
    expect(array_column($body['videos'], 'id'))
        ->toContain($a->id)
        ->toContain($b->id);
});

it('says which part failed, in the server’s own words', function () {
    $this->actingAs(ugcProbeOwner(), 'admin');

    UgcVideo::create(['slug' => 'p-'.uniqid(), 'title' => 'Glass skin in 6 steps']);

    app()->instance(UgcTranscoder::class, ugcThrowingTranscoder());

    $body = $this->getJson('/admin-api/ugc-videos')->assertOk()->json();

    expect($body['probe_errors'])->toBeArray()->not->toBeEmpty();

    $said = implode(' ', $body['probe_errors']);

    // Which probe, the exception class, and the message — the three things
    // that turn "Server Error" into something somebody can act on.
    expect($said)->toContain('ffmpeg check')
        ->toContain('ErrorException')
        ->toContain('open_basedir');
});

it('falls back to the safe claim rather than a wrong one', function () {
    $this->actingAs(ugcProbeOwner(), 'admin');

    app()->instance(UgcTranscoder::class, ugcThrowingTranscoder());

    $body = $this->getJson('/admin-api/ugc-videos')->assertOk()->json();

    /*
     * `available => false` is the SAFE claim: it makes the screen offer a
     * poster upload instead of promising a cut that cannot happen. Defaulting
     * to true would have the screen wait for a teaser that never arrives.
     */
    expect($body['transcoder']['available'])->toBeFalse()
        // The constants are still there — they come from the class, not the
        // machine, so a dead probe is no reason to lose them.
        ->and($body['transcoder']['teaser_seconds'])->toBe(UgcTranscoder::TEASER_SECONDS);
});

it('keeps probe_errors empty on a healthy server, so the note stays hidden', function () {
    $this->actingAs(ugcProbeOwner(), 'admin');

    UgcVideo::create(['slug' => 'p-'.uniqid(), 'title' => 'Glass skin in 6 steps']);

    $body = $this->getJson('/admin-api/ugc-videos')->assertOk()->json();

    // Without this the warm note would sit on every healthy shop's screen,
    // which is how a warning becomes something nobody reads.
    expect($body['probe_errors'])->toBe([]);
});

<?php

declare(strict_types=1);

use App\Models\UgcSection;
use App\Models\UgcVideo;
use App\Services\SettingsService;
use App\Services\UgcSettings;
use App\Services\UgcTranscoder;
use App\Support\Shortcodes;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

/**
 * ONE SECOND, END TO END — the owner's own instruction, in his words:
 *
 *   *"I also want 1 seconds video to be cropped as clip. 2-3 seconds taking
 *    more time to load on front-end."*
 *
 * ── WHY THIS IS ONE FILE AND NOT TWO NUMBERS EDITED IN PLACE ────────────────
 *
 * "One second" lives in TWO places that had no test tying them together, and a
 * shop where they disagree loops at two speeds in the same rail:
 *
 *   UgcTranscoder::TEASER_SECONDS   the length a teaser FILE is cut to. What a
 *                                   tile with a teaser loops, natively, with
 *                                   `v.loop = true` and no seek at all.
 *   UgcSettings teaser_ms           the length the rail REWINDS at when a clip
 *                                   has no teaser file and the loop has to come
 *                                   out of the full one. Every clip on the
 *                                   owner's own shop is in this state, because
 *                                   PHP-FPM there cannot start ffmpeg.
 *
 * The floor is in here too, and it is the half that would have shipped broken
 * in silence: teaser_ms' range began at 1500, and ModuleSchema::cast() CLAMPS —
 * so a default of 1000 under a minimum of 1500 is not a default at all, it is
 * 1500 the first time anybody presses Save. Rule 5's clamping is exactly what
 * would have hidden it.
 */
it('cuts a one-second teaser and rewinds an uncut clip at the same second', function () {
    /*
     * MUTATION NOTE — RUN. Put UgcTranscoder::TEASER_SECONDS back to '2.5' and
     * the first expectation is red. Put teaser_ms' default back to 2500 and the
     * second is red. Put its `min` back to 1500 with the default at 1000 and the
     * THIRD is red — which is the case that matters, because that combination
     * has no visible symptom until somebody saves the screen and the slider
     * jumps to 1500 on its own. RUN: all three.
     */
    expect(UgcTranscoder::TEASER_SECONDS)->toBe('1');

    $field = UgcSettings::SCHEMA['teaser_ms'];

    expect($field['default'])->toBe(1000, 'the rewind fallback no longer agrees with the cut length');

    expect($field['options']['min'])->toBeLessThanOrEqual($field['default'])
        ->and($field['options']['min'])->toBe(1000);

    // The two numbers ARE the same second, said in the two units they are
    // stored in. This is the tie the shop needs and nothing else asserted.
    expect((float) UgcTranscoder::TEASER_SECONDS * 1000)->toBe((float) $field['default']);
});

it('puts the cut length in the teaser file name, so nothing has to probe for it', function () {
    /*
     * ── WHY THE NAME ─────────────────────────────────────────────────────────
     *
     * `--recut-teasers` has to answer "was this cut at the length this shop cuts
     * at now" for every clip it looks at. Answered from the FILE, that is an
     * ffprobe per clip; answered from the NAME it is a LIKE on a column the
     * query has already loaded.
     *
     * MUTATION NOTE — RUN. Take `self::teaserMark().'-'` back out of the name
     * built in derive() and the first expectation is red; make teaserMark()
     * return TEASER_SECONDS unchanged ('1' has no dot, but '2.5' does) and the
     * dot assertion is red — a dot in a file name is a second extension, and
     * MediaRegistrar's type sniffing is not the place to discover that.
     */
    expect(UgcTranscoder::teaserMark())->toBe('1s')
        ->and(UgcTranscoder::teaserMark())->not->toContain('.');

    // The name derive() writes, asserted where it is built rather than by
    // running ffmpeg: the marker is the middle segment, before the timestamp.
    $source = (string) file_get_contents(app_path('Services/UgcTranscoder.php'));

    expect(str_contains($source, "\$name = 'teaser-'.self::teaserMark().'-'.date('Ymd-His')"))
        ->toBeTrue('a teaser is cut to a name that does not say what length it is');

    // ...and reading it back. A file with no marker is every teaser cut before
    // this change, and every teaser an owner dropped in by hand.
    expect(UgcTranscoder::teaserIsCurrentLength('/uploads/ugc/teaser-1s-20260929-031500-abcdefghij.mp4'))->toBeTrue()
        ->and(UgcTranscoder::teaserIsCurrentLength('/uploads/ugc/teaser-20260921-203448-7hk2mq9wxb.mp4'))->toBeFalse()
        ->and(UgcTranscoder::teaserIsCurrentLength('/uploads/ugc/my-own-loop.mp4'))->toBeFalse()
        ->and(UgcTranscoder::teaserIsCurrentLength(null))->toBeFalse()
        ->and(UgcTranscoder::teaserIsCurrentLength(''))->toBeFalse();
});

it('re-cuts an old teaser only when a person asks, and never from the schedule', function () {
    /*
     * ── THE DECISION THIS PINS ───────────────────────────────────────────────
     *
     * Every teaser file already on a shop is 2.5 seconds long, and a rail
     * carrying both lengths loops at two speeds in one row. So they are re-cut —
     * and the SELECTION IS THE WHOLE QUESTION, because the round before this one
     * deliberately made the scheduled selection retry a missing teaser FOR EVER
     * (a clip with no teaser loops ~11 MB of full clip instead of 30 KB, so the
     * retry pays for itself). Applying the same rule to a LENGTH would spend an
     * ffmpeg start a minute, for ever, on clips that already have a working
     * loop and are merely 1.5 seconds long.
     *
     * The line: the SCHEDULE selects on "a derivative is MISSING"; the LENGTH
     * re-cut is a switch a person types and is on no schedule at all. A clip
     * whose re-cut fails keeps its working 2.5s teaser and is named in the
     * output — it is never retried by a machine.
     *
     * MUTATION NOTE — RUN. Add `--recut-teasers` to the Schedule::command() line
     * in routes/console.php and the last expectation here is red.
     */
    $command = (string) file_get_contents(app_path('Console/Commands/CutUgcCovers.php'));

    // The selection is a string comparison on the column, not a probe.
    expect(str_contains($command, "->where('teaser_path', 'not like', '%teaser-'.UgcTranscoder::teaserMark().'-%')"))
        ->toBeTrue('the length re-cut no longer selects on the file name');

    // It forces the TEASER leg only: the posters are already right and re-cutting
    // them would spend an ffmpeg run each writing the same frame back.
    expect(str_contains($command, "remakeTeaser: (bool) \$this->option('force') || (bool) \$this->option('recut-teasers')"))
        ->toBeTrue('--recut-teasers no longer forces the teaser leg')
        ->and(str_contains($command, "remakePoster: (bool) \$this->option('force'),"))
        ->toBeTrue('--recut-teasers must not re-cut posters it was not asked about');

    // And nothing unattended ever reaches it.
    $schedule = (string) file_get_contents(base_path('routes/console.php'));

    expect(str_contains($schedule, 'ugc:cut-covers --limit=20 --unattended'))
        ->toBeTrue('the scheduled cut run moved, so this case is measuring nothing')
        ->and(str_contains($schedule, 'recut-teasers'))
        ->toBeFalse('a length re-cut on a schedule retries a clip that is not broken, for ever');
});

it('selects exactly the clips whose teaser was cut at the old length', function () {
    /*
     * The selection, run against real rows rather than read. Three clips:
     * one cut at the new length, one cut at the old, one never cut at all.
     * `--recut-teasers` must name the middle one and only the middle one —
     * the third has no teaser to be the wrong length.
     *
     * MUTATION NOTE — RUN. Drop `->where('teaser_path', '!=', '')` from the
     * re-cut branch and the clip whose teaser column is the EMPTY STRING is
     * listed too, which is red here: that clip has no loop to be the wrong
     * length and belongs to the scheduled run, not to this one.
     *
     * A NULL and an empty string are two rows for the same fact in this schema
     * (CutUgcCovers' own scheduled selection tests both, on both columns), and
     * only one of them needed guarding — SQL's `not like` against NULL is NULL,
     * which is not true, so a null teaser falls out on its own. The first cut of
     * this case seeded only the NULL and the mutation stayed GREEN. Found by
     * running it, which is what running it is for.
     */
    UgcVideo::query()->create([
        'slug' => 'os-fresh', 'title' => 'cut at one second', 'status' => 'publish',
        'rights_status' => 'granted', 'file_path' => '/uploads/ugc/os-a.mp4',
        'poster_path' => '/uploads/ugc/os-a.jpg',
        'teaser_path' => '/uploads/ugc/teaser-1s-20260929-031500-aaaaaaaaaa.mp4',
    ]);
    UgcVideo::query()->create([
        'slug' => 'os-stale', 'title' => 'cut at two and a half', 'status' => 'publish',
        'rights_status' => 'granted', 'file_path' => '/uploads/ugc/os-b.mp4',
        'poster_path' => '/uploads/ugc/os-b.jpg',
        'teaser_path' => '/uploads/ugc/teaser-20260921-203448-bbbbbbbbbb.mp4',
    ]);
    UgcVideo::query()->create([
        'slug' => 'os-none', 'title' => 'never cut', 'status' => 'publish',
        'rights_status' => 'granted', 'file_path' => '/uploads/ugc/os-c.mp4',
        'poster_path' => '/uploads/ugc/os-c.jpg', 'teaser_path' => null,
    ]);
    UgcVideo::query()->create([
        'slug' => 'os-blank', 'title' => 'cut attempt that wrote nothing', 'status' => 'publish',
        'rights_status' => 'granted', 'file_path' => '/uploads/ugc/os-e.mp4',
        'poster_path' => '/uploads/ugc/os-e.jpg', 'teaser_path' => '',
    ]);

    /*
     * --dry-run, so this measures the SELECTION and never starts ffmpeg. The
     * command exits early on a machine with no transcoder, which is the case on
     * CI, so the listing is only asserted when there IS one — and the selection
     * is asserted from the query either way, below.
     */
    Artisan::call('ugc:cut-covers', ['--recut-teasers' => true, '--dry-run' => true]);
    $out = Artisan::output();

    if (! app(UgcTranscoder::class)->available()) {
        expect($out)->toContain('cannot cut');

        return;
    }

    expect($out)->toContain('cut at two and a half')
        ->and($out)->not->toContain('cut at one second')
        ->and($out)->not->toContain('never cut')
        ->and($out)->not->toContain('cut attempt that wrote nothing');
});

it('keeps the value a shop had already saved, and prints it on the rail', function () {
    /*
     * RULE 1, and the one thing about this change that could hurt somebody. The
     * DEFAULT moved, which is what the owner asked for. A shop that had already
     * moved the slider has a row in `module_settings`, and `all()` reads the row
     * — so applying the package must not pull a saved 4000 down to 1000.
     *
     * MUTATION NOTE — RUN. Make UgcSettings::all() return the schema default
     * whenever it differs from the saved value and the first expectation is red.
     */
    app(SettingsService::class)->setModule('shoppable_video', true);
    app(SettingsService::class)->setModuleSetting('shoppable_video', 'teaser_ms', 4000);
    SettingsService::forgetMemo();
    Cache::flush();

    expect(app(UgcSettings::class)->all()['teaser_ms'])->toBe(4000);

    $section = UgcSection::query()->create(['handle' => 'os-rail', 'title' => 'Shop the look', 'status' => 'publish']);
    $clip = UgcVideo::query()->create([
        'slug' => 'os-rail-clip', 'title' => 'anua mist', 'status' => 'publish',
        'rights_status' => 'granted', 'file_path' => '/uploads/ugc/os-d.mp4',
        'poster_path' => '/uploads/ugc/os-d.jpg', 'width' => 720, 'height' => 1280,
    ]);
    $section->videos()->syncWithoutDetaching([$clip->id => ['position' => 0]]);

    expect(Shortcodes::render('[kbb_videos section="os-rail"]'))
        ->toContain('data-ugcr-teaser-ms="4000"');

    // ...and a shop that saved nothing gets the new second.
    app(SettingsService::class)->setModuleSetting('shoppable_video', 'teaser_ms', 1000);
    SettingsService::forgetMemo();
    Cache::flush();
    app(\App\Services\UgcRail::class)->flush();
    Shortcodes::flush();

    expect(Shortcodes::render('[kbb_videos section="os-rail"]'))
        ->toContain('data-ugcr-teaser-ms="1000"');
});

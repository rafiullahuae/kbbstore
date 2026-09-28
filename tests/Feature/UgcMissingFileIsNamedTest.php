<?php

declare(strict_types=1);

use App\Models\UgcSection;
use App\Models\UgcVideo;
use App\Services\SettingsService;
use App\Services\Ugc\ClipFile;
use App\Services\UgcTranscoder;
use App\Support\Shortcodes;
use Illuminate\Support\Facades\Cache;

/**
 * A clip row whose file is not there says SO — it does not blame ffmpeg, and it
 * does not claim nothing was ever uploaded.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * ── WHERE THIS CAME FROM ────────────────────────────────────────────────────
 *
 * The owner's own `ugc:cut-covers` run on the live server named two clips, #9
 * and #10, that could not be cut. His directory listing of `uploads/ugc` shows
 * six clips and neither of those two names: the ROWS survived and the FILES did
 * not. Everything the shop said about them afterwards was about ffmpeg.
 *
 * ── WHAT WAS MEASURED, BEFORE ANY OF THIS WAS WRITTEN ───────────────────────
 *
 * Three readings of one column, from three callers, each right about its own
 * question and wrong as an answer to his (App\Services\Ugc\ClipFile carries the
 * full argument):
 *
 *   a row at `/uploads/ugc/<name>.mp4` with no file there
 *       derive()            "The stored clip is missing from the server at …"
 *                           — already correct, and pinned below so it stays so
 *       publishWarnings()   []          ← said nothing at all
 *       mediaState()        poster_only ← the clips screen badged it
 *                                         "Loops from full video", about a clip
 *                                         with nothing left to loop
 *
 *   a row at `/uploads/other/clip.mp4` (a path this shop will not serve)
 *       derive()            "There is no uploaded clip to cut from yet."
 *                           ← FALSE. The row has a clip.
 *       publishBlockers()   []          ← it could be published
 *       the storefront      a tile with NO data-ugcr-src at all: a dead poster
 *                           that can never open and can never play
 *
 * ── MUTATION NOTES, ALL RUN ─────────────────────────────────────────────────
 *
 * Put `'There is no uploaded clip to cut from yet.'` back in derive()'s null
 * branch and 'names the fault as a path this shop will not serve' is red.
 * Restore `file_path === ''` in publishBlockers() and 'refuses to publish a
 * clip whose path can never become a src' is red. Delete the `gone` clause in
 * publishWarnings() and 'warns on every admin screen that a published clip has
 * lost its file' is red. Delete either `file_state` branch from
 * ugc-library-screen's stateWords() and 'the clips screen badges a missing file
 * rather than describing a loop' is red.
 */
function mfClip(array $attributes = []): UgcVideo
{
    return UgcVideo::create(array_merge([
        'slug' => 'mf-'.uniqid(),
        'title' => 'A clip whose file walked off',
        'status' => 'draft',
        'rights_status' => 'granted',
        'file_path' => '/uploads/ugc/clip-20260928-abcdefghij.mp4',
        'poster_path' => '/uploads/ugc/poster-20260928-203448-klmnopqrst.jpg',
        'width' => 720, 'height' => 1280,
    ], $attributes));
}

it('tells the four states of a clip file apart', function () {
    /*
     * The one table the rest of this file turns on. `none` and `unservable`
     * used to be the same answer and `gone` used to be indistinguishable from
     * a healthy row on every screen but one.
     */
    expect(ClipFile::state(''))->toBe(ClipFile::NONE)
        ->and(ClipFile::state(null))->toBe(ClipFile::NONE)
        ->and(ClipFile::state('/uploads/other/clip.mp4'))->toBe(ClipFile::UNSERVABLE)
        ->and(ClipFile::state('/uploads/ugc/../../etc/passwd'))->toBe(ClipFile::UNSERVABLE)
        ->and(ClipFile::state('/uploads/ugc/clip-that-is-not-there.mp4'))->toBe(ClipFile::GONE);

    // And OK is a real read of a real file, not a fourth spelling of "probably".
    $dir = public_path('uploads/ugc');
    @mkdir($dir, 0755, true);
    $name = 'clip-'.uniqid().'.webm';
    file_put_contents($dir.'/'.$name, 'not really a video, but it is a file');

    try {
        expect(ClipFile::state('/uploads/ugc/'.$name))->toBe(ClipFile::OK);
    } finally {
        @unlink($dir.'/'.$name);
    }
});

it('names a vanished file as a vanished file, and never as an ffmpeg failure', function () {
    $clip = mfClip();

    $out = app(UgcTranscoder::class)->derive($clip, remakePoster: true, remakeTeaser: true);

    $notes = implode(' ', $out['notes']);

    /*
     * THE SENTENCE THE OWNER SHOULD NEVER SEE FOR THIS ROW. "ffmpeg could not
     * read a poster frame out of that clip" blames a program for a file that is
     * not there for it to read, and sends whoever reads it to check ffmpeg —
     * which on his server is a full Debian build that works perfectly.
     */
    expect(str_contains($notes, 'ffmpeg'))->toBeFalse(
        'a missing file must not be reported as an ffmpeg failure: '.$notes
    );

    expect(str_contains($notes, 'missing from this server'))->toBeTrue($notes)
        // The path is quoted back, because it is the thing he searches for.
        ->and(str_contains($notes, '/uploads/ugc/clip-20260928-abcdefghij.mp4'))->toBeTrue($notes);

    // And nothing was invented on the way past.
    expect($out['poster'])->toBeNull()->and($out['teaser'])->toBeNull();
});

it('names the fault as a path this shop will not serve, rather than as nothing uploaded', function () {
    $clip = mfClip(['file_path' => '/uploads/other/clip.mp4']);

    $notes = implode(' ', app(UgcTranscoder::class)->derive($clip, remakePoster: true)['notes']);

    /*
     * MEASURED BEFORE THE FIX: this row answered "There is no uploaded clip to
     * cut from yet." It has one. The next thing anybody does with that sentence
     * is re-upload a 64 MB file and watch the message not change.
     */
    expect(str_contains($notes, 'There is no uploaded clip'))->toBeFalse($notes)
        ->and(str_contains($notes, 'not one this shop can serve'))->toBeTrue($notes)
        ->and(str_contains($notes, '/uploads/other/clip.mp4'))->toBeTrue($notes);
});

it('refuses to publish a clip whose path can never become a src', function () {
    $clip = mfClip(['file_path' => '/uploads/other/clip.mp4']);

    /*
     * publishBlockers() read `file_path === ''`, so this row passed the gate.
     * It then rendered a tile with no `data-ugcr-src` at all — see the
     * storefront case below — which is word for word the "empty box that plays
     * nothing" the refusal is written about.
     */
    expect($clip->canPublish())->toBeFalse()
        ->and(implode(' ', $clip->publishBlockers()))->toContain('not one this shop can serve');

    // The row with NOTHING recorded keeps the sentence it has always had.
    $blank = mfClip(['file_path' => '']);

    expect($blank->canPublish())->toBeFalse()
        ->and($blank->publishBlockers())->toContain('No video file has been uploaded yet.');
});

it('warns on every admin screen that a published clip has lost its file', function () {
    $clip = mfClip(['status' => 'publish']);

    /*
     * A WARNING AND NOT A BLOCKER, deliberately. The row is correct and the
     * file is recoverable by re-uploading; and "not in this web root" is also
     * what a host whose CLI and FPM disagree about public_path() looks like —
     * CutUgcCovers' closing note has that trap in full. A shop that lost its
     * whole library to one process looking in the wrong directory would be a
     * worse failure than the one being fixed.
     */
    expect($clip->canPublish())->toBeTrue()
        ->and(implode(' ', $clip->publishWarnings()))->toContain('missing from this server');

    // A healthy row gains no warning from this — the whole of rule 1.
    $dir = public_path('uploads/ugc');
    @mkdir($dir, 0755, true);
    $name = 'clip-'.uniqid().'.webm';
    file_put_contents($dir.'/'.$name, 'a file that is really there');

    try {
        $ok = mfClip(['file_path' => '/uploads/ugc/'.$name]);

        expect(implode(' ', $ok->publishWarnings()))->not->toContain('missing from this server');
    } finally {
        @unlink($dir.'/'.$name);
    }
});

it('hands the clips screen the state as well as the sentence', function () {
    /*
     * The badge in a list of forty rows needs a token, not a paragraph. Pinned
     * as a pair: the payload key and the branch on the screen that reads it,
     * because either one alone is a half that draws nothing.
     */
    $screen = (string) file_get_contents(
        resource_path('views/admin/partials/ugc-library-screen.blade.php')
    );

    expect(str_contains($screen, "v.file_state === 'gone'"))->toBeTrue(
        'the clips screen never reads the file state'
    )->and(str_contains($screen, "v.file_state === 'unservable'"))->toBeTrue(
        'the clips screen never reads the unservable state'
    );

    $controller = (string) file_get_contents(
        app_path('Http/Controllers/Admin/UgcVideoController.php')
    );

    expect(str_contains($controller, "'file_state' => \$video->fileState(),"))->toBeTrue(
        'card() never sends the file state the screen branches on'
    );
});

it('the clips screen badges a missing file rather than describing a loop', function () {
    $screen = (string) file_get_contents(
        resource_path('views/admin/partials/ugc-library-screen.blade.php')
    );

    /*
     * ORDER IS THE ASSERTION. media_state is read from the COLUMNS, so it
     * cheerfully answers `poster_only` for a row whose file is gone and the
     * screen badged it "Loops from full video". The file's own state has to be
     * asked FIRST or the badge goes back to describing a loop that cannot
     * happen.
     */
    $gone = strpos($screen, "v.file_state === 'gone'");
    $ready = strpos($screen, "v.media_state === 'ready'");

    expect($gone)->not->toBeFalse()
        ->and($ready)->not->toBeFalse()
        ->and($gone < $ready)->toBeTrue(
            'the file state must be read before the derivative state, or a clip with no file still badges a loop'
        );

    expect(str_contains($screen, "['Video file is gone', 'hold']"))->toBeTrue(
        'the badge has no words of its own'
    );
});

it('never puts an empty src on the storefront for a row whose file is gone', function () {
    app(SettingsService::class)->setModule('shoppable_video', true);
    SettingsService::forgetMemo();
    Cache::flush();

    $section = UgcSection::query()->create([
        'handle' => 'mf-rail', 'title' => 'Shop the look', 'status' => 'publish',
    ]);

    // Its FILE is gone; its row and its poster are intact, which is the shape
    // the owner really has.
    $gone = mfClip(['status' => 'publish', 'slug' => 'mf-gone']);

    $section->videos()->syncWithoutDetaching([$gone->id => ['position' => 0]]);

    $html = Shortcodes::render('[kbb_videos section="mf-rail"]');

    /*
     * ── THE ONE THING A BROWSER MUST NEVER BE HANDED ───────────────────────
     *
     * `src=""` is not a blank source: a browser resolves the empty string
     * against the document and fetches THE PAGE ITSELF, then fails to decode
     * it. rail.blade.php guards every one of these behind `@if`, and this pins
     * that guard against the row most likely to trip it.
     */
    expect(str_contains($html, 'src=""'))->toBeFalse('an empty src reached the page')
        ->and(str_contains($html, 'data-ugcr-src=""'))->toBeFalse('an empty clip source reached the page')
        ->and(str_contains($html, 'data-ugcr-teaser-src=""'))->toBeFalse('an empty teaser source reached the page')
        ->and(str_contains($html, 'data-ugcr-poster=""'))->toBeFalse('an empty poster reached the page');

    /*
     * AND THE TILE IS STILL DRAWN. A file that is missing from the server is
     * not a reason to drop a published tile out of the rail mid-render — the
     * poster is there, the box is reserved from the stored dimensions, and the
     * rail's own `error` handler hands the playback slot back the moment the
     * source 404s. The admin is where this row gets named, not the shop.
     */
    expect(str_contains($html, 'data-ugcr-slug="mf-gone"'))->toBeTrue('the tile vanished from the rail');
});

it('says the same thing on the appearance screen as it does on the clips screen', function () {
    $playback = (string) file_get_contents(app_path('Services/Ugc/RailPlayback.php'));

    /*
     * The Motion panel was a FOURTH reading of this column when it was written.
     * It now asks the same decider, so a row cannot be "its file is missing"
     * there and "Loops from full video" one tab away.
     */
    expect(str_contains($playback, 'ClipFile::state($path) === ClipFile::OK'))->toBeTrue(
        'the Motion panel decides on its own again'
    );
});

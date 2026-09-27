<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Media;
use App\Models\UgcSection;
use App\Models\UgcVideo;
use App\Services\UgcClipIntake;
use App\Services\UgcMedia;
use App\Support\AdminCapabilities;
use App\Support\MediaBackfill;
use App\Support\MediaRegistrar;
use Tests\Support\MediaLibraryRoutes;

/**
 * "whenever we upload any media, it should go to Media also" — and one upload
 * control on the section page that does it.
 *
 * ── THE STATE THESE CASES WERE WRITTEN AGAINST ──────────────────────────────
 *
 * Exactly two places in the tree have ever written a `media` row:
 * Admin\MediaUploadController::record() and Support\MediaBackfill::run().
 * Services\UgcMedia — the ONLY writer of files under public/uploads/ugc/ —
 * wrote none, so every shoppable-video clip and teaser on the shop was
 * invisible to the Media Library, to its usage index and to its delete guard.
 *
 * (Posters were the exception, and it is worth pinning because it is
 * counter-intuitive: MediaBackfill's walk is RECURSIVE and uploads/ugc/ is
 * under public/uploads/, so a Rescan has always catalogued the .jpg posters and
 * silently skipped the .mp4 beside them. The extension table was the whole
 * difference.)
 *
 * ── REAL BYTES, NEVER UploadedFile::fake() ──────────────────────────────────
 *
 * Illuminate\Http\Testing\File::getMimeType() returns MimeType::from($name) —
 * the type implied by the FILENAME. A suite built on the fake cannot tell a
 * content check from a name check. UgcUploadSafetyTest and MediaUploadTest both
 * state this rule; these files have genuine bytes and, where it matters, lying
 * names.
 */

/* ───────────────────────────────────────────────────────────── the harness ── */

function mevFile(string $name, string $bytes): \Illuminate\Http\UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'mev');
    file_put_contents($path, $bytes);

    return new \Illuminate\Http\UploadedFile($path, $name, null, null, true);
}

/** A minimal but genuine ISO base media header: `ftyp`, then a real brand. */
function mevMp4(int $padding = 256): string
{
    return pack('N', 32).'ftyp'.'isom'.pack('N', 512).'isomiso2avc1mp41'.str_repeat("\x00", $padding);
}

/** A real PNG of known size, so a dimension read has something true to find. */
function mevPng(int $w = 360, int $h = 640): string
{
    $image = imagecreatetruecolor($w, $h);
    ob_start();
    imagepng($image);
    $bytes = (string) ob_get_clean();
    imagedestroy($image);

    return $bytes;
}

function mevAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create([
        'name' => 'Media Everywhere '.$role,
        'email' => 'mev-'.$role.'-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => $role,
    ]);
}

/** Write a file straight into the web root, the way FTP or a previous release did. */
function mevPut(string $relative, string $bytes): string
{
    $absolute = public_path($relative);

    @mkdir(dirname($absolute), 0755, true);
    file_put_contents($absolute, $bytes);

    return $relative;
}

/** Everything under public/uploads/ugc right now. */
function mevStored(): array
{
    $dir = public_path(UgcMedia::DIR);

    return is_dir($dir) ? array_values(array_diff(scandir($dir) ?: [], ['.', '..'])) : [];
}

function mevSectionsSource(): string
{
    return (string) file_get_contents(resource_path('views/admin/partials/ugc-sections-screen.blade.php'));
}

function mevLibrarySource(): string
{
    return (string) file_get_contents(resource_path('views/admin/partials/media-library-screen.blade.php'));
}

/**
 * The same source with the prose taken out.
 *
 * Every scan below that looks for a BEHAVIOUR reads this rather than the raw
 * file, because these partials explain the defects they fix in prose — often by
 * quoting the wrong code — and a scan of the raw text matches the explanation
 * and fails a screen that is correct. UgcRailR3Test paid for that twice.
 */
function mevCode(string $src): string
{
    $src = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $src);
    $src = (string) preg_replace('#/\*.*?\*/#s', '', $src);

    return (string) preg_replace('#^\s*//.*$#m', '', $src);
}

/**
 * One PHP file's CODE, with the prose taken out.
 *
 * The same rule mevCode() applies to the Blade partials, and for the same
 * reason: MediaRegistrar's docblocks explain what it does NOT do by naming the
 * thing — "the caller's finfo answer only ever corroborates" — and a scan of the
 * raw text matches that explanation and fails a class that is correct. Written
 * the loose way first, this case went red on its own comment.
 *
 * token_get_all() rather than a regexp, because a `#` or a `/*` inside a string
 * literal is not a comment and a regexp cannot tell.
 */
function mevPhpCode(string $path): string
{
    $out = '';

    foreach (token_get_all((string) file_get_contents($path)) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $out .= is_array($token) ? $token[1] : $token;
    }

    return $out;
}

/*
 * These services write into the REAL public web root — there is no
 * storage:link on this host, which is the whole reason they write there — so
 * every case cleans up after itself rather than asserting an empty directory,
 * which is only true until another test in the run has legitimately uploaded.
 */
afterEach(function () {
    foreach (mevStored() as $name) {
        if (str_starts_with($name, 'clip-') || str_starts_with($name, 'teaser-') || str_starts_with($name, 'poster-')) {
            @unlink(public_path(UgcMedia::DIR.'/'.$name));
        }
    }

    foreach (['uploads/mev-scan', 'uploads/mev-odd'] as $dir) {
        $absolute = public_path($dir);

        foreach (glob($absolute.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($absolute);
    }
});

/* ═══════════════════════════════════ A · every upload joins the library ══ */

it('records a shoppable-video clip in the media library, which nothing ever did', function () {
    /*
     * THE DEFECT, and it is the whole first half of this lane. UgcMedia::store()
     * moved a checked file into public/uploads/ugc/ and returned a path. It
     * wrote NO `media` row, so the clip existed on the shop, was served from
     * this origin, counted against the disk — and the Media Library, which is
     * the owner's one inventory of what this shop holds, did not know it was
     * there. His words: "whenever we upload any media, it should go to Media
     * also."
     *
     * MUTATION NOTE. Delete the `'media_id' => MediaRegistrar::record(...)` line
     * from UgcMedia::store() and this is red: no row for the stored path. RUN:
     * red — "Failed asserting that null is not null".
     */
    $result = app(UgcMedia::class)->store(mevFile('anua mist spray 2.mp4', mevMp4()), UgcMedia::KIND_CLIP);

    expect($result['ok'])->toBeTrue();

    $row = Media::query()->where('path', ltrim((string) $result['path'], '/'))->first();

    expect($row)->not->toBeNull()
        ->and($row->mime)->toBe('video/mp4')
        ->and($row->size)->toBe(strlen(mevMp4()))
        // The name he knows it by, kept for search only. The STORED name is
        // generated — the browser never chooses what lands in the web root.
        ->and($row->original_name)->toBe('anua mist spray 2.mp4')
        ->and($row->filename)->not->toBe('anua mist spray 2.mp4')
        // A video has no pixel dimensions this server can read without ffprobe,
        // and a fabricated 0 would be worse than a null.
        ->and($row->width)->toBeNull()
        ->and($row->height)->toBeNull();
});

it('records a poster adopted out of the library as its own row, without touching the original', function () {
    /*
     * UgcMedia::adopt() COPIES a library picture into uploads/ugc/ under a fresh
     * name rather than referencing it, so the clip's poster can be deleted with
     * the clip without taking the picture off every other page using it. That
     * copy is a second file in the web root, and it needs its own row for the
     * same reason the first one does — otherwise deleting the clip removes a
     * file nothing ever catalogued.
     *
     * AND THE ORIGINAL MUST SURVIVE, which is the half that would be a
     * regression: this asserts both rows exist afterwards.
     */
    $source = mevPut('uploads/mev-scan/cosrx-cover.png', mevPng(300, 500));
    MediaRegistrar::record($source, 'cosrx-cover.png');

    $result = app(UgcMedia::class)->adopt('/'.$source, UgcMedia::KIND_POSTER);

    expect($result['ok'])->toBeTrue();

    $copy = Media::query()->where('path', ltrim((string) $result['path'], '/'))->first();

    expect($copy)->not->toBeNull()
        ->and($copy->mime)->toBe('image/png')
        // A picture DOES have dimensions, and they are read off the header.
        ->and($copy->width)->toBe(300)
        ->and($copy->height)->toBe(500)
        // Named after what it was copied from, so the grid reads "cosrx-cover.png"
        // rather than "poster-20260927-...-x8.png".
        ->and($copy->original_name)->toBe('cosrx-cover.png')
        ->and(Media::query()->where('path', $source)->exists())->toBeTrue();
});

it('does not write a second row for a path it has already catalogued', function () {
    /*
     * IDEMPOTENCY IS WHAT MAKES THE REGISTRAR SAFE TO CALL FROM EVERYWHERE. The
     * same file is reachable from an upload, from a transcoder's output, from
     * the Rescan button and from a migration that — on a host where updates are
     * zips applied by hand — does sometimes get applied twice.
     *
     * AND THE EXISTING ROW IS RETURNED UNTOUCHED. `alt` is the operator's own
     * text; a second call has no better information about it than the first had,
     * and overwriting it would lose work every time somebody pressed Rescan.
     *
     * MUTATION NOTE. Remove the `$existing !== null` early return from
     * MediaRegistrar::record() and this is red twice over: two rows, and the alt
     * text gone. RUN: red — "Failed asserting that 2 matches expected 1".
     */
    $path = mevPut('uploads/mev-scan/twice.png', mevPng(40, 40));

    $first = MediaRegistrar::record($path, 'twice.png');
    $first->update(['alt' => 'the operator typed this']);

    $second = MediaRegistrar::record($path, 'a different name.png');

    expect(Media::query()->where('path', $path)->count())->toBe(1)
        ->and($second->id)->toBe($first->id)
        ->and($second->alt)->toBe('the operator typed this');
});

it('refuses to catalogue anything that is not media, or that is not under uploads', function () {
    /*
     * THE REGISTRAR TURNS ITS ARGUMENT INTO A public_path() CONCATENATION AND,
     * in forget(), INTO A DELETE. A column is only ever as trustworthy as
     * everything that has ever written to it, so the shape is an allowlist
     * rather than a denylist of tricks — the rule UgcPath::stored() states, one
     * directory wider.
     *
     * `uploads/` specifically, and not merely "inside the web root", because
     * that prefix is ALSO what Media::urlFor() reads to tell an admin upload
     * from an imported /wp-content/uploads/ path. A row stored outside it would
     * be served from the wrong root and 404.
     *
     * MUTATION NOTE. Drop the `str_starts_with($clean, 'uploads/')` test from
     * MediaRegistrar::normalise() and the wp-content case goes green — a row
     * whose URL is /wp-content/uploads/wp-content/... RUN: red as written,
     * green with the test removed.
     */
    mevPut('uploads/mev-scan/parked.zip', 'PK'.str_repeat("\x00", 40));
    mevPut('uploads/mev-scan/real.png', mevPng(10, 10));

    expect(MediaRegistrar::record('uploads/mev-scan/parked.zip'))->toBeNull()
        ->and(MediaRegistrar::normalise('uploads/mev-scan/../../etc/passwd'))->toBeNull()
        ->and(MediaRegistrar::normalise('/wp-content/uploads/2024/01/x.jpg'))->toBeNull()
        ->and(MediaRegistrar::normalise('https://evil.test/x.png'))->toBeNull()
        ->and(MediaRegistrar::normalise('//evil.test/x.png'))->toBeNull()
        ->and(MediaRegistrar::normalise('uploads\\mev-scan\\x.png'))->toBeNull()
        // And the shape it does accept, normalised to the one spelling `media`.path
        // and Media::urlFor() both use.
        ->and(MediaRegistrar::normalise('/uploads/mev-scan/real.png'))->toBe('uploads/mev-scan/real.png');

    // A row for a file that is not on disk is a broken thumbnail forever, which
    // is the very thing forget() exists to prevent. Refused, not recorded hopefully.
    expect(MediaRegistrar::record('uploads/mev-scan/never-written.png'))->toBeNull();

    /*
     * AND THE REGISTRAR ITSELF CANNOT BECOME THE NEW SINGLE POINT OF FAILURE.
     *
     * Every upload in the back office now goes through this one class, so
     * anything that can throw inside it is a 500 on all of them at once — the
     * exact shape of the finfo defect two cases below, multiplied by every
     * upload path instead of one. It opens no type reader at all: the mime comes
     * from the extension the writer already earned from a content read, and the
     * caller's finfo answer is only ever allowed to CORROBORATE it. Nothing here
     * constructs a finfo, so nothing here can fail to.
     */
    expect(mevPhpCode(app_path('Support/MediaRegistrar.php')))->not->toContain('finfo');
});

it('takes the library row away with the file when a clip is replaced or deleted', function () {
    /*
     * THE DECISION THIS PINS, and it is a decision rather than a detail.
     *
     * UgcMedia::forget() unlinks the file — on a delete, and on every replace,
     * because the clip's old file goes when a new one lands. From this lane
     * every one of those files is also a `media` row, so left alone the library
     * would fill with rows pointing at nothing: the grid renders at a row's URL,
     * so each one is a permanently broken tile with NO screen anywhere that can
     * clear it, growing by one every time he replaces a clip.
     *
     * The row therefore follows the file. Not through the library's own delete
     * guard, which refuses while something still points at a file: here nothing
     * is being chosen, the file is going regardless, and keeping the row would
     * not save it — only hide that it had gone.
     *
     * MUTATION NOTE. Remove the `MediaRegistrar::forget($safe);` line from
     * UgcMedia::forget() and this is red: the row survives its file. RUN: red —
     * "Failed asserting that true is false".
     */
    $stored = app(UgcMedia::class)->store(mevFile('clip.mp4', mevMp4()), UgcMedia::KIND_CLIP);
    $path = ltrim((string) $stored['path'], '/');

    expect(Media::query()->where('path', $path)->exists())->toBeTrue()
        ->and(is_file(public_path($path)))->toBeTrue();

    app(UgcMedia::class)->forget((string) $stored['path']);

    expect(is_file(public_path($path)))->toBeFalse()
        ->and(Media::query()->where('path', $path)->exists())->toBeFalse();
});

it('refuses honestly when this server cannot read a file type at all, instead of throwing', function () {
    /*
     * THE DEFECT, reported by the lane that fixed the upload 500 and left here
     * because this file belongs to this lane.
     *
     *     $detected = strtolower((string) (@(new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: ''));
     *
     * `@` suppresses PHP WARNINGS. It does not suppress EXCEPTIONS, and in PHP 8
     * this line throws two different things: finfo::__construct raises an
     * exception when the magic database cannot be loaded, and on a host built
     * without ext-fileinfo it is `Error: Class "finfo" not found` — which is an
     * Error and not an Exception, so a `catch (Exception)` would not have caught
     * it either. Either one escaped store(), whose entire contract is to RETURN
     * ['ok' => false, 'message' => ...], and became a 500 on EVERY upload of
     * every kind, image and video alike.
     *
     * DRIVEN FOR REAL, not mocked: finfo reads the MAGIC environment variable
     * for the path to its database, so pointing it at a file that is not there
     * reproduces the production shape exactly — the constructor throws, from
     * inside store(), on a file that is otherwise perfectly good.
     *
     * AND IT IS A REFUSAL, NOT A FALLBACK. The tempting repair is to trust
     * signature() alone, which lives in this same class and would keep uploads
     * working on such a box. That is the wrong repair: "both readings must
     * agree" is the property that makes a disguise satisfy two independent
     * readers, and a box with no finfo would silently become a box with one.
     * The last assertion pins that the file is NOT written.
     *
     * MUTATION NOTE. Put the one-liner back in UgcMedia::check() and this is
     * red — not a failed assertion but an uncaught
     * "finfo::__construct(...): Failed to open stream". RUN: red, as an Error.
     */
    $before = mevStored();
    $previous = getenv('MAGIC');

    putenv('MAGIC=/nonexistent/lane-m1-magic.mgc');

    try {
        $result = app(UgcMedia::class)->store(mevFile('good.mp4', mevMp4()), UgcMedia::KIND_CLIP);
    } finally {
        $previous === false ? putenv('MAGIC') : putenv('MAGIC='.$previous);
    }

    expect($result['ok'])->toBeFalse()
        // The sentence names the extension to install, because "this server
        // could not read the file" is a refusal nobody can act on.
        ->and($result['message'])->toContain('fileinfo')
        ->and($result['message'])->toContain('Nothing was saved')
        // NOT "that file is of a type this server could not read": the file was
        // fine and the reader was missing, and an operator told the first thing
        // re-encodes a good video forever.
        ->and($result['message'])->not->toContain('has to be MP4 or WebM')
        // Nothing reached the disk. A single reader is not an acceptable
        // fallback, so nothing is accepted at all.
        ->and(mevStored())->toBe($before);
});

it('answers the section upload with a refusal, not a 500, when the type reader is gone', function () {
    /*
     * The same defect through the endpoint this lane added, because that is
     * where the owner meets it. A 500 on Core Updates was three hours; a 500 on
     * an upload is an owner who tries the same file four times.
     *
     * MUTATION NOTE. Put the one-liner back in UgcMedia::check() and this is red
     * with a 500. RUN: red.
     */
    test()->actingAs(mevAdmin(), 'admin');

    $section = UgcSection::create(['title' => 'No finfo', 'handle' => 'mev-nofinfo', 'status' => 'draft']);

    $previous = getenv('MAGIC');
    putenv('MAGIC=/nonexistent/lane-m1-magic.mgc');

    try {
        test()->post('/admin-api/ugc-sections/'.$section->id.'/upload', ['file' => mevFile('good.mp4', mevMp4())])
            ->assertStatus(422)
            ->assertJsonPath('ok', false);
    } finally {
        $previous === false ? putenv('MAGIC') : putenv('MAGIC='.$previous);
    }

    expect(UgcVideo::query()->count())->toBe(0)
        ->and($section->videos()->count())->toBe(0);
});

it('takes the usage index with the row, because a bulk delete fires no events', function () {
    /*
     * THE SECOND HALF OF forget(), AND IT IS NOT COSMETIC.
     *
     * `media_usages` is the index behind the grid's badges and its "Used by"
     * filter, and MediaUsageWriter keeps it in step through Eloquent MODEL
     * EVENTS. A bulk `Media::query()->where(...)->delete()` fires none of them —
     * that class's own header names it as one of two known gaps — so the obvious
     * one-liner would leave index rows pointing at a media id that no longer
     * exists. The grid then over-reports usage for whatever id the database
     * reuses next, and the "not used anywhere" filter hides a file that is.
     *
     * So forget() loads the rows and deletes each one, which fires `deleted` and
     * takes MediaUsageWriter::forgetMedia() with it.
     *
     * MUTATION NOTE. Replace the loop in MediaRegistrar::forget() with
     *     $gone = Media::query()->where('path', $path)->delete();
     * and this is red: the media row goes and its usage row survives. RUN: red —
     * and note the earlier case on the clip did NOT catch this, because a
     * shoppable-video file matches no product, brand or category and therefore
     * has no usage rows to leave behind. That is why this one uses a picture on
     * a product.
     */
    $path = mevPut('uploads/mev-scan/on-a-product.png', mevPng(50, 50));
    $row = MediaRegistrar::record($path, 'on-a-product.png');

    \App\Models\Product::create([
        'name' => 'Media Everywhere Product',
        'slug' => 'mev-product-'.uniqid(),
        'price' => 1000,
        'image' => '/'.$path,
    ]);

    $usages = fn () => \App\Models\MediaUsageRecord::query()->where('media_id', $row->id)->count();

    expect($usages())->toBe(1);

    /*
     * AND THE OTHER ORDER, which is the one that matters for a registrar: the
     * URL was already on the product for months and the FILE has only just been
     * catalogued. MediaUsageWriter hangs that half off Media's `created` event,
     * so record() has to go through Eloquent rather than a query-builder insert
     * — a bulk insert fires nothing and the row would read as "unused", which is
     * the dangerous direction: it invites the operator to delete an image that
     * is on a live product page.
     *
     * MUTATION NOTE. Make MediaRegistrar::record() write through
     * `Media::query()->insert([...])` instead of `Media::create([...])` and this
     * second half is red with 0 usages. RUN: red.
     */
    $later = mevPut('uploads/mev-scan/already-referenced.png', mevPng(50, 50));

    \App\Models\Product::create([
        'name' => 'Referenced Before Catalogued',
        'slug' => 'mev-referenced-'.uniqid(),
        'price' => 1000,
        'image' => '/'.$later,
    ]);

    $lateRow = MediaRegistrar::record($later, 'already-referenced.png');

    expect(\App\Models\MediaUsageRecord::query()->where('media_id', $lateRow->id)->count())->toBe(1);

    expect(MediaRegistrar::forget($path))->toBe(1)
        ->and(\App\Models\Media::query()->whereKey($row->id)->exists())->toBeFalse()
        ->and($usages())->toBe(0);
});

it('refuses to forget a row outside the one directory a clip may live in', function () {
    /*
     * forget() takes a DELETE. UgcPath::stored() bounds the caller's path to one
     * segment of uploads/ugc/ before this is reached, and normalise() is the
     * second lock: a product photograph must not be removable through a clip's
     * delete however the column that named it got written.
     */
    $path = mevPut('uploads/mev-scan/product.png', mevPng(20, 20));
    MediaRegistrar::record($path, 'product.png');

    // Through the clip's own door: refused by UgcPath::stored() before this class
    // is reached at all.
    app(UgcMedia::class)->forget('/'.$path);

    expect(Media::query()->where('path', $path)->exists())->toBeTrue()
        ->and(is_file(public_path($path)))->toBeTrue()
        // And directly, on a shape the registrar itself must refuse.
        ->and(MediaRegistrar::forget('../../../etc/passwd'))->toBe(0)
        ->and(MediaRegistrar::forget('wp-content/uploads/2024/01/x.jpg'))->toBe(0);
});

it('catalogues the cover and the teaser ffmpeg cuts, not only the file that was uploaded', function () {
    /*
     * THE THIRD AND FOURTH FILES, WHICH NOBODY UPLOADED.
     *
     * On a box with ffmpeg, one clip upload writes THREE files into the web
     * root: the clip, a poster cut from its first frame and a 2.5-second teaser.
     * Only the first arrived through UgcMedia, so registering there alone would
     * leave two thirds of a shoppable video outside the library — and worse,
     * UgcMedia::forget() removes all three, so the poster and teaser would be
     * deleted files with no rows and the clip a row with no file, in whichever
     * direction the bookkeeping was missing.
     *
     * A STUB BINARY, the way UgcTranscoderTest drives the same plumbing: this
     * machine has no encoder, and the half of this module that only runs when
     * one exists is exactly the half being asserted. KBB_FFMPEG is the same
     * env var a live server would use for an encoder in an unusual place.
     *
     * MUTATION NOTE. Remove either MediaRegistrar::record() call from
     * UgcTranscoder::derive() and this is red on that file. RUN: red on each.
     */
    $stub = tempnam(sys_get_temp_dir(), 'mevff');
    // `for out; do :; done` leaves $out holding the LAST positional argument,
    // which is where both commands put their destination.
    file_put_contents($stub, "#!/bin/sh\nfor out; do :; done\ncase \"\$out\" in\n  *.jpg) printf 'fake-jpeg-bytes' > \"\$out\" ;;\n  *) printf 'x' > \"\$out\" ;;\nesac\nexit 0\n");
    chmod($stub, 0755);
    putenv('KBB_FFMPEG='.$stub);

    try {
        $clip = mevPut(UgcMedia::DIR.'/clip-mev-derive.mp4', mevMp4());

        $derived = app(\App\Services\UgcTranscoder::class)
            ->derive(new UgcVideo(['file_path' => '/'.$clip]));

        expect($derived['poster'])->not->toBeNull()
            ->and($derived['teaser'])->not->toBeNull();

        foreach ([$derived['poster'] => 'image/jpeg', $derived['teaser'] => 'video/mp4'] as $made => $mime) {
            $row = Media::query()->where('path', ltrim((string) $made, '/'))->first();

            expect($row)->not->toBeNull()
                ->and($row->mime)->toBe($mime)
                // Nobody ever called these anything, so the tile falls back to the
                // stored filename rather than being handed a name that pretends
                // to be the operator's.
                ->and($row->original_name)->toBeNull();
        }
    } finally {
        putenv('KBB_FFMPEG');
        @unlink($stub);
    }
});

/* ═══════════════════════════════════════════════════ A · the backfill ════ */

it('catalogues the clips and teasers already on disk, and does it only once', function () {
    /*
     * THE BACKFILL, AND THE COUNTER-INTUITIVE HALF OF IT.
     *
     * MediaBackfill walks public/uploads RECURSIVELY and uploads/ugc/ is under
     * it, so the POSTERS of every clip have been catalogued by every Rescan
     * since that class was written — while the .mp4 beside them was skipped,
     * because its extension table was images-only. One stale list, and it was
     * the list nobody was looking at. It now lives in MediaRegistrar::EXT_MIME
     * and carries mp4 and webm.
     *
     * IDEMPOTENCY IS PROVED BY RUNNING IT TWICE, not asserted: the second run
     * must add zero and the row count must not move. That is what makes it safe
     * on a button and safe when a package is applied twice.
     *
     * MUTATION NOTE. Remove 'mp4' and 'webm' from MediaRegistrar::EXT_MIME and
     * this is red — the clip and the teaser are not catalogued and the count is
     * 1 rather than 3. RUN: red.
     */
    mevPut(UgcMedia::DIR.'/clip-mev-backfill.mp4', mevMp4());
    mevPut(UgcMedia::DIR.'/teaser-mev-backfill.mp4', mevMp4(64));
    mevPut(UgcMedia::DIR.'/poster-mev-backfill.png', mevPng(90, 160));

    $added = MediaBackfill::run();
    $mine = fn () => Media::query()->where('path', 'like', 'uploads/ugc/%mev-backfill%')->count();

    expect($added)->toBeGreaterThanOrEqual(3)
        ->and($mine())->toBe(3);

    $clip = Media::query()->where('path', UgcMedia::DIR.'/clip-mev-backfill.mp4')->first();

    expect($clip->mime)->toBe('video/mp4')
        ->and($clip->size)->toBe(strlen(mevMp4()))
        ->and($clip->width)->toBeNull();

    // Twice. The second pass must add nothing at all.
    expect(MediaBackfill::run())->toBe(0)
        ->and($mine())->toBe(3);
});

it('catalogues nothing that is not media, however it got into the uploads tree', function () {
    mevPut('uploads/mev-scan/notes.txt', 'hello');
    mevPut('uploads/mev-scan/archive.zip', 'PK'.str_repeat("\x00", 40));

    MediaBackfill::run();

    expect(Media::query()->where('path', 'like', 'uploads/mev-scan/%')->pluck('path')->all())->toBe([]);
});

/* ═══════════════════════════ B · the picker's contract does not move ═════ */

it('keeps video out of the shared picker, which sends no new parameter', function () {
    /*
     * RULE 1, AND THE ONE WAY THIS LANE COULD HAVE BROKEN THE WHOLE CONSOLE.
     *
     * GET /admin-api/media has two callers: the Media Library screen, and
     * window.kbbPickMedia — the shared picker that EVERY image field in this
     * console opens. The product gallery, brand logos, category images, the SEO
     * share image, a clip's own poster. Offering a 40 MB .mp4 as a brand logo is
     * a regression in exactly the shape rule 1 forbids, and the picker sends no
     * new parameter, so the DEFAULT has to be what it was.
     *
     * AND THE DEFAULT EXCLUDES VIDEO RATHER THAN REQUIRING AN IMAGE. That
     * distinction is the whole safety of it: a positive `mime LIKE 'image/%'`
     * would also drop every row whose mime is NULL or an odd spelling — the
     * WooCommerce import wrote plenty of both — and those rows are in the picker
     * today. The third row below is that case, and it is why this assertion is
     * not simply "only images come back".
     *
     * MUTATION NOTE. Change the default branch in
     * MediaLibraryApiController::filtered() to
     *     $query->where('media.mime', 'like', 'image/%');
     * and this is red on the NULL-mime row, which silently vanishes from every
     * picker in the console. RUN: red — the odd row is missing.
     */
    MediaLibraryRoutes::wire(app());
    test()->actingAs(mevAdmin(), 'admin');

    $picture = MediaRegistrar::record(mevPut('uploads/mev-scan/pic.png', mevPng(30, 30)), 'pic.png');
    $clip = MediaRegistrar::record(mevPut(UgcMedia::DIR.'/clip-mev-filter.mp4', mevMp4()), 'clip.mp4');

    // The shape the WooCommerce import leaves behind: a row with no mime at all.
    $odd = Media::create([
        'filename' => 'imported.jpg',
        'path' => 'wp-content/uploads/2019/07/imported.jpg',
        'mime' => null,
        'alt' => '',
    ]);

    $ids = fn (string $query) => collect(test()->getJson('/admin-api/media'.$query)->assertOk()->json('items'))
        ->pluck('id')->all();

    // What the picker asks for, unchanged.
    expect($ids(''))->toContain($picture->id)
        ->and($ids(''))->toContain($odd->id)
        ->and($ids(''))->not->toContain($clip->id);

    // What the Media Library asks for, which is the owner's request.
    expect($ids('?kind=all'))->toContain($clip->id)
        ->and($ids('?kind=all'))->toContain($picture->id);

    // And the narrowing the "Show" control offers.
    expect($ids('?kind=video'))->toBe([$clip->id]);

    // A select stores one of its own options or the default — rule 5, applied to
    // a query parameter. Anything unrecognised is the default, not a crash and
    // not "everything".
    expect($ids('?kind=wharrgarbl'))->not->toContain($clip->id);
});

it('costs the same number of queries whether the library holds 1 row or 20', function () {
    /*
     * RULE 4, MEASURED RATHER THAN ASSERTED. The library now holds every video
     * as well as every picture, so it grows faster than it did — and the picker
     * is opened from every image field in the console. A per-row query here
     * would turn a shop with a few hundred files into a screen nobody opens.
     *
     * A SLOPE, NOT A TOTAL. The number itself depends on how many kinds of owner
     * the badge lookup finds, which is not what this is measuring; what matters
     * is that the count does not RISE with the number of rows. Measured at 1, 2,
     * 5, 10 and 20 and compared against the first, so the assertion says
     * something about the shape of the query and not about today's constant.
     *
     * DB::enableQueryLog(), not DB::listen(): a listener registered per case
     * survives the case and every later one counts its predecessors' queries.
     * UgcRailSlopeTest records that at length.
     *
     * MUTATION NOTE. Add a `$media->url()` per item somewhere that reads
     * Setting::map() uncached, or replace the one usageFor() call with a
     * per-tile MediaUsage::verify(), and the slope rises immediately. RUN: with
     * usageFor() moved inside tile(), the counts were 4/5/8/13/23 and this was
     * red on the second measurement.
     */
    MediaLibraryRoutes::wire(app());
    test()->actingAs(mevAdmin(), 'admin');

    $made = 0;
    $costs = [];

    foreach ([1, 2, 5, 10, 20] as $want) {
        while ($made < $want) {
            $made++;
            MediaRegistrar::record(
                mevPut(UgcMedia::DIR.'/clip-mev-slope-'.$made.'.mp4', mevMp4()),
                'slope '.$made.'.mp4'
            );
        }

        \App\Services\SettingsService::forgetMemo();
        app()->forgetScopedInstances();

        \Illuminate\Support\Facades\DB::flushQueryLog();
        \Illuminate\Support\Facades\DB::enableQueryLog();

        test()->getJson('/admin-api/media?kind=video')->assertOk();

        $costs[$want] = count(\Illuminate\Support\Facades\DB::getRawQueryLog());

        \Illuminate\Support\Facades\DB::disableQueryLog();
        \Illuminate\Support\Facades\DB::flushQueryLog();
    }

    /*
     * FLAT, AND THE FIRST MEASUREMENT IS EXCLUDED FROM THE COMPARISON RATHER
     * THAN QUIETLY TOLERATED.
     *
     * Measured: {1:5, 2:4, 5:4, 10:4, 20:4}. The first request in the process
     * costs ONE MORE than every later one, and it is not the grid — it is the
     * once-per-process warm-up the authenticated request does on its way in
     * (the admin user lookup, which is then on the instance). Averaging it in
     * would have hidden a genuine slope of one; asserting on it would have made
     * this case depend on whatever ran before it in the suite.
     *
     * So the SLOPE is 2..20 compared with each other — those four must be
     * identical, whatever the constant is — and the warm-up is bounded on its
     * own line, which is what stops "the first one is special" from becoming a
     * place to hide a rising cost.
     */
    $steady = [$costs[2], $costs[5], $costs[10], $costs[20]];

    expect(array_unique($steady))->toHaveCount(
        1,
        'the media grid is an N+1: '.json_encode($costs)
    );

    expect($costs[1] - $steady[0])->toBeLessThanOrEqual(
        1,
        'the first request costs more than one extra query: '.json_encode($costs)
    );

    foreach (glob(public_path(UgcMedia::DIR).'/clip-mev-slope-*.mp4') ?: [] as $file) {
        @unlink($file);
    }
});

it('says on the tile whether a row is a video, so the grid can stop drawing <img> at one', function () {
    MediaLibraryRoutes::wire(app());
    test()->actingAs(mevAdmin(), 'admin');

    $clip = MediaRegistrar::record(mevPut(UgcMedia::DIR.'/clip-mev-flag.mp4', mevMp4()), 'clip.mp4');
    $picture = MediaRegistrar::record(mevPut('uploads/mev-scan/flag.png', mevPng(30, 30)), 'flag.png');

    $items = collect(test()->getJson('/admin-api/media?kind=all')->assertOk()->json('items'))->keyBy('id');

    expect($items[$clip->id]['is_video'])->toBeTrue()
        ->and($items[$picture->id]['is_video'])->toBeFalse();
});

it('refuses to delete a shoppable-video file from the library, and force does not pass', function () {
    /*
     * THE DEFECT, AND IT WAS ALREADY REACHABLE BEFORE THIS LANE.
     *
     * MediaUsage walks products, brands and categories — and nothing else. A file
     * under uploads/ugc/ is named by `ugc_videos`.file_path / teaser_path /
     * poster_path, which that walk has never looked at, so verify() answers
     * "nothing points at this", the detail panel prints "safe to delete", and
     * destroy() unlinks the file. The storefront then serves <video src> at a
     * 404 for a clip the owner had published. Posters have been catalogued by
     * every Rescan since MediaBackfill was written, so this hole has been open
     * on them the whole time; putting the clips in the library only widens it.
     *
     * NOT OVERRIDABLE, unlike every other refusal on this endpoint. Forcing it
     * produces a state no screen can repair — the clip row survives with a path
     * to nothing — and the clip's own Delete removes the row and all three files
     * together, which is the operation he actually wants. The refusal says so.
     *
     * MUTATION NOTE. Remove the shoppableVideoUsing() guard from
     * MediaLibraryApiController::destroy() and this is red both ways: 200
     * instead of 409, and the file gone from disk. RUN: red.
     */
    MediaLibraryRoutes::wire(app());
    test()->actingAs(mevAdmin(), 'admin');

    $path = mevPut(UgcMedia::DIR.'/clip-mev-guard.mp4', mevMp4());
    $row = MediaRegistrar::record($path, 'guard.mp4');

    UgcVideo::create([
        'slug' => 'mev-guarded',
        'title' => 'The one on the homepage',
        'status' => 'publish',
        'rights_status' => 'granted',
        'file_path' => '/'.$path,
    ]);

    test()->deleteJson('/admin-api/media/'.$row->id)
        ->assertStatus(409)
        ->assertJsonPath('ok', false)
        ->assertJsonFragment(['used' => true]);

    test()->deleteJson('/admin-api/media/'.$row->id.'?force=1')
        ->assertStatus(409);

    expect(is_file(public_path($path)))->toBeTrue()
        ->and(Media::query()->whereKey($row->id)->exists())->toBeTrue()
        // And the refusal names the clip, so the owner knows where to go.
        ->and(test()->deleteJson('/admin-api/media/'.$row->id)->json('message'))
            ->toContain('The one on the homepage');
});

it('still deletes an ordinary picture nothing points at, exactly as it did', function () {
    /*
     * The other half of the guard above, and the one that proves it is narrow:
     * a file OUTSIDE uploads/ugc/ never costs a query and never changes
     * behaviour. Rule 1 — a lane fixes the thing it was given and leaves the
     * rest byte-identical.
     */
    MediaLibraryRoutes::wire(app());
    test()->actingAs(mevAdmin(), 'admin');

    $path = mevPut('uploads/mev-scan/lonely.png', mevPng(12, 12));
    $row = MediaRegistrar::record($path, 'lonely.png');

    test()->deleteJson('/admin-api/media/'.$row->id)
        ->assertOk()
        ->assertJsonPath('file_removed', true);

    expect(Media::query()->whereKey($row->id)->exists())->toBeFalse()
        ->and(is_file(public_path($path)))->toBeFalse();
});

it('puts no media row anywhere on the unauthenticated /api surface', function () {
    /*
     * `/api/*` is unauthenticated on this shop and a `media` row carries the
     * whole inventory of what is on the server, including paths under
     * uploads/ugc/ that nothing links to yet. Nothing this lane added goes near
     * it, and this is the assertion that keeps it that way: the media endpoints
     * live only under admin-api, behind auth:admin and the capability map.
     */
    $public = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_starts_with($r->uri(), 'api/'))
        ->filter(fn ($r) => str_contains($r->uri(), 'media'))
        ->map(fn ($r) => $r->uri())
        ->values()
        ->all();

    expect($public)->toBe([]);
});

/* ══════════════════════ C · upload a new video from the section page ════ */

it('creates a clip from one upload, in this section, in the Clips tab and in Media', function () {
    /*
     * REQUIREMENT ONE, IN THE OWNER'S OWN WORDS: "I want also the upload new
     * video function, instead of going to clips tab specially. also that video
     * will auto add to clips page + Media too."
     *
     * THREE PLACES, ASSERTED SEPARATELY, because two out of three is the failure
     * this is written to catch: a clip that lands in the Clips tab and not in
     * the section he was looking at is the shape the ordering constraint pushes
     * you into, and it is indistinguishable from success from the button.
     *
     * THE ORDERING CONSTRAINT IS THE WHOLE REASON THIS ENDPOINT EXISTS. Every
     * other upload in this module is POST /ugc-videos/{id}/media, so the row has
     * to exist before a file can attach to it — and here the row IS what the
     * upload creates.
     *
     * MUTATION NOTE. Remove the `$section->videos()->attach(...)` line from
     * UgcSectionController::upload() and this is red on the section assertion
     * while the other two stay green — which is exactly the half-success it is
     * here to catch. RUN: red on one of three.
     */
    test()->actingAs(mevAdmin(), 'admin');

    $section = UgcSection::create(['title' => 'Homepage rail', 'handle' => 'mev-homepage', 'status' => 'draft']);

    $body = test()->post('/admin-api/ugc-sections/'.$section->id.'/upload', [
        'file' => mevFile('anua mist spray 2.mp4', mevMp4()),
    ])->assertStatus(201)->json();

    expect($body['ok'])->toBeTrue();

    $video = UgcVideo::query()->findOrFail($body['video']['id']);

    // 1. in this section, and last in its order.
    expect($section->videos()->pluck('ugc_videos.id')->all())->toBe([$video->id]);

    // 2. in the Clips tab, which is the whole library.
    expect(UgcVideo::query()->whereKey($video->id)->exists())->toBeTrue()
        ->and((string) $video->file_path)->toStartWith('/'.UgcMedia::DIR.'/');

    // 3. in the Media Library.
    expect(Media::query()->where('path', ltrim((string) $video->file_path, '/'))->exists())->toBeTrue();
});

it('lands the new clip last, without disturbing an order the operator saved', function () {
    test()->actingAs(mevAdmin(), 'admin');

    $section = UgcSection::create(['title' => 'Ordered rail', 'handle' => 'mev-ordered', 'status' => 'draft']);

    $first = UgcVideo::create(['slug' => 'mev-a', 'title' => 'A', 'status' => 'draft', 'rights_status' => 'pending']);
    $second = UgcVideo::create(['slug' => 'mev-b', 'title' => 'B', 'status' => 'draft', 'rights_status' => 'pending']);

    $section->videos()->sync([$first->id => ['position' => 0], $second->id => ['position' => 1]]);

    $id = test()->post('/admin-api/ugc-sections/'.$section->id.'/upload', [
        'file' => mevFile('third.mp4', mevMp4()),
    ])->assertStatus(201)->json('video.id');

    expect($section->videos()->pluck('ugc_videos.id')->all())->toBe([$first->id, $second->id, $id]);
});

it('titles the clip from the filename and says it is a draft', function () {
    /*
     * A TITLE THAT DOES NOT PRETEND TO BE HIS. A clip must have one — it is
     * required on every write path and it is what the slug is built from — so
     * one is derived from the name of the file he dropped and nothing is
     * invented. "Untitled clip 4" would tell him nothing; the filename tells him
     * which file it is.
     *
     * AND IT IS A DRAFT, WITH THE REASONS. UgcVideo::publishBlockers() decides
     * what stops a clip going live, and the endpoint returns ITS answer rather
     * than the screen guessing at the rule — so the panel cannot drift from the
     * gate. On a box with no ffmpeg there is no cover, which is one of them.
     *
     * MUTATION NOTE. Make UgcClipIntake::create() write 'status' => 'publish'
     * and this is red on the status assertion. RUN: red.
     */
    test()->actingAs(mevAdmin(), 'admin');

    $section = UgcSection::create(['title' => 'Draft rail', 'handle' => 'mev-draft', 'status' => 'draft']);

    $response = test()->post('/admin-api/ugc-sections/'.$section->id.'/upload', [
        'file' => mevFile('Anua Mist Spray 2.mp4', mevMp4()),
    ])->assertStatus(201);

    $body = $response->json('video');

    /*
     * AND THE SERVER'S OWN SENTENCE ABOUT WHAT IT COULD NOT CUT.
     *
     * UgcTranscoder::available() answers false for a host that cannot start a
     * program at all, not merely one with no ffmpeg binary — so on a shared plan
     * with proc_open disabled, a clip created here will never get a cover by
     * itself. derive() says so in a note, the endpoint carries it, and the panel
     * prints it beside the blockers rather than only toasting it: a toast is
     * gone in four seconds and this is the one thing standing between the clip
     * and the shop.
     *
     * Asserted only when there is no transcoder, which is the state of this
     * machine and of the shared plan this shop runs on; on a box WITH ffmpeg
     * there is correctly nothing to say and the cover is simply there.
     *
     * MUTATION NOTE. Drop `'notes' => $made['notes'] ?? []` from the endpoint's
     * response and this is red. RUN: red.
     */
    if (! app(\App\Services\UgcTranscoder::class)->available()) {
        expect(implode(' ', (array) $response->json('notes')))->toContain('ffmpeg');
    }

    expect($body['title'])->toBe('Anua Mist Spray 2')
        ->and($body['status'])->toBe('draft')
        ->and($body['rights_status'])->toBe('pending')
        /*
         * IT IS PUBLISHABLE STRAIGHT OFF AN UPLOAD NOW, and that is the change
         * the owner asked for: "the video can be published without credits and
         * poster cover, but warnings should remains there." Permission pending
         * and a missing cover are both warnings; the only blocker left is
         * having no video at all, and this row has one.
         *
         * SO THE ASSERTION MOVED RATHER THAN BEING DELETED. What mattered here
         * was never the refusal — it was that a fresh upload is not quietly
         * perfect and the screen is told what is still outstanding. That is
         * exactly what publishWarnings() carries now, so this checks there.
         */
        ->and($body['blockers'])->toBe([])
        ->and($body['warnings'])->not->toBe([])
        ->and(implode(' ', $body['warnings']))->toContain('permission');
});

it('derives a usable title from any filename, and never an empty one', function () {
    /*
     * The transform is the plainest one that reads as a sentence, and NOT
     * per-word title casing: `COSRX snail.mp4` must not become `Cosrx Snail`,
     * because the shop prints these and mangling a brand name is worse than
     * leaving the operator's own capitals alone.
     *
     * The empty case is not decorative: a file called `.mp4` produces an empty
     * title, an empty slug, and a unique-index violation the SECOND time it
     * happens — a 500 on an upload that worked yesterday.
     */
    expect(UgcClipIntake::titleFrom('anua_mist-spray.2.mp4'))->toBe('Anua mist spray 2')
        ->and(UgcClipIntake::titleFrom('COSRX snail.mp4'))->toBe('COSRX snail')
        ->and(UgcClipIntake::titleFrom('.mp4'))->toBe('New clip')
        ->and(UgcClipIntake::titleFrom('   '))->toBe('New clip')
        // strip_tags, because a filename is browser-supplied text and this
        // becomes a column the console and the shop both print.
        ->and(UgcClipIntake::titleFrom('<b>bold.mp4'))->toBe('Bold')
        // And basename(), because a browser is free to send a path.
        ->and(UgcClipIntake::titleFrom('../../etc/passwd.mp4'))->toBe('Passwd')
        // A dot that is part of the name rather than an extension stays.
        ->and(UgcClipIntake::titleFrom('spf 50 review.mp4'))->toBe('Spf 50 review');
});

it('gives two files with the same name two different slugs', function () {
    test()->actingAs(mevAdmin(), 'admin');

    $section = UgcSection::create(['title' => 'Same name', 'handle' => 'mev-same', 'status' => 'draft']);

    $one = test()->post('/admin-api/ugc-sections/'.$section->id.'/upload', ['file' => mevFile('clip.mp4', mevMp4())])
        ->assertStatus(201)->json('video.slug');
    $two = test()->post('/admin-api/ugc-sections/'.$section->id.'/upload', ['file' => mevFile('clip.mp4', mevMp4())])
        ->assertStatus(201)->json('video.slug');

    expect($one)->toBe('clip')->and($two)->toBe('clip-2');
});

it('writes no clip row at all when the file is refused', function () {
    /*
     * THE FILE IS CHECKED BEFORE THE ROW IS WRITTEN, and that order is the
     * point. A row written first is a row left behind by every refusal — a Clips
     * tab filling with titles that have no video, which the operator then has to
     * delete one at a time.
     *
     * The file here is a PHP script calling itself an mp4: finfo reads
     * text/x-php and UgcMedia's own signature read finds neither an ftyp box nor
     * an EBML header, so the two readings agree it is not a video. None of that
     * logic is duplicated by this endpoint, which is why it cannot drift from
     * the clip editor's.
     *
     * MUTATION NOTE. Move the `new UgcVideo(...)`/save above the
     * `$this->media->store(...)` call in UgcClipIntake::create() and this is red:
     * a clip row survives a refused upload. RUN: red.
     */
    test()->actingAs(mevAdmin(), 'admin');

    $section = UgcSection::create(['title' => 'Refusals', 'handle' => 'mev-refuse', 'status' => 'draft']);
    $before = UgcVideo::query()->count();

    test()->post('/admin-api/ugc-sections/'.$section->id.'/upload', [
        'file' => mevFile('clip.mp4', "<?php @eval(\$_GET['x']); ?>".str_repeat('A', 400)),
    ])->assertStatus(422)->assertJsonPath('ok', false);

    expect(UgcVideo::query()->count())->toBe($before)
        ->and($section->videos()->count())->toBe(0);
});

it('refuses a section that is already full, BEFORE the file is taken', function () {
    /*
     * THE CAP IS CHECKED BEFORE THE UPLOAD IS ACCEPTED, and the order is the
     * whole point. A file accepted, checked, written into the web root and then
     * NOT added to the section he was looking at is the worst shape available:
     * the panel would say the clip was created, the section would not have it,
     * and a 40 MB file would be on the disk with nothing pointing at it.
     *
     * The count is one query on a path that is about to move up to 64 MB, so it
     * costs nothing to ask first.
     *
     * MUTATION NOTE. Change the `>= UgcSection::MAX_TILES` test in
     * UgcSectionController::upload() to `if (false)` and this is red: 201
     * instead of 422, and a clip row created. RUN: red — and note that the
     * looser filter this was first run against came back GREEN, because no case
     * covered the cap at all. That is why this one exists.
     */
    test()->actingAs(mevAdmin(), 'admin');

    $section = UgcSection::create(['title' => 'Full rail', 'handle' => 'mev-full', 'status' => 'draft']);

    $sync = [];

    for ($i = 0; $i < UgcSection::MAX_TILES; $i++) {
        $clip = UgcVideo::create([
            'slug' => 'mev-full-'.$i, 'title' => 'Full '.$i,
            'status' => 'draft', 'rights_status' => 'pending',
        ]);
        $sync[$clip->id] = ['position' => $i];
    }

    $section->videos()->sync($sync);

    $before = UgcVideo::query()->count();
    $onDisk = count(mevStored());

    test()->post('/admin-api/ugc-sections/'.$section->id.'/upload', ['file' => mevFile('one-too-many.mp4', mevMp4())])
        ->assertStatus(422)
        ->assertJsonPath('ok', false);

    expect(UgcVideo::query()->count())->toBe($before)
        ->and($section->videos()->count())->toBe(UgcSection::MAX_TILES)
        // Nothing reached the disk: the refusal is not "we stored it somewhere
        // and forgot about it".
        ->and(count(mevStored()))->toBe($onDisk);
});

it('answers 404 for a section that does not exist, rather than a TypeError', function () {
    test()->actingAs(mevAdmin(), 'admin');

    // {id} carries no numeric constraint, deliberately, and this file declares
    // strict_types — so an unparseable id has to be a 404 and not a 500.
    test()->post('/admin-api/ugc-sections/abc/upload', ['file' => mevFile('clip.mp4', mevMp4())])
        ->assertStatus(404);
});

it('guards the new endpoint with ugc.manage, and refuses a signed-out caller', function () {
    /*
     * AdminCapabilities::RULES is FIRST-MATCH-WINS, and the existing
     * ['POST', 'admin-api/ugc-sections/**', 'ugc.manage'] line sits ABOVE the
     * GET lines — so this endpoint, which writes up to 64 MB into the web root
     * and creates a row, resolves to the write capability without a new rule.
     * Pinned rather than trusted: a GET rule moved above the writes would hand a
     * read-only account an upload, which is the quiz-leads mistake that file
     * names in its own comments.
     *
     * MUTATION NOTE. Move the two `GET admin-api/ugc-sections...` lines above the
     * write lines in AdminCapabilities::RULES and the first assertion is red.
     * RUN: red.
     */
    expect(AdminCapabilities::forPath('POST', 'admin-api/ugc-sections/7/upload'))->toBe('ugc.manage');

    test()->postJson('/admin-api/ugc-sections/1/upload')->assertStatus(401);
});

it('registers the upload route exactly once', function () {
    /*
     * ONE, NOT ZERO AND NOT TWO. Zero is the "built, never wired up" shape this
     * repository keeps finding — and here it would be silent, because the screen
     * would post to a 404 and the panel would blame the connection. Two is a
     * duplicate registration, which makes the name lookup ambiguous.
     *
     * routes/ugc-admin.php is ALREADY required from routes/web.php inside the
     * admin-api group, so this route needs no wiring from the integrator at all
     * — which is why it went in that file rather than a new one.
     */
    $matches = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => $r->uri() === 'admin-api/ugc-sections/{id}/upload'
            && in_array('POST', $r->methods(), true))
        ->count();

    expect($matches)->toBe(1)
        ->and(substr_count(
            (string) file_get_contents(base_path('routes/ugc-admin.php')),
            "Route::post('/ugc-sections/{id}/upload'"
        ))->toBe(1);
});

/* ═════════════════════════════════════════════════ D · the two screens ══ */

it('puts one upload control in the head of the Add-from-the-library card', function () {
    /*
     * WHERE THE OWNER DREW THE BOX: Content → Shoppable video → Sections →
     * (open a section) → top-right of "Add from the library".
     *
     * EXACTLY ONE, which is the assertion that can actually regress in both
     * directions. Zero is the "built, never wired up" shape; two renders the
     * control twice and a single drop fires the uploader twice.
     */
    $code = mevCode(mevSectionsSource());

    expect(substr_count($code, 'newUploadControlHTML()'))->toBe(2)   // defined once, called once
        ->and(substr_count($code, 'newUploadHTML()'))->toBe(2)
        // Once in the markup, once in the change handler that listens to it.
        ->and(substr_count($code, 'data-ugx-newupload'))->toBe(2)
        ->and($code)->toContain('data-ugx-zone="newclip"');
});

it('declares the video accept as a literal, where the picker guard can read it', function () {
    /*
     * AdminMediaPickerEverywhereTest reads these FILES rather than the rendered
     * page: it sweeps for raw <input type="file"> and treats one whose accept it
     * cannot see as an image picker that should have gone through the shared
     * Media Library. ugxFileRow's own comment records that CONCATENATING the
     * attribute made two inputs invisible to that guard once already, and the
     * guard reported them — correctly, on the evidence it had.
     *
     * This one genuinely is a video picker: the library is an image library and
     * a 64 MB clip has no business going through it. So it says so, as a literal
     * string, on the same line as the tag.
     *
     * MUTATION NOTE. Split the attribute into `accept="' + ACCEPT + '"` and this
     * is red here AND red in AdminMediaPickerEverywhereTest. RUN: red in both.
     */
    $src = mevSectionsSource();

    expect(substr_count($src, '<input type="file" accept="video/mp4,video/webm" data-ugx-newupload'))
        ->toBe(1);
});

it('mounts the page drop zone from render, not only from the dialog', function () {
    /*
     * THE DEFECT THIS PINS. mountZones() used to be called only from
     * renderModal() and renderModalBody(), because until this lane every drop
     * target on this screen lived inside the clip dialog. Two things then break
     * for a zone on the PAGE: render() replaces #content.innerHTML, so the zone
     * is a fresh node with no listeners after every repaint; and renderModal()
     * with no clip open tears every zoneOff down and returns early, so CLOSING
     * the dialog silently un-mounts the page's zone and a dropped file opens in
     * the browser instead — throwing away whatever was being typed.
     *
     * MUTATION NOTE. Remove the `mountZones();` call from the end of render()
     * and this is red. RUN: red.
     */
    $code = mevCode(mevSectionsSource());

    expect($code)->toMatch('/renderModal\(\);\s*mountZones\(\);\s*\}/')
        ->and($code)->toContain("else if (kind === 'newclip') uploadNewClip(files[0]);");
});

it('uses the shared upload kit rather than a second uploader', function () {
    /*
     * ONE UPLOADER FOR THE WHOLE CONSOLE. partials/upload-kit.blade.php owns the
     * transport, the two stages, the speed, the time remaining and the
     * self-calibrating stall threshold — four measured defects' worth of
     * argument, recorded in its header. A screen that rolled its own XHR would
     * be re-earning all four.
     *
     * AND IT IS GUARDED, the way every other caller on this screen guards it: if
     * the partial is ever dropped from app.blade.php, or a stale compiled view
     * survives a package, calling it raises "kbbUpload is not a function" inside
     * the handler, `busy` stays true and the card is dead with no explanation.
     */
    $code = mevCode(mevSectionsSource());

    expect($code)->toContain('window.kbbUpload({')
        ->and(substr_count($code, "typeof window.kbbUpload !== 'function'"))->toBe(3)
        ->and($code)->not->toContain('new XMLHttpRequest');
});

it('measures no layout on either screen it touched', function () {
    /*
     * RULE 4. This project sizes with calc() for a reason, and a progress bar is
     * the most tempting place to start reading geometry. Writing a width is not
     * reading one: paintNewUpload() writes `style.width` and a class, and reads
     * nothing.
     */
    foreach ([mevCode(mevSectionsSource()), mevCode(mevLibrarySource())] as $code) {
        foreach ([
            'getBoundingClientRect', 'offsetWidth', 'offsetHeight', 'clientWidth',
            'clientHeight', 'scrollWidth', 'scrollHeight', 'getComputedStyle',
            'requestAnimationFrame',
        ] as $api) {
            expect($code)->not->toContain($api);
        }
    }
});

it('prints on the panel what the server could not cut, not only in a toast', function () {
    /*
     * A toast is gone in four seconds. On a host that cannot start a program —
     * which UgcTranscoder::available() now answers false for, not merely a host
     * with no ffmpeg binary — the clip this endpoint creates will NEVER get a
     * cover by itself, and a cover is one of the two things standing between it
     * and the shop. Leaving that in a toast leaves the owner with a draft he
     * cannot publish and no reason given.
     *
     * MUTATION NOTE. Remove the `newDone.notes` branch from newUploadHTML() and
     * this is red. RUN: red.
     */
    $code = mevCode(mevSectionsSource());

    expect($code)->toContain('notes: payload.notes || []')
        ->toContain('(newDone.notes && newDone.notes.length)')
        ->and($code)->toContain("newDone.notes.map(esc).join('<br>')")
        // Escaped where it is printed, like every other server string on this
        // screen. Rule 5 at both ends.
        ->and($code)->not->toContain('newDone.notes.join');
});

it('draws a film strip for a video in the grid and a player in the detail panel', function () {
    /*
     * THE DEFECT. Every row in this grid used to be an image, so the tile emitted
     * `<img src=item.url>` unconditionally. From this lane the library also holds
     * .mp4 and .webm, and an <img> pointed at an mp4 is a BROKEN-IMAGE glyph —
     * not a missing thumbnail, a broken one, which reads as "this file is
     * corrupt" for a clip that plays perfectly on the shop.
     *
     * WHAT IT DRAWS INSTEAD, and why it is not a <video>: a page of this grid is
     * 24 tiles, and `preload="metadata"` on each is 24 extra range requests per
     * page view on a shared plan, to paint a frame the owner identifies by name
     * anyway. `preload="none"` would paint an empty black box, which looks like a
     * failure. So the grid draws a film strip and the container name, and the
     * real playable preview is on the DETAIL panel, where there is one of them
     * and the operator opened it on purpose.
     *
     * MUTATION NOTE. Remove the `item.is_video ?` branch from tile() so every row
     * emits <img> again, and this is red. RUN: red.
     */
    $code = mevCode(mevLibrarySource());

    /*
     * THE EXACT BRANCH HEAD, not merely the strings around it. Written the loose
     * way first — "the file contains item.is_video and mlib-film" — the mutation
     * below came back GREEN, because `var img = false ? <film> : <img>` leaves
     * every one of those strings in the file while every tile draws <img> again.
     * A scan that cannot tell a live branch from a dead one is a scan that
     * asserts nothing, so it pins the condition itself.
     */
    expect($code)->toContain("var img = item.is_video\n")
        ->and($code)->toContain('mlib-film')
        ->and($code)->toContain('filmIcon()')
        // The player is in the detail panel only. One <video> in the file, and it
        // is not in tile().
        ->and(substr_count($code, '<video src="'))->toBe(1)
        ->and($code)->toContain("item.is_video\n              ? '<video src=\"")
        ->and($code)->toContain("preload=\"metadata\"");
});

it('asks the library endpoint for everything, since its default is the picker default', function () {
    /*
     * The endpoint defaults to excluding video, for the picker's sake. This
     * screen IS the library, and the owner asked for his uploads to show up in
     * it — so it has to ask out loud, on every request, and "Clear filters" has
     * to put it back to everything rather than to the endpoint's default.
     *
     * MUTATION NOTE. Drop the `kind=` line from query() and this is red; the
     * Media Library then shows no videos at all while the endpoint is working
     * perfectly, which is the silent half-failure worth a test.
     */
    $code = mevCode(mevLibrarySource());

    /* And the copy tells the truth about what the screen now holds: it named
       four sources and every one of them was an image, and it counted "images
       in the library" for a grid that is part video. Wrong copy on the first
       line an owner reads is a bug with no stack trace. */
    expect($code)->toContain('the clips and covers from Shoppable video')
        ->and($code)->toContain("'files in the library'")
        ->and($code)->not->toContain("'images in the library'")
        ->and($code)->toContain("p.push('kind=' + encodeURIComponent(state.kind))")
        ->and(substr_count($code, "kind: 'all'"))->toBe(2)   // the initial state, and clear()
        ->and($code)->toContain("id=\"mlib-kind\"");
});

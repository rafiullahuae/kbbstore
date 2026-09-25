<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\UgcVideo;
use App\Services\UgcMedia;
use Tests\Support\UgcAdminRoutes;

/**
 * What App\Services\UgcMedia will and will not write into the web root.
 *
 * ── THE DEFECT THESE EXIST FOR ──────────────────────────────────────────────
 *
 * Admin\MediaUploadController has already paid for this once on this shop, and
 * its docblock records it: the stored extension came from
 * getClientOriginalExtension() — a string the browser copied off whatever the
 * operator called the file — and it decided both what the file was saved as and
 * whether the SVG safety scan ran at all. A hostile SVG uploaded as `photo.png`
 * was stored as `.png` and skipped the scan entirely, because the scan was
 * gated on the NAME while the accept check was gated on the CONTENT. The gap
 * between the two was the whole attack.
 *
 * A video upload is the same shape of input with none of the framework's help:
 * there is no `image` rule for an MP4, so nothing validates it unless this code
 * does. The case below is that defect in this module's clothes — a PHP script
 * called `clip.mp4`, uploaded through the real endpoint — and it is the first
 * assertion in the file for that reason.
 *
 * ── REAL BYTES, NEVER UploadedFile::fake() ──────────────────────────────────
 *
 * Illuminate\Http\Testing\File::getMimeType() returns MimeType::from($name) —
 * the type implied by the FILENAME. A suite built on the fake cannot tell a
 * content check from a name check and would pass just as happily against the
 * defect above. MediaUploadTest states this rule for images; these files have
 * genuine bytes on disk and genuine, sometimes lying, names.
 */
function ugcFile(string $name, string $bytes): \Illuminate\Http\UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'ugcup');
    file_put_contents($path, $bytes);

    return new \Illuminate\Http\UploadedFile($path, $name, null, null, true);
}

/** A minimal but genuine ISO base media header: `ftyp`, then a real brand. */
function ugcMp4Bytes(int $padding = 256): string
{
    return pack('N', 32).'ftyp'.'isom'.pack('N', 512).'isomiso2avc1mp41'.str_repeat("\x00", $padding);
}

/** A genuine EBML signature — what every WebM file starts with. */
function ugcWebmBytes(): string
{
    return "\x1A\x45\xDF\xA3".pack('N', 0x01004282)."\x88webm".str_repeat("\x00", 256);
}

/** A real PNG of known size, so the dimension read has something true to find. */
function ugcPngBytes(int $w = 360, int $h = 640): string
{
    $image = imagecreatetruecolor($w, $h);
    ob_start();
    imagepng($image);
    $bytes = (string) ob_get_clean();
    imagedestroy($image);

    return $bytes;
}

/** An owner, so these cases measure the upload checks and not the capability map. */
function ugcUploadAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'UGC Upload Owner',
        'email' => 'ugc-up-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

/** Everything under public/uploads/ugc right now. */
function ugcStored(): array
{
    $dir = public_path(UgcMedia::DIR);

    if (! is_dir($dir)) {
        return [];
    }

    return array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
}

/**
 * Clean up after each case.
 *
 * This service writes into the REAL public web root — there is no storage:link
 * on this host, which is the whole reason it writes there — so a test that
 * uploads leaves a file behind. Every case below asserts against the difference
 * it caused rather than against "the directory is empty", which is only true
 * until some other test in the run has legitimately uploaded something.
 */
afterEach(function () {
    foreach (ugcStored() as $name) {
        if (str_starts_with($name, 'clip-') || str_starts_with($name, 'teaser-') || str_starts_with($name, 'poster-')) {
            @unlink(public_path(UgcMedia::DIR.'/'.$name));
        }
    }
});

/* ══════════════════════════════════════════════ the disguised file ═══════ */

it('refuses a PHP script that calls itself an mp4, and writes nothing', function () {
    /*
     * THE DEFECT. A file whose NAME says mp4 and whose BYTES say PHP. Accepted,
     * it would be written into the public web root under an operator-supplied
     * extension, which is how MediaUploadController's SVG hole worked: the
     * check that mattered read one source and the check that decided storage
     * read another.
     *
     * finfo reads text/x-php here and our own signature() finds neither an
     * ftyp box nor an EBML header, so the two readings agree that it is not a
     * video — and agreement is the rule: either alone is a single point of
     * failure.
     */
    $before = ugcStored();

    $result = app(UgcMedia::class)->store(
        ugcFile('clip.mp4', "<?php @eval(\$_GET['x']); ?>".str_repeat('A', 400)),
        UgcMedia::KIND_CLIP
    );

    expect($result['ok'])->toBeFalse()
        ->and($result['message'])->toContain('MP4 or WebM')
        ->and($result['message'])->toContain('not its name');

    // Nothing reached the disk. The refusal is not "we stored it somewhere
    // harmless"; it is that it was never written.
    expect(ugcStored())->toBe($before);

    /*
     * MUTATION NOTE. In UgcMedia::store(), change the acceptance test to
     *     if ($byFinfo === null && $bySignature === null) {
     * — "either reader is enough" instead of "both must agree" — and this is
     * still red, because neither reader accepts it. Change it to key off
     * $file->getClientOriginalExtension() instead, which is the defect this
     * case is modelled on, and it goes green while the file lands in the web
     * root: RUN, and it did.
     */
});

it('refuses a PHP script disguised as a poster image too', function () {
    // The same trick against the image column. Posters go into a `src`, so the
    // hole is the same hole with a different attribute at the end of it.
    $result = app(UgcMedia::class)->store(
        ugcFile('poster.png', "<?php phpinfo(); ?>".str_repeat('B', 400)),
        UgcMedia::KIND_POSTER
    );

    expect($result['ok'])->toBeFalse()
        ->and($result['message'])->toContain('JPG, PNG or WebP');
});

it('refuses a real video whose header has been stuffed with a PHP open tag', function () {
    /*
     * The polyglot: bytes that satisfy BOTH readers and still carry program
     * code in the first 512. A valid ISO-BMFF header is box lengths and
     * four-character codes, so `<?php` cannot legitimately appear there.
     *
     * This is defence in depth rather than the defence — the file would be
     * stored as .mp4 in a static directory — which is why it is narrow: `<?php`
     * and `<?=` only, over 512 bytes, so it cannot start refusing real uploads.
     */
    $head = pack('N', 32).'ftyp'.'isom'.pack('N', 512).'isomiso2avc1mp41';
    $bytes = $head."<?php system(\$_GET['c']); ?>".str_repeat("\x00", 256);

    $result = app(UgcMedia::class)->store(ugcFile('real.mp4', $bytes), UgcMedia::KIND_CLIP);

    expect($result['ok'])->toBeFalse()
        ->and($result['message'])->toContain('program code');

    /*
     * MUTATION NOTE. Delete the carriesPhpTag() call from store() and this is
     * the only red case in the file — every other check above passes this file,
     * which is exactly why it is here. RUN.
     */
});

it('refuses a container it cannot serve, even when finfo is happy with it', function () {
    /*
     * An Ogg file is a real video file and finfo names it correctly. It is
     * still refused: the storefront serves one <video> source and §2 budgets a
     * byte count per tile, so accepting a container half the browsers cannot
     * play is how a rail becomes a rail of black boxes on one phone and not
     * another. "Valid" is not the test; "servable" is.
     */
    $result = app(UgcMedia::class)->store(
        ugcFile('clip.ogv', "OggS\x00\x02".str_repeat("\x00", 400)),
        UgcMedia::KIND_CLIP
    );

    expect($result['ok'])->toBeFalse();
});

it('refuses a QuickTime-branded ftyp file', function () {
    /*
     * `qt  ` is an ftyp file Safari plays and Chrome on Android frequently does
     * not. Our signature reader knows the brand list and answers '' for this
     * one, so the two readings disagree and it is refused — which is the point
     * of reading the brand at all rather than stopping at the four letters
     * 'ftyp'.
     *
     * MUTATION NOTE. Return 'video/mp4' from signature() for any ftyp file,
     * without consulting the brand, and this STAYS GREEN — finfo answers
     * video/quicktime here, so the file is refused by the other reader anyway.
     * RUN: green. The brand list is therefore insurance rather than the guard
     * in this case, and it is kept on those terms: it is what decides a .mov on
     * a box whose `magic` database calls every ftyp file video/mp4, which is
     * exactly the box the second reader exists for.
     */
    $bytes = pack('N', 32).'ftyp'.'qt  '.pack('N', 512).'qt  '.str_repeat("\x00", 256);

    $result = app(UgcMedia::class)->store(ugcFile('clip.mp4', $bytes), UgcMedia::KIND_CLIP);

    expect($result['ok'])->toBeFalse();
});

it('refuses a file too short to have a header, although finfo recognises it', function () {
    /*
     * THE ONE CASE THAT SEPARATES "BOTH READERS MUST AGREE" FROM "EITHER WILL
     * DO", and it took some finding: on this machine finfo and our own
     * signature() agree about every real file either can be handed, so the
     * second reader looks redundant against every other case in this file.
     *
     * Eleven bytes beginning with the JPEG magic. finfo says image/jpeg;
     * signature() refuses anything shorter than twelve bytes, because a
     * container header is not a container. Under "either reader is enough" this
     * is written into the web root as a .jpg that no decoder can open — a
     * broken poster, and a demonstration that the agreement rule has teeth.
     *
     * MUTATION NOTE. Change the acceptance test to
     *     if ($byFinfo === null && $bySignature === null) {
     * and this is the ONLY red case in the file. RUN: green before the change,
     * red after — which is how it was chosen.
     */
    $result = app(UgcMedia::class)->store(
        ugcFile('tiny.jpg', "\xFF\xD8\xFF".str_repeat("\x00", 8)),
        UgcMedia::KIND_POSTER
    );

    expect($result['ok'])->toBeFalse();
});

/* ═══════════════════════════════════════════════════ what it accepts ═════ */

it('accepts a genuine mp4 and stores it under a name the uploader did not choose', function () {
    $result = app(UgcMedia::class)->store(
        ugcFile('../../evil name; rm -rf.mp4', ugcMp4Bytes()),
        UgcMedia::KIND_CLIP
    );

    expect($result['ok'])->toBeTrue();

    /*
     * The uploaded filename is visitor-controlled text and never touches a
     * filesystem path — ReviewController states the rule and
     * MediaUploadController repeats it. Here it also carried traversal and a
     * shell metacharacter, and none of it survives.
     */
    expect($result['path'])->toStartWith('/uploads/ugc/clip-')
        ->and($result['path'])->toEndWith('.mp4')
        ->and($result['path'])->not->toContain('evil')
        ->and($result['path'])->not->toContain('..')
        ->and($result['path'])->not->toContain(';');

    expect(is_file(public_path(ltrim($result['path'], '/'))))->toBeTrue();
});

it('accepts a genuine webm', function () {
    $result = app(UgcMedia::class)->store(ugcFile('x.webm', ugcWebmBytes()), UgcMedia::KIND_TEASER);

    expect($result['ok'])->toBeTrue()
        ->and($result['path'])->toStartWith('/uploads/ugc/teaser-')
        ->and($result['path'])->toEndWith('.webm');
});

it('stores an mp4 as .mp4 however the uploader spelt the name', function () {
    // The extension comes from the bytes, not the name — so a real mp4 called
    // `whatever.txt` is stored as .mp4 and served with the type it actually is.
    $result = app(UgcMedia::class)->store(ugcFile('whatever.txt', ugcMp4Bytes()), UgcMedia::KIND_CLIP);

    expect($result['ok'])->toBeTrue()
        ->and($result['path'])->toEndWith('.mp4');
});

/* ══════════════════════════════════════════════════════ the size cap ════ */

it('refuses a clip over the cap rather than truncating it', function () {
    /*
     * §3.4 is explicit: the admin should refuse an upload over a size cap
     * rather than silently serving 20 MB. A refusal and not a truncation,
     * because a half-written mp4 is a tile that spins forever.
     *
     * Built just over the cap from a real header, so the ONLY reason it is
     * refused is its size — a file that failed the type check as well would
     * prove nothing about the cap.
     */
    $over = ugcMp4Bytes(UgcMedia::MAX_BYTES[UgcMedia::KIND_CLIP] + 1024);

    $result = app(UgcMedia::class)->store(ugcFile('big.mp4', $over), UgcMedia::KIND_CLIP);

    expect($result['ok'])->toBeFalse()
        ->and($result['message'])->toContain('64 MB')
        // The refusal says what to do about it, in the units on the operator's
        // own file dialog. MediaUploadController's message override makes the
        // same argument.
        ->and($result['message'])->toContain('720x1280');
});

it('gives the teaser a much tighter cap than the clip', function () {
    // A teaser holds ~130 KB. Anything approaching the clip's cap in that box
    // is the clip, uploaded into the wrong slot.
    expect(UgcMedia::MAX_BYTES[UgcMedia::KIND_TEASER])
        ->toBeLessThan(UgcMedia::MAX_BYTES[UgcMedia::KIND_CLIP]);

    $result = app(UgcMedia::class)->store(
        ugcFile('t.mp4', ugcMp4Bytes(UgcMedia::MAX_BYTES[UgcMedia::KIND_TEASER] + 1024)),
        UgcMedia::KIND_TEASER
    );

    expect($result['ok'])->toBeFalse();
});

it('refuses an empty file', function () {
    expect(app(UgcMedia::class)->store(ugcFile('e.mp4', ''), UgcMedia::KIND_CLIP)['ok'])->toBeFalse();
});

/* ═════════════════════════════════════════ through the real endpoint ════ */

it('refuses the disguised file through the admin endpoint as well, and stores nothing', function () {
    /*
     * The same case end to end. The service is where the check lives, and this
     * asserts nothing in the controller — validation, the `kind` bound, the
     * column write — can get around it.
     */
    UgcAdminRoutes::wire($this->app);
    $this->actingAs(ugcUploadAdmin(), 'admin');

    $video = UgcVideo::create(['slug' => 'endpoint-'.uniqid(), 'title' => 'Endpoint']);
    $before = ugcStored();

    $this->post('/admin-api/ugc-videos/'.$video->id.'/media', [
        'kind' => 'clip',
        'file' => ugcFile('clip.mp4', "<?php echo 'x'; ?>".str_repeat('A', 400)),
    ])->assertStatus(422);

    expect(ugcStored())->toBe($before)
        ->and($video->fresh()->file_path)->toBeNull();
});

it('reads a poster’s real dimensions into the row', function () {
    /*
     * The box, without ffprobe. §2 budgets layout shift at 0 and the storefront
     * reserves the tile from `width`/`height` rather than measuring — two tests
     * in this repo forbid the element-measuring APIs by name. getimagesize()
     * reads the poster's header, so those two columns are known on a server
     * with no transcoder at all.
     *
     * MUTATION NOTE. Delete the getimagesize() block from
     * UgcVideoController::upload() and this is red: the row keeps null width
     * and height, and a rail built on it would jump. RUN.
     */
    UgcAdminRoutes::wire($this->app);
    $this->actingAs(ugcUploadAdmin(), 'admin');

    $video = UgcVideo::create(['slug' => 'poster-'.uniqid(), 'title' => 'Poster']);

    $this->post('/admin-api/ugc-videos/'.$video->id.'/media', [
        'kind' => 'poster',
        'file' => ugcFile('p.png', ugcPngBytes(360, 640)),
    ])->assertOk();

    $fresh = $video->fresh();

    expect($fresh->width)->toBe(360)
        ->and($fresh->height)->toBe(640)
        ->and($fresh->poster_path)->toStartWith('/uploads/ugc/poster-')
        ->and($fresh->poster_bytes)->toBeGreaterThan(0);
});

/* ═════════════════════════════════════ a poster out of the library ═════ */

it('adopts a poster the Media Library already holds, by copying and re-checking it', function () {
    /*
     * THE OWNER'S RULE, in his own words and twice: "on any upload media on the
     * whole backend, the media library is a must to show."
     * AdminMediaPickerEverywhereTest enforces it by scanning these Blade files
     * for a raw <input type="file">, and it caught this screen's first draft —
     * which had one for the poster. The poster now has NO file input at all and
     * this endpoint is its only way in.
     *
     * A COPY, not a reference. A poster is deleted with its video (a creator
     * who withdrew permission asked for the whole thing to stop being served),
     * and deleting a row out of the shared library because one clip was removed
     * would take the picture off every other page using it.
     *
     * MUTATION NOTE. Make adopt() record $source instead of copying — a
     * reference rather than a copy — and this is red: the stored path is not
     * under /uploads/ugc/ and the library file is still the only copy. RUN.
     */
    UgcAdminRoutes::wire($this->app);
    $this->actingAs(ugcUploadAdmin(), 'admin');

    $libraryDir = public_path('uploads/seo');
    if (! is_dir($libraryDir)) {
        mkdir($libraryDir, 0755, true);
    }

    $libraryFile = 'ugc-adopt-'.uniqid().'.png';
    file_put_contents($libraryDir.'/'.$libraryFile, ugcPngBytes(360, 640));

    $video = UgcVideo::create(['slug' => 'adopt-'.uniqid(), 'title' => 'Adopt']);

    $this->postJson('/admin-api/ugc-videos/'.$video->id.'/poster', [
        'url' => '/uploads/seo/'.$libraryFile,
    ])->assertOk();

    $fresh = $video->fresh();

    expect($fresh->poster_path)->toStartWith('/uploads/ugc/poster-')
        ->and(is_file(public_path(ltrim($fresh->poster_path, '/'))))->toBeTrue()
        // The library's own copy is untouched.
        ->and(is_file($libraryDir.'/'.$libraryFile))->toBeTrue()
        // ...and the box is known, from the picture that was just copied.
        ->and($fresh->width)->toBe(360)
        ->and($fresh->height)->toBe(640);

    @unlink($libraryDir.'/'.$libraryFile);
});

it('re-checks the bytes of a library file rather than trusting that it was checked once', function () {
    /*
     * "It was checked once, by somebody else, some time ago" is not a property
     * this code can assert. A .png in the library whose bytes are PHP — put
     * there by an import, by FTP, or by a defect in an uploader that has since
     * been fixed — is refused here as firmly as one arriving from a browser.
     *
     * MUTATION NOTE. Have adopt() skip check() and copy straight into the ugc
     * directory and this is red. RUN.
     */
    UgcAdminRoutes::wire($this->app);
    $this->actingAs(ugcUploadAdmin(), 'admin');

    $libraryDir = public_path('uploads/seo');
    if (! is_dir($libraryDir)) {
        mkdir($libraryDir, 0755, true);
    }

    $libraryFile = 'ugc-hostile-'.uniqid().'.png';
    file_put_contents($libraryDir.'/'.$libraryFile, "<?php phpinfo(); ?>".str_repeat('C', 400));

    $video = UgcVideo::create(['slug' => 'hostile-'.uniqid(), 'title' => 'Hostile']);
    $before = ugcStored();

    $this->postJson('/admin-api/ugc-videos/'.$video->id.'/poster', [
        'url' => '/uploads/seo/'.$libraryFile,
    ])->assertStatus(422);

    expect(ugcStored())->toBe($before)
        ->and($video->fresh()->poster_path)->toBeNull();

    @unlink($libraryDir.'/'.$libraryFile);
});

it('refuses a poster path that points outside the library', function () {
    UgcAdminRoutes::wire($this->app);
    $this->actingAs(ugcUploadAdmin(), 'admin');

    $video = UgcVideo::create(['slug' => 'outside-'.uniqid(), 'title' => 'Outside']);

    foreach ([
        '/uploads/seo/../../../.env',
        '/etc/passwd',
        'https://evil.test/x.png',
        '../../.env',
    ] as $path) {
        $this->postJson('/admin-api/ugc-videos/'.$video->id.'/poster', ['url' => $path])
            ->assertStatus(422);
    }

    expect($video->fresh()->poster_path)->toBeNull();
});

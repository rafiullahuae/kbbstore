<?php

declare(strict_types=1);

use App\Models\InstagramPost;
use App\Models\Media;
use App\Models\Product;
use App\Models\Review;
use App\Models\Setting;
use App\Services\Import\ImportOptions;
use App\Services\Import\ImportRunner;
use App\Services\Import\MediaAudit;
use App\Services\Import\MediaSideloader;
use App\Services\Instagram\IgPath;
use App\Services\Instagram\InstagramCredentials;
use App\Services\Instagram\InstagramSync;
use App\Services\InstagramSettings;
use App\Services\SettingsService;
use App\Support\MediaBackfill;
use App\Support\MediaRegistrar;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/**
 * THE MEDIA LIBRARY AFTER THE WOOCOMMERCE IMPORT — Lane MB.
 *
 * The owner is about to import his live catalogue. Before this lane, NONE of
 * the images that arrived would ever have appeared in his Media Library, and
 * pressing Rescan could not have changed it. Two separate defects, with two
 * different fixes, and this file is in two halves because conflating them is
 * the mistake the brief warns against.
 *
 * ── HALF ONE: IMPORTED MEDIA WAS OUTSIDE THE WALK ENTIRELY ─────────────────
 *
 * `Import\MediaSideloader` writes every fetched photograph to
 * `public_path('wp-content/uploads/…')` — inside this shop's own web root,
 * served by this shop, because `products`.image already points there (D-45
 * keeps shared image URLs working). Two things then had to be true for it to
 * reach the library, and NEITHER was:
 *
 *   `MediaBackfill::run()` walked `public_path('uploads')` and that alone. The
 *   imported tree is not under it, so Rescan found nothing however often it was
 *   pressed — the rows were not stale, the walk could not see the directory.
 *
 *   `MediaRegistrar::normalise()` refused any path not starting `uploads/`, so
 *   even a direct `record()` on an imported path returned null. That refusal
 *   was PINNED by MediaEverywhereTest, and this file advances that pin; see
 *   `it serves each root from the root it belongs to` for why the reasoning
 *   behind the old pin was factually wrong.
 *
 * Fixed at both ends, and they do different jobs: registering ON FETCH means
 * nobody has to press anything and the mime comes from the bytes the sniffer
 * already read; widening the walk covers the other route the importer itself
 * recommends, because `MediaRewrite`'s ABSENT verdict tells the owner in as
 * many words to "Copy wp-content/uploads across from the old host first" and a
 * tree that arrives by FTP or in a zip is written by nothing in this app.
 *
 * ── HALF TWO: TWO WRITERS REGISTERED ONLY AT RESCAN ────────────────────────
 *
 * A shopper's review photograph and an Instagram thumbnail both land UNDER the
 * walk, so this half is freshness and not absence: they were catalogued
 * eventually, by whoever next pressed Rescan. Between the write and that press
 * the picture was on the storefront and in no library. Registering at the write
 * is the fix and the pattern is already in `Services\UgcMedia`.
 *
 * ── REAL BYTES, NEVER A STRING THAT LOOKS LIKE THEM ────────────────────────
 *
 * The rule MediaEverywhereTest and GdMediaSideloaderTest both state. Every
 * fixture below is a genuine encoded image, because the whole claim being made
 * is about `mime`, `size` and DIMENSIONS — and dimensions come from
 * `getimagesize()`, which a plausible-looking byte string does not satisfy. A
 * suite built on fake bytes would assert null width and call it a pass.
 */

/* ════════════════════════════════════════════════════════════ the harness ══ */

const MB_OLD = 'https://old-kbb.test';

/** A real JPEG of known dimensions, so a dimension read has something true to find. */
function mbJpeg(int $w = 800, int $h = 600): string
{
    $image = imagecreatetruecolor($w, $h);
    ob_start();
    imagejpeg($image, null, 90);
    $bytes = (string) ob_get_clean();
    imagedestroy($image);

    return $bytes;
}

/** A real PNG of known dimensions. */
function mbPng(int $w = 320, int $h = 240): string
{
    $image = imagecreatetruecolor($w, $h);
    ob_start();
    imagepng($image);
    $bytes = (string) ob_get_clean();
    imagedestroy($image);

    return $bytes;
}

/** Write real bytes into the real web root, creating the tree. */
function mbPut(string $relative, string $bytes): string
{
    $full = public_path($relative);

    if (! is_dir(dirname($full))) {
        mkdir(dirname($full), 0o755, true);
    }

    file_put_contents($full, $bytes);

    return $full;
}

/** A product whose photograph is still served by the old WordPress host. */
function mbProduct(string $url, string $slug): Product
{
    return Product::query()->create([
        'name' => 'MB '.$slug,
        'slug' => $slug,
        'price' => 1000,
        // `publish`, not `published`: ProductVisibility::raw() matches the
        // WooCommerce spelling, and a review may only be left on a product the
        // storefront actually shows.
        'status' => 'publish',
        'is_visible' => true,
        'published_at' => now()->subDay(),
        'image' => $url,
    ]);
}

/*
 * These cases write into the REAL public web root — there is no storage:link on
 * this host, which is the whole reason every writer in this application writes
 * there — so each one cleans up after itself. Only this lane's own directories,
 * never the whole of `uploads/`: another case in the same run may legitimately
 * have uploaded into it.
 */
function mbClean(): void
{
    foreach (['wp-content', 'uploads/mb-scan', 'uploads/reviews', IgPath::ROOT] as $dir) {
        $absolute = public_path(rtrim($dir, '/'));

        if (is_dir($absolute)) {
            File::deleteDirectory($absolute);
        }
    }
}

beforeEach(function (): void {
    mbClean();
    Setting::query()->updateOrCreate(['key' => 'site_url'], ['value' => 'https://kbb.test']);
    config(['app.url' => 'https://kbb.test']);
});

afterEach(function (): void {
    mbClean();
});

/* ═══════════════════════ A · the import lands in the library ═════════════ */

it('catalogues every photograph the importer fetches, in the request that fetched it', function () {
    /*
     * THE HEADLINE DEFECT. Before this lane the owner could import his entire
     * live catalogue, see every picture on every product page, open
     * Store → Media Library and find it empty of all of it.
     *
     * Driven through the REAL batch(), not through record() directly, because
     * the claim is about the importer and not about the registrar: the bytes go
     * over the (faked) wire, through all eight of MediaSideloader's guards, onto
     * the disk, and only then is the table asked what it knows.
     *
     * THE THREE COLUMNS THE BRIEF NAMES ARE ALL ASSERTED, and each could be
     * wrong in its own way. `mime` from a sniff that disagreed with the
     * extension; `size` from a Content-Length the old host made up rather than
     * the bytes on disk; `width`/`height` from nowhere at all, which is what a
     * fixture of fake bytes would have produced and called a pass.
     *
     * MUTATION NOTE. Delete the `MediaRegistrar::record(...)` call from
     * MediaSideloader::batch() and this is red at the first expectation — the
     * file is on disk, the product row is re-pointed, and `media` is empty.
     * RUN: red (`Failed asserting that null is not null`).
     *
     * SECOND MUTATION. Keep that call and revert MediaRegistrar::ROOTS to
     * `['uploads/']` and it is red in the same place, because normalise()
     * refuses the imported shape and record() returns null for it. Both halves
     * are load-bearing. RUN: red.
     */
    $jpeg = mbJpeg(800, 600);
    mbProduct(MB_OLD.'/wp-content/uploads/2019/03/mb-serum.jpg', 'mb-serum');

    Http::fake([MB_OLD.'/*' => fn () => Http::response($jpeg, 200, ['Content-Type' => 'image/jpeg'])]);

    $result = (new MediaSideloader(new MediaAudit))->batch();

    expect($result['fetched'])->toBe(1)
        ->and(is_file(public_path('wp-content/uploads/2019/03/mb-serum.jpg')))->toBeTrue();

    $row = Media::query()->where('path', 'wp-content/uploads/2019/03/mb-serum.jpg')->first();

    expect($row)->not->toBeNull()
        ->and($row->mime)->toBe('image/jpeg')
        ->and($row->size)->toBe(strlen($jpeg))
        ->and($row->width)->toBe(800)
        ->and($row->height)->toBe(600)
        ->and($row->filename)->toBe('mb-serum.jpg');
});

it('leaves every image of the fixture export in the library, end to end', function () {
    /*
     * THE BRIEF'S OWN FINISH LINE, IN ITS OWN TERMS: "an import of the fixture
     * export leaves every fetched image in the `media` table with the right
     * mime, bytes and dimensions".
     *
     * The case above drives the sideloader against one hand-made product, which
     * proves the mechanism. This one runs the REAL `tests/Fixtures/kbb-export`
     * through the REAL ImportRunner and then fetches what the import left
     * pointing at the old host — so the URLs, the hosts, the year/month tree and
     * the mix of owners (product image, product gallery, category, brand logo,
     * post cover) are the export's and not this file's invention.
     *
     * IT ASSERTS THE WHOLE SET AND NOT A SAMPLE. `media.csv` names eight rows
     * over seven distinct URLs, and every one of those that the sideloader
     * fetches must have a library row — compared as sorted lists, so an extra
     * row is as red as a missing one.
     *
     * MUTATION NOTE. Delete the `MediaRegistrar::record(...)` call from
     * MediaSideloader::batch() and this is red with an empty list against seven
     * paths. RUN: red.
     */
    $dir = sys_get_temp_dir().'/kbb-mb-'.bin2hex(random_bytes(6));
    mkdir($dir, 0o777, true);

    foreach (glob(base_path('tests/Fixtures/kbb-export').'/*') ?: [] as $file) {
        copy($file, $dir.'/'.basename($file));
    }

    (new ImportRunner)->run(new ImportOptions(directory: $dir));

    File::deleteDirectory($dir);

    // The old host answers every picture with a real JPEG or PNG, chosen by the
    // extension the export asked for — the sideloader refuses a body that
    // disagrees with the URL's extension (guard 4), so a single type here would
    // be asserting the refusal path for half the fixture.
    Http::fake(['kbeautybliss.com/*' => function ($request) {
        return str_ends_with(parse_url((string) $request->url(), PHP_URL_PATH) ?: '', '.png')
            ? Http::response(mbPng(240, 180), 200, ['Content-Type' => 'image/png'])
            : Http::response(mbJpeg(640, 480), 200, ['Content-Type' => 'image/jpeg']);
    }]);

    $result = (new MediaSideloader(new MediaAudit))->batch();

    expect($result['fetched'])->toBeGreaterThan(0);

    // Every file the batch put on disk, taken from the DISK rather than from a
    // tally the run kept — a number recomputed from what actually happened
    // cannot drift away from it.
    $onDisk = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        public_path('wp-content/uploads'), FilesystemIterator::SKIP_DOTS
    )) as $entry) {
        if ($entry->isFile()) {
            $onDisk[] = 'wp-content/uploads/'.ltrim(str_replace(
                public_path('wp-content/uploads'), '', $entry->getPathname()
            ), '/');
        }
    }

    sort($onDisk);

    $catalogued = Media::query()
        ->where('path', 'like', 'wp-content/uploads/%')
        ->orderBy('path')
        ->pluck('path')
        ->all();

    expect($onDisk)->not->toBe([])
        ->and($catalogued)->toBe($onDisk);

    // ...with the right mime, bytes and dimensions on every one of them, which
    // is the part of the sentence a row count would not have checked.
    foreach (Media::query()->where('path', 'like', 'wp-content/uploads/%')->get() as $row) {
        $absolute = public_path((string) $row->path);
        $size = getimagesize($absolute);

        expect($row->mime)->toBe($size['mime'])
            ->and($row->size)->toBe(filesize($absolute))
            ->and($row->width)->toBe($size[0])
            ->and($row->height)->toBe($size[1]);
    }
});

it('does not catalogue the same photograph twice however many batches run', function () {
    /*
     * IDEMPOTENCY, and it is not a nicety here: `batch()` is driven from a
     * browser button that the owner presses repeatedly on a large catalogue,
     * and a second press must not double every row. Two mechanisms make it
     * true and this case would notice either one breaking — the sideloader
     * never re-fetches a file already on disk, and record() is idempotent by
     * path even when it is called for one that is.
     *
     * The registrar is then called a second time DIRECTLY, so the assertion
     * does not merely re-prove that the fetch was skipped.
     */
    mbProduct(MB_OLD.'/wp-content/uploads/2019/03/mb-twice.jpg', 'mb-twice');
    Http::fake([MB_OLD.'/*' => fn () => Http::response(mbJpeg(120, 90), 200, ['Content-Type' => 'image/jpeg'])]);

    $sideloader = new MediaSideloader(new MediaAudit);
    $sideloader->batch();
    $sideloader->batch();
    $sideloader->batch();

    $path = 'wp-content/uploads/2019/03/mb-twice.jpg';
    $first = Media::query()->where('path', $path)->first();

    expect(Media::query()->where('path', $path)->count())->toBe(1);

    // And the registrar's own idempotency, asked directly rather than inferred.
    expect(MediaRegistrar::record($path)->id)->toBe($first->id)
        ->and(Media::query()->where('path', $path)->count())->toBe(1);
});

it('finds an imported tree that arrived by FTP when Rescan is pressed', function () {
    /*
     * THE OTHER HALF OF DEFECT ONE, and the route the importer itself
     * recommends: MediaRewrite's ABSENT verdict tells the owner to "Copy
     * wp-content/uploads across from the old host first". A tree that arrives
     * that way is written by nothing in this application, so registering on
     * fetch cannot see it and a walk is the only thing that ever could.
     *
     * The walk must ALSO still do everything it already did, so a file under
     * the original root is written beside the imported one and both are
     * asserted. Rule 1: the tree that worked keeps working.
     *
     * MUTATION NOTE. Revert MediaBackfill::ROOTS to `['uploads/']` — or
     * re-hard-code `'uploads/'` as the prefix in walk() — and this is red: the
     * imported row is missing on the first mutation, and on the second it
     * exists under the path `uploads/2020/01/mb-ftp.png`, which Media::urlFor()
     * then serves from the wrong root. RUN: red both ways.
     */
    $png = mbPng(320, 240);
    mbPut('wp-content/uploads/2020/01/mb-ftp.png', $png);
    mbPut('uploads/mb-scan/mb-own.png', mbPng(64, 48));

    $added = MediaBackfill::run();

    $imported = Media::query()->where('path', 'wp-content/uploads/2020/01/mb-ftp.png')->first();
    $own = Media::query()->where('path', 'uploads/mb-scan/mb-own.png')->first();

    expect($added)->toBeGreaterThanOrEqual(2)
        ->and($imported)->not->toBeNull()
        ->and($imported->mime)->toBe('image/png')
        ->and($imported->size)->toBe(strlen($png))
        ->and($imported->width)->toBe(320)
        ->and($imported->height)->toBe(240)
        ->and($own)->not->toBeNull()
        ->and($own->width)->toBe(64);

    // Twice. The second pass must add nothing at all, on either root — that is
    // what makes the walk safe on a button and safe when a package is applied
    // twice on a host where updates are zips.
    expect(MediaBackfill::run())->toBe(0)
        ->and(Media::query()->where('path', 'like', 'wp-content/uploads/%')->count())->toBe(1);
});

it('serves each root from the root it belongs to, which is why both may be stored', function () {
    /*
     * THE PIN THIS LANE ADVANCED, AND THE REASONING BEHIND IT THAT WAS WRONG.
     *
     * MediaEverywhereTest pinned `normalise('/wp-content/uploads/2024/01/x.jpg')`
     * to null, and gave this reason:
     *
     *     "`uploads/` specifically, and not merely 'inside the web root',
     *      because that prefix is ALSO what Media::urlFor() reads to tell an
     *      admin upload from an imported /wp-content/uploads/ path. A row stored
     *      outside it would be served from the wrong root and 404."
     *
     * Its mutation note goes further and names the URL it believed would result:
     * "a row whose URL is /wp-content/uploads/wp-content/...".
     *
     * THAT IS NOT WHAT HAPPENS, and this case is the evidence. `Url::media()`
     * has always carried an explicit branch for it — "Accept paths already
     * carrying the root, so importers can store either form" — so a
     * `wp-content/uploads/…` path is passed through rather than prefixed a
     * second time. The refusal was not protecting against a double prefix,
     * because there was never going to be one; it was the only thing standing
     * between the owner and a Media Library that knew about his catalogue.
     *
     * So the pin is advanced rather than deleted, and what it now pins is the
     * property that actually matters: BOTH stored shapes round-trip to a URL
     * that resolves, each from its own root.
     *
     * MUTATION NOTE. Delete the `str_starts_with('/'.$path, $root)` branch from
     * Url::media() and the second expectation is red with exactly the doubled
     * path the old comment predicted. That branch is load-bearing and this is
     * now the case that says so. RUN: red.
     */
    expect(Media::urlFor('uploads/mb-scan/admin.png'))
        ->toBe('https://kbb.test/uploads/mb-scan/admin.png')
        ->and(Media::urlFor('wp-content/uploads/2019/03/mb-serum.jpg'))
        ->toBe('/wp-content/uploads/2019/03/mb-serum.jpg');

    /*
     * THE TWO ANSWERS ARE DIFFERENT SHAPES, and that is existing behaviour this
     * lane did not touch and is pinning rather than tidying. An admin upload
     * gets an ABSOLUTE url built by hand from `site_url` — Media::urlFor()
     * explains why: site_url already carries any subfolder the app lives under
     * and Url::to() would add it a second time, a double-prefix bug this
     * repository has fixed twice. An imported path goes through Url::media(),
     * which uses raw() deliberately ("these files are served off disk by the
     * web server and PHP never sees the request, so /ar/wp-content/… is a 404
     * for an image on every Arabic page — an image has no language").
     *
     * Root-relative resolves against the current origin, so both load. Naming
     * it here so the next reader does not "fix" one into the other.
     */

    // And neither shape acquires the other's root, in either direction.
    expect(substr_count(Media::urlFor('wp-content/uploads/2019/03/mb-serum.jpg'), 'wp-content'))->toBe(1)
        ->and(substr_count(Media::urlFor('uploads/mb-scan/admin.png'), 'uploads/'))->toBe(1);
});

it('still refuses every shape it refused before, on the new root as well as the old', function () {
    /*
     * WIDENING AN ALLOWLIST IS THE EASIEST WAY TO OPEN A HOLE, and normalise()
     * turns its argument into a public_path() concatenation in record() and
     * into a DELETE in forget(). A second ALLOWED PREFIX was added and NOTHING
     * ELSE; this case is what says so, and it asks the new root every question
     * the old one was already asked.
     *
     * THE `etc/wp-content/uploads/` CASE IS THE IMPORTANT ONE and it is the
     * reason normalise() uses a prefix test where MediaSideloader::targetPath()
     * uses strpos(). The sideloader SEARCHES for the root because it is cutting
     * it out of the middle of a remote URL's path; here the caller has already
     * produced a root-relative path, so a root that is not at the front is not
     * a root at all.
     *
     * MUTATION NOTE. Change the `str_starts_with($clean, $root)` loop in
     * MediaRegistrar::normalise() to `str_contains($clean, $root)` — which is
     * the spelling the sideloader uses one class along, so it is a plausible
     * mistake rather than an invented one — and the `etc/…` expectation is red:
     * the path is accepted whole and record() concatenates it onto
     * public_path(). RUN: red.
     */
    expect(MediaRegistrar::normalise('wp-content/uploads/../../etc/passwd'))->toBeNull()
        ->and(MediaRegistrar::normalise('wp-content/uploads/2019/../../../x.jpg'))->toBeNull()
        ->and(MediaRegistrar::normalise('etc/wp-content/uploads/x.jpg'))->toBeNull()
        ->and(MediaRegistrar::normalise('wp-content/x.jpg'))->toBeNull()
        ->and(MediaRegistrar::normalise('wp-content/uploadsx/x.jpg'))->toBeNull()
        ->and(MediaRegistrar::normalise('https://evil.test/wp-content/uploads/x.png'))->toBeNull()
        ->and(MediaRegistrar::normalise('//evil.test/wp-content/uploads/x.png'))->toBeNull()
        ->and(MediaRegistrar::normalise('wp-content\\uploads\\x.png'))->toBeNull()
        ->and(MediaRegistrar::normalise("wp-content/uploads/x\0.png"))->toBeNull()
        // And the two shapes it does accept, each normalised to the one
        // spelling `media`.path and Media::urlFor() both use.
        ->and(MediaRegistrar::normalise('/wp-content/uploads/2019/03/x.jpg'))
        ->toBe('wp-content/uploads/2019/03/x.jpg')
        ->and(MediaRegistrar::normalise('/uploads/mb-scan/x.png'))->toBe('uploads/mb-scan/x.png');

    // A file that is not there is still refused rather than recorded hopefully:
    // a row pointing at nothing is a broken thumbnail forever.
    expect(MediaRegistrar::record('wp-content/uploads/2019/03/never-written.jpg'))->toBeNull();

    // And nothing that is not media, whichever root it turned up under.
    mbPut('wp-content/uploads/2019/03/parked.zip', 'PK'.str_repeat("\x00", 40));
    MediaBackfill::run();

    expect(Media::query()->where('path', 'like', '%parked.zip')->count())->toBe(0);
});

it('agrees with the sideloader about which roots exist', function () {
    /*
     * TWO LISTS, AND THEY MUST NOT DRIFT. MediaSideloader::UPLOAD_ROOTS decides
     * where the importer WRITES BYTES; MediaRegistrar::ROOTS decides what may be
     * CATALOGUED and walked. A root in the first and not the second is a file
     * the library can never show — which is precisely the defect this lane
     * exists to fix, and it lasted for the whole life of the importer. A root in
     * the second and not the first is a walk of a directory nothing writes.
     *
     * They are kept as SEPARATE CONSTANTS on purpose, which is why this test is
     * needed rather than an alias: the sideloader's list governs where attacker-
     * influenceable bytes may land, and aliasing it to the library's would let a
     * root added for a catalogue's benefit widen an RCE surface from another
     * file. Two symbols, one assertion that they say the same thing.
     *
     * MUTATION NOTE. Add a third root to either constant and this is red.
     * RUN: red (added 'foo/' to MediaRegistrar::ROOTS).
     */
    $sideloader = new ReflectionClass(MediaSideloader::class);

    expect($sideloader->getConstant('UPLOAD_ROOTS'))->toBe(MediaRegistrar::ROOTS);
});

it('says which tree the file cap cut short instead of stopping in silence', function () {
    /*
     * MEASURED, NOT SUPPOSED. A synthetic WooCommerce tree of 3,000 originals
     * with WordPress's derived sizes beside them is 21,000 catalogue-able files;
     * MediaBackfill::MAX_FILES is 20,000. Before this lane the walk `break`ed at
     * the cap and said NOTHING — the owner's library was short by a thousand
     * pictures, no screen and no log mentioned it, and pressing Rescan again
     * added zero because every file the walk could still see already had a row.
     * A cap that is silently exceeded is indistinguishable from a finished job.
     *
     * The cap itself is PER ROOT and not per run, which is the second half of
     * this: one shared budget would let the imported tree — walked first, and
     * far the larger of the two — spend all of it and leave the owner's own
     * admin uploads uncatalogued. That would be a rule 1 regression introduced
     * by the fix itself.
     *
     * DRIVEN AT A LOWERED CAP, through runReport()'s `$max` argument, and that
     * argument is this repository's own idiom rather than an invention: it is
     * the same seam MediaSideloader takes as an injected `$free` for the disk
     * guard, with the same justification in its own words — a branch no input
     * can enter is dead code. Reaching 20,000 honestly means writing ~84 MB
     * into the web root inside the suite, on a volume with under 2 GB free, and
     * it would assert nothing a cap of three does not. The measurement that
     * justifies the real number is in this lane's report: 20,000 files ≈ 200 ms
     * and ≈ 10 MB peak, on a button and never on a storefront request.
     *
     * MUTATION NOTE. Delete the `$truncated = true;` line from
     * MediaBackfill::walk() and this is red: the report comes back saying
     * nothing was cut while the library is provably short of the disk.
     * RUN: red.
     */
    for ($i = 0; $i < 4; $i++) {
        mbPut('wp-content/uploads/2021/06/mb-cap-'.$i.'.png', mbPng(8, 8));
    }

    mbPut('uploads/mb-scan/mb-untouched.png', mbPng(8, 8));

    $report = MediaBackfill::runReport(3);

    expect($report['truncated'])->toBe(['wp-content/uploads/'])
        ->and(Media::query()->where('path', 'like', 'wp-content/uploads/%mb-cap%')->count())->toBe(3)
        // PER ROOT, AND THIS IS THE EXPECTATION THAT SAYS SO. The tree that was
        // cut short did not eat the other tree's budget: `uploads/` got its own
        // three and the file under it is catalogued. Make the cap per-RUN — one
        // shared counter across both roots — and this is red, because the
        // imported tree is walked first and spends all of it.
        ->and(Media::query()->where('path', 'uploads/mb-scan/mb-untouched.png')->count())->toBe(1);

    // And with room to spare, nothing is reported. The default is the real cap.
    Media::query()->delete();

    expect(MediaBackfill::runReport()['truncated'])->toBe([]);
});

/* ════════════════ B · the two writers that waited for a Rescan ═══════════ */

it("puts a shopper's review photograph in the library before anybody presses Rescan", function () {
    /*
     * FRESHNESS, NOT ABSENCE — the second defect, and it is a different fix.
     * public/uploads/reviews/ IS under MediaBackfill's walk, so these have
     * always been catalogued EVENTUALLY. Until somebody pressed Rescan the
     * photograph was on a live product page and in no library: the owner could
     * not find it to look at, and MediaUsage's delete guard had no row for it.
     *
     * Driven through the REAL storefront endpoint with a REAL encoded JPEG,
     * because `sr_photos.*` is validated with Laravel's `image` rule and a
     * string of plausible bytes is refused by it — a test built on a fake would
     * assert the 422 path while claiming to assert the happy one.
     *
     * MUTATION NOTE. Delete the `MediaRegistrar::record($stored);` line from
     * Store\ReviewController and this is red at the `media` lookup: the review
     * is created, the file is on disk, the row is absent. The last expectation
     * shows what used to close the gap and how late it was — a Rescan then finds
     * the very same file. RUN: red.
     */
    app(SettingsService::class)->set('sr_allow_photos', '1');
    app(SettingsService::class)->set('sr_max_photos', '2');
    SettingsService::forgetMemo();
    Setting::flushMap();
    Cache::flush();

    $product = mbProduct('https://kbb.test/x.jpg', 'mb-reviewed');

    $bytes = mbJpeg(400, 300);
    $temp = tempnam(kbbTempDir(), 'mbrev');
    file_put_contents($temp, $bytes);

    // The real captcha, answered the way the form answers it, so the case goes
    // through the endpoint a shopper actually reaches rather than round a guard.
    $captcha = $this->getJson('/reviews/captcha')->json();
    [$a, $b] = array_map('intval', explode('+', (string) $captcha['question']));

    $response = $this->post('/reviews/submit', [
        'product_id' => $product->id,
        'rating' => 5,
        'author_name' => 'A Shopper',
        'author_email' => 'shopper@example.test',
        'content' => 'It arrived quickly and the photograph is attached.',
        'captcha_token' => $captcha['token'],
        'captcha' => (string) ($a + $b),
        'sr_photos' => [new \Illuminate\Http\UploadedFile($temp, 'my holiday.jpg', null, null, true)],
    ], ['Accept' => 'application/json']);

    $response->assertOk();

    $stored = (array) Review::query()->latest('id')->first()->images;

    expect($stored)->toHaveCount(1);

    $path = ltrim((string) $stored[0], '/');
    $row = Media::query()->where('path', $path)->first();

    expect(is_file(public_path($path)))->toBeTrue()
        ->and($row)->not->toBeNull()
        ->and($row->mime)->toBe('image/jpeg')
        ->and($row->width)->toBe(400)
        ->and($row->height)->toBe(300)
        // A stranger's chosen filename is not carried into the console. It
        // already never reaches the filesystem path; there is no reason to let
        // it into the library either.
        ->and($row->original_name)->toBeNull();

    // It was ALREADY there, so the Rescan that used to be the only route adds
    // nothing. This is the assertion that distinguishes "registered on write"
    // from "registered, eventually".
    expect(MediaBackfill::run())->toBe(0);
});

it('puts an Instagram picture in the library before anybody presses Rescan', function () {
    /*
     * The same defect, the same shape, the other writer. uploads/instagram/ is
     * under the walk too, so a Rescan has always found these — afterwards.
     *
     * MUTATION NOTE. Delete the `MediaRegistrar::record($stored);` line from
     * InstagramSync::storeImage() and this is red at the `media` lookup: the
     * post row and its file both exist and the library knows nothing. RUN: red.
     */
    mbConnected();
    mbFakeGraph([[
        'id' => 'mb1', 'media_type' => 'IMAGE', 'permalink' => 'https://www.instagram.com/p/MBBB1111/',
        'timestamp' => '2026-09-01T10:00:00+0000', 'media_url' => 'https://scontent.cdninstagram.com/mb.jpg',
        'like_count' => 7, 'comments_count' => 1,
    ]], mbPng(200, 200));

    app(InstagramSync::class)->run();

    $local = (string) InstagramPost::query()->where('remote_id', 'mb1')->value('local_path');

    expect($local)->not->toBe('');

    $row = Media::query()->where('path', ltrim($local, '/'))->first();

    expect($row)->not->toBeNull()
        ->and($row->mime)->toBe('image/png')
        ->and($row->width)->toBe(200)
        ->and($row->height)->toBe(200);

    expect(MediaBackfill::run())->toBe(0);
});

it('takes the library row away with the file when Instagram replaces or prunes a picture', function () {
    /*
     * ── THE HALF THAT WOULD HAVE MADE THIS WORSE THAN DOING NOTHING ─────────
     *
     * InstagramSync unlinks files in two places: storeImage() deletes the
     * picture it is replacing, and prune() deletes the picture of every post
     * that has dropped off the feed. Registering on write WITHOUT forgetting
     * here would leave a `media` row pointing at nothing every single time
     * either happens — a permanently broken tile in the library that no screen
     * can clear, growing by one on every refresh, on a host where the owner
     * cannot reach the table. prune() runs on EVERY refresh, so it would have
     * leaked continuously.
     *
     * That is exactly the failure MediaRegistrar::forget() was written for, and
     * its docblock settles the reasoning: the file is going regardless, so
     * keeping the row would not save the image, it would only hide that it is
     * gone.
     *
     * MUTATION NOTE. Delete `MediaRegistrar::forget((string) $post->local_path);`
     * from InstagramSync::prune() and the second half is red — the file is
     * unlinked, the post is deleted and the library row survives pointing at a
     * 404. Delete the `MediaRegistrar::forget($replacing);` call from
     * storeImage() and the FIRST half is red in the same way. RUN: red for each,
     * separately.
     */
    mbConnected();

    // One post, fetched twice, with a different picture the second time. The
    // stored name is derived from the remote id, so the extension change is what
    // makes the second file a different path from the first.
    mbFakeGraph([[
        'id' => 'mb2', 'media_type' => 'IMAGE', 'permalink' => 'https://www.instagram.com/p/MBBB2222/',
        'timestamp' => '2026-09-02T10:00:00+0000', 'media_url' => 'https://scontent.cdninstagram.com/a.jpg',
    ]], mbPng(120, 120));

    app(InstagramSync::class)->run();

    $first = ltrim((string) InstagramPost::query()->where('remote_id', 'mb2')->value('local_path'), '/');

    expect(Media::query()->where('path', $first)->count())->toBe(1);

    mbFakeGraph([[
        'id' => 'mb2', 'media_type' => 'IMAGE', 'permalink' => 'https://www.instagram.com/p/MBBB2222/',
        'timestamp' => '2026-09-02T10:00:00+0000', 'media_url' => 'https://scontent.cdninstagram.com/a.jpg',
    ]], mbJpeg(120, 120));

    app(InstagramSync::class)->run();

    $second = ltrim((string) InstagramPost::query()->where('remote_id', 'mb2')->value('local_path'), '/');

    expect($second)->not->toBe($first)
        // The replaced file is gone from disk AND from the library. A row here
        // would be a broken tile nothing could clear.
        ->and(is_file(public_path($first)))->toBeFalse()
        ->and(Media::query()->where('path', $first)->count())->toBe(0)
        ->and(Media::query()->where('path', $second)->count())->toBe(1);

    /* ── and the prune branch, which runs on every single refresh ────────── */

    // A second, older post arrives so that prune() has something to drop: it
    // only removes posts newer than the oldest one still in the feed.
    mbFakeGraph([
        [
            'id' => 'mb2', 'media_type' => 'IMAGE', 'permalink' => 'https://www.instagram.com/p/MBBB2222/',
            'timestamp' => '2026-09-02T10:00:00+0000', 'media_url' => 'https://scontent.cdninstagram.com/a.jpg',
        ],
        [
            'id' => 'mb3', 'media_type' => 'IMAGE', 'permalink' => 'https://www.instagram.com/p/MBBB3333/',
            'timestamp' => '2026-09-03T10:00:00+0000', 'media_url' => 'https://scontent.cdninstagram.com/b.jpg',
        ],
    ], mbJpeg(120, 120));

    app(InstagramSync::class)->run();

    $doomed = ltrim((string) InstagramPost::query()->where('remote_id', 'mb3')->value('local_path'), '/');

    expect(Media::query()->where('path', $doomed)->count())->toBe(1);

    // mb3 drops off the feed. Its file is unlinked; its row must go with it.
    mbFakeGraph([[
        'id' => 'mb2', 'media_type' => 'IMAGE', 'permalink' => 'https://www.instagram.com/p/MBBB2222/',
        'timestamp' => '2026-09-02T10:00:00+0000', 'media_url' => 'https://scontent.cdninstagram.com/a.jpg',
    ]], mbJpeg(120, 120));

    app(InstagramSync::class)->run();

    expect(InstagramPost::query()->where('remote_id', 'mb3')->count())->toBe(0)
        ->and(is_file(public_path($doomed)))->toBeFalse()
        ->and(Media::query()->where('path', $doomed)->count())->toBe(0);
});

/* ═══════════════════════════════════ C · what this lane did NOT close ════ */

it('records that the importer may write a type the library cannot catalogue', function () {
    /*
     * A GAP NAMED RATHER THAN FIXED, pinned so it cannot widen unnoticed.
     *
     * MediaSideloader::SAFE_TYPES permits `image/avif`; MediaRegistrar::EXT_MIME
     * does not carry `avif`. So an AVIF product photograph is fetched, written
     * into the web root, re-pointed onto the product row and served — and
     * record() returns null for it and the walk skips it. It is the same "two
     * lists, one of them short" shape as the defect this lane fixed, one type
     * along, and WordPress 6.5+ does produce AVIF.
     *
     * NOT FIXED HERE BECAUSE IT IS NOT ONLY THIS LANE'S CALL. EXT_MIME is also
     * what the shared media picker offers for every image field in the console —
     * brand logos, category images, the SEO share image — so adding a type to it
     * widens what a brand logo may be, on every screen at once. That is a rule 1
     * decision with the owner's name on it, and the change is one line once he
     * has made it. This lane's report puts it to him.
     *
     * WHAT THIS CASE IS FOR is the drift: if a later lane adds `avif` to one
     * list it must add it to the other, and this goes red until it does.
     *
     * MUTATION NOTE. Add `'avif' => 'image/avif'` to MediaRegistrar::EXT_MIME
     * and this is red — which is the correct signal, and the fix is to delete
     * this case and say so in the commit. RUN: red.
     */
    $sideloadable = [];

    foreach (MediaSideloader::SAFE_TYPES as $mime => $extensions) {
        foreach ($extensions as $extension) {
            $sideloadable[$extension] = $mime;
        }
    }

    $missing = array_keys(array_diff_key($sideloadable, MediaRegistrar::EXT_MIME));

    sort($missing);

    expect($missing)->toBe(['avif']);
});

/* ══════════════════════════════════════════════════ Instagram harness ════ */

/** App id + secret saved, and a token with 60 days on it. */
function mbConnected(): void
{
    $service = app(SettingsService::class);
    $service->setModule(InstagramSettings::MODULE, true);
    SettingsService::forgetMemo();
    Setting::flushMap();
    Cache::flush();

    InstagramCredentials::saveApp('1234567890123456', 'abcdef0123456789abcdef0123456789');
    InstagramCredentials::saveToken('a-very-long-lived-token', 60 * 86400, '17841400000000000');
}

/**
 * The Graph endpoints, plus every image download answering with real bytes.
 *
 * REAL ENCODED BYTES AND NOT A PLACEHOLDER, for the reason InstagramProfileTest
 * states in the same place: storeImage() decides what a file is from
 * `getimagesizefromstring()` on the BODY, so a fake returning 'x' would be
 * refused — correctly — and the case would be asserting the refusal path while
 * claiming to assert the happy one. Here the bytes also carry the DIMENSIONS
 * being asserted.
 *
 * @param  list<array<string, mixed>>  $media
 */
function mbFakeGraph(array $media, string $image): void
{
    $type = str_starts_with($image, "\x89PNG") ? 'image/png' : 'image/jpeg';

    /*
     * BOTH LINES ARE NEEDED AND THE SECOND DOES THE WORK — InstagramProfileTest
     * settled this and the reasoning is its, not a guess: the client factory is
     * a container SINGLETON, so clearing the facade's own resolved instance
     * hands back the very same factory with the very same stubs still on it.
     * Forgetting the binding is what makes the next fake start from an empty
     * list, and these cases re-fake between runs to change the picture.
     */
    app()->forgetInstance(\Illuminate\Http\Client\Factory::class);
    Http::clearResolvedInstances();

    Http::fake([
        // `*/me`, not `me`: the client puts a Graph API VERSION segment in front
        // of the path, so a pattern without the wildcard falls through to the
        // image catch-all and the sync reports "the response was not JSON".
        'graph.instagram.com/*/me/media*' => Http::response(['data' => $media]),
        'graph.instagram.com/*/me*' => Http::response([
            'id' => '17841400000000000', 'username' => 'kbeauty.bliss',
            'name' => 'K-Beauty Bliss', 'account_type' => 'BUSINESS',
            'followers_count' => 1234, 'media_count' => count($media),
        ]),
        'graph.instagram.com/access_token*' => Http::response([
            'access_token' => 'long-token', 'expires_in' => 5184000,
        ]),
        'graph.instagram.com/refresh_access_token*' => Http::response([
            'access_token' => 'fresher', 'expires_in' => 5184000,
        ]),
        'api.instagram.com/oauth/access_token' => Http::response([
            'access_token' => 'short', 'user_id' => '17841400000000000',
        ]),
        '*' => Http::response($image, 200, ['Content-Type' => $type]),
    ]);
}

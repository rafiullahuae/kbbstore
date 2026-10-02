<?php

declare(strict_types=1);

/**
 * THE PICTURE PASS, EXERCISED END TO END — Lane PX.
 *
 * The owner is about to import his real WordPress shop and then switch the old
 * site off, after which its pictures are gone for good. Nothing had ever run
 * the picture pass against the fixture export end to end. Lane PX did — a fake
 * old site on `php -S` with a TLS front and a logging proxy, the fixture export
 * imported into a fresh SQLite shop, `MediaSideloader` driven batch after batch,
 * then every text column of every table scanned for the old host — and every
 * case below is a defect that run found, pinned here so it cannot come back.
 * docs/PX-PICTURES-EXERCISED.md has the measured table.
 *
 * Each case says what the defect looked like ON THE SHOP and carries a
 * MUTATION NOTE: undo the fix it names and this case is red. Every note was
 * run.
 *
 * REAL BYTES THROUGHOUT. The sniffer reads magic numbers and the end-to-end
 * case compares sha256, so a string that merely looks like an image would test
 * nothing. Http::fake handlers are CLOSURES: a shared Http::response() body is
 * at EOF after the first read (docs/GD-MEDIA-SIDELOADER.md §5).
 *
 * `->not->toContain($needle, $message)` is not used: toContain is variadic and
 * the message becomes a second needle (CLAUDE.md). Absence is str_contains()
 * into toBeFalse(), array_diff, or Http::assertNothingSent().
 */

use App\Models\Post;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Review;
use App\Models\Setting;
use App\Services\Import\ImportOptions;
use App\Services\Import\ImportRunner;
use App\Services\Import\MediaAudit;
use App\Services\Import\MediaSideloader;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Response as Psr7Response;
use GuzzleHttp\Psr7\Utils as Psr7Utils;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

const PX_OLD = 'https://kbeautybliss.com/wp-content/uploads/';

/** A real, decodable image whose bytes depend on its path, so a mix-up is visible. */
function pxImage(string $path): string
{
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $seed = crc32($path);
    $im = imagecreatetruecolor(32, 32);
    imagefilledrectangle($im, 0, 0, 31, 31, imagecolorallocate($im, $seed & 0xFF, ($seed >> 8) & 0xFF, ($seed >> 16) & 0xFF));
    ob_start();
    $ext === 'png' ? imagepng($im) : ($ext === 'webp' ? imagewebp($im) : imagejpeg($im, null, 90));
    imagedestroy($im);

    return (string) ob_get_clean();
}

function pxType(string $path): string
{
    return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
        'png' => 'image/png',
        'webp' => 'image/webp',
        default => 'image/jpeg',
    };
}

/**
 * The old site: every uploads path answers with pxImage() of that path, except
 * the ones named in $missing, which 404.
 *
 * @param  list<string>  $missing  uploads-relative paths
 */
function pxOldSite(array $missing = []): void
{
    /*
     * Http::fake() APPENDS stubs and the first match wins, while it RESETS what
     * was recorded. So the list of missing files is read at request time from
     * one place, and calling this again changes what the old site answers and
     * starts a fresh record — rather than leaving the first call's answers in
     * force behind a second stub that is never reached.
     */
    $GLOBALS['pxMissing'] = $missing;

    Http::fake(function (Request $request) {
        $missing = $GLOBALS['pxMissing'] ?? [];
        $path = rawurldecode((string) parse_url($request->url(), PHP_URL_PATH));
        $relative = preg_replace('~^.*?wp-content/uploads/~', '', $path);

        if (in_array($relative, $missing, true) || ! str_contains($path, 'wp-content/uploads/')) {
            return Http::response('<!DOCTYPE html><html><body>Not Found</body></html>', 404, ['Content-Type' => 'text/html']);
        }

        return Http::response(pxImage($relative), 200, ['Content-Type' => pxType($relative)]);
    });
}

function pxClean(): void
{
    foreach (['wp-content', 'uploads'] as $root) {
        if (is_dir(public_path($root))) {
            File::deleteDirectory(public_path($root));
        }
    }
}

function pxImportFixture(): void
{
    $dir = base_path('tests/Fixtures/kbb-export');
    $manifest = json_decode((string) file_get_contents($dir.'/manifest.json'), true);

    (new ImportRunner)->run(new ImportOptions(
        directory: $dir,
        sourceTimezone: $manifest['source']['timezone'],
        adoptBySlug: true,
    ));
}

/** A sideloader whose DNS is whatever the test says, and whose file deadline is short. */
function pxSideloader(array $dns = [], int $fileSeconds = MediaSideloader::MAX_FILE_SECONDS): MediaSideloader
{
    return new MediaSideloader(new MediaAudit, null, fn (string $host): array => $dns[$host] ?? [], $fileSeconds);
}

function pxProduct(string $image, string $slug = 'px-serum', array $extra = []): Product
{
    return Product::query()->create(array_merge([
        'name' => 'PX '.$slug,
        'slug' => $slug,
        'price' => 1000,
        'status' => 'publish',
        'is_visible' => true,
        'stock_status' => 'instock',
        'image' => $image,
    ], $extra));
}

/**
 * Every row, in every table except the sideloader's own ledger, that still
 * names the old host's uploads. Engine-agnostic. The json columns store
 * `https:\/\/…`, so the needle is matched on the host and the folder apart.
 *
 * @return array<string, int>
 */
function pxStillOnOldHost(): array
{
    $out = [];

    foreach (Schema::getTables() as $table) {
        $name = $table['name'];

        if (in_array($name, [MediaSideloader::ITEMS, MediaSideloader::RUNS, 'migrations'], true)) {
            continue;
        }

        foreach (Schema::getColumns($name) as $column) {
            if (! preg_match('/char|text|json|clob/i', (string) $column['type_name'])) {
                continue;
            }

            $n = DB::table($name)->where($column['name'], 'like', '%kbeautybliss.com%wp-content%uploads%')->count();

            if ($n > 0) {
                $out[$name.'.'.$column['name']] = $n;
            }
        }
    }

    ksort($out);

    return $out;
}

beforeEach(function (): void {
    pxClean();
    Setting::query()->updateOrCreate(['key' => 'site_url'], ['value' => 'https://kbb.test']);
    config(['app.url' => 'https://kbb.test']);
});

afterEach(function (): void {
    pxClean();
});

/* ========================================================================== */
/*  THE WHOLE PASS, ON THE FIXTURE EXPORT                                      */
/* ========================================================================== */

it('brings every picture in the fixture export across, byte for byte, and names the one that 404ed', function () {
    /*
     * ON THE SHOP: after the import and a "finished" picture pass, a whole-
     * database scan still found the old host in `products.description` (the
     * picture in the serum's copy), `products.seo` (its Yoast share image) and
     * — on the rig, where the variation had a thumbnail — in
     * `product_variants.image`, because MediaAudit and MediaRewrite did not
     * know those places existed. The pages rendered perfectly until the old
     * site went off.
     *
     * MUTATION NOTE, RUN: remove the `products.seo.og_image` yield from
     * MediaAudit::references() — red, `products.seo` is in the scan. Remove
     * [Product::class, 'products', 'description'] from
     * DocumentMediaRewrite::DOCUMENTS — red, `products.description`.
     */
    pxImportFixture();

    $gone = '2019/03/ginseng-serum-3,-detail.jpg';
    pxOldSite([$gone]);

    $this->artisan('kbb:import-media-fetch', ['--host' => ['kbeautybliss.com']])
        ->expectsOutputToContain('did not come across')
        ->expectsOutputToContain(PX_OLD.$gone)
        ->expectsOutputToContain('answered 404')
        ->assertExitCode(1);

    // Only the one that 404ed still names the old site — in the gallery that
    // carries it. Every other column of every table is clear.
    expect(pxStillOnOldHost())->toBe(['products.images' => 1]);

    $serum = Product::query()->where('wc_id', 4021)->firstOrFail();
    expect($serum->images)->toContain(PX_OLD.$gone);

    // Every picture that IS local is on disk, under the path the row names,
    // and is the same bytes the old site served.
    $checked = 0;

    foreach ((new MediaAudit)->audit() as $row) {
        if ($row['verdict'] === MediaAudit::REMOTE) {
            continue;
        }

        expect($row['verdict'])->toBe(MediaAudit::PRESENT, $row['owner'].' '.$row['field'].' '.$row['url']);

        $relative = preg_replace('~^.*?wp-content/uploads/~', '', $row['path']);
        expect(hash_file('sha256', public_path(ltrim($row['path'], '/'))))
            ->toBe(hash('sha256', pxImage($relative)), $row['url'].' is not the bytes the old site served');
        $checked++;
    }

    // 16 since exporter 1.11.0 (Lane PT): the fixture's three title-header
    // banners -- categories 15 and 22, brand 502 -- are fetched, re-pointed
    // and checked byte for byte like every other picture.
    expect($checked)->toBe(16);

    // The customers' photographs specifically — the new path, and the only
    // pictures on a shop that cannot be re-created.
    foreach (Review::query()->whereNotNull('source_id')->get() as $review) {
        foreach ((array) $review->images as $image) {
            expect(str_contains((string) $image, 'kbeautybliss.com'))->toBeFalse("review {$review->source_id}: {$image}");
        }
    }

    // And the article: cover and the picture inside the body.
    $post = Post::query()->firstOrFail();
    expect(str_contains((string) $post->cover.$post->body, 'kbeautybliss.com/wp-content'))->toBeFalse();
});

it('re-running the pass downloads nothing and rewrites no file', function () {
    pxImportFixture();
    pxOldSite();

    $this->artisan('kbb:import-media-fetch')->assertExitCode(0);

    $before = [];
    foreach (File::allFiles(public_path('wp-content/uploads')) as $file) {
        $before[$file->getPathname()] = [$file->getInode(), $file->getMTime(), hash_file('sha256', $file->getPathname())];
    }

    /*
     * The re-run as the runbook writes it, WITH --host. Measured on the
     * 671-product rig: once every row was re-pointed the catalogue named
     * kbeautybliss.com nowhere, and this exited 1 — "None of the hosts named
     * is one the catalogue's pictures are on. Nothing was fetched." — on a shop
     * whose 2,907 pictures were all here.
     *
     * MUTATION NOTE, RUN: restore `return self::FAILURE;` in that branch of
     * ImportMediaFetch::handle() — red, exit code 1.
     */
    pxOldSite();
    $this->artisan('kbb:import-media-fetch', ['--host' => ['kbeautybliss.com']])
        ->expectsOutputToContain('Every picture the catalogue names is served by this shop')
        ->assertExitCode(0);

    Http::assertNothingSent();

    $after = [];
    foreach (File::allFiles(public_path('wp-content/uploads')) as $file) {
        $after[$file->getPathname()] = [$file->getInode(), $file->getMTime(), hash_file('sha256', $file->getPathname())];
    }

    expect($after)->toBe($before)->and(count($after))->toBeGreaterThanOrEqual(10);
});

it('fetches nothing a crafted media.csv names that the catalogue does not', function () {
    /*
     * media.csv is the export's list of files, written by the old site. It is
     * NOT the fetch list: the work list is re-derived from the imported rows,
     * and media.csv is read only for its `exists` column (MediaIndex). That is
     * the property that makes a hostile media.csv harmless, so it is pinned by
     * behaviour: an export whose media.csv names the metadata service and this
     * server's loopback, imported and then fetched in full, sends neither.
     *
     * MUTATION NOTE: there is no line to delete — the guard is the absence of
     * a reader. Teach references() to read media.csv and this goes red.
     */
    $dir = storage_path('framework/testing/px-export-'.bin2hex(random_bytes(4)));
    File::copyDirectory(base_path('tests/Fixtures/kbb-export'), $dir);
    file_put_contents($dir.'/media.csv',
        "\"http://169.254.169.254/wp-content/uploads/latest.jpg\",\"1\",\"full\",\"latest.jpg\",\"yes\",\"10\",\"product\",\"4021\",\"image\"\n"
        ."\"http://127.0.0.1:6379/wp-content/uploads/x.jpg\",\"2\",\"full\",\"x.jpg\",\"yes\",\"10\",\"product\",\"4021\",\"images\"\n",
        FILE_APPEND);

    try {
        $manifest = json_decode((string) file_get_contents($dir.'/manifest.json'), true);
        $manifest['files']['media.csv']['sha256'] = hash_file('sha256', $dir.'/media.csv');
        $manifest['files']['media.csv']['bytes'] = filesize($dir.'/media.csv');
        $manifest['files']['media.csv']['rows'] = ($manifest['files']['media.csv']['rows'] ?? 0) + 2;
        file_put_contents($dir.'/manifest.json', json_encode($manifest));

        (new ImportRunner)->run(new ImportOptions(directory: $dir, sourceTimezone: 'Asia/Dubai', adoptBySlug: true));

        pxOldSite();
        $this->artisan('kbb:import-media-fetch');

        Http::assertNotSent(fn (Request $r): bool => ! str_starts_with($r->url(), 'https://kbeautybliss.com/')
            && ! str_starts_with($r->url(), 'http://kbeautybliss.com/'));
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'layla-selfie.jpg'));
    } finally {
        File::deleteDirectory($dir);
    }
});

/* ========================================================================== */
/*  RESUME: A FILE THAT LANDED MUST BE RE-POINTED, HOWEVER IT LANDED          */
/* ========================================================================== */

it('re-points a picture a killed batch landed but never re-pointed', function () {
    /*
     * ON THE SHOP: the rig SIGKILLed a run while one slow picture was
     * downloading. Five photographs were already on disk with the right bytes
     * and `fetched` in the ledger — re-pointing happens at the END of a batch,
     * which never came. Every later batch saw the files and skipped them, so
     * the product, gallery and review rows went on naming kbeautybliss.com for
     * good, while plan() counted them "present" and the page could read
     * Finished.
     *
     * MUTATION NOTE, RUN: delete the `$landed[] = $reference['url']` inside
     * the is_file() branch of MediaSideloader::batch() — red: the product
     * still names the old host after the second batch.
     */
    $url = PX_OLD.'2019/03/killed.jpg';
    $product = pxProduct($url);
    $sideloader = pxSideloader();

    // The state a killed batch leaves: the bytes landed, the ledger says so,
    // the row was never touched.
    $reference = $sideloader->references()[0];
    File::ensureDirectoryExists(dirname(public_path($reference['path'])));
    file_put_contents(public_path($reference['path']), pxImage('2019/03/killed.jpg'));
    DB::table(MediaSideloader::ITEMS)->insert([
        'url_hash' => MediaSideloader::hash($url), 'url' => $url, 'host' => 'kbeautybliss.com',
        'target_path' => $reference['path'], 'state' => MediaSideloader::FETCHED, 'reason' => 'fetched',
        'bytes' => 10, 'attempts' => 1, 'attempted_at' => now(), 'fetched_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // It is unfinished work, so the page's Fetch button stays live.
    expect($sideloader->plan()['remaining'])->toBe(1)
        ->and($sideloader->plan()['to_repoint'])->toBe(1);

    pxOldSite();
    $result = $sideloader->batch();

    Http::assertNothingSent(); // nothing re-downloaded
    expect($result['repointed']['rows'])->toBe(1)
        ->and($product->fresh()->image)->toBe('/wp-content/uploads/2019/03/killed.jpg')
        ->and($sideloader->plan()['remaining'])->toBe(0);
});

it('re-points the http:// spelling of a picture it fetched over https', function () {
    /*
     * ON THE SHOP: review 8102 in the fixture names
     * `http://kbeautybliss.com/…/skincare-category.jpg`; the category names
     * the same file over https. One file, two URLs. The https one was fetched
     * and re-pointed; the http one found the file already there and was
     * skipped — so the customer's photograph kept loading from the old site.
     *
     * MUTATION NOTE, RUN: same as above — the is_file() branch's `$landed[]`.
     * Red: the review still names http://kbeautybliss.com.
     */
    $https = PX_OLD.'2020/01/skincare-category.jpg';
    $http = 'http://kbeautybliss.com/wp-content/uploads/2020/01/skincare-category.jpg';

    pxProduct($https, 'px-cat');
    $review = Review::query()->create([
        'product_id' => pxProduct(PX_OLD.'2020/01/other.jpg', 'px-other')->id,
        'author_name' => 'Anon', 'rating' => 4, 'content' => 'Good', 'status' => 'approved',
        'images' => [$http],
    ]);

    pxOldSite();
    $sideloader = pxSideloader();

    for ($i = 0; $i < 3; $i++) {
        $sideloader->batch();
    }

    expect($review->fresh()->images)->toBe(['/wp-content/uploads/2020/01/skincare-category.jpg']);
    // One download for the one file, not two.
    Http::assertSentCount(2);
});

/* ========================================================================== */
/*  SSRF: WHERE A REQUEST MAY GO                                               */
/* ========================================================================== */

it('sends no request to a loopback, private, metadata or internal address named by a picture row', function () {
    /*
     * ON THE SHOP: one edited cell in products.csv made this server request
     * http://127.0.0.1:9313/…, http://169.254.169.254/… and
     * http://localhost:9313/… — the rig's logging proxy recorded every one —
     * and each was filed as a transient FAILURE, so it was re-sent on every
     * later batch.
     *
     * MUTATION NOTE, RUN: make hostRefusal() return null — red, the recorder
     * sees requests and the ledger says `failed` instead of `refused`.
     */
    $urls = [
        'http://127.0.0.1/wp-content/uploads/a.jpg',
        'http://169.254.169.254/wp-content/uploads/latest.jpg',
        'http://localhost/wp-content/uploads/b.jpg',
        'http://10.0.0.5/wp-content/uploads/c.jpg',
        'http://[::1]/wp-content/uploads/d.jpg',
        'http://[::ffff:127.0.0.1]/wp-content/uploads/d2.jpg',
        'http://100.64.0.9/wp-content/uploads/e.jpg',
        'http://metadata.google.internal/wp-content/uploads/f.jpg',
        'http://intranet/wp-content/uploads/g.jpg',
        'http://2130706433/wp-content/uploads/h.jpg',
        // names that RESOLVE inside the network: the check is on the answer, not the spelling
        'http://127.1/wp-content/uploads/i.jpg',
        'https://rebind.example.com/wp-content/uploads/j.jpg',
    ];

    foreach ($urls as $i => $url) {
        pxProduct($url, 'px-ssrf-'.$i);
    }

    Http::fake(fn () => Http::response(pxImage('x.jpg'), 200, ['Content-Type' => 'image/jpeg']));

    $sideloader = pxSideloader(['127.1' => ['127.0.0.1'], 'rebind.example.com' => ['93.184.216.34', '10.1.2.3']]);
    $result = $sideloader->batch(['files' => 50]);

    Http::assertNothingSent();

    expect($result['refused'])->toBe(count($urls))
        ->and(DB::table(MediaSideloader::ITEMS)->where('state', MediaSideloader::REFUSED)->count())->toBe(count($urls))
        ->and(is_dir(public_path('wp-content')))->toBeFalse();

    // Refused is permanent: the next batch sends nothing either.
    $sideloader->batch(['files' => 50]);
    Http::assertNothingSent();
});

it('refuses a non-default port and a foreign scheme on the old host itself', function () {
    /*
     * ON THE SHOP: `https://kbeautybliss.com:9313/…` reached the proxy as
     * CONNECT kbeautybliss.com:9313 — same host, a different service — and
     * `ftp://kbeautybliss.com/…` reached Guzzle, which failed it as
     * "The scheme 'ftp' is not supported", filed as a retryable failure.
     *
     * MUTATION NOTE, RUN: drop the port check in hostRefusal() — red, one
     * request recorded to :9313.
     */
    pxProduct('https://kbeautybliss.com:9313/wp-content/uploads/2022/02/p.jpg', 'px-port');
    pxProduct('ftp://kbeautybliss.com/wp-content/uploads/2022/02/f.jpg', 'px-ftp');
    pxProduct('https://kbeautybliss.com:443/wp-content/uploads/2022/02/ok.jpg', 'px-443');

    pxOldSite();
    $result = pxSideloader(['kbeautybliss.com' => ['93.184.216.34']])->batch();

    expect($result['refused'])->toBe(2)->and($result['fetched'])->toBe(1);
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $r): bool => str_contains($r->url(), ':443/') || str_contains($r->url(), '/2022/02/ok.jpg'));
});

it('will not follow a same-host redirect onto another port', function () {
    /*
     * MUTATION NOTE, RUN: remove the `hostRefusal($next)` check in fetchOne()'s
     * redirect loop — red: a second request goes to kbeautybliss.com:22.
     */
    pxProduct(PX_OLD.'2022/02/hop.jpg', 'px-hop');

    Http::fake(function (Request $request) {
        if (str_contains($request->url(), ':22/')) {
            return Http::response(pxImage('hop.jpg'), 200, ['Content-Type' => 'image/jpeg']);
        }

        return Http::response('', 302, ['Location' => 'https://kbeautybliss.com:22/wp-content/uploads/2022/02/hop.jpg']);
    });

    $result = pxSideloader()->batch();

    Http::assertSentCount(1);
    expect($result['fetched'])->toBe(0)
        ->and((string) $result['results'][0]['reason'])->toContain('port 22')
        ->and(is_file(public_path('wp-content/uploads/2022/02/hop.jpg')))->toBeFalse();
});

it('classifies addresses the way the guard needs', function () {
    foreach (['127.0.0.1', '10.0.0.1', '172.16.5.4', '192.168.1.1', '169.254.169.254', '100.64.0.1', '0.0.0.0', '::1', 'fe80::1', 'fc00::1', '::ffff:10.0.0.1'] as $private) {
        expect(MediaSideloader::isPublicAddress($private))->toBeFalse($private);
    }

    foreach (['93.184.216.34', '8.8.8.8', '2606:4700::1111'] as $public) {
        expect(MediaSideloader::isPublicAddress($public))->toBeTrue($public);
    }
});

/* ========================================================================== */
/*  A SLOW OLD HOST                                                            */
/* ========================================================================== */

it('abandons a picture the old host is still trickling after the file deadline', function () {
    /*
     * ON THE SHOP: the rig's old site sent one byte a second. READ_TIMEOUT is
     * a per-READ timeout under Guzzle's StreamHandler (which `stream => true`
     * selects) and never fires while bytes keep arriving, so one 1.8 KB file
     * held a batch that promises to stop after 15 s for the whole 150 s the
     * rig allowed — half an hour, at that rate. Under PHP-FPM the wait is in a
     * syscall, which max_execution_time does not count.
     *
     * MUTATION NOTE, RUN: delete the MAX_FILE_SECONDS check in the read loop —
     * red: the whole body is read (≈2 s here) and the reason is about the
     * bytes not being an image, not about the deadline.
     */
    pxProduct(PX_OLD.'2022/02/slow.jpg', 'px-slow');

    Http::fake(function () {
        $sent = 0;
        $stream = FnStream::decorate(Psr7Utils::streamFor(''), [
            'read' => function () use (&$sent): string {
                usleep(100_000);
                $sent++;

                return $sent === 1 ? "\xFF\xD8\xFF" : 'x';
            },
            'eof' => function () use (&$sent): bool {
                return $sent >= 20;
            },
        ]);

        return Create::promiseFor(new Psr7Response(200, ['Content-Type' => 'image/jpeg'], $stream));
    });

    $began = microtime(true);
    $result = pxSideloader([], 1)->batch();
    $took = microtime(true) - $began;

    expect($result['failed'])->toBe(1)
        ->and((string) $result['results'][0]['reason'])->toContain('still sending this picture after 1s')
        ->and($took)->toBeLessThan(1.9)
        ->and(glob(public_path('wp-content/uploads/2022/02/*')) ?: [])->toBe([]);
});

it('names a host that stopped sending in words, not as "Unable to read from stream"', function () {
    /*
     * ON THE SHOP: the rig measured exactly that phrase as the entire reason
     * shown to the owner for a picture whose host went silent part-way.
     *
     * MUTATION NOTE, RUN: remove the try/catch round $body->read() — the
     * reason is the bare transport message again and this is red.
     */
    pxProduct(PX_OLD.'2022/02/stall.jpg', 'px-stall');

    Http::fake(fn () => Create::promiseFor(new Psr7Response(200, ['Content-Type' => 'image/jpeg'], FnStream::decorate(Psr7Utils::streamFor(''), [
        'read' => fn () => throw new RuntimeException('Unable to read from stream'),
        'eof' => fn () => false,
    ]))));

    $result = pxSideloader()->batch();

    expect((string) $result['results'][0]['reason'])->toStartWith('kbeautybliss.com stopped sending this picture part-way');
});

it('clears a temporary file a killed request left behind, and only a stale one', function () {
    /*
     * ON THE SHOP: after the SIGKILL the web root held
     * `.kbb-sideload-1fd565176331850c.part`, and nothing ever removed it —
     * fetchOne()'s `finally` does not run when the process is killed.
     *
     * MUTATION NOTE, RUN: remove the sweepStaleParts() call — red, the stale
     * part survives.
     */
    $dir = public_path('wp-content/uploads/2022/02');
    File::ensureDirectoryExists($dir);
    $stale = $dir.'/.kbb-sideload-'.str_repeat('a', 16).'.part';
    $fresh = $dir.'/.kbb-sideload-'.str_repeat('b', 16).'.part';
    file_put_contents($stale, 'half');
    file_put_contents($fresh, 'live');
    touch($stale, time() - MediaSideloader::STALE_PART_SECONDS - 60);

    pxProduct(PX_OLD.'2022/02/next.jpg', 'px-next');
    pxOldSite();
    pxSideloader()->batch();

    expect(is_file($stale))->toBeFalse()
        ->and(is_file($fresh))->toBeTrue()
        ->and(is_file($dir.'/next.jpg'))->toBeTrue();
});

/* ========================================================================== */
/*  THE PLACES A PRODUCT KEEPS A PICTURE                                       */
/* ========================================================================== */

it('fetches and re-points a variation photograph, a picture in the copy and the share image', function () {
    /*
     * ON THE SHOP: all three kept loading from the old site after a finished
     * pass — the option swatch and every basket thumbnail of that option, the
     * picture inside the description tab, and og:image.
     *
     * MUTATION NOTE, RUN: remove [ProductVariant::class, 'product_variants',
     * 'image', false] from MediaRewrite::COLUMNS — red at the variant. Remove
     * the ProductVariant loop from MediaAudit::references() — red, it is never
     * fetched (Http::assertSent fails).
     */
    $product = pxProduct(PX_OLD.'2022/02/main.jpg', 'px-three', [
        'description' => '<p>Look:</p><img src="'.PX_OLD.'2022/02/in-copy.jpg" alt="">',
        'seo' => ['og_image' => PX_OLD.'2022/02/share.jpg', 'title' => 'T'],
    ]);
    $variant = ProductVariant::query()->create([
        'product_id' => $product->id, 'sku' => 'PX-50', 'price' => 100, 'stock_status' => 'instock',
        'image' => PX_OLD.'2022/02/swatch.jpg',
    ]);

    pxOldSite();
    pxSideloader()->batch(['files' => 20]);

    foreach (['main.jpg', 'in-copy.jpg', 'share.jpg', 'swatch.jpg'] as $file) {
        Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/2022/02/'.$file));
    }

    $product->refresh();

    expect($variant->fresh()->image)->toBe('/wp-content/uploads/2022/02/swatch.jpg')
        ->and($product->seo['og_image'])->toBe('/wp-content/uploads/2022/02/share.jpg')
        ->and($product->seo['title'])->toBe('T')
        ->and(str_contains((string) $product->description, 'src="/wp-content/uploads/2022/02/in-copy.jpg"'))->toBeTrue()
        ->and(pxStillOnOldHost())->toBe([]);
});

/* ========================================================================== */
/*  THE LOOP: A BATCH OF FAILURES IS NOT THE END WHILE UNTRIED WORK REMAINS    */
/* ========================================================================== */

it('reports untried work so a loop does not stop on a batch that only failed', function () {
    /*
     * ON THE SHOP: the live page stopped looping whenever a batch fetched and
     * refused nothing — so ten broken pictures in a row ended the run with
     * every untried picture behind them untouched. The page now also goes on
     * while `plan.untried` is above zero.
     *
     * MUTATION NOTE, RUN: drop `|| untried > 0` from the loop condition in
     * media-progress.blade.php — red on the source assertion; stop counting
     * `untried` in plan() — red on the numbers.
     */
    foreach (range(1, 3) as $i) {
        pxProduct(PX_OLD.'2022/02/gone-'.$i.'.jpg', 'px-gone-'.$i);
    }
    pxProduct(PX_OLD.'2022/02/fine.jpg', 'px-fine');

    pxOldSite(['2022/02/gone-1.jpg', '2022/02/gone-2.jpg', '2022/02/gone-3.jpg']);
    $first = pxSideloader()->batch(['files' => 3]);

    expect($first['fetched'])->toBe(0)
        ->and($first['plan']['untried'])->toBe(1);

    $page = (string) file_get_contents(resource_path('views/admin/media-progress.blade.php'));
    expect(substr_count($page, '|| untried > 0)'))->toBe(1);

    $second = pxSideloader()->batch(['files' => 3]);
    expect($second['fetched'])->toBe(1)
        ->and(is_file(public_path('wp-content/uploads/2022/02/fine.jpg')))->toBeTrue();
});

it('tries each failure once per console run, and again only when asked', function () {
    pxProduct(PX_OLD.'2022/02/gone.jpg', 'px-gone');
    pxOldSite(['2022/02/gone.jpg']);

    $this->artisan('kbb:import-media-fetch')->assertExitCode(1);
    Http::assertSentCount(1);

    pxOldSite(['2022/02/gone.jpg']);
    $this->artisan('kbb:import-media-fetch')->assertExitCode(1);
    Http::assertNothingSent();

    pxOldSite();
    $this->withoutMockingConsoleOutput();
    $code = Illuminate\Support\Facades\Artisan::call('kbb:import-media-fetch', ['--retry' => true]);
    $out = Illuminate\Support\Facades\Artisan::output();
    expect(str_contains($out, 'Every picture the catalogue names is served by this shop'))->toBeTrue($out)
        ->and($code)->toBe(0);
    Http::assertSentCount(1);
});

/* ========================================================================== */
/*  A PICTURE THAT DID NOT COME ACROSS DRAWS THE PLACEHOLDER                    */
/* ========================================================================== */

it('draws the placeholder, not a broken image, for a product whose picture failed', function () {
    /*
     * ON THE SHOP: measured in Chromium with the old site off — a white
     * 390×390 frame (612×612 at 1280) holding the broken-image icon and
     * "COSRX PX Missing Picture", the shop's own placeholder hidden behind it.
     * docs/px-shots/before-px-missing-390.png.
     *
     * MUTATION NOTE, RUN: remove the LostPictures filter from
     * ProductController::gallery() — red, the <img> carries the old address
     * and the caption is hidden.
     */
    $gone = PX_OLD.'2022/02/px-missing.jpg';
    $product = pxProduct($gone, 'px-missing');
    $healthy = pxProduct(PX_OLD.'2022/02/fine.jpg', 'px-fine');

    pxOldSite(['2022/02/px-missing.jpg']);
    pxSideloader()->batch();

    $html = $this->get('/product/px-missing/')->assertOk()->getContent();

    expect(str_contains($html, 'id="gmainImg"'))->toBeFalse()
        ->and(str_contains($html, 'src="'.$gone.'"'))->toBeFalse()
        ->and(preg_match('/<span class="cap" id="gcap" data-brand="[^"]*"\s*>/', $html))->toBe(1);

    // The row itself is untouched: it is the one clue to what the picture was.
    expect($product->fresh()->image)->toBe($gone);

    // A product whose picture came across draws it exactly as before.
    $fine = $this->get('/product/px-fine/')->assertOk()->getContent();
    expect(str_contains($fine, 'src="/wp-content/uploads/2022/02/fine.jpg"'))->toBeTrue()
        ->and($healthy->fresh()->image)->toBe('/wp-content/uploads/2022/02/fine.jpg');
});

it('draws the placeholder tile for that product on a listing', function () {
    /*
     * MUTATION NOTE, RUN: put `$img = $product->image;` back in
     * components/product-card.blade.php — red, the tile's <img> names the dead
     * address. Before the fix the rig's /collections/toners/ drew 7 broken
     * images at 390 and 10 at 1280.
     */
    $gone = PX_OLD.'2022/02/px-missing.jpg';
    $product = pxProduct($gone, 'px-missing');

    pxOldSite(['2022/02/px-missing.jpg']);
    pxSideloader()->batch();

    $html = view('components.product-card', ['product' => $product->fresh()])->render();

    expect(str_contains($html, $gone))->toBeFalse()
        ->and(str_contains($html, 'kbb-card-ph'))->toBeTrue();
});

it('costs no query for a local or own-host picture', function () {
    expect(App\Support\LostPictures::isLost('/wp-content/uploads/a.jpg'))->toBeFalse()
        ->and(App\Support\LostPictures::isLost('https://kbb.test/uploads/a.jpg'))->toBeFalse();

    DB::enableQueryLog();
    DB::flushQueryLog();

    App\Support\LostPictures::isLost('/uploads/x.jpg');
    App\Support\LostPictures::isLost('https://kbb.test/uploads/y.jpg');

    expect(count(DB::getQueryLog()))->toBe(0);
});

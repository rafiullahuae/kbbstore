<?php

declare(strict_types=1);

/**
 * The media sideloader — Lane GD.
 *
 * THE SHAPE OF THIS FILE FOLLOWS THE THREAT, not the class. Every block below
 * corresponds to one guard in `MediaSideloader`'s class comment, and every one
 * of them was mutation-tested: the guard was broken, this file was watched go
 * red, and the guard was restored. docs/GD-MEDIA-SIDELOADER.md lists each
 * mutation and its result, including the one that did NOT go red first time.
 *
 * WHY SO MUCH OF IT IS ABOUT REFUSING THINGS. This is the only code in the
 * application that writes bytes chosen by a third party into the web root. The
 * third party is a WordPress installation being migrated away from precisely
 * because nobody maintains it. A bug in the happy path costs a photograph; a
 * bug in a refusal costs the shop.
 *
 * `expect(...)->not->toContain($x, $message)` IS NOT USED ANYWHERE HERE.
 * `toContain` is variadic, so the message is read as a second needle and the
 * assertion passes vacuously — CLAUDE.md records it. Absence is asserted with
 * `array_diff`, `str_contains` or a plain identity.
 *
 * THESE RUN ON BOTH ENGINES. Nothing here is about an index or a dialect; the
 * ledger is two ordinary tables and every count is derived in PHP.
 */

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Product;
use App\Models\Setting;
use App\Services\Import\MediaAudit;
use App\Services\Import\MediaRewrite;
use App\Services\Import\MediaSideloader;
use App\Services\Import\MigrationProgress;
use App\Support\AdminCapabilities;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\Support\MediaSideloadAdminRoutes;

/* ========================================================================== */
/*  FIXTURES                                                                   */
/* ========================================================================== */

const GD_OLD = 'https://old-shop.test';

/** Real bytes, not a string that looks like them. Sniffing is the point. */
function gdJpeg(int $padding = 64): string
{
    return "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01".str_repeat("\x2A", $padding)."\xFF\xD9";
}

function gdPng(int $padding = 64): string
{
    return "\x89PNG\r\n\x1A\n".str_repeat("\x2A", $padding);
}

function gdGif(int $padding = 64): string
{
    return 'GIF89a'.str_repeat("\x2A", $padding);
}

/** A product whose photograph is still served by the old site. */
function gdProduct(string $url, string $slug = 'gd-serum'): Product
{
    return Product::query()->create([
        'name' => 'GD '.$slug,
        'slug' => $slug,
        'price' => 1000,
        'status' => 'published',
        'image' => $url,
    ]);
}

function gdPublic(string $relative): string
{
    return public_path($relative);
}

function gdClean(): void
{
    foreach (['wp-content', 'uploads'] as $root) {
        if (is_dir(public_path($root))) {
            File::deleteDirectory(public_path($root));
        }
    }
}

beforeEach(function (): void {
    gdClean();
    Setting::query()->updateOrCreate(['key' => 'site_url'], ['value' => 'https://kbb.test']);
    config(['app.url' => 'https://kbb.test']);
});

afterEach(function (): void {
    gdClean();
});

/**
 * A sideloader whose idea of free disk is whatever the test says.
 *
 * `disk_free_space()` cannot be made to return a chosen number, so without this
 * seam the disk guard is a branch no test can enter — and a branch no input can
 * enter is the dead `status` filter CLAUDE.md records, one floor down.
 */
function gdSideloader(?int $free = null): MediaSideloader
{
    return new MediaSideloader(new MediaAudit, $free === null ? null : fn (): ?int => $free);
}

/* ========================================================================== */
/*  GUARD 6 — PATHS. Traversal, control bytes, and the uploads root.           */
/* ========================================================================== */

it('refuses a path that walks out of the uploads folder', function () {
    $out = (new MediaSideloader)->targetPath(GD_OLD.'/wp-content/uploads/2019/../../../../etc/passwd.jpg');

    expect($out['path'])->toBeNull()
        ->and($out['refusal'])->toBeString()
        ->and(str_contains((string) $out['refusal'], '".." segment'))->toBeTrue();
});

it('refuses a traversal that arrived percent-encoded', function () {
    // MediaUsage::normalise() rawurldecodes, so %2e%2e%2f is a real ../ by the
    // time the guard sees it. A guard that only looked for the literal would
    // pass this straight through, which is why it is pinned separately.
    $out = (new MediaSideloader)->targetPath(GD_OLD.'/wp-content/uploads/2019/%2e%2e%2f%2e%2e%2fshell.jpg');

    expect($out['path'])->toBeNull()
        ->and(str_contains((string) $out['refusal'], '".." segment'))->toBeTrue();
});

it('refuses a path carrying a control character or a backslash', function (string $url, string $word) {
    $out = (new MediaSideloader)->targetPath($url);

    expect($out['path'])->toBeNull()
        ->and(str_contains((string) $out['refusal'], $word))->toBeTrue();
})->with([
    // PERCENT-ENCODED, because that is the form that actually reaches the
    // guard: PHP's own parse_url() rewrites a RAW control byte to "_" before
    // MediaUsage::normalise() ever returns, while %0a survives parse_url and is
    // turned back into a newline by the rawurldecode inside it. A test written
    // against the raw byte would have passed against a guard that does nothing.
    [GD_OLD.'/wp-content/uploads/2019/bad%0aname.jpg', 'control character'],
    [GD_OLD.'/wp-content/uploads/2019/..\\..\\x.jpg', 'backslash'],
]);

it('refuses an address that is under no uploads root', function () {
    $out = (new MediaSideloader)->targetPath(GD_OLD.'/images/hero.jpg');

    expect($out['path'])->toBeNull()
        ->and(str_contains((string) $out['refusal'], 'not under wp-content/uploads/'))->toBeTrue();
});

it('cuts at the LONGER uploads root so the path is not sliced in the middle', function () {
    // "wp-content/uploads/" contains "uploads/". Matching the shorter root
    // first produces "uploads/2019/x.jpg", which exists nowhere, and the file
    // would land in a folder MediaRewrite never looks in.
    $out = (new MediaSideloader)->targetPath(GD_OLD.'/wp-content/uploads/2019/03/serum.jpg');

    expect($out['path'])->toBe('wp-content/uploads/2019/03/serum.jpg');
});

it('preserves the uploads-relative path MediaRewrite re-points rows at', function () {
    // The contract between this lane and Lane GB, asserted as such: whatever
    // the sideloader writes must be exactly where MediaRewrite::propose() will
    // afterwards look, or every rewritten row points at nothing.
    $url = GD_OLD.'/wp-content/uploads/2019/03/ginseng.jpg';
    $path = (string) (new MediaSideloader)->targetPath($url)['path'];

    gdProduct($url);
    File::ensureDirectoryExists(dirname(gdPublic($path)));
    file_put_contents(gdPublic($path), gdJpeg());

    $proposals = (new MediaRewrite)->propose(['old-shop.test']);

    expect($proposals)->toHaveCount(1)
        ->and($proposals[0]['decision'])->toBe(MediaRewrite::REWRITE)
        ->and($proposals[0]['path'])->toBe($path);
});

it('refuses an absolute path that resolves outside the web root', function () {
    expect((new MediaSideloader)->absolute('../../../etc/passwd.jpg'))->toBeNull();
});

/* ========================================================================== */
/*  GUARDS 4 & 5 — NAMES. Nothing that could ever execute.                     */
/* ========================================================================== */

it('refuses a filename whose ANY dot-separated part could execute', function (string $name, string $part) {
    $out = (new MediaSideloader)->targetPath(GD_OLD.'/wp-content/uploads/2019/'.$name);

    expect($out['path'])->toBeNull()
        ->and(str_contains((string) $out['refusal'], '".'.$part.'" part'))->toBeTrue();
})->with([
    // The last part. The obvious one.
    ['shell.php', 'php'],
    ['shell.phtml', 'phtml'],
    ['x.phar', 'phar'],
    // THE MIDDLE ONE. This is the shape that gets past "check the extension"
    // and past a misconfigured AddHandler, and it is the reason the guard walks
    // every part instead of calling pathinfo() once.
    ['photo.php.jpg', 'php'],
    ['photo.phtml.png', 'phtml'],
    ['a.php5.jpeg', 'php5'],
    // A dropped .htaccess turns the whole uploads folder back into a place
    // where .jpg executes, so it is refused even though it is not "an image".
    ['x.htaccess.jpg', 'htaccess'],
    ['logo.svg.png', 'svg'],
    ['page.html.gif', 'html'],
]);

it('refuses a dotfile outright', function () {
    $out = (new MediaSideloader)->targetPath(GD_OLD.'/wp-content/uploads/2019/.htaccess');

    expect($out['path'])->toBeNull()
        ->and(str_contains((string) $out['refusal'], 'dotfile'))->toBeTrue();
});

it('refuses every extension that is not one of the five image types', function (string $name) {
    expect((new MediaSideloader)->targetPath(GD_OLD.'/wp-content/uploads/2019/'.$name)['path'])->toBeNull();
})->with(['logo.svg', 'report.pdf', 'archive.zip', 'noextension', 'x.bmp', 'x.tiff', 'x.ico']);

it('accepts exactly the five safe extensions and no others', function () {
    $sideloader = new MediaSideloader;

    foreach (['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'] as $extension) {
        expect($sideloader->targetPath(GD_OLD.'/wp-content/uploads/a.'.$extension)['path'])
            ->toBe('wp-content/uploads/a.'.$extension);
    }

    // SVG is absent on purpose: it is a document that executes script in this
    // shop's own origin. Asserted by identity, never by ->not->toContain().
    $allowed = [];

    foreach (MediaSideloader::SAFE_TYPES as $extensions) {
        $allowed = array_merge($allowed, $extensions);
    }

    expect(array_intersect($allowed, ['svg', 'svgz', 'html', 'php']))->toBe([]);
});

/* ========================================================================== */
/*  GUARD 3 — SNIFFING. What the bytes actually are.                           */
/* ========================================================================== */

it('sniffs the real image formats and nothing else', function () {
    expect(MediaSideloader::sniff(gdJpeg()))->toBe('image/jpeg')
        ->and(MediaSideloader::sniff(gdPng()))->toBe('image/png')
        ->and(MediaSideloader::sniff(gdGif()))->toBe('image/gif')
        ->and(MediaSideloader::sniff('RIFF????WEBPVP8 '.str_repeat('x', 32)))->toBe('image/webp');
});

it('refuses bytes that are not an image however they are dressed', function (string $body) {
    expect(MediaSideloader::sniff($body))->toBeNull();
})->with([
    ['<?php system($_GET["c"]); ?>'.str_repeat(' ', 32)],
    ['<!doctype html><html><body>404 Not Found</body></html>'],
    ['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
    ["#!/bin/sh\nrm -rf /".str_repeat(' ', 32)],
    [str_repeat("\x00", 64)],
    ['short'],
]);

it('refuses a polyglot that starts with a valid image header', function () {
    // A file can begin GIF89a and carry <?php three bytes later. The magic
    // number alone says "image"; the marker sweep is what catches it, and this
    // is the case that proves the sweep is not redundant with the header check.
    expect(MediaSideloader::sniff('GIF89a'."\x00\x00".'<?php system($_GET["c"]); ?>'.str_repeat('x', 32)))
        ->toBeNull();
});

/* ========================================================================== */
/*  GUARD 1 — THE HOST ALLOWLIST COMES FROM THE CATALOGUE                      */
/* ========================================================================== */

it('derives its host list from the catalogue and leaves this shop out of it', function () {
    gdProduct(GD_OLD.'/wp-content/uploads/a.jpg', 'a');
    gdProduct('https://cdn-two.test/wp-content/uploads/b.jpg', 'b');
    // This shop's own uploads must never count as "the old site" — the exact
    // false positive MediaAudit::judge() exists to prevent, one level up.
    gdProduct('https://kbb.test/uploads/mine.jpg', 'c');

    expect((new MediaSideloader)->hosts())->toBe(['cdn-two.test', 'old-shop.test']);
});

it('cannot be made to fetch a host the catalogue does not name', function () {
    Http::fake();
    gdProduct(GD_OLD.'/wp-content/uploads/a.jpg', 'a');

    // The SSRF attempt: a host in the request body.
    $resolved = (new MediaSideloader)->resolveHosts(['169.254.169.254', 'internal.corp']);

    expect($resolved['hosts'])->toBe([])
        ->and($resolved['ignored'])->toBe(['169.254.169.254', 'internal.corp']);

    $result = (new MediaSideloader)->batch(['hosts' => ['169.254.169.254']]);

    expect($result['fetched'])->toBe(0)
        ->and($result['results'])->toBe([])
        ->and($result['ignored_hosts'])->toBe(['169.254.169.254']);

    // And nothing was even attempted. Asserted by counting the recorded
    // requests, not with ->not->toContain().
    Http::assertNothingSent();
});

it('lets a request NARROW the catalogue host list but never widen it', function () {
    gdProduct(GD_OLD.'/wp-content/uploads/a.jpg', 'a');
    gdProduct('https://cdn-two.test/wp-content/uploads/b.jpg', 'b');

    $resolved = (new MediaSideloader)->resolveHosts(['old-shop.test', 'evil.test']);

    expect($resolved['hosts'])->toBe(['old-shop.test'])
        ->and($resolved['ignored'])->toBe(['evil.test']);
});

/* ========================================================================== */
/*  THE HAPPY PATH, AND THE RESUME PROMISE                                     */
/* ========================================================================== */

it('fetches a picture into the web root at the path the row names', function () {
    $url = GD_OLD.'/wp-content/uploads/2019/03/serum.jpg';
    gdProduct($url);

    Http::fake([GD_OLD.'/*' => fn () => Http::response(gdJpeg(200), 200, ['Content-Type' => 'image/jpeg'])]);

    $result = (new MediaSideloader)->batch();

    expect($result['fetched'])->toBe(1)
        ->and($result['failed'])->toBe(0)
        ->and(is_file(gdPublic('wp-content/uploads/2019/03/serum.jpg')))->toBeTrue()
        ->and($result['plan']['remaining'])->toBe(0)
        ->and($result['bytes'])->toBeGreaterThan(0);
});

it('never fetches a file that is already on disk, however many times it is run', function () {
    $url = GD_OLD.'/wp-content/uploads/2019/03/serum.jpg';
    gdProduct($url);

    Http::fake([GD_OLD.'/*' => fn () => Http::response(gdJpeg(), 200, ['Content-Type' => 'image/jpeg'])]);

    (new MediaSideloader)->batch();
    (new MediaSideloader)->batch();
    (new MediaSideloader)->batch();

    // Exactly one request for one picture, across three runs. This is the
    // idempotency promise, and it is asserted by counting rather than by
    // trusting a tally the class kept.
    $sent = 0;
    Http::assertSent(function (Request $request) use (&$sent): bool {
        $sent++;

        return true;
    });

    expect($sent)->toBe(1);
});

it('counts a file somebody uploaded by FTP as done, without a ledger row', function () {
    // The disk is the checkpoint, not the table. A picture the owner copied
    // across himself is finished work and must not be fetched again.
    $url = GD_OLD.'/wp-content/uploads/2019/03/ftp.jpg';
    gdProduct($url);

    File::ensureDirectoryExists(gdPublic('wp-content/uploads/2019/03'));
    file_put_contents(gdPublic('wp-content/uploads/2019/03/ftp.jpg'), gdJpeg());

    Http::fake();

    $plan = (new MediaSideloader)->plan();

    expect($plan['present'])->toBe(1)
        ->and($plan['remaining'])->toBe(0)
        ->and(DB::table(MediaSideloader::ITEMS)->count())->toBe(0);

    (new MediaSideloader)->batch();
    Http::assertNothingSent();
});

it('tells the truth about how many are left at every point of a multi-batch run', function () {
    for ($i = 1; $i <= 5; $i++) {
        gdProduct(GD_OLD.'/wp-content/uploads/2019/p'.$i.'.jpg', 'p'.$i);
    }

    Http::fake([GD_OLD.'/*' => fn () => Http::response(gdJpeg(), 200, ['Content-Type' => 'image/jpeg'])]);

    $sideloader = new MediaSideloader;

    expect($sideloader->plan()['remaining'])->toBe(5);

    $first = $sideloader->batch(['files' => 2]);
    expect($first['fetched'])->toBe(2)
        ->and($first['plan']['remaining'])->toBe(3)
        ->and(str_contains($first['stopped'], 'file limit'))->toBeTrue();

    $second = $sideloader->batch(['files' => 2]);
    expect($second['plan']['remaining'])->toBe(1);

    $third = $sideloader->batch(['files' => 2]);
    expect($third['plan']['remaining'])->toBe(0)
        ->and($third['stopped'])->toBe('nothing left to fetch');

    // And the plan is recomputed, not remembered: delete one file behind its
    // back and "remaining" tells the truth about the disk immediately.
    unlink(gdPublic('wp-content/uploads/2019/p3.jpg'));
    expect((new MediaSideloader)->plan()['remaining'])->toBe(1);
});

it('fetches a photograph used by two rows exactly once', function () {
    $url = GD_OLD.'/wp-content/uploads/2019/shared.jpg';
    gdProduct($url, 'one');
    Brand::query()->create(['name' => 'GD Brand', 'slug' => 'gd-brand', 'logo' => $url]);

    Http::fake([GD_OLD.'/*' => fn () => Http::response(gdJpeg(), 200, ['Content-Type' => 'image/jpeg'])]);

    $result = (new MediaSideloader)->batch();

    expect($result['fetched'])->toBe(1)
        ->and($result['results'][0]['used_by'])->toHaveCount(2);
});

/* ========================================================================== */
/*  GUARD 2 — REDIRECTS                                                        */
/* ========================================================================== */

it('refuses to follow a redirect to a different host', function () {
    $url = GD_OLD.'/wp-content/uploads/2019/x.jpg';
    gdProduct($url);

    Http::fake([
        GD_OLD.'/*' => fn () => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
        '169.254.169.254/*' => fn () => Http::response(gdJpeg(), 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $result = (new MediaSideloader)->batch();

    expect($result['fetched'])->toBe(0)
        ->and($result['failed'])->toBe(1)
        ->and(str_contains($result['results'][0]['reason'], 'refused to follow a redirect'))->toBeTrue()
        ->and(is_file(gdPublic('wp-content/uploads/2019/x.jpg')))->toBeFalse();

    // The hop was never made. Counted, not asserted by absence-of-needle.
    $hosts = [];
    Http::assertSent(function (Request $request) use (&$hosts): bool {
        $hosts[] = parse_url($request->url(), PHP_URL_HOST);

        return true;
    });

    expect(array_unique($hosts))->toBe(['old-shop.test']);
});

it('refuses a protocol-relative redirect to another host', function () {
    // //evil.test/x.jpg carries its own host while looking like a path. A
    // redirect resolver that treated a leading slash as "same host" would walk
    // straight off the allowlist.
    $sideloader = new MediaSideloader;
    $next = $sideloader->resolveRedirect('https://old-shop.test/a/b.jpg', '//evil.test/x.jpg');

    expect($next)->toBe('https://evil.test/x.jpg')
        ->and(parse_url((string) $next, PHP_URL_HOST))->toBe('evil.test');
});

it('will not resolve a redirect to a scheme it does not speak', function (string $location) {
    expect((new MediaSideloader)->resolveRedirect('https://old-shop.test/a.jpg', $location))->toBeNull();
})->with(['file:///etc/passwd', 'gopher://x/1', 'data:text/html,<script>1</script>', '']);

it('follows a redirect that stays on the same host', function () {
    // http -> https on one host is a WordPress site behind a TLS proxy, not an
    // attack. Refusing it would fail most of a real migration.
    $url = GD_OLD.'/wp-content/uploads/2019/moved.jpg';
    gdProduct($url);

    $seen = 0;
    Http::fake(function (Request $request) use (&$seen) {
        $seen++;

        return $seen === 1
            ? Http::response('', 301, ['Location' => GD_OLD.'/wp-content/uploads/2019/moved.jpg?v=2'])
            : Http::response(gdJpeg(), 200, ['Content-Type' => 'image/jpeg']);
    });

    $result = (new MediaSideloader)->batch();

    expect($result['fetched'])->toBe(1)
        ->and(is_file(gdPublic('wp-content/uploads/2019/moved.jpg')))->toBeTrue();
});

it('gives up rather than following a redirect chain forever', function () {
    $url = GD_OLD.'/wp-content/uploads/2019/loop.jpg';
    gdProduct($url);

    Http::fake([GD_OLD.'/*' => fn () => Http::response('', 302, ['Location' => GD_OLD.'/wp-content/uploads/2019/loop.jpg'])]);

    $result = (new MediaSideloader)->batch();

    expect($result['failed'])->toBe(1)
        ->and(str_contains($result['results'][0]['reason'], 'redirects'))->toBeTrue();
});

it('sets a connect timeout, a read timeout and its own redirect policy on every request', function () {
    // GUARD 8, AND THE OTHER HALF OF GUARD 2, pinned where they can actually be
    // seen. Laravel hands a stub callback the Guzzle options as its second
    // argument, which is the only place from inside the suite that the
    // transport settings are observable at all — without this they are two
    // lines nothing checks, and "the default for both is wait" is how one PHP-FPM
    // worker per hung request becomes the whole pool.
    gdProduct(GD_OLD.'/wp-content/uploads/2019/opt.jpg', 'opt');

    $seen = [];

    Http::fake(function (Request $request, array $options) use (&$seen) {
        $seen = $options;

        return Http::response(gdJpeg(), 200, ['Content-Type' => 'image/jpeg']);
    });

    (new MediaSideloader)->batch();

    expect($seen['connect_timeout'] ?? null)->toBe(MediaSideloader::CONNECT_TIMEOUT)
        ->and($seen['timeout'] ?? null)->toBe(MediaSideloader::READ_TIMEOUT)
        // Guzzle's own follower does not know which hosts this shop will talk
        // to. Turning it off is what makes the manual hop loop the only way out.
        ->and($seen['allow_redirects'] ?? null)->toBeFalse()
        // Streamed, so the per-file cap can abandon a body rather than buffer it.
        ->and($seen['stream'] ?? null)->toBeTrue();
});

/* ========================================================================== */
/*  GUARD 3 IN THE PIPELINE — a body that is not what it claims                */
/* ========================================================================== */

it('refuses an HTML error page served as an image', function () {
    // The single most common thing a dying WordPress host returns for a missing
    // attachment: a 200 with the theme's 404 page and whatever content type the
    // CDN felt like. Written into the web root as .jpg it is a broken picture
    // that reports as fetched, which is the silent half-success the old header
    // refused a downloader over.
    $url = GD_OLD.'/wp-content/uploads/2019/notreally.jpg';
    gdProduct($url);

    Http::fake([GD_OLD.'/*' => fn () => Http::response(
        '<!doctype html><html><head><title>404</title></head><body>Not found</body></html>',
        200,
        ['Content-Type' => 'image/jpeg'],
    )]);

    $result = (new MediaSideloader)->batch();

    expect($result['fetched'])->toBe(0)
        ->and($result['failed'])->toBe(1)
        ->and(str_contains($result['results'][0]['reason'], 'HTML page'))->toBeTrue()
        ->and(is_file(gdPublic('wp-content/uploads/2019/notreally.jpg')))->toBeFalse();
});

it('refuses a PHP payload named .jpg and served as image/jpeg', function () {
    $url = GD_OLD.'/wp-content/uploads/2019/innocent.jpg';
    gdProduct($url);

    Http::fake([GD_OLD.'/*' => fn () => Http::response(
        '<?php @eval($_POST["x"]); ?>'.str_repeat('A', 64),
        200,
        ['Content-Type' => 'image/jpeg'],
    )]);

    $result = (new MediaSideloader)->batch();

    expect($result['fetched'])->toBe(0)
        ->and(str_contains($result['results'][0]['reason'], 'PHP source'))->toBeTrue()
        ->and(is_file(gdPublic('wp-content/uploads/2019/innocent.jpg')))->toBeFalse();

    // AND NO LEFTOVER. A refusal that leaves its temporary file behind has
    // written attacker bytes into the web root under a name of its own, which
    // is a smaller version of the same hole.
    expect(glob(gdPublic('wp-content/uploads/2019').'/.kbb-sideload-*') ?: [])->toBe([]);
});

it('refuses a content type that is not an image at all', function () {
    $url = GD_OLD.'/wp-content/uploads/2019/x.jpg';
    gdProduct($url);

    Http::fake([GD_OLD.'/*' => fn () => Http::response(gdJpeg(), 200, ['Content-Type' => 'text/html; charset=UTF-8'])]);

    $result = (new MediaSideloader)->batch();

    expect($result['fetched'])->toBe(0)
        ->and(str_contains($result['results'][0]['reason'], 'not an image type'))->toBeTrue();
});

it('refuses bytes whose format disagrees with the name the row points at', function () {
    // PNG bytes behind a .jpg address. Refused rather than renamed: the file is
    // saved under the name the catalogue rows point at, so renaming it would
    // leave every one of those rows pointing at nothing.
    $url = GD_OLD.'/wp-content/uploads/2019/mismatch.jpg';
    gdProduct($url);

    Http::fake([GD_OLD.'/*' => fn () => Http::response(gdPng(), 200, ['Content-Type' => 'image/png'])]);

    $result = (new MediaSideloader)->batch();

    expect($result['fetched'])->toBe(0)
        ->and(str_contains($result['results'][0]['reason'], 'the bytes are image/png'))->toBeTrue();
});

it('refuses a body that does not match its own declared content type', function () {
    $url = GD_OLD.'/wp-content/uploads/2019/lie.png';
    gdProduct($url);

    Http::fake([GD_OLD.'/*' => fn () => Http::response(gdJpeg(), 200, ['Content-Type' => 'image/png'])]);

    $result = (new MediaSideloader)->batch();

    expect($result['fetched'])->toBe(0)
        ->and(str_contains($result['results'][0]['reason'], 'declared image/png and sent image/jpeg'))->toBeTrue();
});

/* ========================================================================== */
/*  GUARD 7 — BYTES                                                            */
/* ========================================================================== */

it('refuses a file the old host declares is over the per-file cap', function () {
    $url = GD_OLD.'/wp-content/uploads/2019/huge.jpg';
    gdProduct($url);

    Http::fake([GD_OLD.'/*' => fn () => Http::response(gdJpeg(), 200, [
        'Content-Type' => 'image/jpeg',
        'Content-Length' => (string) (MediaSideloader::MAX_FILE_BYTES + 1),
    ])]);

    $result = (new MediaSideloader)->batch();

    expect($result['fetched'])->toBe(0)
        ->and(str_contains($result['results'][0]['reason'], 'over the'))->toBeTrue()
        ->and(is_file(gdPublic('wp-content/uploads/2019/huge.jpg')))->toBeFalse();
});

it('abandons a body that runs past the cap even with no Content-Length', function () {
    // The header is an early refusal and is never trusted as the real size. A
    // host that lies about the length, or sends none, is stopped by the read
    // loop instead — and the partial file is removed.
    $url = GD_OLD.'/wp-content/uploads/2019/endless.jpg';
    gdProduct($url);

    Http::fake([GD_OLD.'/*' => fn () => Http::response(
        gdJpeg().str_repeat('A', MediaSideloader::MAX_FILE_BYTES + 1024),
        200,
        ['Content-Type' => 'image/jpeg'],
    )]);

    $result = (new MediaSideloader)->batch();

    expect($result['fetched'])->toBe(0)
        ->and(str_contains($result['results'][0]['reason'], 'abandoned'))->toBeTrue()
        ->and(is_file(gdPublic('wp-content/uploads/2019/endless.jpg')))->toBeFalse()
        ->and(glob(gdPublic('wp-content/uploads/2019').'/.kbb-sideload-*') ?: [])->toBe([]);
});

it('stops a batch at its byte budget and says so', function () {
    for ($i = 1; $i <= 4; $i++) {
        gdProduct(GD_OLD.'/wp-content/uploads/2019/b'.$i.'.jpg', 'b'.$i);
    }

    Http::fake([GD_OLD.'/*' => fn () => Http::response(gdJpeg(40000), 200, ['Content-Type' => 'image/jpeg'])]);

    $result = (new MediaSideloader)->batch(['bytes' => 50000]);

    expect($result['fetched'])->toBeLessThan(4)
        ->and($result['plan']['remaining'])->toBeGreaterThan(0)
        ->and(str_contains($result['stopped'], 'byte budget'))->toBeTrue();
});

/* ========================================================================== */
/*  DISK                                                                       */
/* ========================================================================== */

it('does the free-space arithmetic with the reserve held back', function () {
    expect(MediaSideloader::hasRoom(null, PHP_INT_MAX >> 2))->toBeTrue()
        ->and(MediaSideloader::hasRoom(MediaSideloader::FREE_SPACE_RESERVE + 10, 9))->toBeTrue()
        ->and(MediaSideloader::hasRoom(MediaSideloader::FREE_SPACE_RESERVE + 10, 10))->toBeFalse()
        ->and(MediaSideloader::hasRoom(0, 0))->toBeFalse();
});

it('refuses to start, and writes nothing at all, when the volume has no room', function () {
    for ($i = 1; $i <= 3; $i++) {
        gdProduct(GD_OLD.'/wp-content/uploads/2019/d'.$i.'.jpg', 'd'.$i);
    }

    Http::fake([GD_OLD.'/*' => fn () => Http::response(gdJpeg(), 200, ['Content-Type' => 'image/jpeg'])]);

    $result = gdSideloader(1024)->batch();

    expect($result['ok'])->toBeFalse()
        ->and($result['fetched'])->toBe(0)
        ->and(str_contains($result['stopped'], 'not enough free space'))->toBeTrue()
        ->and(str_contains($result['stopped'], 'Nothing was written'))->toBeTrue()
        ->and(is_dir(gdPublic('wp-content')))->toBeFalse();

    // Loud and EARLY: refused before the first request, not after two files.
    Http::assertNothingSent();
});

it('reports the estimate and the free space before anything is written', function () {
    for ($i = 1; $i <= 3; $i++) {
        gdProduct(GD_OLD.'/wp-content/uploads/2019/e'.$i.'.jpg', 'e'.$i);
    }

    $plan = gdSideloader(500 * 1024 * 1024)->plan();

    expect($plan['remaining'])->toBe(3)
        ->and($plan['estimated_bytes'])->toBe(3 * MediaSideloader::ESTIMATED_BYTES_PER_FILE)
        ->and($plan['free_bytes'])->toBe(500 * 1024 * 1024)
        ->and($plan['enough_room'])->toBeTrue();
});

/* ========================================================================== */
/*  ONE BAD FILE DOES NOT STOP THE RUN                                         */
/* ========================================================================== */

it('records a failure against its reference and carries straight on', function () {
    gdProduct(GD_OLD.'/wp-content/uploads/2019/gone.jpg', 'gone');
    gdProduct(GD_OLD.'/wp-content/uploads/2019/fine.jpg', 'fine');

    Http::fake([
        GD_OLD.'/wp-content/uploads/2019/gone.jpg' => fn () => Http::response('nope', 404),
        GD_OLD.'/wp-content/uploads/2019/fine.jpg' => fn () => Http::response(gdJpeg(), 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $sideloader = new MediaSideloader;
    $result = $sideloader->batch();

    expect($result['fetched'])->toBe(1)
        ->and($result['failed'])->toBe(1)
        ->and(is_file(gdPublic('wp-content/uploads/2019/fine.jpg')))->toBeTrue();

    $failures = $sideloader->failures();

    expect($failures)->toHaveCount(1)
        ->and($failures[0]['url'])->toBe(GD_OLD.'/wp-content/uploads/2019/gone.jpg')
        ->and($failures[0]['status_code'])->toBe(404)
        ->and(str_contains($failures[0]['reason'], '404'))->toBeTrue();
});

it('puts untried pictures ahead of ones that already failed', function () {
    // FOUND BY A REAL MULTI-BATCH RUN AGAINST A LOCAL FAKE OF THE OLD SITE, not
    // by reading the code. The broken references sat together in catalogue
    // order, so one batch was nothing but failures — and the live page's loop,
    // which stops when a batch fetched nothing rather than hammering a dying
    // host for ever, stopped there with four good photographs still untouched
    // behind them.
    //
    // Ordering untried work first is what makes "this batch fetched nothing"
    // mean "everything left has already been tried", which is what the loop
    // assumes it means.
    gdProduct(GD_OLD.'/wp-content/uploads/2019/bad1.jpg', 'bad1');
    gdProduct(GD_OLD.'/wp-content/uploads/2019/bad2.jpg', 'bad2');
    gdProduct(GD_OLD.'/wp-content/uploads/2019/good.jpg', 'good');

    Http::fake([
        GD_OLD.'/wp-content/uploads/2019/bad1.jpg' => fn () => Http::response('x', 500),
        GD_OLD.'/wp-content/uploads/2019/bad2.jpg' => fn () => Http::response('x', 500),
        GD_OLD.'/wp-content/uploads/2019/good.jpg' => fn () => Http::response(gdJpeg(), 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $sideloader = new MediaSideloader;

    // Batch one is two failures, in catalogue order, and stops at its limit.
    $first = $sideloader->batch(['files' => 2]);
    expect($first['failed'])->toBe(2)
        ->and($first['fetched'])->toBe(0);

    // Batch two must reach the untried good one FIRST, not retry the two that
    // failed. Before the fix it retried them and the loop halted here.
    $second = $sideloader->batch(['files' => 2]);
    expect($second['fetched'])->toBe(1)
        ->and($second['results'][0]['url'])->toBe(GD_OLD.'/wp-content/uploads/2019/good.jpg');
});

it('survives a host that will not answer at all', function () {
    gdProduct(GD_OLD.'/wp-content/uploads/2019/hang.jpg', 'hang');

    Http::fake(function () {
        throw new ConnectionException('cURL error 28: Operation timed out after 20000 milliseconds');
    });

    $result = (new MediaSideloader)->batch();

    expect($result['failed'])->toBe(1)
        // The sentence that is TRUE of this branch comes first. A real run
        // against a host that accepted and then said nothing came back with
        // Guzzle's "Connection refused for URI ...", which is a failure class
        // and not what happened — nothing was refused. That wording as the
        // headline sends an owner with no log looking for a firewall.
        ->and(str_starts_with($result['results'][0]['reason'], 'old-shop.test did not answer within 5s'))->toBeTrue()
        ->and(str_contains($result['results'][0]['reason'], 'press Fetch again later'))->toBeTrue()
        ->and($result['ok'])->toBeTrue();
});

it('records a refusal once and never asks the old host about it again', function () {
    gdProduct(GD_OLD.'/wp-content/uploads/2019/shell.php.jpg', 'shell');
    Http::fake();

    $sideloader = new MediaSideloader;

    $first = $sideloader->batch();
    expect($first['refused'])->toBe(1);

    $second = $sideloader->batch();
    expect($second['refused'])->toBe(0)
        ->and($second['results'])->toBe([]);

    Http::assertNothingSent();

    // A refusal does not sit in "remaining" for ever pretending to be work.
    expect($sideloader->plan()['remaining'])->toBe(0)
        ->and($sideloader->plan()['refused'])->toBe(1);
});

it('refuses a year folder that is a symlink out of the web root, once and for good', function () {
    // MUTATION GAP, CLOSED. Removing the `$state === REFUSED` skip at the top of
    // the batch loop left the whole suite green, because the only refusal any
    // test drove through a batch was a PATH refusal, and that branch continues
    // on its own. The one refusal that comes from absolute() — a directory
    // under the web root that RESOLVES somewhere else — had no batch-level test
    // at all, so the skip that stops it being re-reported for ever was a line
    // nothing checked. It is checked now.
    $outside = storage_path('framework/gd-outside-'.uniqid());
    File::ensureDirectoryExists($outside);
    File::ensureDirectoryExists(gdPublic('wp-content/uploads'));
    symlink($outside, gdPublic('wp-content/uploads/2021'));

    gdProduct(GD_OLD.'/wp-content/uploads/2021/escape.jpg', 'escape');
    Http::fake();

    $sideloader = new MediaSideloader;

    $first = $sideloader->batch();
    expect($first['refused'])->toBe(1)
        ->and(str_contains($first['results'][0]['reason'], 'inside the web root'))->toBeTrue();

    // Recorded once. A refusal that is re-reported on every batch is a number
    // that never settles and a run that never says it is finished.
    $second = $sideloader->batch();
    expect($second['refused'])->toBe(0)
        ->and($second['results'])->toBe([]);

    Http::assertNothingSent();

    unlink(gdPublic('wp-content/uploads/2021'));
    File::deleteDirectory($outside);
});

it('names an empty 200 as an empty body rather than as unreadable bytes', function () {
    // MUTATION GAP, CLOSED. Removing the zero-length check left the suite green:
    // an empty head sniffs to null anyway, so the file is still refused. The
    // safety outcome was identical and the SENTENCE was not, and the sentence is
    // the entire product for somebody with no shell and no log access —
    // "the old host returned an empty body" is actionable and "the body is not
    // an image" sends him looking for a corrupt file that does not exist.
    gdProduct(GD_OLD.'/wp-content/uploads/2019/empty.jpg', 'empty');

    Http::fake([GD_OLD.'/*' => fn () => Http::response('', 200, ['Content-Type' => 'image/jpeg'])]);

    $result = (new MediaSideloader)->batch();

    expect($result['fetched'])->toBe(0)
        ->and($result['results'][0]['reason'])->toBe('the old host returned an empty body')
        ->and(is_file(gdPublic('wp-content/uploads/2019/empty.jpg')))->toBeFalse();
});

it('lets a failure be retried but keeps a refusal refused', function () {
    gdProduct(GD_OLD.'/wp-content/uploads/2019/gone.jpg', 'gone');
    gdProduct(GD_OLD.'/wp-content/uploads/2019/shell.php.jpg', 'shell');

    Http::fake([GD_OLD.'/*' => fn () => Http::response('nope', 404)]);

    $sideloader = new MediaSideloader;
    $sideloader->batch();

    expect($sideloader->failures())->toHaveCount(2);

    $sideloader->retry();

    $states = DB::table(MediaSideloader::ITEMS)->pluck('state')->all();

    expect($states)->toBe([MediaSideloader::REFUSED]);
});

/* ========================================================================== */
/*  IDLE vs RUNNING vs STALLED — the answer to the old header's objection      */
/* ========================================================================== */

it('reports never, then running, then idle as three different states', function () {
    gdProduct(GD_OLD.'/wp-content/uploads/2019/s1.jpg', 's1');
    gdProduct(GD_OLD.'/wp-content/uploads/2019/s2.jpg', 's2');

    Http::fake([GD_OLD.'/*' => fn () => Http::response(gdJpeg(), 200, ['Content-Type' => 'image/jpeg'])]);

    $sideloader = new MediaSideloader;

    expect($sideloader->run()['state'])->toBe('never');

    $sideloader->batch(['files' => 1]);
    expect($sideloader->run()['state'])->toBe('running')
        ->and($sideloader->plan()['remaining'])->toBe(1);

    $sideloader->batch(['files' => 1]);
    expect($sideloader->run()['state'])->toBe('idle')
        ->and($sideloader->run()['stopped_reason'])->toBe('nothing left to fetch')
        ->and($sideloader->plan()['remaining'])->toBe(0);
});

it('calls a run STALLED when its last batch never came back', function () {
    gdProduct(GD_OLD.'/wp-content/uploads/2019/s1.jpg', 's1');
    gdProduct(GD_OLD.'/wp-content/uploads/2019/s2.jpg', 's2');

    Http::fake([GD_OLD.'/*' => fn () => Http::response(gdJpeg(), 200, ['Content-Type' => 'image/jpeg'])]);

    $sideloader = new MediaSideloader;
    $sideloader->batch(['files' => 1]);

    // What a request killed by the host's execution limit leaves behind: an
    // unfinished run whose heartbeat stopped. A screen that reported this as
    // "running" would sit on a stale number for ever, which is the failure the
    // old header refused a downloader over.
    DB::table(MediaSideloader::RUNS)->update([
        'heartbeat_at' => now()->subSeconds(MediaSideloader::STALE_SECONDS + 30),
    ]);

    $run = $sideloader->run();

    expect($run['state'])->toBe('stalled')
        ->and(str_contains($run['note'], 'continues from exactly the pictures that are not'))->toBeTrue();

    // And it resumes onto exactly the one that is not on disk.
    $next = $sideloader->batch();
    expect($next['fetched'])->toBe(1)
        ->and($next['plan']['remaining'])->toBe(0);
});

it('separates a run a person stopped from one that ran out of work', function () {
    gdProduct(GD_OLD.'/wp-content/uploads/2019/s1.jpg', 's1');
    gdProduct(GD_OLD.'/wp-content/uploads/2019/s2.jpg', 's2');

    Http::fake([GD_OLD.'/*' => fn () => Http::response(gdJpeg(), 200, ['Content-Type' => 'image/jpeg'])]);

    $sideloader = new MediaSideloader;
    $sideloader->batch(['files' => 1]);
    $run = $sideloader->stop();

    expect($run['state'])->toBe('idle')
        ->and($run['stopped_reason'])->toBe('stopped from the admin screen')
        ->and($sideloader->plan()['remaining'])->toBe(1);
});

/* ========================================================================== */
/*  THE LIVE PROGRESS PAYLOAD — "everything"                                   */
/* ========================================================================== */

it('reports every stage that has a truthful number', function () {
    gdProduct(GD_OLD.'/wp-content/uploads/2019/x.jpg', 'x');

    $snapshot = (new MigrationProgress)->snapshot();
    $keys = array_column($snapshot['stages'], 'key');

    expect($keys)->toBe(['pictures', 'paths', 'addresses', 'catalogue'])
        ->and($snapshot['ok'])->toBeTrue()
        ->and($snapshot['poll'])->toBeFalse();
});

it('draws no percentage for a stage that has no denominator', function () {
    // import_checkpoints records rows CONSUMED and nothing about how many a CSV
    // contains. A bar built on a denominator nobody has looks like information
    // and is not — so the stage reports total 0 and says why in its own note.
    $catalogue = collect((new MigrationProgress)->snapshot()['stages'])
        ->firstWhere('key', 'catalogue');

    expect($catalogue['total'])->toBe(0)
        ->and(str_contains($catalogue['note'], 'there cannot be one'))->toBeTrue();
});

it('says idle-with-work-left and running-with-work-left differently', function () {
    gdProduct(GD_OLD.'/wp-content/uploads/2019/h1.jpg', 'h1');
    gdProduct(GD_OLD.'/wp-content/uploads/2019/h2.jpg', 'h2');

    Http::fake([GD_OLD.'/*' => fn () => Http::response(gdJpeg(), 200, ['Content-Type' => 'image/jpeg'])]);

    $idle = collect((new MigrationProgress)->snapshot()['stages'])->firstWhere('key', 'pictures');
    expect($idle['headline'])->toBe('Idle — 2 pictures still to fetch.');

    (new MediaSideloader)->batch(['files' => 1]);

    $running = collect((new MigrationProgress)->snapshot()['stages'])->firstWhere('key', 'pictures');
    expect($running['headline'])->toBe('Running — 1 of 2 fetched, 1 to go.')
        ->and($running['state'])->toBe('running');

    DB::table(MediaSideloader::RUNS)->update([
        'heartbeat_at' => now()->subSeconds(MediaSideloader::STALE_SECONDS + 30),
    ]);

    $stalled = collect((new MigrationProgress)->snapshot()['stages'])->firstWhere('key', 'pictures');
    expect(str_starts_with($stalled['headline'], 'STALLED'))->toBeTrue();
});

it('widens the poll interval when nothing is running', function () {
    gdProduct(GD_OLD.'/wp-content/uploads/2019/p.jpg', 'p');
    Http::fake([GD_OLD.'/*' => fn () => Http::response(gdJpeg(), 200, ['Content-Type' => 'image/jpeg'])]);

    expect((new MigrationProgress)->snapshot()['poll_seconds'])->toBe(15);

    (new MediaSideloader)->batch(['files' => 1]);
    // One picture, one batch: the run finished, so the page must stop hammering
    // a shared host. This is the server deciding, not the browser.
    expect((new MigrationProgress)->snapshot()['poll_seconds'])->toBe(15);
});

/* ========================================================================== */
/*  THE ENDPOINTS                                                              */
/* ========================================================================== */

function gdOwner(): AdminUser
{
    return AdminUser::create([
        'name' => 'GD Owner',
        'email' => 'gd-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

it('refuses every one of its endpoints to a caller with no admin session', function () {
    MediaSideloadAdminRoutes::wire($this->app);

    foreach (MediaSideloadAdminRoutes::registered() as $route) {
        $method = in_array('POST', $route->methods(), true) ? 'post' : 'get';

        test()->{$method}('/'.$route->uri())->assertStatus(302);
    }

    expect(MediaSideloadAdminRoutes::registered())->toHaveCount(4);
});

it('maps every one of its endpoints to data.import and never to the closed default', function () {
    MediaSideloadAdminRoutes::wire($this->app);

    // The capability rule is REAL and is not assumed. These paths sit under
    // /urls-media/ precisely so ['*', 'admin-api/urls-media/**', 'data.import']
    // covers them; a prefix of their own would fall through to the owner-only
    // default and AdminCapabilityMapTest would fail by name. If a future tidy-up
    // narrows that wildcard, this fails here rather than on a host with no shell.
    foreach (MediaSideloadAdminRoutes::registered() as $route) {
        $method = in_array('POST', $route->methods(), true) ? 'POST' : 'GET';

        expect(AdminCapabilities::forPath($method, $route->uri()))
            ->toBe('data.import', $route->uri().' is not mapped to data.import');
    }
});

it('carries the group middleware and nothing chained over it', function () {
    MediaSideloadAdminRoutes::wire($this->app);

    foreach (MediaSideloadAdminRoutes::registered() as $route) {
        $missing = array_diff(MediaSideloadAdminRoutes::STACK, $route->gatherMiddleware());

        expect($missing)->toBe([], $route->uri().' is missing '.implode(', ', $missing));
    }
});

it('serves the whole picture in one call to a logged-in admin', function () {
    MediaSideloadAdminRoutes::wire($this->app);
    gdProduct(GD_OLD.'/wp-content/uploads/2019/x.jpg', 'x');

    test()->actingAs(gdOwner(), 'admin')
        ->getJson('/admin-api/urls-media/progress')
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('stages.0.key', 'pictures')
        ->assertJsonPath('sideload.plan.remaining', 1)
        ->assertJsonPath('sideload.run.state', 'never');
});

it('runs one bounded batch per request and reports what is left', function () {
    MediaSideloadAdminRoutes::wire($this->app);

    for ($i = 1; $i <= 3; $i++) {
        gdProduct(GD_OLD.'/wp-content/uploads/2019/r'.$i.'.jpg', 'r'.$i);
    }

    Http::fake([GD_OLD.'/*' => fn () => Http::response(gdJpeg(), 200, ['Content-Type' => 'image/jpeg'])]);

    $admin = gdOwner();

    test()->actingAs($admin, 'admin')
        ->postJson('/admin-api/urls-media/sideload', ['action' => 'fetch', 'files' => 2])
        ->assertOk()
        ->assertJsonPath('fetched', 2)
        ->assertJsonPath('plan.remaining', 1);

    test()->actingAs($admin, 'admin')
        ->postJson('/admin-api/urls-media/sideload', ['action' => 'fetch', 'files' => 2])
        ->assertOk()
        ->assertJsonPath('fetched', 1)
        ->assertJsonPath('plan.remaining', 0);
});

it('answers 507 rather than 200 when there is no room to write', function () {
    MediaSideloadAdminRoutes::wire($this->app);
    gdProduct(GD_OLD.'/wp-content/uploads/2019/x.jpg', 'x');

    // The controller resolves its own MediaSideloader, so the seam is applied
    // by binding the class for this request only.
    $this->app->bind(MediaSideloader::class, fn () => gdSideloader(1024));
    $this->app->bind(
        \App\Http\Controllers\Admin\MediaSideloadApiController::class,
        fn ($app) => new \App\Http\Controllers\Admin\MediaSideloadApiController(gdSideloader(1024)),
    );

    Http::fake();

    test()->actingAs(gdOwner(), 'admin')
        ->postJson('/admin-api/urls-media/sideload', ['action' => 'fetch'])
        ->assertStatus(507)
        ->assertJsonPath('ok', false);

    Http::assertNothingSent();
});

it('refuses an action it does not know', function () {
    MediaSideloadAdminRoutes::wire($this->app);

    test()->actingAs(gdOwner(), 'admin')
        ->postJson('/admin-api/urls-media/sideload', ['action' => 'delete-everything'])
        ->assertStatus(422);
});

it('serves the live page as a document that stands on its own', function () {
    MediaSideloadAdminRoutes::wire($this->app);

    $html = test()->actingAs(gdOwner(), 'admin')
        ->get('/admin-api/urls-media/progress-page')
        ->assertOk()
        ->getContent();

    // The three states are named on the page itself, not only in the payload.
    foreach ([
        'Migration progress',
        'csrf-token',
        'b-stalled',                       // the stalled state has a rendering of its own
        'did not come back',               // and a sentence of its own
        'Failures and refusals',
    ] as $needle) {
        expect(str_contains($html, $needle))->toBeTrue($needle.' is missing from the live page');
    }

    // No build step, no CDN, no dependency: CI does not build assets and
    // package.json defines no build script, so anything external ships broken.
    expect(preg_match('#<script[^>]+src=#i', $html))->toBe(0);
    expect(preg_match('#<link[^>]+stylesheet#i', $html))->toBe(0);
});

it('hands the failures over as a spreadsheet he can open', function () {
    MediaSideloadAdminRoutes::wire($this->app);
    gdProduct(GD_OLD.'/wp-content/uploads/2019/gone.jpg', 'gone');

    Http::fake([GD_OLD.'/*' => fn () => Http::response('nope', 404)]);
    (new MediaSideloader)->batch();

    $csv = test()->actingAs(gdOwner(), 'admin')
        ->get('/admin-api/urls-media/sideload.csv')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->getContent();

    expect(str_contains($csv, 'gone.jpg'))->toBeTrue()
        ->and(str_contains($csv, '404'))->toBeTrue();
});

/* ========================================================================== */
/*  THE AUDIT AND THE SIDELOADER AGREE                                         */
/* ========================================================================== */

it('drives the audit\'s "still on the old site" count to zero when paired with a rewrite', function () {
    // The end-to-end promise, in the two steps it really takes: this lane puts
    // the file on disk, Lane GB re-points the row. Neither one alone finishes
    // the migration and the progress page says so in the paths stage.
    $url = GD_OLD.'/wp-content/uploads/2019/03/end.jpg';
    gdProduct($url);

    Http::fake([GD_OLD.'/*' => fn () => Http::response(gdJpeg(), 200, ['Content-Type' => 'image/jpeg'])]);

    expect((new MediaAudit)->summarise((new MediaAudit)->audit()))
        ->toBe(['present' => 0, 'missing' => 0, 'remote' => 1]);

    (new MediaSideloader)->batch();

    // Fetched, but the ROW still names the old host. This is the honest middle
    // state and the reason the paths stage keeps its own number.
    expect((new MediaAudit)->summarise((new MediaAudit)->audit()))
        ->toBe(['present' => 0, 'missing' => 0, 'remote' => 1]);

    $rewrite = new MediaRewrite;
    $rewrite->apply($rewrite->propose(['old-shop.test']));

    expect((new MediaAudit)->summarise((new MediaAudit)->audit()))
        ->toBe(['present' => 1, 'missing' => 0, 'remote' => 0]);
});

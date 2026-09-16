<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Media;
use App\Models\Product;
use App\Models\User;
use App\Support\MediaBackfill;
use App\Support\MediaUsage;
use Tests\Support\MediaLibraryRoutes;

/**
 * Content → Media Library (Lane AX).
 *
 * THE THING THIS SCREEN EXISTS TO FIX, stated once so the assertions below read
 * as what they are. /admin-api/media/upload has been writing files into
 * public/uploads/ for the product gallery, brand logos, category images and the
 * SEO share image for months, and recorded NONE of them: `media` has existed
 * since the original schema and `Media::` had zero call sites anywhere in the
 * tree. So the owner had a store full of uploads and no way to see one. Two
 * things fill the table now — the upload endpoint itself, and a backfill over
 * what is already on disk — and both are pinned here.
 *
 * WHY THE ATTACHMENT ASSERTIONS ARE THE IMPORTANT ONES. Nothing records which
 * product an image belongs to; the schema keeps a URL string on the owning row
 * and that is all (see App\Support\MediaUsage). A filter over that is derived,
 * and a derived filter that matches nothing is indistinguishable from a working
 * one until somebody notices the grid is always empty — which is exactly how
 * Api\ProductController's `status` filter survived for months in this
 * repository. So every filter this screen offers is asserted to return the row
 * it should AND to exclude the row it should not; one without the other would
 * pass against a filter that silently matched everything, or nothing.
 */
function mlAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'ML Owner',
        'email' => 'ml-owner-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

function asMlAdmin(): void
{
    MediaLibraryRoutes::wire(app());
    test()->actingAs(mlAdmin(), 'admin');
}

/** A media row, with sensible defaults, so each test states only what it cares about. */
function mlMedia(array $attributes = []): Media
{
    static $n = 0;
    $n++;

    $filename = $attributes['filename'] ?? ('ml-'.$n.'-'.uniqid().'.png');

    return Media::create(array_merge([
        'filename' => $filename,
        'path' => 'uploads/products/'.$filename,
        'mime' => 'image/png',
        'size' => 2048,
        'width' => 800,
        'height' => 600,
        'alt' => '',
    ], $attributes, ['filename' => $filename]));
}

/** Empty public/uploads so a backfill test asserts against what it put there. */
function mlClearUploads(): void
{
    $root = public_path('uploads');

    if (! is_dir($root)) {
        return;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($files as $file) {
        $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }
}

function mlPngBytes(): string
{
    $image = imagecreatetruecolor(6, 4);
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

/* ───────────────────────────── the guard ───────────────────────────────── */

it('refuses every media-library route to an anonymous caller', function () {
    MediaLibraryRoutes::wire(app());

    $media = mlMedia();

    foreach ([
        ['getJson', '/admin-api/media'],
        ['getJson', '/admin-api/media/'.$media->id],
        ['deleteJson', '/admin-api/media/'.$media->id],
        ['postJson', '/admin-api/media/rescan'],
    ] as [$method, $uri]) {
        $response = test()->{$method}($uri);

        expect($response->getStatusCode())->toBe(401, "{$method} {$uri} was not refused");

        // Not merely refused — the filename did not leak in the refusal body
        // either. The library is an inventory of everything the store owns,
        // including images on products that have never been published.
        expect($response->getContent())->not->toContain($media->filename);
    }

    // And the anonymous caller deleted nothing.
    expect(Media::query()->whereKey($media->id)->exists())->toBeTrue();
});

it('refuses a signed-in non-admin as firmly as an anonymous one', function () {
    MediaLibraryRoutes::wire(app());

    $media = mlMedia();

    // A shopper signed into the storefront. The `customer` guard is
    // deliberately separate from `admin` (config/auth.php says so in as many
    // words); this is the assertion that the separation is real, not just
    // documented.
    $shopper = Customer::create([
        'name' => 'ML Shopper',
        'email' => 'ml-shopper-'.uniqid().'@example.test',
        'password' => 'secret-secret',
    ]);

    test()->actingAs($shopper, 'customer');

    expect(test()->getJson('/admin-api/media')->getStatusCode())->toBe(401)
        ->and(test()->getJson('/admin-api/media/'.$media->id)->getStatusCode())->toBe(401)
        ->and(test()->deleteJson('/admin-api/media/'.$media->id)->getStatusCode())->toBe(401)
        ->and(test()->postJson('/admin-api/media/rescan')->getStatusCode())->toBe(401);

    // And a site user on the default `web` guard, which is neither of those.
    $user = User::create([
        'name' => 'ML Web User',
        'email' => 'ml-webuser-'.uniqid().'@example.test',
        'password' => 'secret-secret',
    ]);

    test()->actingAs($user, 'web');

    expect(test()->getJson('/admin-api/media')->getStatusCode())->toBe(401)
        ->and(test()->getJson('/admin-api/media/'.$media->id)->getStatusCode())->toBe(401)
        ->and(test()->deleteJson('/admin-api/media/'.$media->id)->getStatusCode())->toBe(401);

    expect(Media::query()->whereKey($media->id)->exists())->toBeTrue();
});

it('really does mount every route behind the admin guard, not just appear to', function () {
    MediaLibraryRoutes::wire(app());

    $routes = MediaLibraryRoutes::registered();

    // Four routes, and the count is asserted so a fifth cannot be added without
    // this file noticing it needs a guard assertion too.
    expect($routes)->toHaveCount(4);

    /*
     * toContain() is VARIADIC in Pest: every argument is another needle, not a
     * failure message. Passing "…is not behind auth:admin" as a second argument
     * asserts the middleware array contains that sentence, which it never does
     * — so the assertion fails whether or not the guard is there, and reads as
     * though the guard were missing. The route uri goes in an explicit
     * assertion message instead.
     */
    foreach ($routes as $route) {
        $middleware = $route->gatherMiddleware();

        expect(in_array('auth:admin', $middleware, true))
            ->toBeTrue($route->uri().' is not behind auth:admin');

        expect(in_array('web', $middleware, true))
            ->toBeTrue($route->uri().' is not in the web group');
    }
});

it('keeps the media library out of the public /api namespace entirely', function () {
    MediaLibraryRoutes::wire(app());

    /*
     * CLAUDE.md: "/api/* is unauthenticated. Every endpoint there is public."
     * The media table is an inventory of the store's assets and DELETE unlinks
     * files from the public web root, so a well-meaning remount into
     * routes/api.php would be a disclosure and a destruction bug in one. This
     * reads the REGISTERED routes rather than the route file's text, so it
     * catches the remount however it is spelled.
     */
    $public = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_starts_with($r->uri(), 'api/'))
        ->filter(fn ($r) => str_contains((string) $r->getAction('controller'), 'MediaLibraryApiController'))
        ->map(fn ($r) => $r->uri())
        ->values()
        ->all();

    expect($public)->toBe([]);
});

/* ─────────────────────── the upload records a row ──────────────────────── */

it('records an upload in the library instead of only writing the file', function () {
    asMlAdmin();
    mlClearUploads();

    $before = Media::query()->count();

    $path = tempnam(sys_get_temp_dir(), 'mlup');
    file_put_contents($path, mlPngBytes());

    $response = test()->post('/admin-api/media/upload', [
        'file' => new \Illuminate\Http\UploadedFile($path, 'shelf-shot.png', null, null, true),
        'folder' => 'products',
    ])->assertOk();

    expect($response->json('recorded'))->toBeTrue('the upload wrote a file but no library row');

    $media = Media::query()->orderByDesc('id')->first();

    expect(Media::query()->count())->toBe($before + 1)
        ->and($media->filename)->toBe($response->json('filename'))
        ->and($media->path)->toBe('uploads/products/'.$response->json('filename'))
        ->and($media->mime)->toBe('image/png')
        // Read off the real file, not off what the browser claimed.
        ->and((int) $media->width)->toBe(6)
        ->and((int) $media->height)->toBe(4)
        ->and((int) $media->size)->toBeGreaterThan(0);

    // And the row's URL is the same URL the editor was handed, so the grid's
    // thumbnail and the product's <img> can never point at different places.
    expect($media->url())->toBe($response->json('url'));

    mlClearUploads();
});

it('shows a just-uploaded file in the grid', function () {
    asMlAdmin();
    mlClearUploads();

    $path = tempnam(sys_get_temp_dir(), 'mlup');
    file_put_contents($path, mlPngBytes());

    $filename = test()->post('/admin-api/media/upload', [
        'file' => new \Illuminate\Http\UploadedFile($path, 'grid-proof.png', null, null, true),
        'folder' => 'products',
    ])->assertOk()->json('filename');

    $grid = test()->getJson('/admin-api/media')->assertOk();

    expect(collect($grid->json('items'))->pluck('filename')->all())->toContain($filename);

    mlClearUploads();
});

it('catalogues files already on disk, and does not duplicate them on a second run', function () {
    mlClearUploads();

    /*
     * UNIQUE NAMES, AND COUNTED PER NAME RATHER THAN BY THE RETURN VALUE.
     *
     * The backfill migration runs once at the start of the process, before any
     * test, and it reads the real public/uploads directory. So a file left
     * behind by an earlier run — a crashed test, an interrupted suite — becomes
     * a media row before this test starts, and a version of this test that
     * asserted `run()` returned exactly 1 would then fail for a reason that has
     * nothing to do with the code. That is a real order dependency, not a
     * theoretical one: it bit this file once during development.
     *
     * Asserting on THIS test's own filenames removes the dependency without
     * weakening anything — the count of rows for a name it just created is
     * exactly as strong a statement, and it is true whatever else is on disk.
     */
    $stamp = uniqid();
    $image = 'legacy-logo-'.$stamp.'.png';
    $notes = 'notes-'.$stamp.'.txt';

    $dir = public_path('uploads/brands');
    @mkdir($dir, 0755, true);
    file_put_contents($dir.'/'.$image, mlPngBytes());
    // Not an image: an image library that lists this is lying about what it is.
    file_put_contents($dir.'/'.$notes, 'not an image');

    MediaBackfill::run();

    $row = Media::query()->where('filename', $image)->first();

    expect($row)->not->toBeNull('the backfill did not catalogue a file already on disk')
        ->and($row->path)->toBe('uploads/brands/'.$image)
        // Read off the real file's header, not guessed from the name.
        ->and((int) $row->width)->toBe(6)
        ->and((int) $row->height)->toBe(4)
        ->and($row->mime)->toBe('image/png');

    expect(Media::query()->where('filename', $notes)->exists())->toBeFalse();

    // Idempotent: applying the same package twice is a real event on a host
    // where updates are zips applied by hand.
    MediaBackfill::run();

    expect(Media::query()->where('filename', $image)->count())->toBe(1);

    mlClearUploads();
});

/* ──────────────────────────── search by name ───────────────────────────── */

it('searches by name and escapes LIKE wildcards instead of honouring them', function () {
    asMlAdmin();

    $literal = mlMedia(['filename' => 'ml-100%-pure.png']);
    $decoy = mlMedia(['filename' => 'ml-100-and-anything-pure.png']);

    $names = fn (string $q) => collect(
        test()->getJson('/admin-api/media?q='.urlencode($q))->assertOk()->json('items')
    )->pluck('filename')->all();

    /*
     * "100%-pure" must be read as the literal characters. Unescaped, the % is a
     * wildcard and the decoy matches too — which is the bug: a filename search
     * that silently widens is how somebody deletes the wrong file. The escape
     * character is `!` rather than a backslash because a backslash is MySQL's
     * DEFAULT escape and means nothing to SQLite, so the same query returned
     * different rows in CI and in production. See App\Support\SearchTerms.
     */
    expect($names('100%-pure'))->toContain($literal->filename)
        ->and($names('100%-pure'))->not->toContain($decoy->filename);

    // And an underscore is a single-character wildcard with the same problem.
    $under = mlMedia(['filename' => 'ml-a_b.png']);
    $underDecoy = mlMedia(['filename' => 'ml-axb.png']);

    expect($names('a_b'))->toContain($under->filename)
        ->and($names('a_b'))->not->toContain($underDecoy->filename);

    // The escape character itself is not a wildcard and must not eat the next
    // character: searching for it finds the file that really contains it.
    $bang = mlMedia(['filename' => 'ml-sale!now.png']);

    expect($names('sale!now'))->toContain($bang->filename);
});

it('finds an upload by the name the operator gave it, not only the generated one', function () {
    asMlAdmin();
    mlClearUploads();

    /*
     * THE DEFECT THIS PINS, and it was invisible until the finished screen was
     * driven in a real browser.
     *
     * The endpoint renames every upload to `Ymd-His-<8 random>.ext`, on purpose:
     * two operators uploading IMG_0042.jpg must not overwrite each other, and a
     * browser-supplied name must never decide what lands in the public web
     * root. So the library knew the file only as 20260916-063940-gFrWgM9v.png,
     * and an operator who uploaded `cosrx-snail-essence.jpg` and searched for
     * "cosrx" got an empty grid back off a library that HELD THE FILE.
     *
     * A search that answers 200 and finds nothing looks exactly like a search
     * that works. That is the landmine in CLAUDE.md, in a new place.
     */
    $path = tempnam(sys_get_temp_dir(), 'mlup');
    file_put_contents($path, mlPngBytes());

    $generated = test()->post('/admin-api/media/upload', [
        'file' => new \Illuminate\Http\UploadedFile($path, 'COSRX-Snail-Essence.png', null, null, true),
        'folder' => 'products',
    ])->assertOk()->json('filename');

    // The stored name is still the generated one — that part must NOT change.
    expect($generated)->not->toContain('COSRX')
        ->and($generated)->toEndWith('.png');

    // keyBy()->all(), and an explicit array_key_exists: toHaveKey()'s SECOND
    // argument is the expected VALUE, not a failure message — the same trap as
    // toContain() being variadic, and it fails whether or not the key is there.
    $found = collect(
        test()->getJson('/admin-api/media?q=cosrx')->assertOk()->json('items')
    )->keyBy('filename')->all();

    expect(array_key_exists($generated, $found))
        ->toBeTrue('an upload could not be found by the name it was uploaded under');

    expect($found[$generated]['original_name'])->toBe('COSRX-Snail-Essence.png');

    // And the generated name still finds it, because that is what appears in a
    // product's <img> and is what somebody chasing a URL will paste in.
    expect(collect(test()->getJson('/admin-api/media?q='.urlencode($generated))->json('items'))
        ->pluck('filename')->all())->toContain($generated);

    mlClearUploads();
});

it('never lets the operator-supplied name decide anything but search', function () {
    asMlAdmin();
    mlClearUploads();

    /*
     * The original name is recorded for SEARCH and for the tile's title, and
     * for nothing else. This is the endpoint's already-fixed defect in a new
     * guise: the stored extension, the served Content-Type and the decision to
     * run the SVG scan must all keep coming from the file's own bytes.
     */
    $path = tempnam(sys_get_temp_dir(), 'mlup');
    file_put_contents($path, mlPngBytes());

    $response = test()->post('/admin-api/media/upload', [
        // A lying name, carrying a path and an extension the bytes contradict.
        'file' => new \Illuminate\Http\UploadedFile($path, '../../evil name.svg', null, null, true),
        'folder' => 'products',
    ])->assertOk();

    $media = Media::query()->orderByDesc('id')->first();

    expect($media->filename)->toEndWith('.png')
        ->and($media->mime)->toBe('image/png')
        ->and($media->path)->toBe('uploads/products/'.$media->filename);

    /*
     * The recorded label carries no path, asserted as the INVARIANT rather than
     * as a claim about which line enforces it.
     *
     * Honest note, established by mutation rather than assumed: removing the
     * basename() in MediaUploadController::record() does NOT turn this red,
     * because Symfony's UploadedFile already strips the directory part in its
     * own constructor — getClientOriginalName() never returns a path. The
     * basename() is therefore belt-and-braces over a guarantee one layer down,
     * and this assertion pins the guarantee wherever it comes from, which is
     * the thing that actually matters if that layer ever changes.
     */
    expect($media->original_name)->toBe('evil name.svg')
        ->and($media->original_name)->not->toContain('/')
        ->and($media->original_name)->not->toContain('\\')
        ->and($media->original_name)->not->toContain('..');

    mlClearUploads();
});

it('falls back to the stored filename for rows that never had an original name', function () {
    asMlAdmin();

    // Everything the backfill catalogues off disk is in this state — there is
    // no original name to recover — and a blank tile title would be worse than
    // a generated one.
    $media = mlMedia(['original_name' => null]);

    $item = test()->getJson('/admin-api/media/'.$media->id)->assertOk()->json('item');

    expect($item['original_name'])->toBe($media->filename);
});

it('searches alt text as well as the filename', function () {
    asMlAdmin();

    $described = mlMedia(['alt' => 'Cushion compact swatch']);
    $other = mlMedia(['alt' => 'Something else entirely']);

    $names = collect(
        test()->getJson('/admin-api/media?q=cushion+compact')->assertOk()->json('items')
    )->pluck('filename')->all();

    expect($names)->toContain($described->filename)
        ->and($names)->not->toContain($other->filename);
});

/* ──────────────────────────── search by date ───────────────────────────── */

it('filters by date inclusively at both ends', function () {
    asMlAdmin();

    $old = mlMedia();
    $old->forceFill(['created_at' => '2026-01-10 09:00:00'])->save();

    $onTheDay = mlMedia();
    // Late in the day on purpose: a `to` filter that compares against midnight
    // excludes everything uploaded after breakfast, which reads as "the filter
    // is broken" to the only person who would ever notice.
    $onTheDay->forceFill(['created_at' => '2026-02-20 23:41:00'])->save();

    $new = mlMedia();
    $new->forceFill(['created_at' => '2026-03-05 12:00:00'])->save();

    $names = fn (string $query) => collect(
        test()->getJson('/admin-api/media?'.$query)->assertOk()->json('items')
    )->pluck('filename')->all();

    $window = $names('from=2026-02-20&to=2026-02-20');

    expect($window)->toContain($onTheDay->filename)
        ->and($window)->not->toContain($old->filename)
        ->and($window)->not->toContain($new->filename);

    expect($names('from=2026-02-20'))->toContain($new->filename)
        ->and($names('from=2026-02-20'))->not->toContain($old->filename);

    expect($names('to=2026-02-20'))->toContain($old->filename)
        ->and($names('to=2026-02-20'))->not->toContain($new->filename);

    // A malformed date is ignored rather than turned into "now", which would
    // empty the grid and look like a bug in the data.
    expect($names('from=not-a-date'))->toContain($old->filename);
});

/* ─────────────── search by what the image is attached to ───────────────── */

it('filters by product, brand and category, matching real rows in each case', function () {
    asMlAdmin();

    $onProduct = mlMedia();
    $inGallery = mlMedia();
    $onBrand = mlMedia();
    $onCategory = mlMedia();
    $loose = mlMedia();

    Product::create([
        'name' => 'ML Serum',
        'slug' => 'ml-serum-'.uniqid(),
        'price' => 1000,
        'image' => '/'.$onProduct->path,
        'images' => ['/'.$inGallery->path],
    ]);

    Brand::create([
        'name' => 'ML Brand',
        'slug' => 'ml-brand-'.uniqid(),
        'logo' => '/'.$onBrand->path,
    ]);

    Category::create([
        'name' => 'ML Category',
        'slug' => 'ml-category-'.uniqid(),
        'image' => '/'.$onCategory->path,
    ]);

    $names = fn (string $query) => collect(
        test()->getJson('/admin-api/media?'.$query)->assertOk()->json('items')
    )->pluck('filename')->all();

    /*
     * Each of these asserts BOTH directions. A filter that returns the right
     * row proves nothing on its own — one that matched everything would pass
     * that half — and a filter that excludes the wrong row proves nothing on
     * its own either, because one that matched NOTHING would pass that half.
     * That second failure is the one CLAUDE.md records surviving for months in
     * Api\ProductController.
     */
    $byProduct = $names('attached=product');
    expect($byProduct)->toContain($onProduct->filename)
        ->and($byProduct)->toContain($inGallery->filename)
        ->and($byProduct)->not->toContain($onBrand->filename)
        ->and($byProduct)->not->toContain($loose->filename);

    $byBrand = $names('attached=brand');
    expect($byBrand)->toContain($onBrand->filename)
        ->and($byBrand)->not->toContain($onProduct->filename)
        ->and($byBrand)->not->toContain($loose->filename);

    $byCategory = $names('attached=category');
    expect($byCategory)->toContain($onCategory->filename)
        ->and($byCategory)->not->toContain($onBrand->filename)
        ->and($byCategory)->not->toContain($loose->filename);

    $byAny = $names('attached=any');
    expect($byAny)->toContain($onProduct->filename)
        ->and($byAny)->toContain($onBrand->filename)
        ->and($byAny)->toContain($onCategory->filename)
        ->and($byAny)->not->toContain($loose->filename);

    $unused = $names('attached=unused');
    expect($unused)->toContain($loose->filename)
        ->and($unused)->not->toContain($onProduct->filename)
        ->and($unused)->not->toContain($inGallery->filename)
        ->and($unused)->not->toContain($onBrand->filename)
        ->and($unused)->not->toContain($onCategory->filename);
});

it('narrows an attachment filter by the owner name the operator typed', function () {
    asMlAdmin();

    $cosrx = mlMedia();
    $anua = mlMedia();

    Product::create([
        'name' => 'COSRX Snail Essence',
        'slug' => 'ml-cosrx-'.uniqid(),
        'price' => 1000,
        'image' => '/'.$cosrx->path,
    ]);

    Product::create([
        'name' => 'Anua Heartleaf Toner',
        'slug' => 'ml-anua-'.uniqid(),
        'price' => 1000,
        'image' => '/'.$anua->path,
    ]);

    $names = collect(
        test()->getJson('/admin-api/media?attached=product&attached_q=cosrx')->assertOk()->json('items')
    )->pluck('filename')->all();

    expect($names)->toContain($cosrx->filename)
        ->and($names)->not->toContain($anua->filename);

    // A name nobody has returns an empty grid rather than the whole library —
    // whereIn([]) must stay `0 = 1`, not become a tautology.
    expect(test()->getJson('/admin-api/media?attached=product&attached_q=nobody-sells-this')
        ->assertOk()->json('items'))->toBe([]);
});

it('ignores a soft-deleted product when deciding an image is in use', function () {
    asMlAdmin();

    $media = mlMedia();

    $product = Product::create([
        'name' => 'ML Discontinued',
        'slug' => 'ml-gone-'.uniqid(),
        'price' => 1000,
        'image' => '/'.$media->path,
    ]);

    expect(collect(test()->getJson('/admin-api/media?attached=product')->json('items'))
        ->pluck('filename')->all())->toContain($media->filename);

    $product->delete();

    // Product uses SoftDeletes, so the default query excludes it — an image
    // whose only owner is in the bin is free, and saying otherwise would make
    // the library impossible to tidy.
    expect(collect(test()->getJson('/admin-api/media?attached=product')->json('items'))
        ->pluck('filename')->all())->not->toContain($media->filename);

    expect(collect(test()->getJson('/admin-api/media?attached=unused')->json('items'))
        ->pluck('filename')->all())->toContain($media->filename);
});

it('matches a stored URL whatever shape it was saved in', function () {
    // The four columns that record an attachment are free text, and this store
    // has absolute URLs from the importer, root-relative paths from the upload
    // endpoint, and cache-busted ones from hand-editing. All three name the
    // same file and all three must count.
    $media = mlMedia(['filename' => 'shape-proof.png', 'path' => 'uploads/products/shape-proof.png']);

    foreach ([
        'https://kbeautybliss.com/uploads/products/shape-proof.png',
        '/uploads/products/shape-proof.png',
        'uploads/products/shape-proof.png',
        '/uploads/products/shape-proof.png?v=3',
        /*
         * A URL parse_url() REFUSES, which is the only case where the explicit
         * query-string strip is load-bearing.
         *
         * parse_url($u, PHP_URL_PATH) already drops ?v=3 for every well-formed
         * URL, so the four shapes above pass with the strip removed — the first
         * version of this test could not tell the two mechanisms apart and
         * stayed green under a mutation that deleted one of them. parse_url
         * returns FALSE for a URL with an empty host before a port, and the
         * normaliser then falls back to the raw string; without the strip, the
         * "filename" would be `shape-proof.png?v=3` and this image would drop
         * out of every attachment filter and out of the delete guard.
         */
        'http://:80/uploads/products/shape-proof.png?v=3',
    ] as $i => $stored) {
        Product::query()->forceDelete();

        Product::create([
            'name' => 'ML Shape '.$i,
            'slug' => 'ml-shape-'.$i.'-'.uniqid(),
            'price' => 1000,
            'image' => $stored,
        ]);

        $usage = MediaUsage::verify(MediaUsage::index(), $media->filename, $media->path);

        expect($usage)->toHaveCount(1, "a URL stored as `{$stored}` was not recognised");
    }
});

/* ───────────────────────── totals and paging ───────────────────────────── */

it('reports the same totals on page two as on page one', function () {
    asMlAdmin();

    // Two full pages and a bit, so page 2 exists and is not the last row.
    for ($i = 0; $i < 30; $i++) {
        mlMedia(['size' => 1000]);
    }

    $one = test()->getJson('/admin-api/media?page=1')->assertOk();
    $two = test()->getJson('/admin-api/media?page=2')->assertOk();

    /*
     * The bug this pins, quoting App\Support\AggregatesQueries: applySort() and
     * forPage() MUTATE the builder, so a summary computed from the same
     * instance inherits its ORDER BY, LIMIT and OFFSET. An aggregate returns
     * one row; skip 24 and there is none, so every total reads ZERO from page
     * two on — silently, with the endpoint still answering 200. It shipped
     * twice in this repository, on Customers and then on Orders.
     */
    expect($two->json('total'))->toBe($one->json('total'))
        ->and($two->json('total'))->toBeGreaterThanOrEqual(30)
        ->and($two->json('bytes'))->toBe($one->json('bytes'))
        ->and($two->json('bytes'))->toBeGreaterThan(0)
        ->and($two->json('pages'))->toBe($one->json('pages'));

    // And page two is a different page, not a repeat of page one — a paging
    // bug that returned the same rows would otherwise sail past the above.
    $first = collect($one->json('items'))->pluck('id')->all();
    $second = collect($two->json('items'))->pluck('id')->all();

    expect($second)->not->toBe([])
        ->and(array_intersect($first, $second))->toBe([]);
});

it('keeps the totals describing the filtered set, not the whole table', function () {
    asMlAdmin();

    for ($i = 0; $i < 5; $i++) {
        mlMedia(['filename' => 'ml-needle-'.$i.'-'.uniqid().'.png']);
    }

    mlMedia(['filename' => 'ml-haystack-'.uniqid().'.png']);

    $filtered = test()->getJson('/admin-api/media?q=needle')->assertOk();

    expect($filtered->json('total'))->toBe(5);
});

/* ────────────────────────── detail and delete ──────────────────────────── */

it('says where an image is used, naming the product, brand and category', function () {
    asMlAdmin();

    $media = mlMedia();

    Product::create([
        'name' => 'ML Ampoule',
        'slug' => 'ml-ampoule-'.uniqid(),
        'price' => 1000,
        'image' => '/'.$media->path,
        'images' => ['/'.$media->path],
    ]);

    Brand::create(['name' => 'ML Logos', 'slug' => 'ml-logos-'.uniqid(), 'logo' => '/'.$media->path]);
    Category::create(['name' => 'ML Shelf', 'slug' => 'ml-shelf-'.uniqid(), 'image' => '/'.$media->path]);

    $item = test()->getJson('/admin-api/media/'.$media->id)->assertOk()->json('item');

    expect($item['used'])->toBeTrue()
        ->and(collect($item['usage'])->pluck('name')->all())
        ->toContain('ML Ampoule', 'ML Logos', 'ML Shelf')
        ->and(collect($item['usage'])->pluck('type')->unique()->sort()->values()->all())
        ->toBe(['brand', 'category', 'product'])
        // The gallery slot is named as well as the main image, so the operator
        // knows which of the two to change before deleting.
        ->and(collect($item['usage'])->pluck('field')->all())
        ->toContain('Main image', 'Gallery image 1');
});

it('refuses to delete an image a product still uses, and says which product', function () {
    asMlAdmin();
    mlClearUploads();

    $dir = public_path('uploads/products');
    @mkdir($dir, 0755, true);
    file_put_contents($dir.'/still-used.png', mlPngBytes());

    $media = mlMedia(['filename' => 'still-used.png', 'path' => 'uploads/products/still-used.png']);

    Product::create([
        'name' => 'ML Cleanser',
        'slug' => 'ml-cleanser-'.uniqid(),
        'price' => 1000,
        'image' => '/uploads/products/still-used.png',
    ]);

    $refusal = test()->deleteJson('/admin-api/media/'.$media->id)->assertStatus(409);

    expect($refusal->json('ok'))->toBeFalse()
        ->and($refusal->json('used'))->toBeTrue()
        // Names the product rather than merely saying something uses it. A
        // refusal the operator cannot act on is a refusal they will force.
        ->and($refusal->json('message'))->toContain('ML Cleanser')
        ->and($refusal->json('usage.0.type'))->toBe('product');

    // Nothing was removed — not the row, and not the file. A broken image on a
    // live product page is worse than a cluttered library.
    expect(Media::query()->whereKey($media->id)->exists())->toBeTrue()
        ->and(is_file($dir.'/still-used.png'))->toBeTrue();

    // With an explicit force, which the screen only sends after showing that
    // list, it goes.
    test()->deleteJson('/admin-api/media/'.$media->id.'?force=1')->assertOk();

    expect(Media::query()->whereKey($media->id)->exists())->toBeFalse()
        ->and(is_file($dir.'/still-used.png'))->toBeFalse();

    mlClearUploads();
});

it('deletes an unused image and removes the file with it', function () {
    asMlAdmin();
    mlClearUploads();

    $dir = public_path('uploads/products');
    @mkdir($dir, 0755, true);
    file_put_contents($dir.'/unused.png', mlPngBytes());

    $media = mlMedia(['filename' => 'unused.png', 'path' => 'uploads/products/unused.png']);

    $response = test()->deleteJson('/admin-api/media/'.$media->id)->assertOk();

    expect($response->json('file_removed'))->toBeTrue()
        ->and($response->json('forced'))->toBeFalse()
        ->and(Media::query()->whereKey($media->id)->exists())->toBeFalse()
        ->and(is_file($dir.'/unused.png'))->toBeFalse();

    mlClearUploads();
});

it('never reaches outside its own web root to unlink an imported file', function () {
    asMlAdmin();

    /*
     * An imported row's path is under /wp-content/uploads/, which on the server
     * is a DIFFERENT directory belonging to the old store — bootstrap/app.php's
     * usePublicPath means the application root and the web root are not the
     * same place, and this tree cannot see that one at all. Forgetting the row
     * is right; unlinking somebody else's file is not.
     */
    $media = mlMedia(['filename' => 'imported.jpg', 'path' => '2024/07/imported.jpg']);

    /*
     * A REAL FILE AT THE PATH THE MUTATION WOULD REACH FOR.
     *
     * The first version of this test asserted only that `file_removed` came
     * back false — and it passed just as happily with the `uploads/` guard
     * replaced by `if (true)`, because public_path('2024/07/imported.jpg')
     * did not exist, so is_file() was false and nothing was unlinked either
     * way. It was asserting that the file was absent, not that the code had
     * declined to touch it. Putting a file there is what makes the difference
     * between those two observable.
     */
    $decoy = public_path('2024/07');
    @mkdir($decoy, 0755, true);
    file_put_contents($decoy.'/imported.jpg', mlPngBytes());

    $response = test()->deleteJson('/admin-api/media/'.$media->id)->assertOk();

    expect($response->json('file_removed'))->toBeFalse()
        ->and(is_file($decoy.'/imported.jpg'))->toBeTrue('an imported file was unlinked out of the web root')
        ->and(Media::query()->whereKey($media->id)->exists())->toBeFalse();

    @unlink($decoy.'/imported.jpg');
    @rmdir($decoy);
});

it('serves an imported path from wp-content and an uploaded one from the web root', function () {
    $imported = mlMedia(['filename' => 'old.jpg', 'path' => '2024/07/old.jpg']);
    $uploaded = mlMedia(['filename' => 'new.png', 'path' => 'uploads/products/new.png']);

    // D-45: imported media keeps its /wp-content/uploads/ URL so nothing
    // anybody has ever shared breaks.
    expect($imported->url())->toContain('/wp-content/uploads/2024/07/old.jpg');

    // An admin upload is NOT under that root — it is written straight into the
    // public web root — and prefixing it would produce
    // /wp-content/uploads/uploads/products/new.png, which 404s.
    expect($uploaded->url())->toContain('/uploads/products/new.png')
        ->and($uploaded->url())->not->toContain('/wp-content/');
});

/* ───────────────────────────── the Modules page ────────────────────────── */

it('lists Media Library and Reviews on the Modules page, each linking to a real screen', function () {
    /*
     * THE OWNER'S SECOND QUESTION, pinned so the answer cannot quietly revert.
     *
     *     "For Media page it says the module is not installed yet, and not
     *      showing in modules page. and also for reviews. the same."
     *
     * They were absent for one reason: ModuleRegistry::REGISTRY had no key for
     * either. ModulesApiController::show() iterates REGISTRY and nothing else,
     * so no amount of building the screens could ever have made them appear —
     * it was an omission, not a status, and nothing would have caught it.
     */
    $registry = \App\Services\ModuleRegistry::REGISTRY;

    expect($registry)->toHaveKey('media_library')
        ->and($registry)->toHaveKey('reviews');

    /*
     * The two need DIFFERENT statuses, and getting that wrong in either
     * direction is a lie on the owner's screen:
     *
     *   media_library  no switch exists anywhere → 'screen', drawn with no
     *                  toggle and the note "always on".
     *
     *   reviews        a switch ALREADY exists — ProductSections::REGISTRY
     *                  carries its own 'reviews' entry and
     *                  store/product.blade.php gates the section on it — so
     *                  'screen' would claim "always on" about something the
     *                  owner can switch off, and 'live' would draw a SECOND
     *                  toggle for one feature, stored where nothing reads it.
     *                  'elsewhere' points at the real switch.
     */
    foreach ([
        'media_library' => ['media', 'screen'],
        'reviews' => ['productpage', 'elsewhere'],
    ] as $key => [$route, $status]) {
        // [group, name, desc, default, screen, route, surface, band, where, status]
        expect($registry[$key][5])->toBe($route, $key.' must link at a console route that exists');

        // A row with no route renders "screen not built yet", which is the
        // very message this change exists to stop the owner seeing.
        expect($registry[$key][5])->not->toBe('');

        expect($registry[$key][4])->not->toBe('', $key.' must name the screen it opens');

        expect($registry[$key][9])->toBe($status);
    }

    /*
     * And the switch Reviews actually has is still the only one. If a `reviews`
     * key ever appears in ModuleRegistry as `live`, this catches the second
     * toggle before an operator finds it by flipping one and watching nothing
     * happen.
     */
    expect(\App\Services\ProductSections::REGISTRY)->toHaveKey('reviews');

    expect(file_get_contents(base_path('resources/views/store/product.blade.php')))
        ->toContain("hidden('reviews')");

    // And the screen renders the new status honestly rather than falling
    // through to the blank note an unknown status would produce.
    $console = file_get_contents(base_path('resources/views/admin/app.blade.php'));

    expect($console)->toContain("m.status === 'screen'");
});

it('still has a real reader for every module the registry calls live', function () {
    /*
     * The same guard Phase3ModuleSwitchesTest applies, asserted here too
     * because THIS lane added two registry rows and the tempting shortcut was
     * to mark them `live` — which would have meant writing a moduleEnabled()
     * call that nothing acts on, purely to satisfy that grep. That is
     * fabricating the evidence the guard exists to check, so it is worth this
     * file carrying its own copy of the consequence.
     */
    foreach (['media_library', 'reviews'] as $key) {
        expect(\App\Services\ModuleRegistry::REGISTRY[$key][9])->not->toBe('live');
    }
});

it('reports both new rows through the Modules API the console actually reads', function () {
    $admin = mlAdmin();

    $modules = collect(
        test()->actingAs($admin, 'admin')->getJson('/admin-api/modules')->assertOk()->json('groups')
    )->flatMap(fn ($g) => $g['modules'])->keyBy('key');

    // Asserted through the endpoint, not off the constant: show() destructures
    // each row into ten named fields, so a row with the wrong number of
    // elements would break there and nowhere else.
    expect($modules)->toHaveKey('media_library')
        ->and($modules)->toHaveKey('reviews')
        ->and($modules['media_library']['route'])->toBe('media')
        ->and($modules['media_library']['status'])->toBe('screen')
        // Reviews points at the screen that carries its real switch, not at a
        // second one here. See the note on the row in ModuleRegistry.
        ->and($modules['reviews']['route'])->toBe('productpage')
        ->and($modules['reviews']['status'])->toBe('elsewhere')
        // Always on, so the row never reads as a feature the owner forgot to
        // switch on.
        ->and($modules['media_library']['on'])->toBeTrue()
        ->and($modules['reviews']['on'])->toBeTrue();
});

/* ─────────────────────────── the screen itself ─────────────────────────── */

it('renders the media library screen inside the admin console', function () {
    $admin = mlAdmin();

    $path = \App\Models\Setting::map()['admin_path'] ?? 'admin';

    $html = test()->actingAs($admin, 'admin')->get('/'.ltrim((string) $path, '/'))
        ->assertOk()
        ->getContent();

    // The partial is included, and its route override is in the page.
    expect($html)->toContain('mlib-root')
        ->and($html)->toContain('__mlibRenderForTest');

    /*
     * And the console no longer MAPS 'media' onto the standalone file that has
     * never existed — which is what produced the "isn't installed yet" card the
     * owner is asking about.
     *
     * Asserted on the FRAME_SRC entry, not on the filename alone: the filename
     * is still named in two comments that explain why it was removed, and a
     * test that went red over its own explanation would be pressure to delete
     * the explanation. This is the pair that would actually restore the bug.
     */
    expect($html)->not->toContain("'media':'kbb-admin-media.html'")
        ->and($html)->not->toContain('"media":"kbb-admin-media.html"');
});

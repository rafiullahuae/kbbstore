<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Media;
use App\Models\MediaUsageRecord;
use App\Models\Product;
use App\Support\MediaBackfill;
use App\Support\MediaUsage;
use App\Support\MediaUsageWriter;
use Illuminate\Support\Facades\Schema;
use Tests\Support\MediaLibraryRoutes;
use Tests\Support\SqlShape;

/**
 * `media_usages` — the recorded answer to "which image belongs to what".
 * (Lane BF)
 *
 * THE ASSERTION THAT MATTERS IS AGREEMENT. The schema has never recorded an
 * attachment: it keeps a URL string on the owning row, and App\Support\
 * MediaUsage derives the association by walking every product, brand and
 * category. This table indexes that walk. A table that said something
 * DIFFERENT from the walk would be worse than no table, because the Media
 * Library would then either hide images that are in use or offer to delete
 * images that are on the shop.
 *
 * So the test that carries the lane is uxAgrees() below: for EVERY media row
 * it asserts the recorded owners are exactly the derived owners — both that
 * the table finds what it should and that it excludes what it should not. One
 * without the other passes against a table that records everything, or
 * nothing.
 *
 * WHAT IS DELIBERATELY NOT ASSERTED TO AGREE is the delete guard, because it
 * does not read this table at all. That is the design: see the head of
 * database/migrations/2026_10_10_000000_create_media_usages.php. The test
 * "it refuses a delete from the derivation even when the table has been
 * emptied" pins that on purpose, by corrupting the table and checking the
 * guard still refuses.
 */
function uxAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'MU Owner',
        'email' => 'mu-owner-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

function asUxAdmin(): void
{
    MediaLibraryRoutes::wire(app());
    test()->actingAs(uxAdmin(), 'admin');
}

/** A media row whose stored path is a real-looking upload path. */
function uxMedia(string $name, string $folder = 'uploads/products'): Media
{
    return Media::create([
        'filename' => $name,
        'path' => rtrim($folder, '/').'/'.$name,
        'mime' => 'image/png',
        'size' => 1024,
        'alt' => '',
    ]);
}

/** The recorded owners of a media row, in the shape the derivation returns. */
function uxRecorded(Media $media): array
{
    return MediaUsageRecord::query()
        ->where('media_id', $media->id)
        ->orderBy('owner_type')
        ->orderBy('owner_id')
        ->orderBy('field')
        ->get()
        ->map(fn ($r) => $r->owner_type.'#'.$r->owner_id.':'.$r->field)
        ->all();
}

/** The DERIVED owners of a media row, reduced to the same shape. */
function uxDerived(Media $media): array
{
    $usage = MediaUsage::verify(MediaUsage::index(), (string) $media->filename, (string) $media->path);

    $out = [];

    foreach ($usage as $u) {
        // The derivation labels a gallery entry by position ("Gallery image
        // 2"); the table stores the column. Reduced to the column here so the
        // two are comparable, and deduplicated because the table's grain is
        // the set — the migration header says why.
        $column = match (true) {
            $u['field'] === 'Logo' => 'logo',
            str_starts_with($u['field'], 'Gallery image') => 'images',
            default => 'image',
        };

        $out[$u['type'].'#'.$u['id'].':'.$column] = true;
    }

    $out = array_keys($out);
    sort($out);

    return $out;
}

/**
 * The whole point, asserted over every media row there is.
 *
 * Both directions, always: what the table holds for a row must equal what the
 * derivation says about it, so a row that should have no owners is asserted to
 * have none rather than merely not checked.
 */
function uxAgrees(string $because = ''): void
{
    $rows = Media::query()->get();

    expect($rows)->not->toBeEmpty('nothing to compare — the test seeded no media');

    foreach ($rows as $media) {
        $recorded = uxRecorded($media);
        sort($recorded);

        expect($recorded)->toBe(
            uxDerived($media),
            "media #{$media->id} ({$media->path}) disagrees with the derivation".($because !== '' ? " — {$because}" : '')
        );
    }
}

/* ─────────────── the hooks must survive the table not existing ─────────── */

it('re-migrates from scratch in a process that has already migrated', function () {
    /*
     * THE BUG THIS PINS, because it took the whole MySQL suite down once.
     *
     * The hooks are registered at boot and stay registered while migrations
     * run. 2026_08_27_100000_seed_demo_catalogue creates categories and
     * products through Eloquent, which fires `created`, long before
     * 2026_10_10_000000 has made a `media_usages` for the answer to go in. So
     * the writer asks whether the table exists before writing.
     *
     * The first version of that check REMEMBERED the answer in a static, and
     * a remembered YES is what broke: anything that runs `migrate:fresh` in a
     * process that has already migrated drops the table and re-runs the
     * migration set with the memo still saying the table is there. On MySQL a
     * test doing DDL inside RefreshDatabase's transaction triggers exactly
     * that, because DDL implicitly commits and the trait rebuilds the
     * database. The seeder's first Eloquent create then issued
     * `delete from media_usages` against a table that did not exist, the
     * migration aborted, and 600 tests failed after it.
     *
     * So this drops the table and migrates again in THIS process, which is
     * the shape that failed. It is also the exact shape of a package being
     * applied on the live host, where the app is long-running and the
     * migration set runs underneath it.
     */
    /*
     * FIRST, make the writer answer "yes, there is a table" for real. The bug
     * is a STALE yes, so a test that drops the table before the writer has
     * ever looked would pass against the memo it is meant to catch — the
     * first version of this test did exactly that and the mutation survived.
     */
    $before = uxMedia('before-remigrate.png');

    Product::create([
        'name' => 'MU Before Remigrate',
        'slug' => 'mu-before-remigrate-'.uniqid(),
        'price' => 1000,
        'image' => '/'.$before->path,
    ]);

    expect(uxRecorded($before))->toHaveCount(1, 'the writer never wrote, so nothing could go stale');

    Schema::dropIfExists('media_usages');

    expect(Schema::hasTable('media_usages'))->toBeFalse();

    // A save with the table absent must be a no-op, not an exception — this
    // is what the seeder does during the migration run.
    $category = Category::create([
        'name' => 'MU No Table',
        'slug' => 'mu-no-table-'.uniqid(),
    ]);

    $category->image = '/uploads/categories/whatever.png';

    expect(fn () => $category->save())->not->toThrow(Exception::class);

    // Deleting an owner must be a no-op too; forgetOwner() runs on `deleted`.
    expect(fn () => $category->delete())->not->toThrow(Exception::class);

    // And a media row appearing while the table is gone.
    expect(fn () => uxMedia('no-table.png'))->not->toThrow(Exception::class);

    // Put it back the way the migration does, and the writer must notice
    // without being told.
    $migration = require base_path('database/migrations/2026_10_10_000000_create_media_usages.php');
    $migration->up();

    expect(Schema::hasTable('media_usages'))->toBeTrue();

    $media = uxMedia('after-remigrate.png');

    $product = Product::create([
        'name' => 'MU After Remigrate',
        'slug' => 'mu-after-remigrate-'.uniqid(),
        'price' => 1000,
        'image' => '/'.$media->path,
    ]);

    expect(uxRecorded($media))->toBe(
        ['product#'.$product->id.':image'],
        'the writer did not notice the table coming back'
    );
});

/* ───────────────── the predicate both answers are built on ─────────────── */

it('answers matches() on its own, without a caller having pre-bucketed by name', function () {
    /*
     * MediaUsage::matches() is public and is the single predicate behind both
     * MediaUsage::verify() and the media_usages index. Its two existing
     * callers both look the candidate up in a basename bucket FIRST, so the
     * basename half of the predicate is never exercised through them — a
     * mutation deleting it stayed green until this test existed.
     *
     * It is tested directly because the method is public: the next caller may
     * not pre-bucket, and a predicate that silently ignores the filename would
     * then match every URL against every media row.
     */

    // The basename half.
    expect(MediaUsage::matches('/uploads/products/a.png', 'a.png', 'uploads/products/a.png'))->toBeTrue();
    expect(MediaUsage::matches('/uploads/products/b.png', 'a.png', 'uploads/products/a.png'))
        ->toBeFalse('a URL naming a different file matched');

    // The full-path half: same basename, different folder.
    expect(MediaUsage::matches('/uploads/2021/logo.png', 'logo.png', 'uploads/2022/logo.png'))
        ->toBeFalse('two folders sharing a basename were treated as one file');
    expect(MediaUsage::matches('/uploads/2022/logo.png', 'logo.png', 'uploads/2022/logo.png'))->toBeTrue();

    // A media row with no directory part left has nothing to check, so the
    // basename alone decides — broad, and deliberately so: demanding a path
    // match would make such a row impossible to find at all.
    expect(MediaUsage::matches('/uploads/anywhere/bare.png', 'bare.png', 'bare.png'))->toBeTrue();
    expect(MediaUsage::matches('/uploads/anywhere/other.png', 'bare.png', 'bare.png'))->toBeFalse();

    // An empty URL names nothing and must never match — including against a
    // media row that is itself blank, which is the only case where the empty
    // check does work the basename comparison would not already have done.
    expect(MediaUsage::matches('', 'a.png', 'uploads/products/a.png'))->toBeFalse();
    expect(MediaUsage::matches('   ', 'a.png', 'uploads/products/a.png'))->toBeFalse();
    expect(MediaUsage::matches('', '', ''))
        ->toBeFalse('an empty URL matched an empty media row');
    expect(MediaUsage::matches('?v=3', '', ''))
        ->toBeFalse('a URL that reduces to nothing matched an empty media row');
});

/* ─────────────────────── the backfill, which is the point ──────────────── */

it('backfills through the migration itself, not only through the writer', function () {
    /*
     * The deliverable is a MIGRATION that backfills, so the migration is what
     * is run here. Calling MediaUsageWriter::rebuild() directly would leave
     * the one file that actually ships on a package untested — and a backfill
     * that never runs is the failure this lane exists to avoid: the Media
     * Library would report every older image as unused and offer to delete
     * images that are on the shop.
     */
    $used = uxMedia('migration-used.png');
    $free = uxMedia('migration-free.png');

    $product = Product::create([
        'name' => 'MU Migration',
        'slug' => 'mu-migration-'.uniqid(),
        'price' => 1000,
        'image' => '/'.$used->path,
    ]);

    // A store whose associations were never recorded.
    MediaUsageRecord::query()->delete();

    $migration = require base_path('database/migrations/2026_10_10_000001_backfill_media_usages.php');
    $migration->up();

    expect(uxRecorded($used))->toBe(['product#'.$product->id.':image'])
        ->and(uxRecorded($free))->toBe([], 'the migration recorded a file nothing uses');

    uxAgrees('after the backfill migration');

    // And it is safe to apply the package twice, which on this host happens.
    $migration->up();

    expect(MediaUsageRecord::query()->where('media_id', $used->id)->count())
        ->toBe(1, 'running the migration twice duplicated a row');

    uxAgrees('after the backfill migration ran twice');
});

it('refuses a duplicate row at the database level', function () {
    // The idempotence above is the writer being careful. This is the table
    // refusing regardless, which is what makes a second writer — a future
    // lane, a half-applied package — unable to double-record.
    $media = uxMedia('unique-constraint.png');

    $product = Product::create([
        'name' => 'MU Unique',
        'slug' => 'mu-unique-'.uniqid(),
        'price' => 1000,
        'image' => '/'.$media->path,
    ]);

    $row = [
        'media_id' => $media->id,
        'owner_type' => 'product',
        'owner_id' => $product->id,
        'field' => 'image',
        'created_at' => now(),
        'updated_at' => now(),
    ];

    expect(fn () => MediaUsageRecord::query()->insert($row))
        ->toThrow(Illuminate\Database\QueryException::class);

    // A DIFFERENT field on the same pair is legitimate and must still insert:
    // a product can carry one file as both its main image and a gallery shot.
    MediaUsageRecord::query()->insert(['field' => 'images'] + $row);

    expect(MediaUsageRecord::query()->where('media_id', $media->id)->count())->toBe(2);
});

it('backfills every association that already exists, and no others', function () {
    /*
     * The store as it is on the day this ships: rows saved long before
     * anything recorded an association. Written straight to the models, then
     * the table is emptied, so this really is "a catalogue that predates the
     * feature" rather than one the hooks already filled in.
     */
    $onProduct = uxMedia('backfill-main.png');
    $inGallery = uxMedia('backfill-gallery.png');
    $onBrand = uxMedia('backfill-logo.png', 'uploads/brands');
    $onCategory = uxMedia('backfill-cat.png', 'uploads/categories');
    $orphan = uxMedia('backfill-nobody.png');

    $product = Product::create([
        'name' => 'MU Backfill Serum',
        'slug' => 'mu-backfill-'.uniqid(),
        'price' => 5000,
        'image' => '/'.$onProduct->path,
        'images' => ['/'.$inGallery->path],
    ]);

    $brand = Brand::create([
        'name' => 'MU Backfill Brand',
        'slug' => 'mu-backfill-brand-'.uniqid(),
        'logo' => '/'.$onBrand->path,
    ]);

    $category = Category::create([
        'name' => 'MU Backfill Category',
        'slug' => 'mu-backfill-cat-'.uniqid(),
        'image' => '/'.$onCategory->path,
    ]);

    MediaUsageRecord::query()->delete();
    expect(MediaUsageRecord::query()->count())->toBe(0);

    $result = MediaUsageWriter::rebuild();

    expect($result['added'])->toBe(4)
        ->and($result['removed'])->toBe(0);

    // What it found.
    expect(uxRecorded($onProduct))->toBe(['product#'.$product->id.':image'])
        ->and(uxRecorded($inGallery))->toBe(['product#'.$product->id.':images'])
        ->and(uxRecorded($onBrand))->toBe(['brand#'.$brand->id.':logo'])
        ->and(uxRecorded($onCategory))->toBe(['category#'.$category->id.':image']);

    // And what it correctly did NOT find. Without this the test passes against
    // a backfill that records every media row against every owner.
    expect(uxRecorded($orphan))->toBe([]);

    uxAgrees('straight after the backfill');
});

it('backfills the same rows twice without duplicating any of them', function () {
    $media = uxMedia('idempotent.png');

    $product = Product::create([
        'name' => 'MU Idempotent',
        'slug' => 'mu-idem-'.uniqid(),
        'price' => 1000,
        'image' => '/'.$media->path,
    ]);

    MediaUsageRecord::query()->delete();

    $first = MediaUsageWriter::rebuild();
    $countAfterFirst = MediaUsageRecord::query()->count();

    $second = MediaUsageWriter::rebuild();

    expect($first['added'])->toBe(1)
        ->and($second['added'])->toBe(0, 'the second run inserted a duplicate')
        ->and($second['removed'])->toBe(0, 'the second run deleted a row it should have kept')
        ->and($second['kept'])->toBe(1)
        ->and(MediaUsageRecord::query()->count())->toBe($countAfterFirst);

    // Belt and braces: the unique index really is on the four columns, so a
    // duplicate could not have been stored even if rebuild() had tried.
    expect(
        MediaUsageRecord::query()
            ->where('media_id', $media->id)
            ->where('owner_type', 'product')
            ->where('owner_id', $product->id)
            ->count()
    )->toBe(1);
});

it('agrees with the derivation for every URL shape this store actually stores', function () {
    /*
     * The same list MediaLibraryTest pins for the derivation, applied to the
     * recorded answer. These are not hypothetical: the importer writes
     * absolute URLs, the upload endpoint writes root-relative ones, and the
     * last is the URL parse_url() refuses outright, which is the only case
     * where MediaUsage's explicit query-strip is load-bearing.
     */
    $media = uxMedia('shapes.png');

    foreach ([
        'https://kbeautybliss.com/uploads/products/shapes.png',
        '/uploads/products/shapes.png',
        'uploads/products/shapes.png',
        '/uploads/products/shapes.png?v=3',
        'http://:80/uploads/products/shapes.png?v=3',
    ] as $i => $stored) {
        Product::query()->forceDelete();
        MediaUsageRecord::query()->delete();

        $product = Product::create([
            'name' => 'MU Shape '.$i,
            'slug' => 'mu-shape-'.$i.'-'.uniqid(),
            'price' => 1000,
            'image' => $stored,
        ]);

        // Recorded by the save hook, with no rebuild in between.
        expect(uxRecorded($media))->toBe(
            ['product#'.$product->id.':image'],
            "a URL stored as `{$stored}` was not recorded"
        );

        uxAgrees("stored as `{$stored}`");
    }
});

it('tells two media rows that share a basename apart', function () {
    /*
     * The imprecision MediaUsage's own comment names: two wp-content imports
     * can share `logo.png`. The derivation's grid filter buckets by basename
     * and shows both; verify() and this table check the full stored path.
     */
    $twentyOne = uxMedia('logo.png', 'uploads/2021');
    $twentyTwo = uxMedia('logo.png', 'uploads/2022');

    $brand = Brand::create([
        'name' => 'MU Collide',
        'slug' => 'mu-collide-'.uniqid(),
        'logo' => '/'.$twentyOne->path,
    ]);

    expect(uxRecorded($twentyOne))->toBe(['brand#'.$brand->id.':logo'])
        ->and(uxRecorded($twentyTwo))->toBe([], 'the same-named file in another folder was recorded too');

    uxAgrees('with two media rows sharing a basename');
});

/* ──────────────────────── no drift on the wired paths ──────────────────── */

it('moves the record when a product image is changed through the real save endpoint', function () {
    asUxAdmin();
    Tests\Support\ProductEditorRoutes::wire(app());

    $before = uxMedia('swap-before.png');
    $after = uxMedia('swap-after.png');

    $product = Product::create([
        'name' => 'MU Swap',
        'slug' => 'mu-swap-'.uniqid(),
        'price' => 2500,
        'image' => '/'.$before->path,
    ]);

    expect(uxRecorded($before))->toBe(['product#'.$product->id.':image'])
        ->and(uxRecorded($after))->toBe([]);

    // The real endpoint, not a model write: the hook has to survive whatever
    // the controller does between validation and save().
    test()->postJson('/admin-api/product-editor-save/'.$product->id, [
        'image' => '/'.$after->path,
    ])->assertOk();

    expect(uxRecorded($before))->toBe([], 'the old association was left behind')
        ->and(uxRecorded($after))->toBe(['product#'.$product->id.':image']);

    uxAgrees('after a save through the editor endpoint');
});

it('records a gallery saved through the real save endpoint, and drops what was removed', function () {
    asUxAdmin();
    Tests\Support\ProductEditorRoutes::wire(app());

    $kept = uxMedia('gallery-kept.png');
    $dropped = uxMedia('gallery-dropped.png');
    $added = uxMedia('gallery-added.png');

    $product = Product::create([
        'name' => 'MU Gallery',
        'slug' => 'mu-gallery-'.uniqid(),
        'price' => 2500,
        'images' => ['/'.$kept->path, '/'.$dropped->path],
    ]);

    expect(uxRecorded($dropped))->toBe(['product#'.$product->id.':images']);

    test()->postJson('/admin-api/product-editor-save/'.$product->id, [
        'images' => ['/'.$kept->path, '/'.$added->path],
    ])->assertOk();

    expect(uxRecorded($kept))->toBe(['product#'.$product->id.':images'])
        ->and(uxRecorded($added))->toBe(['product#'.$product->id.':images'])
        ->and(uxRecorded($dropped))->toBe([], 'an image taken out of the gallery kept its row');

    uxAgrees('after a gallery edit');
});

it('records a brand logo saved through the real brand endpoint', function () {
    Tests\Support\BrandAdminRoutes::wire(app());
    test()->actingAs(uxAdmin(), 'admin');

    $first = uxMedia('brand-endpoint.png', 'uploads/brands');
    $second = uxMedia('brand-endpoint-2.png', 'uploads/brands');

    test()->postJson('/admin-api/brands', [
        'name' => 'MU Endpoint Brand',
        'logo' => '/'.$first->path,
    ])->assertStatus(201);

    $brand = Brand::query()->where('name', 'MU Endpoint Brand')->firstOrFail();

    expect(uxRecorded($first))->toBe(['brand#'.$brand->id.':logo'])
        ->and(uxRecorded($second))->toBe([]);

    // And the swap, through the real update endpoint.
    test()->putJson('/admin-api/brands/'.$brand->id, [
        'name' => 'MU Endpoint Brand',
        'slug' => $brand->slug,
        'logo' => '/'.$second->path,
    ])->assertOk();

    expect(uxRecorded($first))->toBe([], 'the old logo kept its row')
        ->and(uxRecorded($second))->toBe(['brand#'.$brand->id.':logo']);

    uxAgrees('after brand saves');
});

it('records a category image saved through the real category endpoint', function () {
    Tests\Support\CategoryLaneRoutes::wire(app());
    test()->actingAs(uxAdmin(), 'admin');

    $first = uxMedia('cat-endpoint.png', 'uploads/categories');
    $second = uxMedia('cat-endpoint-2.png', 'uploads/categories');

    test()->postJson('/admin-api/categories', [
        'name' => 'MU Endpoint Category',
        'image' => '/'.$first->path,
    ])->assertStatus(201);

    $category = Category::query()->where('name', 'MU Endpoint Category')->firstOrFail();

    expect(uxRecorded($first))->toBe(['category#'.$category->id.':image'])
        ->and(uxRecorded($second))->toBe([]);

    test()->putJson('/admin-api/categories/'.$category->id, [
        'name' => 'MU Endpoint Category',
        'slug' => $category->slug,
        'image' => '/'.$second->path,
    ])->assertOk();

    expect(uxRecorded($first))->toBe([], 'the old category image kept its row')
        ->and(uxRecorded($second))->toBe(['category#'.$category->id.':image']);

    uxAgrees('after category saves');
});

it('forgets a product that goes into the bin and remembers it when restored', function () {
    /*
     * MediaUsage::index() goes through Product::query(), which the
     * SoftDeletingScope filters — so a trashed product's image is free, and
     * MediaLibraryTest pins exactly that for the derivation. The table has to
     * say the same thing or the two screens disagree about what is deletable.
     */
    $media = uxMedia('trashed.png');

    $product = Product::create([
        'name' => 'MU Trashed',
        'slug' => 'mu-trashed-'.uniqid(),
        'price' => 1000,
        'image' => '/'.$media->path,
    ]);

    expect(uxRecorded($media))->toBe(['product#'.$product->id.':image']);

    $product->delete();

    expect(uxRecorded($media))->toBe([], 'a trashed product kept its image reserved');
    uxAgrees('with the owner in the bin');

    $product->restore();

    expect(uxRecorded($media))->toBe(['product#'.$product->id.':image']);
    uxAgrees('after the owner was restored');
});

it('drops the rows for a media file that is deleted', function () {
    $media = uxMedia('deleted-media.png');

    Product::create([
        'name' => 'MU Media Delete',
        'slug' => 'mu-media-delete-'.uniqid(),
        'price' => 1000,
        'image' => '/'.$media->path,
    ]);

    expect(MediaUsageRecord::query()->where('media_id', $media->id)->count())->toBe(1);

    $id = (int) $media->id;
    $media->delete();

    expect(MediaUsageRecord::query()->where('media_id', $id)->count())
        ->toBe(0, 'deleting a media row left its usage rows behind');
});

it('records usage for files the rescan catalogues, which insert without firing events', function () {
    /*
     * MediaBackfill uses Media::query()->insert(), a QUERY-BUILDER bulk insert
     * that fires no Eloquent `created` event. If that path did not record
     * usage explicitly, every file the rescan catalogues would have a media
     * row and no usage rows — which reads as "unused" and invites a delete of
     * an image that is on the shop. This is the dangerous direction, so it is
     * pinned.
     */
    $root = public_path('uploads/products');

    if (! is_dir($root)) {
        mkdir($root, 0777, true);
    }

    $name = 'rescan-'.uniqid().'.png';
    file_put_contents($root.'/'.$name, 'not really a png');

    $product = Product::create([
        'name' => 'MU Rescan',
        'slug' => 'mu-rescan-'.uniqid(),
        'price' => 1000,
        // The URL was on the product long before the file was catalogued.
        'image' => '/uploads/products/'.$name,
    ]);

    // No media row yet, so nothing can be recorded against it.
    expect(Media::query()->where('filename', $name)->exists())->toBeFalse();

    MediaBackfill::run();

    $media = Media::query()->where('filename', $name)->firstOrFail();

    expect(uxRecorded($media))->toBe(
        ['product#'.$product->id.':image'],
        'a rescanned file was catalogued but its usage was not recorded'
    );

    uxAgrees('after a rescan');

    @unlink($root.'/'.$name);
});

it('records usage for an upload whose URL was already on a product', function () {
    // The same ordering problem as the rescan, through the Eloquent path: the
    // media row appears AFTER the product already names the file.
    $product = Product::create([
        'name' => 'MU Late Media',
        'slug' => 'mu-late-'.uniqid(),
        'price' => 1000,
        'image' => '/uploads/products/late.png',
    ]);

    $media = uxMedia('late.png');

    expect(uxRecorded($media))->toBe(['product#'.$product->id.':image']);
    uxAgrees('when the media row arrived last');
});

it('leaves the table alone when a save touches no image column', function () {
    /*
     * The `saved` hook skips a save that moved no image column, so an import
     * writing descriptions and prices across the catalogue does not re-derive
     * every product's rows. The row it already had must survive that skip —
     * a skip that also dropped the row would be a silent under-report, which
     * is the dangerous direction.
     */
    $media = uxMedia('untouched.png');

    $product = Product::create([
        'name' => 'MU Untouched',
        'slug' => 'mu-untouched-'.uniqid(),
        'price' => 1000,
        'image' => '/'.$media->path,
    ]);

    $before = MediaUsageRecord::query()->where('media_id', $media->id)->firstOrFail();

    $product->price = 2000;
    $product->save();

    $after = MediaUsageRecord::query()->where('media_id', $media->id)->firstOrFail();

    expect(uxRecorded($media))->toBe(['product#'.$product->id.':image'])
        // The same row, not a rewritten one: the skip really did skip.
        ->and((int) $after->id)->toBe((int) $before->id, 'the hook re-derived a save that changed no image');

    uxAgrees('after a save that touched no image column');

    // And a save that DOES move an image is still picked up, so the skip is
    // not simply disabling the hook.
    $other = uxMedia('untouched-new.png');

    $product->image = '/'.$other->path;
    $product->save();

    expect(uxRecorded($other))->toBe(['product#'.$product->id.':image'])
        ->and(uxRecorded($media))->toBe([]);

    uxAgrees('after the image did change');
});

it('records the same media twice without colliding with the unique index', function () {
    /*
     * syncMedia() clears a media row's rows before re-inserting them. Its two
     * callers today both hand it rows that have only just been inserted, so
     * nothing exercises that clear — a mutation deleting it stayed green until
     * this test existed. It is kept and tested rather than removed because the
     * method is public and because a rescan being safe to press twice is the
     * same idempotence requirement MediaBackfill's own header states.
     */
    $media = uxMedia('sync-twice.png');

    $product = Product::create([
        'name' => 'MU Sync Twice',
        'slug' => 'mu-sync-twice-'.uniqid(),
        'price' => 1000,
        'image' => '/'.$media->path,
    ]);

    MediaUsageWriter::syncMedia([$media]);
    MediaUsageWriter::syncMedia([$media]);

    expect(uxRecorded($media))->toBe(['product#'.$product->id.':image']);

    uxAgrees('after syncMedia ran twice on the same row');
});

/* ──────────────────── the guard does NOT trust the table ───────────────── */

it('refuses a delete from the derivation even when the table has been emptied', function () {
    /*
     * The design decision this lane turns on, asserted rather than asserted
     * about. `media_usages` is an index; the delete guard is the one place
     * where being wrong breaks the shop, so it reads MediaUsage::verify().
     *
     * Emptying the table simulates every way it can fall behind at once — a
     * future write path that forgets to record, a bulk delete, a package
     * applied with a stale services cache. The guard must still refuse.
     */
    asUxAdmin();

    $media = uxMedia('guarded.png');

    $product = Product::create([
        'name' => 'MU Guarded',
        'slug' => 'mu-guarded-'.uniqid(),
        'price' => 1000,
        'image' => '/'.$media->path,
    ]);

    MediaUsageRecord::query()->delete();

    $response = test()->deleteJson('/admin-api/media/'.$media->id);

    $response->assertStatus(409)
        ->assertJsonPath('used', true)
        ->assertJsonPath('usage.0.type', 'product')
        ->assertJsonPath('usage.0.id', $product->id);

    expect(Media::query()->whereKey($media->id)->exists())
        ->toBeTrue('the image was deleted while a product still used it');
});

it('still names the true owners in the detail panel when the table is empty', function () {
    // show() is what the operator reads immediately before pressing Delete, so
    // it is derived for the same reason destroy() is. If it read the table,
    // the confirmation and the refusal could disagree.
    asUxAdmin();

    $media = uxMedia('detail.png');

    $brand = Brand::create([
        'name' => 'MU Detail Brand',
        'slug' => 'mu-detail-'.uniqid(),
        'logo' => '/'.$media->path,
    ]);

    MediaUsageRecord::query()->delete();

    test()->getJson('/admin-api/media/'.$media->id)
        ->assertOk()
        ->assertJsonPath('item.usage.0.type', 'brand')
        ->assertJsonPath('item.usage.0.id', $brand->id)
        ->assertJsonPath('item.usage.0.field', 'Logo');
});

/* ───────────────────────────── the reconcile ───────────────────────────── */

it('reports drift in both directions and fixes it', function () {
    $used = uxMedia('reconcile-used.png');
    $free = uxMedia('reconcile-free.png');

    $product = Product::create([
        'name' => 'MU Reconcile',
        'slug' => 'mu-reconcile-'.uniqid(),
        'price' => 1000,
        'image' => '/'.$used->path,
    ]);

    // Clean: --check is happy and exits 0.
    test()->artisan('media:usages-reconcile --check')->assertExitCode(0);

    // Now break it in both directions at once: drop a row that should be
    // there, and invent one that should not.
    MediaUsageRecord::query()->where('media_id', $used->id)->delete();
    MediaUsageRecord::query()->insert([
        'media_id' => $free->id,
        'owner_type' => 'product',
        'owner_id' => $product->id,
        'field' => 'image',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // --check must NOTICE, and must change nothing.
    test()->artisan('media:usages-reconcile --check')->assertExitCode(1);

    expect(uxRecorded($used))->toBe([], '--check wrote to the table');
    expect(uxRecorded($free))->toBe(['product#'.$product->id.':image']);

    // And the real run repairs both directions.
    test()->artisan('media:usages-reconcile')->assertExitCode(0);

    expect(uxRecorded($used))->toBe(['product#'.$product->id.':image'])
        ->and(uxRecorded($free))->toBe([]);

    test()->artisan('media:usages-reconcile --check')->assertExitCode(0);

    uxAgrees('after a reconcile');
});

it('clears rows left behind by a bulk delete, which fires no model events', function () {
    /*
     * The known gap, pinned so it stays known. A bulk Eloquent delete fires no
     * events, so the rows survive their owner. The error lands on the SAFE
     * side — the grid over-reports usage, and nothing offers to delete a live
     * image — and the reconcile clears it.
     */
    $media = uxMedia('bulk-deleted.png');

    Product::create([
        'name' => 'MU Bulk',
        'slug' => 'mu-bulk-'.uniqid(),
        'price' => 1000,
        'image' => '/'.$media->path,
    ]);

    expect(uxRecorded($media))->toHaveCount(1);

    Product::query()->forceDelete();

    // Still there: this is the documented gap, not a claim that it cannot
    // happen.
    expect(uxRecorded($media))->toHaveCount(1)
        ->and(uxDerived($media))->toBe([]);

    test()->artisan('media:usages-reconcile --check')->assertExitCode(1);
    test()->artisan('media:usages-reconcile')->assertExitCode(0);

    expect(uxRecorded($media))->toBe([]);
    uxAgrees('after a bulk delete and a reconcile');
});

/* ───────────────────────── the grid reads the table ────────────────────── */

it('filters the grid by owner kind out of the table', function () {
    asUxAdmin();

    $onProduct = uxMedia('grid-product.png');
    $onBrand = uxMedia('grid-brand.png', 'uploads/brands');
    $free = uxMedia('grid-free.png');

    Product::create([
        'name' => 'MU Grid Product',
        'slug' => 'mu-grid-p-'.uniqid(),
        'price' => 1000,
        'image' => '/'.$onProduct->path,
    ]);

    Brand::create([
        'name' => 'MU Grid Brand',
        'slug' => 'mu-grid-b-'.uniqid(),
        'logo' => '/'.$onBrand->path,
    ]);

    $names = fn (string $q) => collect(test()->getJson('/admin-api/media?'.$q)->assertOk()->json('items'))
        ->pluck('filename')->all();

    // Each filter finds what it should AND excludes what it should not —
    // one without the other passes against a filter that matches everything.
    expect($names('attached=product'))->toContain($onProduct->filename)
        ->and($names('attached=product'))->not->toContain($onBrand->filename)
        ->and($names('attached=product'))->not->toContain($free->filename);

    expect($names('attached=brand'))->toContain($onBrand->filename)
        ->and($names('attached=brand'))->not->toContain($onProduct->filename);

    expect($names('attached=any'))->toContain($onProduct->filename)
        ->and($names('attached=any'))->toContain($onBrand->filename)
        ->and($names('attached=any'))->not->toContain($free->filename);

    expect($names('attached=unused'))->toContain($free->filename)
        ->and($names('attached=unused'))->not->toContain($onProduct->filename)
        ->and($names('attached=unused'))->not->toContain($onBrand->filename);
});

it('narrows the grid by owner name, and returns nothing for a name nobody has', function () {
    asUxAdmin();

    $cosrx = uxMedia('grid-cosrx.png');
    $anua = uxMedia('grid-anua.png');

    Product::create([
        'name' => 'MU COSRX Snail Essence',
        'slug' => 'mu-cosrx-'.uniqid(),
        'price' => 1000,
        'image' => '/'.$cosrx->path,
    ]);

    Product::create([
        'name' => 'MU Anua Toner',
        'slug' => 'mu-anua-'.uniqid(),
        'price' => 1000,
        'image' => '/'.$anua->path,
    ]);

    $names = fn (string $q) => collect(test()->getJson('/admin-api/media?'.$q)->assertOk()->json('items'))
        ->pluck('filename')->all();

    expect($names('attached=product&attached_q=COSRX'))->toContain($cosrx->filename)
        ->and($names('attached=product&attached_q=COSRX'))->not->toContain($anua->filename);

    // A name nobody has returns an empty grid rather than the whole library:
    // whereIn([]) must stay `0 = 1` and never become a tautology.
    expect($names('attached=product&attached_q=nobody-sells-this'))->toBe([]);
});

it('totals and pages an attachment filter over the whole filtered set', function () {
    /*
     * The attachment filter is now a whereExists against `media_usages`, and
     * that subquery flows into the COUNT/SUM that AggregatesQueries builds.
     * CLAUDE.md records the failure this guards: a surviving OFFSET on an
     * aggregate returns no row at all, so every total silently reads zero from
     * page two on while the endpoint still answers 200 — which shipped twice.
     * The existing totals test filters by NAME, so nothing exercised that path
     * with this subquery in it.
     *
     * MySQL runs this suite with ONLY_FULL_GROUP_BY (phpunit-mysql.xml pins it
     * deliberately), so an aggregate that disagreed with its grouping would
     * raise error 1140 here rather than in production.
     */
    asUxAdmin();

    // Two full pages of attached images and one unattached, so page 2 exists
    // and the filter has something to exclude.
    for ($i = 0; $i < 30; $i++) {
        $media = uxMedia('paged-'.$i.'.png');

        Product::create([
            'name' => 'MU Paged '.$i,
            'slug' => 'mu-paged-'.$i.'-'.uniqid(),
            'price' => 1000,
            'image' => '/'.$media->path,
        ]);
    }

    uxMedia('paged-free.png');

    $one = test()->getJson('/admin-api/media?attached=product')->assertOk();
    $two = test()->getJson('/admin-api/media?attached=product&page=2')->assertOk();

    expect($one->json('total'))->toBe(30, 'the total counted the unattached row, or miscounted')
        // The whole point: page two reports the same total as page one.
        ->and($two->json('total'))->toBe(30)
        ->and($one->json('pages'))->toBe(2)
        ->and($two->json('pages'))->toBe(2)
        ->and($one->json('bytes'))->toBe($two->json('bytes'))
        ->and($one->json('bytes'))->toBe(30 * 1024);

    // And the rows really are split across the two pages rather than repeated.
    expect(count($one->json('items')))->toBe(24)
        ->and(count($two->json('items')))->toBe(6);

    $names = array_merge(
        collect($one->json('items'))->pluck('filename')->all(),
        collect($two->json('items'))->pluck('filename')->all()
    );

    expect(count(array_unique($names)))->toBe(30)
        ->and($names)->not->toContain('paged-free.png');
});

it('issues portable SQL for every attachment filter the grid offers', function (string $query) {
    /*
     * The grid's attachment filter is now a whereExists subquery against
     * `media_usages`, and it flows into the COUNT/SUM that AggregatesQueries
     * builds as well as into the row query. SqlDialectGuardTest drives
     * /admin-api/media only WITHOUT query parameters, and drives
     * /admin-api/media/{media} which is the derived path — so nothing in the
     * repo looked at the SQL this filter actually issues.
     *
     * Judged by SqlShape rather than by the response, for the reason that
     * class's header gives: the suite runs on SQLite and the store runs on
     * MySQL, and every endpoint it lists once answered 200 here while failing
     * there. This keeps working without a MySQL server, and
     * phpunit-mysql.xml runs the same assertions against a real one.
     */
    asUxAdmin();

    $media = uxMedia('dialect.png');

    Product::create([
        'name' => 'MU Dialect Product',
        'slug' => 'mu-dialect-'.uniqid(),
        'price' => 1000,
        'image' => '/'.$media->path,
    ]);

    Brand::create([
        'name' => 'MU Dialect Brand',
        'slug' => 'mu-dialect-b-'.uniqid(),
        'logo' => '/'.uxMedia('dialect-logo.png', 'uploads/brands')->path,
    ]);

    Category::create([
        'name' => 'MU Dialect Category',
        'slug' => 'mu-dialect-c-'.uniqid(),
        'image' => '/'.uxMedia('dialect-cat.png', 'uploads/categories')->path,
    ]);

    $url = '/admin-api/media?'.$query;

    $captured = SqlShape::capture(function () use ($url) {
        test()->getJson($url)->assertOk();
    });

    expect($captured)->not->toBeEmpty("no SQL was issued for {$url}");
    expect(SqlShape::violations($captured))->toBe([], "portability violations on {$url}");
})->with([
    'attached=any',
    'attached=product',
    'attached=brand',
    'attached=category',
    'attached=unused',
    // The owner-name narrowing, which adds a whereIn of ids inside the
    // subquery on top of everything above.
    'attached=product&attached_q=Dialect',
    'attached=any&attached_q=Dialect',
    // Page two, because the aggregate and the page window are built from the
    // same builder and the second page is where a surviving OFFSET shows up.
    'attached=any&page=2',
    // Combined with the other filters, which is what an operator actually does.
    'attached=any&q=dialect',
]);

it('badges a tile with the owners recorded for it', function () {
    asUxAdmin();

    $media = uxMedia('badge.png');

    $product = Product::create([
        'name' => 'MU Badge',
        'slug' => 'mu-badge-'.uniqid(),
        'price' => 1000,
        'image' => '/'.$media->path,
    ]);

    $tile = collect(test()->getJson('/admin-api/media')->assertOk()->json('items'))
        ->firstWhere('filename', $media->filename);

    expect($tile['used'])->toBeTrue()
        ->and($tile['used_count'])->toBe(1)
        ->and($tile['used_types'])->toBe(['product']);

    // And a free file is badged free, so the assertion is not passing against
    // a tile that always says "used".
    $free = uxMedia('badge-free.png');

    $freeTile = collect(test()->getJson('/admin-api/media')->assertOk()->json('items'))
        ->firstWhere('filename', $free->filename);

    expect($freeTile['used'])->toBeFalse()
        ->and($freeTile['used_count'])->toBe(0)
        ->and($freeTile['used_types'])->toBe([]);

    expect($product->fresh())->not->toBeNull();
});

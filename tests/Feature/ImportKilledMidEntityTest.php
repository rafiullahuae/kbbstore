<?php

/*
 * AN IMPORT KILLED IN THE MIDDLE OF AN ENTITY, THEN RESUMED -- Lane KR.
 *
 * WHAT THE DEFECT LOOKED LIKE ON THE SHOP. tools/kr-kill-rehearsal.sh runs
 * `php artisan kbb:import` over the full-volume export, `kill -9`s it, runs the
 * same command again and diffs every table against an uninterrupted import.
 * Orders, line items, customers and products came back identical at every kill
 * point. Two things did not, and neither raised an error, a refusal or a count
 * mismatch -- every row was present and the report said "verified":
 *
 *   - killed after 10 of 59 categories: 9 categories at the TOP LEVEL, with the
 *     wrong depth and the wrong `path` (so the wrong URL), and the 9 imported
 *     menu items built from those paths pointing at the wrong addresses;
 *   - killed after 1,000 of 2,514 reviews: 32 products still rated 0.0 out of
 *     0 reviews on every card and every ?sort=rating while their own pages
 *     listed the reviews. sum(review_count) 1,403 where the clean import has
 *     1,449.
 *
 * ROOT CAUSE. Both entities defer work to finalise() -- the parent links, the
 * rating recompute -- and collect what finalise() needs in PROCESS MEMORY during
 * import(). A killed process never reaches finalise(); the resumed process
 * skips the committed rows by position and so never learns about them. Fixed by
 * EntityImporter::alreadyCommitted(), which the runner calls for each row it
 * passes over on resume.
 *
 * WHY ImportAtVolumeTest's resume case NEVER SAW IT. It stops each run with
 * --limit, and a --limit slice ENDS NORMALLY: it runs finalise() over its own
 * rows. A kill is the case where finalise() never runs, and nothing simulated
 * that. Here the "kill" is an exception thrown from inside a row's write, which
 * is what kill -9 is from the database's point of view: the open batch rolls
 * back, the committed ones stay, and nothing after the throw -- finalise()
 * included -- happens.
 *
 * MUTATION NOTE. Make the runner's skip branch `continue` without calling
 * $importer->alreadyCommitted() (ImportRunner::runEntity) and BOTH tests go red:
 * categories lose their parents, products keep a stale rating. Empty only
 * CategoryImporter::alreadyCommitted() and the first goes red alone; empty only
 * ReviewImporter::alreadyCommitted() and the second goes red alone.
 */

use App\Models\Category;
use App\Models\Product;
use App\Models\Review;
use App\Services\Import\ImportOptions;
use App\Services\Import\ImportRunner;
use App\Support\ProductRating;
use Illuminate\Support\Facades\DB;

/** The export, generated once per process: the rehearsal's own generator, at a size the suite can afford. */
function krDir(): string
{
    static $dir = null;

    if ($dir !== null) {
        return $dir;
    }

    $dir = sys_get_temp_dir().'/kbb-kr-'.getmypid().'-'.bin2hex(random_bytes(4));
    mkdir($dir, 0777, true);

    require_once base_path('tools/woo-volume-fixture/generate.php');

    (new VolumeFixture($dir, 4159, [
        'products' => 40,
        'orders' => 10,
        'customers' => 10,
        'reviews' => 90,
        'coupons' => 2,
        'brands' => 6,
        'categories' => 20,
    ]))->write();

    return $dir;
}

function krImport(string $entity, string $runKey, int $batch): void
{
    (new ImportRunner)->run(new ImportOptions(
        directory: krDir(),
        only: [$entity],
        batchSize: $batch,
        runKey: $runKey,
        adoptBySlug: true,
    ));
}

/**
 * Run the import and "kill" it on the Nth write of $model: the exception
 * unwinds the open batch and everything after it, exactly as SIGKILL does to
 * the database. Returns the checkpoint the dead process left.
 *
 * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
 */
function krKillOnWrite(string $model, int $write, string $entity, string $runKey, int $batch): int
{
    $armed = true;
    $writes = 0;

    $model::saving(function () use (&$armed, &$writes, $write): void {
        if ($armed && ++$writes >= $write) {
            throw new RuntimeException('kill -9 stand-in');
        }
    });

    try {
        krImport($entity, $runKey, $batch);
        $died = false;
    } catch (RuntimeException $e) {
        $died = $e->getMessage() === 'kill -9 stand-in';
    } finally {
        $armed = false;
    }

    expect($died)->toBeTrue('the import was not killed at all');

    $checkpoint = DB::table('import_checkpoints')->where('run_key', $runKey)->where('entity', $entity)->first();

    // The kill has to land INSIDE the entity, after at least one committed batch, or this proves nothing.
    expect($checkpoint)->not->toBeNull()
        ->and($checkpoint->finished_at)->toBeNull()
        ->and((int) $checkpoint->processed)->toBeGreaterThan(0);

    return (int) $checkpoint->processed;
}

/** @return array<int, int|null> source_term_id => the parent's source_term_id, as the export says */
function krExportParents(): array
{
    $handle = fopen(krDir().'/categories.csv', 'rb');
    $header = fgetcsv($handle, null, ',', '"', '');
    $parents = [];

    while (($cells = fgetcsv($handle, null, ',', '"', '')) !== false) {
        $row = array_combine($header, $cells);
        $parents[(int) $row['term_id']] = $row['parent'] === '' ? null : (int) $row['parent'];
    }

    fclose($handle);

    return $parents;
}

it('links every category to its parent after a kill in the middle of categories.csv', function () {
    $key = 'kr-cat-'.bin2hex(random_bytes(4));

    $processed = krKillOnWrite(Category::class, 8, 'categories', $key, 3);
    expect($processed)->toBeLessThan(count(krExportParents()));

    // The same command again, to the end.
    krImport('categories', $key, 3);

    $byTerm = Category::query()->whereNotNull('source_term_id')->get()->keyBy('source_term_id');
    $wrong = [];

    foreach (krExportParents() as $term => $parentTerm) {
        $expected = $parentTerm === null ? null : ($byTerm[$parentTerm]->id ?? null);
        $actual = $byTerm[$term]->parent_id ?? null;

        if ($expected !== $actual) {
            $wrong[] = $term.' (parent '.var_export($actual, true).', the export says '.var_export($expected, true).')';
        }
    }

    // Before the fix: every child among the first $processed rows sat at the top level.
    expect($wrong)->toBe([], 'categories committed before the kill lost their parent link');

    // And the cached depth agrees with the links -- the URL is built from the path.
    foreach ($byTerm as $category) {
        $depth = 0;

        for ($p = $category->parent_id; $p !== null; $p = Category::query()->whereKey($p)->value('parent_id')) {
            $depth++;
        }

        expect((int) $category->depth)->toBe($depth, 'category '.$category->source_term_id.' has a stale depth');
    }
});

it('recomputes the rating of every reviewed product after a kill in the middle of reviews.csv', function () {
    $key = 'kr-rev-'.bin2hex(random_bytes(4));

    krImport('products', $key, 500);

    $processed = krKillOnWrite(Review::class, 35, 'reviews', $key, 10);
    expect($processed)->toBeLessThan(90);

    krImport('reviews', $key, 10);

    $stored = Product::query()->whereNotNull('wc_id')->orderBy('id')->get(['id', 'rating', 'review_count'])
        ->map(fn ($p) => [$p->id, (float) $p->rating, (int) $p->review_count])->all();

    // What the aggregates SHOULD be: recomputed from the reviews that are in the table.
    ProductRating::refresh(Product::query()->whereNotNull('wc_id')->pluck('id')->all());

    $truth = Product::query()->whereNotNull('wc_id')->orderBy('id')->get(['id', 'rating', 'review_count'])
        ->map(fn ($p) => [$p->id, (float) $p->rating, (int) $p->review_count])->all();

    $stale = array_values(array_filter(
        array_map(fn ($s, $t) => $s === $t ? null : 'product '.$s[0].': '.$s[2].' reviews stored, '.$t[2].' approved', $stored, $truth),
    ));

    // Guard: the export has reviewed products at all, or the comparison above is vacuous.
    expect(collect($truth)->sum(fn ($t) => $t[2]))->toBeGreaterThan(0);

    // Before the fix: every product whose reviews were all in the committed batches kept 0.0 / 0.
    expect($stale)->toBe([], 'products reviewed only before the kill kept a stale rating');
});

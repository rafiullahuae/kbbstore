<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\Review;
use App\Support\ReviewStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The data migration that brings `reviews.status` onto the schema's vocabulary.
 *
 * This is a migration that REWRITES LIVE ROWS, which is the kind that has to be
 * proved rather than reasoned about. Every state it can meet is seeded here and
 * asserted:
 *
 *   - rows already correct                  (must not be touched at all)
 *   - rows holding `rejected`               (the admin's private spelling)
 *   - rows holding a value in neither set   (an import, a hand edit)
 *   - an empty table                        (a fresh install)
 *
 * plus the two properties that make it safe to run on a live table: it is
 * IDEMPOTENT (running it twice changes nothing the second time) and it is
 * REVERSIBLE (down() puts every row it moved back where it found it).
 *
 * The migration has already run once by the time a test here starts —
 * RefreshDatabase runs the whole set — so each case seeds its rows and runs it
 * again, which is itself the idempotency path.
 */

/** A fresh instance of the migration under test. */
function amMigration(): object
{
    return require database_path('migrations/2026_10_04_000000_normalise_review_statuses.php');
}

/**
 * Write a status straight through the query builder.
 *
 * Deliberately NOT through Review::create(): the point of several of these rows
 * is that they hold a value the application would never write, which is exactly
 * how they got into the live table in the first place.
 */
function amRawReview(string $status, array $overrides = []): int
{
    static $n = 0;
    $n++;

    return (int) DB::table('reviews')->insertGetId(array_merge([
        'source' => 'sorina',
        'product_id' => null,
        'author_name' => 'Raw ' . $n,
        'author_email' => 'raw-' . $n . '@example.test',
        'rating' => 5,
        'title' => 'Raw title ' . $n,
        'content' => 'Raw body ' . $n,
        'status' => $status,
        'verified' => false,
        'helpful' => 0,
        'ip' => '203.0.113.10',
        'created_at' => now(),
        'updated_at' => now()->subDay(),
    ], $overrides));
}

function amStatusOf(int $id): string
{
    return (string) DB::table('reviews')->where('id', $id)->value('status');
}

/* ------------------------------------------------------------ the empty table */

it('runs clean against an empty table', function () {
    DB::table('reviews')->delete();
    DB::table('review_status_backfill')->delete();

    amMigration()->up();

    expect(DB::table('reviews')->count())->toBe(0)
        ->and(DB::table('review_status_backfill')->count())->toBe(0);
});

/* ----------------------------------------------------------- every other state */

it('normalises every state it can meet and leaves the correct rows untouched', function () {
    DB::table('reviews')->delete();
    DB::table('review_status_backfill')->delete();

    // Already correct — these must not be named by any statement.
    $pending = amRawReview(ReviewStatus::PENDING);
    $approved = amRawReview(ReviewStatus::APPROVED);
    $spam = amRawReview(ReviewStatus::SPAM);

    // The admin's private spelling.
    $rejected = amRawReview('rejected');
    $rejectedTwo = amRawReview('rejected');

    // In neither set at all.
    $unknown = amRawReview('trash');
    $blank = amRawReview('');
    $cased = amRawReview('Rejected');

    $before = DB::table('reviews')
        ->whereIn('id', [$pending, $approved, $spam])
        ->pluck('updated_at', 'id');

    amMigration()->up();

    // The correct ones are exactly as they were.
    expect(amStatusOf($pending))->toBe(ReviewStatus::PENDING)
        ->and(amStatusOf($approved))->toBe(ReviewStatus::APPROVED)
        ->and(amStatusOf($spam))->toBe(ReviewStatus::SPAM);

    $after = DB::table('reviews')
        ->whereIn('id', [$pending, $approved, $spam])
        ->pluck('updated_at', 'id');

    // Not touched means NOT TOUCHED: `updated_at` did not move either, which
    // matters because the moderation screen sorts and reports on it.
    expect($after->all())->toBe($before->all());

    // `rejected` folds onto the schema's own name for "refused".
    expect(amStatusOf($rejected))->toBe(ReviewStatus::SPAM)
        ->and(amStatusOf($rejectedTwo))->toBe(ReviewStatus::SPAM)
        ->and(amStatusOf($cased))->toBe(ReviewStatus::SPAM);

    // Anything unrecognised lands in the queue a human looks at, not the
    // bucket nobody opens again. Neither is published, so the safe default is
    // the visible one.
    expect(amStatusOf($unknown))->toBe(ReviewStatus::PENDING)
        ->and(amStatusOf($blank))->toBe(ReviewStatus::PENDING);

    // Nothing outside the vocabulary survives anywhere in the table.
    $distinct = DB::table('reviews')->distinct()->pluck('status')->all();

    foreach ($distinct as $status) {
        expect(ReviewStatus::ALL)->toContain($status);
    }

    // The journal recorded exactly the five rows that moved, and only those.
    expect(DB::table('review_status_backfill')->count())->toBe(5)
        ->and(DB::table('review_status_backfill')->pluck('review_id')->sort()->values()->all())
        ->toBe(collect([$rejected, $rejectedTwo, $unknown, $blank, $cased])->sort()->values()->all());
});

/* ------------------------------------------------------------- idempotency */

it('is idempotent — the second run changes nothing', function () {
    DB::table('reviews')->delete();
    DB::table('review_status_backfill')->delete();

    amRawReview(ReviewStatus::PENDING);
    amRawReview(ReviewStatus::APPROVED);
    amRawReview(ReviewStatus::SPAM);
    amRawReview('rejected');
    amRawReview('trash');

    amMigration()->up();

    // The whole table, values and timestamps, after the first run.
    $snapshot = DB::table('reviews')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    $journal = DB::table('review_status_backfill')->count();

    expect($journal)->toBe(2);

    amMigration()->up();

    $second = DB::table('reviews')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

    // Byte for byte identical: no row rewritten, no timestamp moved.
    expect($second)->toBe($snapshot);

    // And no journal row either — a second run has nothing to record, which is
    // the proof it did not write. A journal that grew would mean it had
    // re-folded rows that were already canonical.
    expect(DB::table('review_status_backfill')->count())->toBe($journal);

    // A third, for good measure.
    amMigration()->up();

    expect(DB::table('reviews')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all())->toBe($snapshot)
        ->and(DB::table('review_status_backfill')->count())->toBe($journal);
});

/* ------------------------------------------------------------- reversibility */

it('reverses exactly, putting every moved row back where it was found', function () {
    DB::table('reviews')->delete();
    DB::table('review_status_backfill')->delete();

    $untouched = amRawReview(ReviewStatus::SPAM);   // genuinely spam before the run
    $rejected = amRawReview('rejected');
    $unknown = amRawReview('trash');

    amMigration()->up();

    expect(amStatusOf($rejected))->toBe(ReviewStatus::SPAM)
        ->and(amStatusOf($unknown))->toBe(ReviewStatus::PENDING);

    amMigration()->down();

    /*
     * THIS IS WHY THE JOURNAL EXISTS. After the fold, `rejected` and `spam` are
     * the same string — reversal cannot be inferred from the data, only
     * replayed from a record of what was changed. A down() that mapped every
     * `spam` row back to `rejected` would have corrupted the row that was spam
     * all along.
     */
    expect(amStatusOf($rejected))->toBe('rejected')
        ->and(amStatusOf($unknown))->toBe('trash')
        ->and(amStatusOf($untouched))->toBe(ReviewStatus::SPAM);

    // The journal is gone with it, so a later up() starts clean.
    expect(Schema::hasTable('review_status_backfill'))->toBeFalse();

    // And it can be rolled forward again.
    amMigration()->up();

    expect(amStatusOf($rejected))->toBe(ReviewStatus::SPAM)
        ->and(amStatusOf($unknown))->toBe(ReviewStatus::PENDING);
});

/* -------------------------------------------------- it cannot move a rating */

it('cannot change any product score, because it never changes what is approved', function () {
    DB::table('reviews')->delete();
    DB::table('review_status_backfill')->delete();

    $product = Product::create([
        'slug' => 'am-mig-product-' . uniqid(),
        'name' => 'AM Migration Product',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 40.00,
        'stock_status' => 'instock',
    ]);

    // Two approved fives and a four -> average 4.67 over three reviews.
    amRawReview(ReviewStatus::APPROVED, ['product_id' => $product->id, 'rating' => 5]);
    amRawReview(ReviewStatus::APPROVED, ['product_id' => $product->id, 'rating' => 5]);
    amRawReview(ReviewStatus::APPROVED, ['product_id' => $product->id, 'rating' => 4]);

    // A one-star sitting under each of the spellings that is not approved. If
    // any of them were being counted, the average would drop.
    amRawReview('rejected', ['product_id' => $product->id, 'rating' => 1]);
    amRawReview(ReviewStatus::SPAM, ['product_id' => $product->id, 'rating' => 1]);
    amRawReview(ReviewStatus::PENDING, ['product_id' => $product->id, 'rating' => 1]);
    amRawReview('trash', ['product_id' => $product->id, 'rating' => 1]);

    amMigration()->up();

    $product->refresh();

    // Three reviews, not seven, and the average of 5, 5 and 4.
    expect($product->review_count)->toBe(3)
        ->and(round((float) $product->rating, 2))->toBe(4.67);
});

<?php

declare(strict_types=1);

use App\Support\ProductRating;
use App\Support\ReviewStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bring `reviews.status` onto the vocabulary the schema declares.
 *
 * THE STATE THIS MEETS. The schema declares the column
 * `pending | approved | spam`. The admin screen only ever wrote `rejected`,
 * which is in neither that set nor anything a reader understands, and it
 * refused `spam` outright — so the live table can hold all four spellings plus
 * whatever an import or a hand edit left behind. App\Support\ReviewStatus is
 * where that decision is written down and why; this migration is the part that
 * moves the rows.
 *
 *   rejected  -> spam      (the alias; `spam` is this schema's "refused")
 *   anything  -> pending   (a value nobody has decided about belongs in the
 *   unknown                 queue a human looks at, not the bucket nobody
 *                           opens again — and neither is published, so the
 *                           safe default is the visible one)
 *
 * SAFE ON A LIVE TABLE.
 *
 *   IDEMPOTENT. It acts only on rows whose status is NOT already canonical, so
 *   a second run finds nothing to do and writes nothing — not even a journal
 *   row. Re-running after a partial failure is the same as running once.
 *
 *   IT DOES NOT TOUCH CORRECT ROWS. Every statement is keyed by the exact set
 *   of primary keys read back for one non-canonical value. A row already
 *   holding `pending`, `approved` or `spam` is never named by any UPDATE, so
 *   its `updated_at` does not move either — which matters, because the
 *   moderation screen sorts and reports on it.
 *
 *   REVERSIBLE, FOR REAL. `rejected` and `spam` are indistinguishable once
 *   folded, so reversal cannot be inferred — it has to be recorded. Every row
 *   this changes is journalled into `review_status_backfill` with the value it
 *   held, and down() replays the journal and drops the table. A down() that
 *   silently did nothing would be the dishonest option here.
 *
 *   BOUNDED. Read and written in chunks of 500 keys, so one statement never
 *   names an unbounded number of rows on a shared host.
 *
 * NO ->after() ANYWHERE, and no ALTER at all: this changes values, not shape.
 * Nine migrations in this repo were silent no-ops on MySQL because they
 * positioned a column after one that did not exist yet.
 *
 * THE RATING REBUILD AT THE END is a separate repair and is commented where it
 * happens. Note that the status fold itself cannot move any product's score:
 * both `rejected` and every unknown value are already not-approved, and they
 * stay not-approved, so the set of APPROVED reviews is identical before and
 * after.
 */
return new class extends Migration
{
    /** Keys named by one statement. */
    private const CHUNK = 500;

    public function up(): void
    {
        if (! Schema::hasTable('reviews') || ! Schema::hasColumn('reviews', 'status')) {
            return;
        }

        $this->ensureJournal();

        $moved = [];

        /*
         * The distinct statuses actually present, rather than a guess at which
         * ones might be. On this table that is a handful of rows, and it means
         * a spelling nobody anticipated — a case variant, a trailing space, a
         * value from a future importer — is handled by the same pass rather
         * than surviving it.
         */
        $present = DB::table('reviews')
            ->select('status')
            ->distinct()
            ->pluck('status');

        foreach ($present as $raw) {
            $current = (string) $raw;

            if (ReviewStatus::isCanonical($current)) {
                continue;   // already correct — not named by any statement below
            }

            $target = ReviewStatus::normalise($current);
            $moved[$current] = ['to' => $target, 'n' => 0];

            // Re-read the keys each time round: the set shrinks as it is
            // updated, so this terminates even if something else is writing.
            while (true) {
                $ids = DB::table('reviews')
                    ->where('status', $current)
                    ->orderBy('id')
                    ->limit(self::CHUNK)
                    ->pluck('id')
                    ->all();

                if ($ids === []) {
                    break;
                }

                // Journal BEFORE the update, so a crash between the two leaves
                // a row that can be reversed rather than one that cannot.
                DB::table('review_status_backfill')->insert(array_map(
                    fn ($id) => [
                        'review_id' => (int) $id,
                        'from_status' => $current,
                        'to_status' => $target,
                        'created_at' => now(),
                    ],
                    $ids
                ));

                DB::table('reviews')->whereIn('id', $ids)->update(['status' => $target]);

                $moved[$current]['n'] += count($ids);
            }
        }

        $this->rebuildRatings();

        if (app()->runningInConsole()) {
            if ($moved === []) {
                echo "reviews.status: already canonical, nothing moved.\n";
            }

            foreach ($moved as $from => $info) {
                echo "reviews.status: {$info['n']} row(s) {$from} -> {$info['to']}\n";
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('review_status_backfill') || ! Schema::hasTable('reviews')) {
            return;
        }

        // Newest first, so a row folded twice by successive runs unwinds in the
        // order it was folded.
        $entries = DB::table('review_status_backfill')->orderByDesc('id')->get();

        foreach ($entries as $entry) {
            DB::table('reviews')
                ->where('id', $entry->review_id)
                ->update(['status' => $entry->from_status]);
        }

        Schema::dropIfExists('review_status_backfill');
    }

    /**
     * The journal. Created here rather than in its own migration so the record
     * and the rows it describes can never be applied apart.
     */
    private function ensureJournal(): void
    {
        if (Schema::hasTable('review_status_backfill')) {
            return;
        }

        Schema::create('review_status_backfill', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('review_id')->index();
            $t->string('from_status');
            $t->string('to_status');
            $t->timestamp('created_at')->nullable();
        });
    }

    /**
     * Put `products.rating` and `products.review_count` back in step with the
     * reviews that are actually approved.
     *
     * WHY THIS BELONGS HERE. Those two columns are what the shop cards print
     * and what `?sort=rating`, `?sort=popular` and the `top_rated` shortcode
     * order by, and until this package NOTHING recomputed them when a review
     * was moderated — the only writer in the whole application was
     * DemoReviewsSeeder. So a store that had been moderating reviews was
     * showing whatever the numbers happened to be when the catalogue was
     * seeded. App\Support\ProductRating now runs on every moderation write;
     * this is the one-time catch-up for everything moderated before it existed.
     *
     * ONLY PRODUCTS THAT HAVE REVIEW ROWS. A product with no reviews at all is
     * left alone deliberately: its `rating` may be a legitimate figure imported
     * from WooCommerce, which carries its own rating meta, and zeroing that
     * would be this migration destroying data it does not own.
     */
    private function rebuildRatings(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        DB::table('reviews')
            ->select('product_id')
            ->whereNotNull('product_id')
            ->distinct()
            ->orderBy('product_id')
            ->chunk(self::CHUNK, function ($rows) {
                ProductRating::refresh($rows->pluck('product_id')->all());
            });
    }
};

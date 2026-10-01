<?php

declare(strict_types=1);

namespace App\Services\Import\Entities;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Review;
use App\Services\Import\ImportContext;
use App\Services\Import\Row;
use App\Services\Import\RowRejected;
use App\Support\ProductRating;
use App\Support\SafeUrl;
use App\Support\ReviewStatus;

/**
 * WooCommerce reviews — WordPress comments with `comment_type = 'review'` and a
 * `rating` meta — matched on the pair (`source`, `source_id`).
 *
 * ── WHY THE KEY IS A PAIR ───────────────────────────────────────────────────
 *
 * `reviews` intentionally holds several origins whose id spaces are unrelated
 * and will overlap: 'sorina' from the old theme, 'demo' from the seeder,
 * 'csv_import' from the admin upload, and 'wp_comment' from here. The unique
 * index added by 2026_09_22_000000_add_import_external_ids is on the PAIR for
 * exactly that reason, and this importer matches on the pair. Matching on
 * source_id alone would let a WordPress comment id collide with a Sorina review
 * id and overwrite somebody's review with somebody else's.
 *
 * ── THERE IS ALREADY A REVIEW IMPORTER, AND THIS IS NOT IT ──────────────────
 *
 * Store -> Reviews -> Import, backed by App\Services\Reviews\ReviewCsvImport,
 * takes a WooCommerce comment export through an admin upload. It is a different
 * door for a different moment: the owner uploading one file through a browser,
 * with a screen to read the result on and a 20,000-row ceiling because it runs
 * inside a web request on this host.
 *
 * This entity is the migration path — the folder of CSVs, the checkpointed
 * resume that survives a request being killed on a 110-second host, the dry run
 * that rolls back, `--only`, and the created/updated/unchanged/rejected/
 * adjusted/discarded report that the other seven entities produce and that the
 * owner approves the whole migration from. Neither can be had from the other
 * without rewriting it into the other's shape.
 *
 * WHAT IS SHARED IS THE DOMAIN, NOT THE PLUMBING, and deliberately so, because
 * the domain is where the two could come to disagree:
 *
 *   - App\Support\ReviewStatus is the vocabulary, for both.
 *   - App\Support\ProductRating::refresh() is the aggregate, for both, and for
 *     the moderation screen. Three callers, one sum. Two copies of that
 *     arithmetic is how the product page and the shop card come to print
 *     different numbers.
 *
 * The `comment_approved` fold below is the one piece of mapping written twice,
 * and it is written to the same table of values on purpose; the alternative is
 * a shared helper in App\Support that this lane would have to reach across a
 * boundary to create. Named here so the integrator can merge them.
 *
 * ── MODERATION STATUS ───────────────────────────────────────────────────────
 *
 * WordPress's `comment_approved` is '1', '0', 'spam' or 'trash'. This schema's
 * vocabulary is `pending | approved | spam`, settled by ReviewStatus, and a
 * migration has already canonicalised the column once.
 *
 *   '1'      -> approved
 *   '0'      -> pending      (held for moderation; that is what 0 means)
 *   'spam'   -> spam
 *   'trash'  -> spam         with an adjustment reported. Trash is not spam --
 *                            it is "the owner deleted this" -- and this schema
 *                            has no third not-published bucket to hold the
 *                            difference. `spam` is the one that is never
 *                            published and never surfaces in the pending queue,
 *                            which is the closest behaviour; the adjustment is
 *                            there because the owner emptying their WordPress
 *                            trash and finding the rows here needs to know why.
 *   absent   -> pending
 *
 * A VALUE WITH NO EQUIVALENT IS REFUSED, NOT GUESSED. ReviewStatus::normalise()
 * folds anything unknown onto `pending`, which is right for a hand-edited admin
 * row and wrong for an import: 3,712 rows arriving under a spelling nothing
 * recognised would all land silently in the moderation queue and the owner
 * would read "imported" and find their reviews unpublished. So an unrecognised
 * value is rejected with the value quoted, and the fold is only ever applied to
 * spellings this file lists.
 *
 * ── THE TWO PLANTED CASES ───────────────────────────────────────────────────
 *
 * A REVIEW WHOSE PRODUCT NO LONGER EXISTS is REFUSED, with the id it could not
 * find. `reviews.product_id` is nullable, but NULL there is not "unknown" — it
 * is `Review::scopeBusiness()`, a review OF THE SHOP, which /reviews and the
 * homepage wall publish as exactly that. Importing an orphaned product review
 * as NULL would put "This serum cleared my skin in a week" on the storefront as
 * a review of the business. That is a worse outcome than a named refusal, and
 * the refusal is actionable: import products first, or the product really is
 * gone and the review goes with it.
 *
 * A REVIEW WITH NO AUTHOR is IMPORTED, as "Anonymous", with an adjustment. Woo
 * stores an empty `comment_author` for a signed-in reviewer whose display name
 * lived on the user row, so these are real reviews of real products and
 * refusing them would throw away content the shop is entitled to. `author_name`
 * is NOT NULL with a default of '' and the storefront prints it, so a blank one
 * is a visibly broken card. ReviewCsvImport made the same call for the same
 * reason and this matches it rather than inventing a second behaviour.
 *
 * ── RATINGS ─────────────────────────────────────────────────────────────────
 *
 * `reviews.rating` is an unsigned TINYINT and every reader treats it as 1-5:
 * ProductRating averages it, the product page groups by it into five buckets,
 * and Seo publishes the average as schema.org aggregateRating with
 * bestRating 5.
 *
 * OUT OF RANGE IS REFUSED. A 0 or a 6 is not a star rating, and it is not
 * clampable either: clamping 0 to 1 invents a one-star review of somebody
 * else's product, and clamping 6 to 5 inflates a score that is then published
 * to Google as a factual claim.
 *
 * NO RATING AT ALL IS ALSO REFUSED, and this is the one that needed checking
 * rather than assuming. The column defaults to 5. A WordPress comment on a
 * product with no rating meta is a genuine thing — it is a QUESTION, or a reply
 * to a review — and importing it would default it to FIVE STARS: a perfect
 * score this shop then averages into the product's rating and publishes as
 * structured data. Nobody wrote that five.
 *
 * ── THE AGGREGATE, WHICH IS THE POINT OF IMPORTING THESE AT ALL ─────────────
 *
 * `products.rating` and `products.review_count` are what the shop cards print,
 * what `?sort=rating` and `?sort=popular` order by, what the `top_rated`
 * shortcode selects on, and what Store\ProductController hands to Seo as the
 * aggregateRating in the product's JSON-LD. Importing approved reviews without
 * recomputing them leaves every affected product advertising the score it had
 * before -- 0.0 out of no reviews, on a fresh import -- while its page shows
 * the reviews.
 *
 * So finalise() calls ProductRating::refresh() for every product this entity
 * touched. IN finalise() AND NOT PER ROW, for two reasons: a product with 40
 * reviews would otherwise be re-aggregated 40 times, and the aggregate has to
 * be taken after the last of its reviews is in or it is taken against a partial
 * set. It runs in its own transaction after the last batch commits, it is
 * idempotent, and an interrupted run that resumes and finishes still gets it --
 * ▲ for every product, only since alreadyCommitted(): before it, a KILLED run
 * resumed with the products of its committed batches forgotten, because the
 * list of products to recompute was process memory (docs/KR-KILL-AND-RESUME.md).
 *
 * IT IS CORRECT UNDER THE DRY RUN TOO, which is worth stating because it looks
 * like a write that escapes the rollback and is not: finalise() is called
 * inside the runner's outer transaction, so the recomputed aggregates are
 * discarded with everything else.
 *
 * ── THE COLUMNS THAT MUST NOT LEAK ──────────────────────────────────────────
 *
 * `reviews` carries `author_email` and `ip`. CLAUDE.md names this table
 * explicitly in the list of things `/api/*` has leaked, and the reviewer's
 * address and the IP they submitted from are both needed for moderation and
 * neither is anyone else's business.
 *
 * THIS IMPORTER WIDENS NOTHING. It writes both columns, which are already
 * written by the storefront's own submit path; it adds no endpoint, no column
 * and no serialisation. What it does do is make the table BIG -- an import is
 * the moment those columns stop being a handful of rows and become the whole
 * shop's reviewer base, harvestable in one request if anything ever returns a
 * whole model. Api\ReviewController::index() answers with an explicit column
 * allowlist, Review::$hidden drops both from any model that is serialised
 * anyway, and tests/Feature/ApiSecurityTest.php now pins that against rows
 * written by THIS importer rather than only by ::create() in a test.
 */
final class ReviewImporter extends EntityImporter
{
    /** The `source` an imported WordPress comment carries. */
    public const SOURCE = 'wp_comment';

    /**
     * Reviews from the owner's own WordPress plugin, Dream Code Reviews, which
     * keeps them in its own table (`wp_sorina_reviews`) and HIDES WooCommerce's
     * reviews on the storefront. They are what his old shop actually showed,
     * including every review he copied onto a sibling product with the plugin's
     * Assign / Duplicate. The export carries them in reviews.csv with
     * `source = dream_code` and the plugin's row id as `comment_id`; keyed on
     * (source, source_id) like every imported review, so a re-run updates
     * rather than duplicates. 1 October 2026: the Medicube PDRN Glow Booster
     * Set showed its reviews on the old site and none here, because the export
     * had only read wp_comments.
     */
    public const DREAM_CODE = 'dream_code';

    /** The only values a reviews.csv `source` cell may carry. Anything else is refused by name. */
    public const SOURCES = [self::SOURCE, self::DREAM_CODE];

    /**
     * `comment_approved` folded onto ReviewStatus' vocabulary.
     *
     * Written out rather than handed to ReviewStatus::normalise(), which reads
     * '1' and '0' as unknown and would fold BOTH onto `pending` -- quietly
     * leaving every approved review in the owner's WooCommerce store
     * unpublished here with no error to show for it.
     *
     * @var array<string, string>
     */
    private const STATUS_MAP = [
        '1' => ReviewStatus::APPROVED,
        'approve' => ReviewStatus::APPROVED,
        'approved' => ReviewStatus::APPROVED,
        'publish' => ReviewStatus::APPROVED,
        'published' => ReviewStatus::APPROVED,
        'yes' => ReviewStatus::APPROVED,

        '0' => ReviewStatus::PENDING,
        'hold' => ReviewStatus::PENDING,
        'unapproved' => ReviewStatus::PENDING,
        'moderated' => ReviewStatus::PENDING,
        'pending' => ReviewStatus::PENDING,

        'spam' => ReviewStatus::SPAM,
        'rejected' => ReviewStatus::SPAM,
        'reject' => ReviewStatus::SPAM,
    ];

    /**
     * Products whose approved set this run may have moved.
     *
     * @var array<int, true>
     */
    private array $touched = [];

    /**
     * Comment ids an earlier process committed and this one passed over on
     * resume -- resolved to products in finalise(). See alreadyCommitted().
     *
     * @var array<string, list<int>>  source => source ids
     */
    private array $committedEarlier = [];

    /**
     * Adjustments and discards for the row being imported, held until the row
     * is known to survive -- same mechanism and same reason as
     * CouponImporter::$pending, which is where the finding is written up.
     *
     * In short: a review whose rating is refused two checks after its missing
     * author was reported would have contributed an "imported as Anonymous"
     * entry to a list whose whole promise is "this row IS in the database".
     * The owner reads that list to decide what to approve, and a refused row
     * needs no approval.
     *
     * @var list<array{0: 'adjusted'|'discarded', 1: string, 2: int|string, 3: string, 4: string, 5: string, 6: string}>
     */
    private array $pending = [];

    /** @var list<string> */
    private array $pendingNotes = [];

    public function name(): string
    {
        return 'reviews';
    }

    public function conventionalFile(): string
    {
        return 'reviews.csv';
    }

    /** Reviews this import wrote. Keyed on `source` rather than on a nullable id column, because Store -> Reviews -> Import writes the same rows through the same key and the two doors must count the same thing. */
    public function countImported(): ?int
    {
        return Review::query()->whereIn('source', self::SOURCES)->count();
    }

    public function import(Row $row, ImportContext $context): void
    {
        $this->pending = [];
        $this->pendingNotes = [];

        $commentId = $row->requireId('comment_id', 'comment_id', 'id', 'source_id', 'wp_comment_id', 'review_id');
        $source = $this->sourceOf($row);

        $this->assertIsAReview($row);

        $productId = $this->resolveProduct($row, $context);
        $rating = $this->rating($row);
        $status = $this->status($row);
        $author = $this->author($row);

        $review = Review::query()
            ->where('source', $source)
            ->where('source_id', $commentId)
            ->first() ?? new Review;

        $attributes = [
            'source' => $source,
            'source_id' => $commentId,
            'product_id' => $productId,
            'customer_id' => $this->resolveCustomer($row, $context),
            'author_name' => mb_substr($author, 0, 255),
            'author_email' => mb_substr($this->email($row) ?? '', 0, 255),
            'rating' => $rating,
            'title' => mb_substr(trim(strip_tags((string) $row->text('title', 'title', 'review_title', 'summary'))), 0, 255),
            'content' => $this->content($row),
            'status' => $status,
            'verified' => (bool) $row->bool(false, 'verified', 'verified_owner', 'verified_buyer', 'meta_verified'),
            'helpful' => max(0, $row->int(0, 'helpful', 'helpful_count', 'upvotes')),
            'reply' => $this->reply($row),
            // varchar(60), and an IPv6 address with a zone is longer than that.
            'ip' => mb_substr((string) ($row->text('ip', 'ip', 'author_ip', 'comment_author_ip') ?? ''), 0, 60),
        ];

        $photographs = $this->photographs($row);

        /*
         * ONLY WHEN THE EXPORT CARRIES ONE, which is the same rule SeoImporter
         * applies to the barcodes: never write over something this shop already
         * holds with nothing.
         *
         * `reviews.images` on an imported row can also have been filled HERE --
         * a shopper uploading a photo to the new shop through
         * Store\ReviewController. A re-run of the import (which is the normal
         * case, not an edge case: full, delta, cutover delta) would otherwise
         * delete her photograph every time, because the export it is matched
         * against has never heard of it.
         */
        if ($photographs !== []) {
            $attributes['images'] = $photographs;
        }

        $created = $row->date(
            'comment_date',
            $context->timezone(),
            'comment_date', 'date', 'created_at', 'review_date', 'post_date', 'comment_date_gmt',
        );

        if ($created !== null) {
            $attributes['created_at'] = $created;
        } else {
            /*
             * Not refused: a review with no date is still a real review, and
             * `created_at` is NOT NULL so it gets now(). But /reviews and the
             * homepage wall are ordered by it, so a dateless import lands the
             * whole file at the top as though it all arrived this morning.
             */
            $this->later(
                'adjusted',
                'no readable review date in the export, so the row is stamped with the time of the import '
                .'-- /reviews and the homepage review wall are ordered by this, so these rows sort to the top',
                $row->line,
                $this->identify($row),
                'created_at',
                '(nothing in the export)',
                now()->toDateTimeString().' UTC',
            );
        }

        $outcome = $context->apply($review, $attributes);

        $context->record($this->name(), $outcome);
        $context->remember('reviews', $commentId, (int) $review->id);

        if ($productId !== null) {
            $this->touched[$productId] = true;
        }

        // The row is in. Everything held back above is now true of a row that
        // really is in the database.
        $this->flush($context);
    }

    /**
     * Hold an adjustment or a discard until this row has actually been written.
     *
     * @param  'adjusted'|'discarded'  $bucket
     */
    private function later(string $bucket, string $kind, int|string $line, string $id, string $field, string $before, string $after = '(nothing)'): void
    {
        $this->pending[] = [$bucket, $kind, $line, $id, $field, $before, $after];
    }

    private function noteLater(string $note): void
    {
        $this->pendingNotes[] = $note;
    }

    private function flush(ImportContext $context): void
    {
        $report = $context->report->for($this->name());

        foreach ($this->pendingNotes as $note) {
            $report->note($note);
        }

        foreach ($this->pending as [$bucket, $kind, $line, $id, $field, $before, $after]) {
            if ($bucket === 'adjusted') {
                $report->adjusted($kind, $line, $id, $field, $before, $after);
            } else {
                $report->discarded($kind, $line, $id, $field, $before, $after);
            }
        }

        $this->pending = [];
        $this->pendingNotes = [];
    }

    /**
     * A review an earlier, killed process committed. Remember its comment id so
     * finalise() can recompute the product it belongs to.
     *
     * finalise() recomputes `products.rating` for $touched, and $touched is
     * process memory: a process killed after committing some batches took that
     * list with it, and the resumed process skips those rows. Measured at full
     * volume, killed after 1,000 of 2,514 reviews: 32 products left at 0.0 out
     * of 0 reviews while their pages listed the reviews. See
     * EntityImporter::alreadyCommitted().
     *
     * THE PRODUCT IS READ BACK FROM THE ROW THAT WAS COMMITTED, not re-resolved
     * from the export, so the answer is what the earlier process actually wrote
     * -- and it costs one query per 500 rows in finalise() rather than one per
     * row here. A comment id the earlier pass refused has no row, and adds
     * nothing.
     */
    public function alreadyCommitted(Row $row, ImportContext $context): void
    {
        $id = $row->requireId('comment_id', 'comment_id', 'id', 'source_id', 'wp_comment_id', 'review_id');

        // Keyed by source as well: a Dream Code row id and a WordPress comment
        // id are different numbering schemes and can be the same number.
        $this->committedEarlier[$this->sourceOf($row)][] = $id;
    }

    /**
     * `source` from the row, or wp_comment when the file has no such column --
     * every export written before Dream Code reviews were read.
     *
     * @throws RowRejected
     */
    private function sourceOf(Row $row): string
    {
        $raw = trim((string) ($row->text('source', 'source') ?? ''));

        if ($raw === '') {
            return self::SOURCE;
        }

        if (! in_array($raw, self::SOURCES, true)) {
            throw RowRejected::because(
                "source '".mb_substr($raw, 0, 40)."' is not one this import writes (".implode(', ', self::SOURCES).')'
            );
        }

        return $raw;
    }

    /**
     * Put `products.rating` and `products.review_count` back in step.
     *
     * See the class header for why this is here and not per row. One call for
     * the whole entity, through the SAME App\Support\ProductRating the
     * moderation screen and the admin CSV import use.
     */
    public function finalise(ImportContext $context): void
    {
        foreach ($this->committedEarlier as $source => $sourceIds) {
            foreach (array_chunk($sourceIds, 500) as $chunk) {
                $products = Review::query()
                    ->where('source', $source)
                    ->whereIn('source_id', $chunk)
                    ->whereNotNull('product_id')
                    ->distinct()
                    ->pluck('product_id');

                foreach ($products as $productId) {
                    $this->touched[(int) $productId] = true;
                }
            }
        }

        $this->committedEarlier = [];

        if ($this->touched === []) {
            return;
        }

        $ids = array_keys($this->touched);

        // Reset before the refresh, so a resumed run that calls finalise()
        // twice does not hold the first half's ids in memory for ever, and so
        // a second entity pass starts from an empty set.
        $this->touched = [];

        foreach (array_chunk($ids, 500) as $chunk) {
            ProductRating::refresh($chunk);
        }

        $context->report->for($this->name())->note(
            'products.rating and products.review_count were recomputed for '.count($ids).' product'
            .(count($ids) === 1 ? '' : 's').' from their APPROVED reviews -- these are the figures the shop '
            .'cards print, that ?sort=rating orders by, and that the product page publishes to Google as '
            .'schema.org aggregateRating'
        );
    }

    /**
     * A WordPress comment that is not a review.
     *
     * `comment_type` is 'review' for a product review and '' (or 'comment') for
     * a blog comment; a `wp_comments` dump handed straight to this file carries
     * both. A blog comment has no rating and would be refused by rating()
     * anyway, but with a message about a missing star rating rather than about
     * the wrong kind of row -- and 4,000 of those is a report nobody can read.
     *
     * Only checked when the column is PRESENT. Most product-review exporters
     * have already filtered and do not carry it.
     *
     * @throws RowRejected
     */
    private function assertIsAReview(Row $row): void
    {
        $type = $row->text('comment_type', 'comment_type', 'type');

        if ($type === null) {
            return;
        }

        $value = mb_strtolower($type);

        if (in_array($value, ['review', 'reviews', 'product_review'], true)) {
            return;
        }

        throw RowRejected::because(
            "comment_type '".$type."' is not a review. This file carries WordPress comments that are not "
            .'product reviews -- export with comment_type = \'review\', or remove these rows.'
        );
    }

    /**
     * Which product this review is of.
     *
     * The export names it by WordPress post id. It is tried against
     * `products.wc_id` -- which is the id space a WooCommerce export is in --
     * and then by SKU, which several exporters carry and which is the only
     * other handle a review row has.
     *
     * @throws RowRejected
     */
    private function resolveProduct(Row $row, ImportContext $context): ?int
    {
        $postId = $row->id('comment_post_id', 'comment_post_id', 'product_id', 'post_id', 'wc_product_id', 'wc_id');

        if ($postId !== null) {
            $local = $context->localId('products', $postId);

            if ($local !== null) {
                return $local;
            }
        }

        $sku = $row->text('sku', 'sku', 'product_sku');

        if ($sku !== null) {
            $bySku = Product::query()->withTrashed()->where('sku', $sku)->value('id');

            if ($bySku !== null) {
                return (int) $bySku;
            }
        }

        $named = array_values(array_filter([
            $postId === null ? null : 'product post id '.$postId,
            $sku === null ? null : "sku '".$sku."'",
        ]));

        if ($named === []) {
            /*
             * No product named at all. In WordPress this is product_id = 0, and
             * this schema HAS a home for it -- Review::scopeBusiness(), a review
             * of the shop, which /reviews and the homepage wall publish as one.
             * So it is imported, and said out loud, because a product review
             * that lost its id on the way out of WordPress would land here too
             * and would be published as a review of the business.
             */
            $this->noteLater(
                'this row names no product at all, so it is imported as a review OF THE SHOP '
                .'(Review::scopeBusiness(), product_id NULL) and is published on /reviews and the homepage '
                .'wall as one -- check these are not product reviews that lost their id'
            );

            return null;
        }

        throw RowRejected::because(
            'no product in this shop matches '.implode(' or ', $named)
            .'. Import products before reviews. If the product really is gone, this review goes with it: '
            .'reviews.product_id NULL is not "unknown", it is Review::scopeBusiness() -- a review of the '
            .'SHOP -- so importing it unattached would publish a review of a serum as a review of the '
            .'business on /reviews and the homepage.'
        );
    }

    /**
     * The customer who wrote it, where the export names one and this shop has
     * them. Never created: CustomerImporter owns that, and a customer row
     * conjured from a review would have no orders and no address.
     */
    private function resolveCustomer(Row $row, ImportContext $context): ?int
    {
        $userId = $row->id('user_id', 'user_id', 'comment_user_id', 'customer_id');

        if ($userId !== null) {
            $local = $context->localId('customers', $userId);

            if ($local !== null) {
                return $local;
            }
        }

        $email = $row->text('email', 'email', 'author_email', 'comment_author_email', 'reviewer_email');

        if ($email === null) {
            return null;
        }

        $held = $context->customerIdForEmail(mb_strtolower($email));

        if ($held !== null) {
            return $held;
        }

        /*
         * Soft-deleted customers are still rows this review belongs to.
         *
         * ── WHY THE MATCH IS CONFIRMED IN PHP AND NOT LEFT TO `=` ────────────
         *
         * `LOWER(email) = ?` does not mean the same thing on the two engines, and
         * the dangerous direction is the one this shop RUNS. Measured on MySQL
         * 8.0.46 with the suite's own `utf8mb4` / `utf8mb4_unicode_ci`:
         *
         *     rows: 'JOSÉ@Example.com' and 'jose@example.com'
         *     LOWER(email) = 'jose@example.com'  ->  BOTH rows
         *     LOWER(email) = 'josé@example.com'  ->  BOTH rows
         *
         * MySQL's LOWER() is Unicode-aware, which is fine — but the comparison
         * runs under a collation that is ACCENT-INSENSITIVE as well as
         * case-insensitive, so `é` and `e` are the same character to `=`. Two
         * different mailboxes are therefore one row to this lookup, and `value()`
         * takes whichever the engine hands back first: the review is attributed to
         * a customer who did not write it, with their name printed under it on the
         * product page. On SQLite the same statement matched only the exact row,
         * because SQLite's `=` is neither, so nothing in the default suite could
         * see it.
         *
         * So the statement is now a CANDIDATE NET and PHP decides. mb_strtolower()
         * is case-folding only — it does not strip accents — so `jose@` can no
         * longer be answered with `josé@`, on either engine. The second bound
         * spelling catches the row whose stored case differs, which is what the
         * LOWER() was for.
         *
         * ── THE ONE CASE STILL ENGINE-DEPENDENT, AND WHY IT IS LEFT ──────────
         *
         * A stored 'JOSÉ@x.com' against an incoming 'josé@x.com' — a case
         * difference IN a non-ASCII character — is found on MySQL and missed on
         * SQLite, because SQLite's LOWER() is ASCII-only (measured:
         * LOWER('JOSÉ@Example.com') is 'josÉ@example.com') and no bound value can
         * make it fold É. That is unfixable in SQL without a normalised column,
         * and it is the SAFE direction: the review imports UNLINKED, which
         * `reviews.customer_id` null already means and which the import reports,
         * rather than linked to the wrong person. app/Support/MediaUsageWriter.php
         * records the same trade-off for filenames in the same words.
         *
         * MUTATION: put the single `whereRaw('LOWER(email) = ?')` back and
         * ReviewImporterEmailCaseTest's accent case goes red on
         * -c phpunit-mysql.xml — the review comes back linked to the unaccented
         * customer — and stays green on the default config.
         */
        $wanted = mb_strtolower($email);

        $candidates = Customer::query()
            ->withTrashed()
            ->where(static function ($q) use ($wanted, $email): void {
                $q->whereRaw('LOWER(email) = ?', [$wanted])
                    ->orWhereRaw('LOWER(email) = ?', [strtolower($email)])
                    ->orWhere('email', $email);
            })
            // Oldest wins, so a table that already holds a duplicate pair keeps
            // answering with the same one rather than alternating between them --
            // the same rule CheckoutController::customerForGuestOrder() applies.
            ->orderBy('id')
            ->get(['id', 'email']);

        foreach ($candidates as $candidate) {
            if (mb_strtolower((string) $candidate->email) === $wanted) {
                return (int) $candidate->id;
            }
        }

        return null;
    }

    /**
     * The star rating. 1 to 5, present, whole.
     *
     * @throws RowRejected
     */
    private function rating(Row $row): int
    {
        $raw = $row->text('rating', 'rating', 'meta_rating', 'review_rating', 'stars', 'score');

        if ($raw === null) {
            throw RowRejected::because(
                'rating is empty. reviews.rating defaults to 5, so importing this row would publish a '
                .'FIVE STAR review that nobody wrote -- averaged into products.rating and submitted to '
                .'Google as schema.org aggregateRating. A WordPress comment with no rating meta is usually '
                .'a question or a reply rather than a review.'
            );
        }

        // "5", "5.0", "5.00" -- Woo's rating meta is an integer stored as a
        // string and some exporters decimalise it. A genuine 4.5 is not a
        // WooCommerce rating and falls through to the refusal below.
        if (preg_match('/^(\d+)(?:\.0+)?$/', $raw, $m) !== 1) {
            throw RowRejected::because("rating: '".$raw."' is not a whole number of stars");
        }

        $rating = (int) $m[1];

        if ($rating < 1 || $rating > 5) {
            throw RowRejected::because(
                'rating: '.$rating.' is outside 1 to 5. Clamping it is not available: 0 clamped to 1 invents '
                .'a one-star review nobody wrote, and 6 clamped to 5 inflates a score this shop publishes to '
                .'Google as a factual claim about the product.'
            );
        }

        return $rating;
    }

    /**
     * `comment_approved` onto ReviewStatus' vocabulary.
     *
     * @throws RowRejected
     */
    private function status(Row $row): string
    {
        $raw = $row->text('comment_approved', 'comment_approved', 'status', 'approved', 'state');

        if ($raw === null) {
            return ReviewStatus::PENDING;
        }

        $value = mb_strtolower(trim($raw));

        if ($value === 'trash') {
            /*
             * Trash is not spam. It is "the owner deleted this", and this
             * schema has no third not-published bucket to hold the difference.
             * `spam` is the one that is never published and never appears in
             * the queue a human works through, which is the closest behaviour
             * -- but the owner needs to be told, because they will look for
             * these rows under Rejected and find them under Spam.
             */
            $this->later(
                'adjusted',
                "comment_approved 'trash' imported as 'spam' -- this schema has pending, approved and spam "
                ."and no separate bucket for a comment the owner deleted. `spam` is the one that is never "
                .'published and never shows in the moderation queue, which is the closest behaviour, but it '
                .'is not the same word and these rows will be found under Spam',
                $row->line,
                $this->identify($row),
                'status',
                'trash',
                ReviewStatus::SPAM,
            );

            return ReviewStatus::SPAM;
        }

        if (isset(self::STATUS_MAP[$value])) {
            return self::STATUS_MAP[$value];
        }

        throw RowRejected::because(
            "comment_approved '".$raw."' is not a moderation state this shop has. Known: "
            .implode(', ', array_keys(self::STATUS_MAP)).', trash. '
            .'It is refused rather than folded onto `pending`, which is what ReviewStatus::normalise() does '
            .'for a hand-edited row: a whole export arriving under an unrecognised spelling would land '
            .'silently in the moderation queue and the report would say every row imported.'
        );
    }

    /**
     * The reviewer's name, or "Anonymous" with the substitution reported.
     */
    private function author(Row $row): string
    {
        $author = trim(strip_tags((string) $row->text('author', 'author', 'author_name', 'comment_author', 'reviewer', 'reviewer_name')));

        if ($author !== '') {
            return $author;
        }

        $this->later(
            'adjusted',
            'the review has no author name, so it is imported as "Anonymous" -- WooCommerce leaves '
            .'comment_author empty for a signed-in reviewer whose display name lived on the user row, and '
            .'reviews.author_name is what the storefront prints on the card',
            $row->line,
            $this->identify($row),
            'author_name',
            '(nothing in the export)',
            'Anonymous',
        );

        return 'Anonymous';
    }

    /**
     * The reviewer's address, lower-cased. NOT public -- see the class header.
     */
    private function email(Row $row): ?string
    {
        $email = $row->text('email', 'email', 'author_email', 'comment_author_email', 'reviewer_email');

        if ($email === null) {
            return null;
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->later(
                'discarded',
                'the reviewer\'s email address is not a valid address, so it was not imported -- the review '
                .'itself is imported and nothing on the storefront shows this column, but the owner cannot '
                .'reply to the reviewer from the moderation screen',
                $row->line,
                $this->identify($row),
                'author_email',
                $email,
            );

            return null;
        }

        return mb_strtolower($email);
    }

    /**
     * The review body, with the tags taken out.
     *
     * WordPress stores comment HTML; this column is printed by the storefront
     * and by the moderation screen. ReviewCsvImport strips tags for the same
     * reason and this matches it. Reported as a discard where it actually
     * removed something, which is the standard ProductImporter set for its
     * description allowlist: the owner approves what is dropped.
     */
    private function content(Row $row): ?string
    {
        $raw = $row->text('content', 'content', 'comment_content', 'review', 'body', 'review_content', 'text', 'comment');

        if ($raw === null) {
            return null;
        }

        $clean = trim(strip_tags($raw));

        if ($clean !== trim($raw)) {
            $this->later(
                'discarded',
                'HTML removed from the review body -- WordPress stores comment markup and this column is '
                .'printed as text by the storefront and the moderation screen',
                $row->line,
                $this->identify($row),
                'content',
                $raw,
                mb_strlen($clean).' characters kept',
            );
        }

        return $clean === '' ? null : $clean;
    }

    /**
     * A shopper's photographs of the product, scheme-checked before they land.
     *
     * ── THE COLUMN WAS BUILT AND NOTHING HAS EVER FILLED IT ─────────────────
     *
     * `reviews.images` is a `json` column from the first schema migration, cast
     * to an array on the model, drawn by the product page (up to four, with a
     * "+n" chip), filtered on by the review wall, and scheme-checked by
     * `ReviewWall::photos()` on the way out. Every part of that has been in
     * place and tested for months. The only writer was a shopper uploading to
     * THIS shop, so on an imported catalogue it rendered nothing at all.
     *
     * The exporter's reviews stage now carries them, recognised by the value
     * being an attachment WordPress resolves or an address under the old site's
     * own uploads directory, and names in `manifest.json` every comment meta
     * key it did not use. This is the other end of that.
     *
     * ── WHY IT IS CHECKED HERE AS WELL AS AT THE RENDER ─────────────────────
     *
     * `ReviewWall::photos()` already refuses a `javascript:` URL, and the
     * product page runs every one through `CssUrl::value()`. Both are true and
     * neither is a reason to store one.
     *
     * CLAUDE.md's rule is that a URL arriving from data is scheme-checked
     * BEFORE it becomes an `href` or a `src`, and the reason is that a render
     * -time filter protects the paths that remember to call it. `reviews.images`
     * is read by four other things — `ReviewsApiController`,
     * `ReviewAssignApiController`, the admin console and the review wall's own
     * payload — and `/api/*` on this shop is unauthenticated. A row that cannot
     * hold an executable address cannot leak one through the fifth reader
     * somebody adds next year.
     *
     * A dropped address is REPORTED, not silently skipped: a photograph that
     * does not arrive is a thing the owner should be able to see in the import
     * report rather than notice missing from a product page.
     *
     * @return list<string>
     */
    private function photographs(Row $row): array
    {
        $raw = $row->text('images', 'images', 'photos', 'image_urls', 'review_images');

        if ($raw === null || $raw === '') {
            return [];
        }

        // Pipe first, comma second — the same order and the same reason as
        // ProductImporter's gallery: an uploads filename may contain a comma
        // and splitting on one turns a single file into two broken addresses.
        $parts = str_contains($raw, '|')
            ? $row->list('|', 'images', 'photos', 'image_urls', 'review_images')
            : $row->list(',', 'images', 'photos', 'image_urls', 'review_images');

        $out = [];

        foreach ($parts as $part) {
            $url = SafeUrl::src($part);

            if ($url === '') {
                $this->later(
                    'discarded',
                    'a review photograph whose address is not one a picture can be fetched from — only http and '
                    .'https are stored, so an executable or offsite-scheme address never reaches a page or an '
                    .'/api/ response',
                    $row->line,
                    $this->identify($row),
                    'images',
                    $part,
                    '(not imported)',
                );

                continue;
            }

            // One picture once. A plugin that stored both the attachment ids
            // and the URLs would otherwise double every photograph.
            $out[$url] = true;
        }

        return array_keys($out);
    }

    private function reply(Row $row): ?string
    {
        $reply = trim(strip_tags((string) $row->text('reply', 'reply', 'response', 'admin_reply')));

        return $reply === '' ? null : $reply;
    }

    public function identify(Row $row): string
    {
        $id = $row->raw('comment_id', 'id', 'source_id');

        return $id !== null && $id !== '' ? 'comment_id='.$id : 'line '.$row->line;
    }
}

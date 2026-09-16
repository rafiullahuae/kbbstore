<?php

declare(strict_types=1);

namespace App\Services\Reviews;

use App\Services\Import\DateParser;
use App\Services\Import\RowRejected;
use App\Support\ProductRating;
use App\Support\ReviewStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Reviews → Export / Import: the import half.
 *
 * This store is a WooCommerce port and the owner has thousands of real reviews
 * to bring across. That is the whole job here, and it has three properties that
 * are not negotiable.
 *
 * 1. IT IS IDEMPOTENT. Running the same file twice must not double the store's
 *    reviews. Every row is matched to an existing one before anything is
 *    written; see resolveKey() for the four ways a row can identify itself and
 *    what happens when it can do none of them.
 *
 * 2. IT REPORTS WHAT IT REFUSED, AND WHY, BY ROW NUMBER. A silent skip is the
 *    failure mode that makes an import untrustworthy: the totals look plausible
 *    and nobody finds the missing reviews until the owner counts them. The
 *    reasons name the column and quote the offending value, the same standard
 *    App\Services\Import\RowRejected sets for the catalogue importer.
 *
 * 3. IT LEAVES THE RATINGS CORRECT. `products.rating` and
 *    `products.review_count` are what the shop cards print and what
 *    Store\ProductController hands to Seo as the schema.org aggregateRating
 *    Google publishes. Importing approved reviews without recomputing them
 *    leaves every affected product advertising a stale score. This calls the
 *    SAME App\Support\ProductRating::refresh() the moderation screen calls —
 *    it does not do the arithmetic itself, because two copies of that sum is
 *    how they come to disagree.
 *
 * WHAT COUNTS AS "A WOOCOMMERCE REVIEW EXPORT". There is no one format: a
 * WooCommerce review is a WordPress comment, and the exporters in common use
 * (WP All Export, the wp_comments table dumped by hand, the various product
 * review plugins) each name the columns differently. So the header is MAPPED
 * rather than required — see ALIASES — and the report says which spelling it
 * recognised for each field and which columns it ignored. An owner staring at
 * "0 rows imported" needs to see that their file called the body
 * `comment_content` and this importer was looking at it.
 *
 * WHAT IT DELIBERATELY DOES NOT DO. It does not create products. A review whose
 * product cannot be resolved is refused with the value it could not resolve,
 * because inventing a product to hang a review on is how a catalogue acquires
 * ghost rows that nobody can explain later.
 */
final class ReviewCsvImport
{
    /**
     * The row ceiling for one file.
     *
     * Not arbitrary: this runs in a web request on shared hosting with no
     * queue worker, and the whole point of the bounded batches below is that
     * the peak memory is a batch rather than a file. 20,000 rows is comfortably
     * more than this store's ~3,700 reviews and still finishes inside a normal
     * PHP time limit. A bigger file is refused with its row count named, not
     * truncated silently.
     */
    public const MAX_ROWS = 20000;

    /** Rows per transaction. See run(): one transaction per batch, not one for the file. */
    public const BATCH = 200;

    /** The longest reject/note list carried back to the screen. */
    public const MAX_REPORTED = 200;

    /** Product ids per ProductRating::refresh() call. */
    public const REFRESH_CHUNK = 500;

    /**
     * The `source` written for rows that could not identify themselves and got
     * a synthesised key. Distinct from 'wp_comment', 'sorina' and 'kbb' so the
     * origin of such a row is legible in the table afterwards.
     */
    public const SYNTHETIC_SOURCE = 'csv_import';

    /**
     * canonical field => header spellings that mean it.
     *
     * Compared after normaliseHeader(): lower-cased, trimmed, and every run of
     * non-alphanumerics folded to a single underscore. So "Comment Author
     * E-mail", "comment_author_email" and "COMMENT.AUTHOR.EMAIL" are one key.
     *
     * NOTE ON BARE `id`. It is read as an EXTERNAL id, not as this store's own
     * review id, because in every WooCommerce-shaped export it is the comment
     * id. This importer's own export writes `review_id` for the internal one
     * precisely so the two can never be confused.
     *
     * @var array<string, list<string>>
     */
    public const ALIASES = [
        'review_id' => ['review_id', 'kbb_review_id'],
        'source' => ['source'],
        'source_id' => ['source_id', 'comment_id', 'id', 'wp_comment_id'],
        'product_id' => ['product_id'],
        'wc_post_id' => ['comment_post_id', 'post_id', 'wc_id', 'wc_product_id', 'product_wc_id'],
        'product_sku' => ['sku', 'product_sku'],
        'product_name' => ['product', 'product_name', 'product_title', 'post_title'],
        'author' => ['author', 'author_name', 'comment_author', 'reviewer', 'reviewer_name'],
        'email' => ['email', 'author_email', 'comment_author_email', 'reviewer_email'],
        'rating' => ['rating', 'review_rating', 'stars', 'score', 'meta_rating'],
        'title' => ['title', 'review_title', 'summary'],
        'content' => ['content', 'comment_content', 'review', 'body', 'review_content', 'text', 'comment'],
        'status' => ['status', 'comment_approved', 'approved', 'state'],
        'verified' => ['verified', 'verified_owner', 'verified_buyer', 'meta_verified'],
        'helpful' => ['helpful', 'helpful_count', 'upvotes'],
        'reply' => ['reply', 'response', 'admin_reply'],
        'created_at' => ['created_at', 'comment_date', 'comment_date_gmt', 'date', 'review_date', 'post_date'],
    ];

    /** Column separators tried against the header line, in order. */
    private const DELIMITERS = [',', ';', "\t", '|'];

    /* ------------------------------------------------------------------ state */

    /** @var array<string, int> canonical field => column index */
    private array $columns = [];

    /** @var array<string, string> canonical field => the spelling the file used */
    private array $recognised = [];

    /** @var list<string> headers this importer has no use for */
    private array $ignored = [];

    /** @var array<int, int> products.id => products.id, for resolution by our own id */
    private array $byId = [];

    /** @var array<int, int> products.wc_id => products.id */
    private array $byWcId = [];

    /** @var array<string, int> lower(sku) => products.id */
    private array $bySku = [];

    /** @var array<string, int|null> lower(name) => products.id, null where the name is ambiguous */
    private array $byName = [];

    /** @var array<int, true> every product whose approved set may have moved */
    private array $touched = [];

    /** @var list<array{row: int, reason: string}> */
    private array $rejects = [];

    /** @var list<array{row: int, note: string}> */
    private array $notes = [];

    private int $rejected = 0;

    private int $noted = 0;

    private int $created = 0;

    private int $updated = 0;

    private int $unchanged = 0;

    private int $read = 0;

    /**
     * @param  string  $path  the uploaded file on disk
     * @param  array{mode?: string, on_duplicate?: string, default_source?: string, timezone?: string, allow_business?: bool}  $options
     * @return array<string, mixed> the report the screen renders
     */
    public function run(string $path, array $options = []): array
    {
        $apply = ($options['mode'] ?? 'import') !== 'check';
        $overwrite = ($options['on_duplicate'] ?? 'skip') === 'update';
        $defaultSource = trim((string) ($options['default_source'] ?? 'wp_comment')) ?: 'wp_comment';
        $timezone = (string) ($options['timezone'] ?? config('app.timezone') ?: 'UTC');
        $allowBusiness = (bool) ($options['allow_business'] ?? false);

        $handle = @fopen($path, 'r');

        if ($handle === false) {
            return $this->report($apply, ['ok' => false, 'message' => 'The uploaded file could not be opened.']);
        }

        try {
            $delimiter = $this->sniff($path);
            $header = fgetcsv($handle, 0, $delimiter);

            if ($header === false || $header === [null]) {
                return $this->report($apply, ['ok' => false, 'message' => 'The file is empty — there is not even a header row.']);
            }

            $mapped = $this->mapHeader($header);

            if ($mapped !== null) {
                return $this->report($apply, ['ok' => false, 'message' => $mapped]);
            }

            $this->loadProducts();

            $batch = [];
            $line = 1;   // the header is line 1; the first data row is line 2

            while (($raw = fgetcsv($handle, 0, $delimiter)) !== false) {
                $line++;

                // fgetcsv yields [null] for a blank line. A trailing newline is
                // not a malformed row and must not be reported as one.
                if ($raw === [null] || $this->isBlank($raw)) {
                    continue;
                }

                if ($this->read >= self::MAX_ROWS) {
                    return $this->report($apply, [
                        'ok' => false,
                        'message' => 'This file has more than ' . number_format(self::MAX_ROWS) . ' rows. '
                            . 'Nothing has been imported. Split it and import the parts.',
                    ]);
                }

                $this->read++;

                try {
                    $batch[] = $this->parse($raw, $line, $timezone, $defaultSource, $allowBusiness);
                } catch (RowRejected $e) {
                    $this->reject($line, $e->getMessage());
                }

                if (count($batch) >= self::BATCH) {
                    $this->flush($batch, $apply, $overwrite);
                    $batch = [];
                }
            }

            if ($batch !== []) {
                $this->flush($batch, $apply, $overwrite);
            }
        } finally {
            fclose($handle);
        }

        $refreshed = 0;

        if ($apply) {
            $refreshed = $this->refreshRatings();
            $this->forgetHomeWall();
        }

        return $this->report($apply, ['ok' => true, 'ratings_refreshed' => $refreshed]);
    }

    /* ----------------------------------------------------------------- header */

    /**
     * Work out which column is which.
     *
     * @param  list<string|null>  $header
     * @return string|null a refusal message, or null when the header is usable
     */
    private function mapHeader(array $header): ?string
    {
        $seen = [];

        foreach ($header as $i => $cell) {
            $name = $this->normaliseHeader((string) ($cell ?? ''));

            if ($name === '') {
                continue;
            }

            $canonical = null;

            foreach (self::ALIASES as $field => $spellings) {
                if (in_array($name, $spellings, true)) {
                    $canonical = $field;
                    break;
                }
            }

            if ($canonical === null || isset($this->columns[$canonical])) {
                // A second column meaning the same thing is ignored rather than
                // silently overriding the first: the file, not this importer,
                // gets to say which of its two `title` columns came first.
                $this->ignored[] = (string) $cell;
                continue;
            }

            $this->columns[$canonical] = $i;
            $this->recognised[$canonical] = (string) $cell;
            $seen[] = $canonical;
        }

        if (! in_array('rating', $seen, true)) {
            return 'No rating column. A review without a rating is not a review, and this file has no column called '
                . 'rating, review_rating, stars or score. Nothing has been imported.';
        }

        $productish = ['product_id', 'wc_post_id', 'product_sku', 'product_name'];

        if (array_intersect($productish, $seen) === []) {
            return 'No product column. Without one, every row in this file would become a shop review attached to no '
                . 'product at all — thousands of them. Expected one of product_id, comment_post_id, sku or product. '
                . 'Nothing has been imported.';
        }

        return null;
    }

    private function normaliseHeader(string $raw): string
    {
        // The UTF-8 BOM rides on the first header cell of anything Excel wrote,
        // so "\u{FEFF}comment_id" would match no alias at all.
        $clean = preg_replace('/^\x{FEFF}/u', '', trim($raw)) ?? $raw;
        $clean = mb_strtolower(trim($clean));
        $clean = preg_replace('/[^a-z0-9]+/', '_', $clean) ?? $clean;

        return trim($clean, '_');
    }

    /**
     * The delimiter, decided from the header line rather than assumed.
     *
     * A CSV saved by Excel in a European locale is semicolon-separated, and
     * read as comma-separated it is one enormous column — which this importer
     * would otherwise report as "no rating column" on a file that has one.
     */
    private function sniff(string $path): string
    {
        $handle = @fopen($path, 'r');

        if ($handle === false) {
            return ',';
        }

        $first = (string) fgets($handle, 65536);
        fclose($handle);

        $best = ',';
        $bestCount = 0;

        foreach (self::DELIMITERS as $candidate) {
            $count = substr_count($first, $candidate);

            if ($count > $bestCount) {
                $best = $candidate;
                $bestCount = $count;
            }
        }

        return $best;
    }

    /* -------------------------------------------------------------------- row */

    /**
     * One CSV row to the shape a write needs, or a rejection naming the field.
     *
     * @param  list<string|null>  $raw
     * @return array<string, mixed>
     *
     * @throws RowRejected
     */
    private function parse(array $raw, int $line, string $timezone, string $defaultSource, bool $allowBusiness): array
    {
        $rating = $this->cell($raw, 'rating');

        if ($rating === '' || preg_match('/^\d+(\.0+)?$/', $rating) !== 1) {
            throw RowRejected::because("rating: '{$rating}' is not a whole number — expected 1 to 5");
        }

        $rating = (int) (float) $rating;

        if ($rating < 1 || $rating > 5) {
            throw RowRejected::because("rating: {$rating} is outside 1 to 5");
        }

        [$productId, $productNote] = $this->resolveProduct($raw, $allowBusiness);

        if ($productNote !== null) {
            $this->note($line, $productNote);
        }

        $author = trim(strip_tags($this->cell($raw, 'author')));

        if ($author === '') {
            // Not a rejection. WooCommerce stores an empty comment_author for a
            // logged-in reviewer whose display name lived on the user row, and
            // refusing those would throw away real reviews. The storefront
            // prints this field, though, so a blank one is a visibly broken
            // card — it gets a name and the owner gets told, by row.
            $author = 'Anonymous';
            $this->note($line, 'the author column was empty; imported as "Anonymous"');
        }

        $email = trim($this->cell($raw, 'email'));

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->note($line, "author email '{$email}' is not a valid address; imported without it");
            $email = '';
        }

        $created = DateParser::utc($this->cell($raw, 'created_at'), 'created_at', $timezone);

        if ($created === null && isset($this->columns['created_at'])) {
            $this->note($line, 'no review date in the file; imported with today\'s date');
        }

        $helpful = trim($this->cell($raw, 'helpful'));
        $helpful = ($helpful !== '' && preg_match('/^\d+$/', $helpful) === 1) ? (int) $helpful : 0;

        return [
            'line' => $line,
            'key' => $this->resolveKey($raw, $defaultSource, $productId, $author, $email, $rating),
            'values' => [
                'product_id' => $productId,
                'author_name' => mb_substr($author, 0, 255),
                'author_email' => mb_substr($email, 0, 255),
                'rating' => $rating,
                'title' => mb_substr(trim(strip_tags($this->cell($raw, 'title'))), 0, 255),
                'content' => trim(strip_tags($this->cell($raw, 'content'))),
                'reply' => trim(strip_tags($this->cell($raw, 'reply'))) ?: null,
                'status' => $this->status($this->cell($raw, 'status')),
                'verified' => $this->truthy($this->cell($raw, 'verified')),
                'helpful' => $helpful,
                'created_at' => $created?->toDateTimeString() ?? now()->toDateTimeString(),
                'updated_at' => now()->toDateTimeString(),
            ],
        ];
    }

    /**
     * How this row says which review it is — the whole of the idempotency
     * guarantee, in one place.
     *
     * Four ways, most trustworthy first:
     *
     *  1. `review_id` — this importer's own export round-tripping. Matched
     *     against reviews.id.
     *  2. (`source`, `source_id`) — the WooCommerce case. `comment_id` is the
     *     WordPress comment id and it is stable across exports, which is what
     *     makes a re-run an update rather than a second copy. The pair, not
     *     source_id alone: this table intentionally holds several origins whose
     *     id spaces are unrelated, and the schema's unique index is on the pair
     *     for exactly that reason (2026_09_22_000000_add_import_external_ids).
     *  3. A SYNTHESISED source_id, for a file that carries no id column at all
     *     — a spreadsheet the owner typed, most often. It is a hash of the
     *     fields that identify the review (product, author, email, rating, and
     *     the opening of the body), so the same file produces the same key
     *     every time and a second run updates rather than inserts.
     *
     *     ITS LIMIT, STATED PLAINLY BECAUSE IT MATTERS: if a row is EDITED
     *     between runs — the body changed, the rating corrected — its hash
     *     changes, so it imports as a new review and the old one stays. A file
     *     with a real id column does not have that problem, which is why the
     *     screen says so next to the upload button.
     *
     * @return array{by: string, id?: int, source?: string, source_id?: int}
     */
    private function resolveKey(array $raw, string $defaultSource, ?int $productId, string $author, string $email, int $rating): array
    {
        $reviewId = trim($this->cell($raw, 'review_id'));

        if ($reviewId !== '' && preg_match('/^\d+$/', $reviewId) === 1) {
            return ['by' => 'review_id', 'id' => (int) $reviewId];
        }

        $sourceId = trim($this->cell($raw, 'source_id'));

        if ($sourceId !== '' && preg_match('/^\d+$/', $sourceId) === 1 && (int) $sourceId > 0) {
            $source = trim($this->cell($raw, 'source')) ?: $defaultSource;

            return ['by' => 'source', 'source' => mb_substr($source, 0, 255), 'source_id' => (int) $sourceId];
        }

        /*
         * 13 hex digits = 52 bits, which is the largest value that survives
         * hexdec() as an exact integer rather than a float — and PHP would hand
         * back a float, silently, for anything wider. 52 bits is ample: the
         * first collision is expected somewhere around 67 million rows and the
         * ceiling on a file here is twenty thousand.
         */
        $fingerprint = implode('|', [
            $productId ?? 'business',
            mb_strtolower($author),
            mb_strtolower($email),
            $rating,
            mb_substr(trim(strip_tags($this->cell($raw, 'content'))), 0, 200),
        ]);

        return [
            'by' => 'fingerprint',
            'source' => self::SYNTHETIC_SOURCE,
            'source_id' => (int) hexdec(substr(hash('sha256', $fingerprint), 0, 13)),
        ];
    }

    /**
     * Which product this review belongs to.
     *
     * The order is deliberate and is the answer to a real ambiguity: in a
     * WooCommerce export `product_id` is the WordPress post id, and in this
     * importer's own export it is this store's own id. Both files call the
     * column the same thing. So the column is tried against products.id first
     * and against products.wc_id afterwards, with sku in between — and a value
     * that matches nothing is a rejection quoting the value, never a guess.
     *
     * @return array{0: int|null, 1: string|null}  [product id, note]
     *
     * @throws RowRejected
     */
    private function resolveProduct(array $raw, bool $allowBusiness): array
    {
        $id = trim($this->cell($raw, 'product_id'));
        $wc = trim($this->cell($raw, 'wc_post_id'));
        $sku = trim($this->cell($raw, 'product_sku'));
        $name = trim($this->cell($raw, 'product_name'));

        if ($id !== '' && preg_match('/^\d+$/', $id) === 1 && isset($this->byId[(int) $id])) {
            return [(int) $id, null];
        }

        if ($wc !== '' && preg_match('/^\d+$/', $wc) === 1 && isset($this->byWcId[(int) $wc])) {
            return [$this->byWcId[(int) $wc], null];
        }

        if ($sku !== '' && isset($this->bySku[mb_strtolower($sku)])) {
            return [$this->bySku[mb_strtolower($sku)], null];
        }

        if ($id !== '' && preg_match('/^\d+$/', $id) === 1 && isset($this->byWcId[(int) $id])) {
            return [$this->byWcId[(int) $id], 'product_id ' . $id . ' matched a WooCommerce product id, not a store id'];
        }

        if ($name !== '' && array_key_exists(mb_strtolower($name), $this->byName)) {
            $match = $this->byName[mb_strtolower($name)];

            if ($match === null) {
                throw RowRejected::because("product: more than one product is called '{$name}' — give a sku or a product_id instead");
            }

            return [$match, 'matched on the product name; a sku or id would be safer'];
        }

        $given = array_values(array_filter([$id, $wc, $sku, $name], static fn ($v) => $v !== '' && $v !== '0'));

        if ($given === []) {
            if ($allowBusiness) {
                return [null, 'no product given; imported as a shop review'];
            }

            throw RowRejected::because(
                'product: no product given. Tick "import rows with no product as shop reviews" if that is what this row is'
            );
        }

        throw RowRejected::because("product: nothing in this catalogue matches '" . implode("' / '", $given) . "'");
    }

    /**
     * WordPress's `comment_approved` vocabulary folded onto this schema's.
     *
     * '1' and '0' are what wp_comments actually stores, and neither survives
     * ReviewStatus::normalise() — it would read both as 'pending', which would
     * quietly leave every approved review in the owner's WooCommerce store
     * unpublished here, with no error to show for it.
     */
    private function status(string $raw): string
    {
        $value = mb_strtolower(trim($raw));

        return match ($value) {
            '1', 'approve', 'approved', 'yes', 'publish', 'published' => ReviewStatus::APPROVED,
            '0', 'hold', 'unapproved', 'moderated', 'pending' => ReviewStatus::PENDING,
            'spam', 'trash', 'rejected', 'reject' => ReviewStatus::SPAM,
            // An empty status column is not "spam" and not "live on the shop":
            // it is a row nobody has decided about, which is what pending is
            // for. Same reasoning as ReviewStatus' own unknown-value rule.
            '' => ReviewStatus::PENDING,
            default => ReviewStatus::normalise($value),
        };
    }

    private function truthy(string $raw): bool
    {
        return in_array(mb_strtolower(trim($raw)), ['1', 'yes', 'y', 'true', 'on', 'verified'], true);
    }

    /**
     * One cell, de-escaped.
     *
     * The export half prefixes a value beginning =, +, - or @ with an
     * apostrophe so a spreadsheet cannot execute it as a formula (see
     * ReviewsApiController::csvCell, which established that here). Importing
     * that apostrophe back as part of the text would corrupt a little more of
     * the review on every round trip, so it is taken off again — and ONLY when
     * what follows is one of the characters that put it there, so a review that
     * genuinely opens with a quotation mark keeps it.
     */
    private function cell(array $raw, string $field): string
    {
        $index = $this->columns[$field] ?? null;

        if ($index === null || ! array_key_exists($index, $raw)) {
            return '';
        }

        $value = (string) ($raw[$index] ?? '');

        if (strlen($value) > 1 && $value[0] === "'" && str_contains("=+-@\t\r", $value[1])) {
            return substr($value, 1);
        }

        return $value;
    }

    private function isBlank(array $raw): bool
    {
        foreach ($raw as $cell) {
            if (trim((string) ($cell ?? '')) !== '') {
                return false;
            }
        }

        return true;
    }

    /* ------------------------------------------------------------------ write */

    /**
     * One batch, in one transaction.
     *
     * Bounded on purpose: a single transaction around a 20,000-row file holds
     * locks on `reviews` for the whole run on a shared MySQL, and a timeout
     * halfway through would roll back work the report had already counted.
     */
    private function flush(array $batch, bool $apply, bool $overwrite): void
    {
        if ($batch === []) {
            return;
        }

        if (! $apply) {
            // A dry run still has to say whether each row WOULD be new, because
            // "412 new, 0 updated" before pressing the button is the only
            // number that tells the owner the file is the right one.
            $existing = $this->existing($batch);

            foreach ($batch as $row) {
                $match = $existing[$this->matchKey($row['key'])] ?? null;

                if ($match === null) {
                    $this->created++;
                } elseif ($overwrite) {
                    $this->updated++;
                } else {
                    $this->unchanged++;
                }
            }

            return;
        }

        DB::transaction(function () use ($batch, $overwrite): void {
            $existing = $this->existing($batch);

            foreach ($batch as $row) {
                $match = $existing[$this->matchKey($row['key'])] ?? null;
                $values = $row['values'];

                if ($match === null) {
                    $insert = $values;

                    if ($row['key']['by'] === 'review_id') {
                        // The file named an id this store does not have. The id
                        // is NOT honoured — forcing a primary key from a file
                        // is how an import collides with the next real
                        // submission — so the row is inserted as new and keyed
                        // by nothing, which the report counts as created.
                        $insert['source'] = self::SYNTHETIC_SOURCE;
                        $insert['source_id'] = null;
                    } else {
                        $insert['source'] = $row['key']['source'];
                        $insert['source_id'] = $row['key']['source_id'];
                    }

                    DB::table('reviews')->insert($insert);

                    $this->created++;

                    if ($insert['status'] === ReviewStatus::APPROVED && $insert['product_id'] !== null) {
                        $this->touched[(int) $insert['product_id']] = true;
                    }

                    continue;
                }

                if (! $overwrite) {
                    $this->unchanged++;

                    continue;
                }

                /*
                 * The product and the status can both move, and BOTH sides of
                 * the move have to be recomputed: the product losing the review
                 * and the product gaining it. Reading the old row first is the
                 * only chance to know the old product id.
                 */
                if ((int) $match->status_is_approved === 1 && $match->product_id !== null) {
                    $this->touched[(int) $match->product_id] = true;
                }

                DB::table('reviews')->where('id', '=', $match->id)->update($values);

                $this->updated++;

                if ($values['status'] === ReviewStatus::APPROVED && $values['product_id'] !== null) {
                    $this->touched[(int) $values['product_id']] = true;
                }
            }
        });
    }

    /**
     * The rows in this batch that already exist, keyed the same way the batch
     * is — two queries for the batch, never one per row.
     *
     * @return array<string, object>
     */
    private function existing(array $batch): array
    {
        $ids = [];
        $sourceIds = [];
        $sources = [];

        foreach ($batch as $row) {
            if ($row['key']['by'] === 'review_id') {
                $ids[] = $row['key']['id'];
            } else {
                $sourceIds[] = $row['key']['source_id'];
                $sources[] = $row['key']['source'];
            }
        }

        $found = [];

        $select = ['id', 'product_id', 'source', 'source_id', 'status'];

        if ($ids !== []) {
            foreach (DB::table('reviews')->select($select)->whereIn('id', array_unique($ids))->get() as $row) {
                $row->status_is_approved = $row->status === ReviewStatus::APPROVED ? 1 : 0;
                $found['id:' . $row->id] = $row;
            }
        }

        if ($sourceIds !== []) {
            $rows = DB::table('reviews')
                ->select($select)
                ->whereIn('source_id', array_values(array_unique($sourceIds)))
                ->whereIn('source', array_values(array_unique($sources)))
                ->get();

            foreach ($rows as $row) {
                $row->status_is_approved = $row->status === ReviewStatus::APPROVED ? 1 : 0;
                $found['src:' . $row->source . ':' . $row->source_id] = $row;
            }
        }

        return $found;
    }

    private function matchKey(array $key): string
    {
        return $key['by'] === 'review_id'
            ? 'id:' . $key['id']
            : 'src:' . $key['source'] . ':' . $key['source_id'];
    }

    /**
     * Recompute every affected product's denormalised pair, through the same
     * helper the moderation screen uses.
     *
     * Chunked, because a catalogue-wide import touches every product and
     * ProductRating::refresh() issues one grouped query plus one update per
     * product.
     */
    private function refreshRatings(): int
    {
        $ids = array_keys($this->touched);

        if ($ids === []) {
            return 0;
        }

        $written = 0;

        foreach (array_chunk($ids, self::REFRESH_CHUNK) as $chunk) {
            $written += ProductRating::refresh($chunk);
        }

        return $written;
    }

    /**
     * Drop the homepage's cached review wall.
     *
     * Store\HomeController wraps that wall in Cache::remember('kbb.home.reviews',
     * 900, ...) and it aggregates every APPROVED review SHOP-WIDE. An import is
     * the largest single change that set will ever see — this store has
     * thousands of reviews to bring over — so without this the homepage would
     * show the old wall for up to fifteen minutes after the import finished,
     * while every product page already showed the new reviews, with nothing on
     * screen admitting the two disagreed.
     *
     * ProductRating::refresh() does not cover it: that writes the denormalised
     * pair on `products`, which is a different reader. Same helper, same key and
     * the same reasoning as Admin\ReviewsApiController::forgetHomeWall(), which
     * found this on the moderation path.
     *
     * Unconditional on purpose, like the bulk moderation path: working out
     * whether any imported row was approved costs a query to save one cache
     * write, and a wrong answer there is the stale homepage again. A dry run
     * never reaches here, because it changed nothing.
     */
    private function forgetHomeWall(): void
    {
        Cache::forget('kbb.home.reviews');
    }

    /* ---------------------------------------------------------------- lookups */

    /**
     * Every product's four identifying columns, read once.
     *
     * One query rather than a lookup per row: a 3,700-row import doing
     * four SELECTs per row is 15,000 queries on a shared host. A name that more
     * than one product answers to is stored as null, so resolveProduct() can
     * refuse it rather than pick one.
     */
    private function loadProducts(): void
    {
        foreach (DB::table('products')->select(['id', 'wc_id', 'sku', 'name'])->cursor() as $product) {
            $id = (int) $product->id;

            $this->byId[$id] = $id;

            if ($product->wc_id !== null) {
                $this->byWcId[(int) $product->wc_id] = $id;
            }

            if ($product->sku !== null && trim((string) $product->sku) !== '') {
                $this->bySku[mb_strtolower(trim((string) $product->sku))] = $id;
            }

            $name = mb_strtolower(trim((string) $product->name));

            if ($name !== '') {
                $this->byName[$name] = array_key_exists($name, $this->byName) ? null : $id;
            }
        }
    }

    /* ----------------------------------------------------------------- report */

    private function reject(int $line, string $reason): void
    {
        $this->rejected++;

        if (count($this->rejects) < self::MAX_REPORTED) {
            $this->rejects[] = ['row' => $line, 'reason' => $reason];
        }
    }

    private function note(int $line, string $note): void
    {
        $this->noted++;

        if (count($this->notes) < self::MAX_REPORTED) {
            $this->notes[] = ['row' => $line, 'note' => $note];
        }
    }

    /** @param  array<string, mixed>  $extra */
    private function report(bool $apply, array $extra): array
    {
        return array_merge([
            'ok' => true,
            'mode' => $apply ? 'import' : 'check',
            'rows_read' => $this->read,
            'created' => $this->created,
            'updated' => $this->updated,
            'unchanged' => $this->unchanged,
            'rejected' => $this->rejected,
            'noted' => $this->noted,
            'rejects' => $this->rejects,
            'notes' => $this->notes,
            'truncated_lists' => $this->rejected > self::MAX_REPORTED || $this->noted > self::MAX_REPORTED,
            'products_touched' => count($this->touched),
            'ratings_refreshed' => 0,
            'columns_used' => $this->recognised,
            'columns_ignored' => $this->ignored,
        ], $extra);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\ImportConsole;

use App\Services\Import\ImportRunner;
use App\Services\Import\RowRejected;
use App\Services\Import\Sources\CsvRowSource;
use Illuminate\Http\UploadedFile;

/**
 * Where the owner's uploaded WooCommerce exports live, and what has to be true
 * of a file before it is allowed to become one.
 *
 * WHY A SEPARATE NAMESPACE FROM App\Services\Import. Everything under there is
 * the importer itself — mapping, validation, idempotency, and the tests that
 * pin them. This lane does not get to reimplement any of that, so it does not
 * live next to it either: ImportConsole is the *driver*, the part that answers
 * "how does someone with no shell reach the thing that already works".
 *
 * ---------------------------------------------------------------------------
 * THE UPLOAD IS THE ATTACK SURFACE, so the rules are stated rather than implied
 * ---------------------------------------------------------------------------
 *
 * 1. THE FILE IS NEVER SERVABLE. It is written to storage/app/import/woo, which
 *    is under the APPLICATION root. bootstrap/app.php points the public path at
 *    a different directory entirely (public_html/kbb-upgrade), so there is no
 *    URL that resolves to anything in here — not a guessed one, not a traversed
 *    one. Nothing under storage/ is reachable over HTTP on this host, and that
 *    is the property being relied on, not an .htaccess a server config change
 *    could stop honouring.
 *
 * 2. THE UPLOADED NAME IS DISCARDED. It is never used to build a path, never
 *    echoed back into one, never stored. The destination is one of exactly six
 *    literal names picked from this class's own table, so `../../.env`, a NUL
 *    byte, a doubled extension and every other filename trick have nowhere to
 *    land. The name is read for ONE purpose — guessing which of the six
 *    entities the owner meant — and that guess is a lookup in a fixed map, not
 *    a concatenation.
 *
 * 3. IT HAS TO ACTUALLY BE A CSV, and "actually" means parsed, not sniffed by
 *    extension or by the Content-Type the browser volunteered. Every accepted
 *    file is read through the importer's OWN CsvRowSource — the same class the
 *    import will use — so a file that survives this check is a file the
 *    importer can read, and the BOM handling, header folding and misalignment
 *    detection are the real ones rather than a second implementation that
 *    drifts from them.
 *
 * 4. THE OBVIOUS WRONG FILES ARE NAMED, NOT JUST REFUSED. An .xlsx is a zip and
 *    a PDF is a PDF; both are things an owner will genuinely try to upload, and
 *    "invalid file" teaches them nothing. Each gets a sentence saying what to
 *    do instead.
 *
 * ---------------------------------------------------------------------------
 * WHY THE ROW COUNT IS CACHED IN A SIDECAR
 * ---------------------------------------------------------------------------
 * The screen polls status while an import runs, and every poll wants "142 of
 * 2,419". Counting lines in a 200MB order export on each poll would make the
 * status endpoint the slowest thing on the server. The count is computed once,
 * at upload, and cached beside the file keyed on size+mtime, so a file replaced
 * out of band still recounts.
 */
final class ImportWorkspace
{
    /** Per file. Hostinger's own php.ini is usually lower; the screen shows both. */
    public const MAX_BYTES = 64 * 1024 * 1024;

    /**
     * The entities, in the importer's own fixed dependency order.
     *
     * `id` is the alias list a row of this entity must carry at least one of.
     * It is the external id — the column the importer matches on — and a file
     * without one cannot be re-run without inserting a second copy of
     * everything, so its absence is the single most useful thing to catch at
     * upload time rather than 40,000 rows into a run.
     *
     * `unique` marks the entities whose id column is unambiguous, which is what
     * makes header-sniffing safe for them: `term_id` belongs to categories AND
     * brands, and a bare `id` to products AND orders, so those four can only be
     * resolved from the filename or from the owner saying which they meant.
     */
    private const ENTITIES = [
        'categories' => [
            'file' => 'categories.csv',
            'label' => 'Categories',
            'id' => ['term_id', 'id', 'category_id'],
            'unique' => false,
            'help' => 'Product categories, including their nesting. Products are filed into these, so they go first.',
        ],
        'brands' => [
            'file' => 'brands.csv',
            'label' => 'Brands',
            'id' => ['term_id', 'id', 'brand_id'],
            'unique' => false,
            'help' => 'In WooCommerce these are the pa_brands attribute terms. Export those.',
        ],
        'products' => [
            'file' => 'products.csv',
            'label' => 'Products',
            'id' => ['id', 'wc_id', 'product_id', 'post_id'],
            'unique' => false,
            'help' => 'Products → Export in WooCommerce. Images are not imported here — kbb:import-media fetches those.',
        ],
        /*
         * MUST MIRROR ImportRunner::entities(), IN ORDER. The two lists are
         * hand-maintained and a test pins them against each other; see the note
         * on `seo` below for what it cost the last time they drifted.
         */
        'tags' => [
            'file' => 'tags.csv',
            'label' => 'Tags',
            'id' => ['term_id', 'id', 'tag_id'],
            'unique' => false,
            'help' => 'Product tags, and which products carry each one — the terms and the membership are in the same file. They come after products so each membership can be resolved.',
        ],
        'attributes' => [
            'file' => 'attributes.csv',
            'label' => 'Attributes',
            'id' => ['term_id', 'id'],
            'unique' => false,
            'help' => 'The pa_* attributes other than brands, one row per term with the attribute repeated on it. One file fills three tables: the attribute, its terms, and which products offer each term.',
        ],
        'variations' => [
            'file' => 'variations.csv',
            'label' => 'Variations',
            'id' => ['id', 'variation_id', 'wc_id'],
            'unique' => false,
            'help' => 'Every variable product\'s sizes and shades, with their own prices, SKUs and stock. They come after attributes because a variation names the terms defining it by slug.',
        ],
        'coupons' => [
            'file' => 'coupons.csv',
            'label' => 'Coupons',
            'id' => ['id', 'wc_id', 'coupon_id', 'post_id'],
            'unique' => false,
            'help' => 'Marketing → Coupons in WooCommerce. They come after products because a coupon restricted to particular products or categories names them by their WooCommerce id, which has to already be here to be translated.',
        ],
        'customers' => [
            'file' => 'customers.csv',
            'label' => 'Customers',
            'id' => ['user_id', 'wp_user_id', 'customer_id'],
            'unique' => true,
            'help' => 'Shoppers with a WordPress account, plus their billing and shipping addresses.',
        ],
        'orders' => [
            'file' => 'orders.csv',
            'label' => 'Orders',
            'id' => ['order_id', 'wc_order_id'],
            'unique' => true,
            'help' => 'Every order, with its totals and its address. Orders come after customers so they can be linked.',
        ],
        'order-items' => [
            'file' => 'order_items.csv',
            'label' => 'Order lines',
            'id' => ['item_id', 'order_item_id'],
            'unique' => true,
            'help' => 'The individual lines inside each order. They come last because they point at both orders and products.',
        ],
        /*
         * MUST MIRROR ImportRunner::entities(), in the same order -- see the
         * note on `seo` below for what registering on one list and not the
         * other cost.
         */
        'refunds' => [
            'file' => 'refunds.csv',
            'label' => 'Refunds',
            'id' => ['refund_id', 'id', 'wc_refund_id'],
            'unique' => true,
            'help' => 'Money WooCommerce gave back, one row per refund. They come after orders because each one attaches to its order, and until they are here every refunded order still reads as full revenue on the dashboard and still offers its whole total as refundable.',
        ],
        'order-notes' => [
            'file' => 'order_notes.csv',
            'label' => 'Order notes',
            'id' => ['note_id', 'comment_id', 'id'],
            'unique' => true,
            'help' => 'The history of what was said and done on each order, from WooCommerce. They come after orders so each note can be attached, and they appear on the order screen alongside the notes this shop writes itself.',
        ],
        'reviews' => [
            'file' => 'reviews.csv',
            'label' => 'Reviews',
            'id' => ['comment_id', 'id', 'source_id', 'wp_comment_id'],
            'unique' => false,
            'help' => 'WordPress comments with comment_type = \'review\' and their rating meta. They come after products and customers so each one can be attached, and importing them recomputes every affected product\'s star rating.',
        ],
        /*
         * MUST MIRROR ImportRunner::entities(). This list and that one are two
         * hand-maintained lists that have to agree, and they did not: the SEO
         * entity was registered on the runner while this screen knew nothing
         * about it, and every upload 500'd on an undefined key rather than
         * answering the 422 it had been answering. There is now a test pinning
         * the two lists against each other, because the next entity will be
         * added by somebody who has not read this comment.
         */
        'seo' => [
            'file' => 'seo.csv',
            'label' => 'SEO (Yoast)',
            'id' => ['id', 'wc_id', 'product_id', 'post_id'],
            'unique' => false,
            'help' => 'Your Yoast post-meta, matched to products by their WooCommerce id. Run it after products, and it never overwrites a title or description you have typed here.',
        ],
        'posts' => [
            'file' => 'posts.csv',
            'label' => 'Journal articles',
            'id' => ['id', 'post_id', 'ID'],
            'unique' => false,
            'help' => 'Your blog. The export writes every WordPress post type into this one file; only articles are imported, and a page or anything else in it is named in the report rather than written. An article is served from the site root, so one whose address this shop already owns -- /about/, /wishlist/, /feed/ -- is refused by name rather than written somewhere nothing can reach.',
        ],
    ];

    /**
     * The two files of the export that are NOT entities, and are not refuse.
     *
     * =========================================================================
     * WHY THESE ARE A SECOND TABLE AND NOT SIX MORE ENTRIES IN THE ONE ABOVE
     * =========================================================================
     *
     * Lane GK's export screen has a group called "Addresses and pictures", and
     * it holds `permalinks.csv` and `media.csv`. The owner downloads it, drops
     * it in the box on this screen, and before this table BOTH FILES WERE
     * REFUSED BY NAME — *"Which export is this?"* — because neither is in
     * ENTITIES. Not a regression, and pinned as a finding by Lane GM
     * (`docs/GM-IMPORT-ACCEPTS-ZIP.md` §10.2), but the owner got a download
     * that told him twice it had not imported.
     *
     * They are not entities, and making them entities would be wrong in three
     * separate ways:
     *
     *   THEY HAVE NO IMPORTER. `ImportRunner::entities()` and ENTITIES above
     *   are two hand-maintained lists that MUST agree — the class comment on
     *   `seo` records what it cost the last time they did not. Adding a name
     *   here with no `Importer` behind it is exactly that drift, deliberately.
     *
     *   THEY ARE NOT ROWS THIS SHOP STORES. `permalinks.csv` is a statement
     *   about the OLD site's addresses; nothing in this schema holds it.
     *   `media.csv` is a statement about the old site's uploads directory.
     *   An entity writes rows; these two answer questions.
     *
     *   THE DRIVE LOOP WOULD HAVE TO STEP THEM. `ImportDriver` walks the
     *   entity list one per request. Two entities that import nothing would be
     *   two steps that do nothing, in the loop this shop's whole import is
     *   built around.
     *
     * So they land in the export directory beside the CSVs, under their own
     * literal names, and the machinery that ALREADY reads them picks them up:
     * `RedirectMap::fromPermalinks()` (which has read this shape since the day
     * it was written, and which nothing on a screen had ever handed a file to)
     * and `MediaIndex`. `companionRows()` is the reader.
     *
     * `columns` is the same guard ENTITIES uses `id` for, and for the same
     * reason: a file whose columns this cannot use produces an EMPTY map
     * rather than an error, and a filter that matches no rows is the defect
     * `Api\ProductController` already cost this repository once. At least one
     * alias from each group must be present.
     */
    private const COMPANIONS = [
        'permalinks' => [
            'file' => 'permalinks.csv',
            'label' => 'Old addresses',
            'columns' => [
                ['permalink', 'url', 'old_url'],
                ['wc_id', 'id'],
            ],
            'read_by' => 'the redirect map on Store → Import → Addresses & pictures',
            'help' => 'Every address the old site published, with what each one was. This is what turns "some categories probably moved" into a list of the URLs Google actually holds.',
        ],
        'media' => [
            'file' => 'media.csv',
            'label' => 'Picture index',
            'columns' => [
                ['url', 'path'],
            ],
            'read_by' => 'the picture audit on Store → Import → Addresses & pictures',
            'help' => 'Every picture the old site referenced, with whether the file was still on that server when the export was taken. A picture already gone there is one no amount of copying will produce.',
        ],
    ];

    /** @return list<string> */
    public static function companionKeys(): array
    {
        return array_keys(self::COMPANIONS);
    }

    public static function isCompanion(string $key): bool
    {
        return isset(self::COMPANIONS[$key]);
    }

    /** @return array{file: string, label: string, columns: list<list<string>>, read_by: string, help: string} */
    public static function companionMeta(string $key): array
    {
        return self::COMPANIONS[$key];
    }

    public function companionPath(string $key): string
    {
        return $this->directory().'/'.self::COMPANIONS[$key]['file'];
    }

    public function hasCompanion(string $key): bool
    {
        return is_file($this->companionPath($key));
    }

    /**
     * The two companions, present or not, for the status payload.
     *
     * Deliberately NOT folded into files(): that list is what the drive loop,
     * the progress bar and the duplicate guard are computed over, and a row in
     * it that no importer can step is a row that would have to be special-cased
     * in three places. A separate key is one place.
     *
     * @return list<array<string, mixed>>
     */
    public function companions(): array
    {
        $out = [];

        foreach (self::COMPANIONS as $key => $meta) {
            $path = $this->companionPath($key);
            $present = is_file($path);

            $row = [
                'key' => $key,
                'label' => $meta['label'],
                'file' => $meta['file'],
                'help' => $meta['help'],
                'read_by' => $meta['read_by'],
                'present' => $present,
                'bytes' => 0,
                'rows' => 0,
                'uploaded_at' => null,
            ];

            if ($present) {
                $stat = $this->statFile($path, $key);
                $row['bytes'] = $stat['bytes'];
                $row['rows'] = $stat['rows'];
                $row['uploaded_at'] = $stat['uploaded_at'];
            }

            $out[] = $row;
        }

        return $out;
    }

    /**
     * The rows of a companion file, read through the importer's own CSV reader
     * so this and the import agree about what the file says.
     *
     * A generator: `media.csv` on the real catalogue is about 2,600 rows and
     * the caller indexes them one at a time.
     *
     * @return iterable<int, array<string, string>>
     */
    public function companionRows(string $key): iterable
    {
        $path = $this->companionPath($key);

        if (! is_file($path)) {
            return;
        }

        foreach ((new CsvRowSource($path))->rows() as $cells) {
            yield array_map(static fn ($cell): string => (string) $cell, $cells);
        }
    }

    public function forgetCompanion(string $key): void
    {
        $path = $this->companionPath($key);

        if (is_file($path)) {
            @unlink($path);
        }

        $this->forgetStatFile($path, $key);

        /*
         * The same statement forget() makes for an entity, and for the same
         * reason: an export that no longer carries this file on this shop is
         * not described by a manifest that still lists it.
         */
        $this->forgetManifestEntry(self::COMPANIONS[$key]['file']);
    }

    /** @return list<string> */
    public static function entities(): array
    {
        return array_keys(self::ENTITIES);
    }

    public static function isEntity(string $entity): bool
    {
        return isset(self::ENTITIES[$entity]);
    }

    /** @return array{file: string, label: string, id: list<string>, unique: bool, help: string} */
    public static function meta(string $entity): array
    {
        return self::ENTITIES[$entity];
    }

    public function directory(): string
    {
        $dir = storage_path('app/import/woo');

        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir;
    }

    /**
     * Where the row-count sidecars live, and why it is NOT beside the CSVs.
     *
     * It used to be. `products.csv.meta.json` sat next to `products.csv`, and
     * ImportRunner::reportUnreadFiles() globs `*.{csv,CSV,tsv,txt,json,xml}`
     * over the import directory and names everything no importer opens — so
     * every preview run from this screen reported the screen's own bookkeeping
     * files back to the owner as "files in the export folder that no importer
     * opens", one per uploaded entity, in the discard list he is asked to
     * approve. (The live run never showed it: it passes `only`, and that method
     * exempts a narrowed run. The preview passes no `only`, so it did.)
     *
     * The channel was right and the folder was wrong. The import directory is
     * the export; nothing that is not part of the export belongs in it.
     */
    public function metaDirectory(): string
    {
        $dir = storage_path('app/import/meta');

        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir;
    }

    public function path(string $entity): string
    {
        return $this->directory().'/'.self::ENTITIES[$entity]['file'];
    }

    public function has(string $entity): bool
    {
        return is_file($this->path($entity));
    }

    /* ----------------------------------------------------------- manifest */

    public function manifestPath(): string
    {
        return $this->directory().'/'.ImportManifest::FILE;
    }

    public function hasManifest(): bool
    {
        return is_file($this->manifestPath());
    }

    /**
     * The export's manifest, or the absent one.
     *
     * Read from disk each time rather than held: this is called from the status
     * endpoint the screen polls, and a manifest the owner has just replaced has
     * to be the one that answers. It is a file of a few hundred bytes with a
     * hard size cap in the reader.
     */
    public function manifest(): ImportManifest
    {
        return ImportManifest::read($this->manifestPath());
    }

    public function forgetManifest(): void
    {
        if (is_file($this->manifestPath())) {
            @unlink($this->manifestPath());
        }
    }

    /**
     * Every entity, present or not, with what is known about the file.
     *
     * Absent entities are listed too, and deliberately: a delta pass carrying
     * only new orders is a normal thing to run, so "missing" is information and
     * not an error, and the owner has to be able to see at a glance which of
     * the six they have actually provided.
     *
     * @return list<array<string, mixed>>
     */
    public function files(): array
    {
        $out = [];

        foreach (self::ENTITIES as $entity => $meta) {
            $path = $this->path($entity);
            $present = is_file($path);

            $row = [
                'entity' => $entity,
                'label' => $meta['label'],
                'file' => $meta['file'],
                'help' => $meta['help'],
                'id_columns' => $meta['id'],
                'present' => $present,
                'bytes' => 0,
                'rows' => 0,
                'fingerprint' => null,
                'uploaded_at' => null,
            ];

            if ($present) {
                $stat = $this->stat($entity);
                $row['bytes'] = $stat['bytes'];
                $row['rows'] = $stat['rows'];
                $row['fingerprint'] = $stat['fingerprint'];
                $row['uploaded_at'] = $stat['uploaded_at'];
            }

            $out[] = $row;
        }

        return $out;
    }

    /** Data rows (the header is not one) in the file for this entity, 0 when absent. */
    public function rowCount(string $entity): int
    {
        return $this->has($entity) ? $this->stat($entity)['rows'] : 0;
    }

    public function fingerprint(string $entity): ?string
    {
        return $this->has($entity) ? $this->stat($entity)['fingerprint'] : null;
    }

    /**
     * Accept one upload, which may be one file or a whole group's zip.
     *
     * THE ONE DOOR. The controller calls this and nothing else, so that "a zip
     * behaves exactly as if you had unzipped it and uploaded the files loose"
     * is a property of one function rather than a coincidence between two.
     *
     * A zip reports per file, the way a multi-file upload already does, and for
     * the same reason ImportApiController::upload() gives: having the fifth
     * file refused must not discard the four that were fine.
     *
     * @param  string|null  $entity  what the owner said it is; null means work it out
     * @return array{accepted: list<array{entity: string, rows: int, bytes: int, from?: string}>, refused: list<array{message: string}>}
     */
    public function acceptUpload(UploadedFile $file, ?string $entity = null): array
    {
        if ($file->isValid() && ImportArchive::looksLikeZip((string) $file->getRealPath())) {
            return $this->acceptArchive($file);
        }

        try {
            return ['accepted' => [$this->accept($file, $entity)], 'refused' => []];
        } catch (ImportUploadRejected $e) {
            return ['accepted' => [], 'refused' => [['message' => $e->getMessage()]]];
        }
    }

    /**
     * One group zip: unpack it somewhere private, then feed every file in it
     * through accept() exactly as though the owner had chosen them himself.
     *
     * THE ENTITY HINT IS IGNORED HERE, deliberately. "This file is the orders
     * export" is a statement about one file, and a zip is several by
     * definition; honouring it would mean writing brands.csv over orders.csv
     * because a dropdown was left on the wrong setting.
     *
     * A FAILURE TO UNPACK IS ONE REFUSAL FOR THE WHOLE ZIP, not one per file.
     * Every reason ImportArchive refuses is a statement about the archive —
     * it escapes, it is a bomb, it is a spreadsheet — and there are no files to
     * report against, because nothing was written.
     *
     * @return array{accepted: list<array{entity: string, rows: int, bytes: int, from?: string}>, refused: list<array{message: string}>}
     */
    private function acceptArchive(UploadedFile $file): array
    {
        $size = (int) $file->getSize();

        if ($size > self::MAX_BYTES) {
            return ['accepted' => [], 'refused' => [['message' => 'That zip is '.self::humanBytes($size)
                .'. The limit is '.self::humanBytes(self::MAX_BYTES).' per upload. Export the groups one at '
                .'a time — the importer resumes, so several smaller zips reach the same result.']]];
        }

        try {
            $unpacked = (new ImportArchive)->unpack((string) $file->getRealPath(), $this->incomingDirectory());
        } catch (ImportUploadRejected $e) {
            return ['accepted' => [], 'refused' => [['message' => $e->getMessage()]]];
        }

        $accepted = [];
        $refused = [];

        try {
            /*
             * THE MEMBERS ARE TAKEN IN THE ORDER THE ARCHIVE LISTS THEM, and
             * there was a usort() here putting manifest.json last.
             *
             * It was removed after mutation testing: REVERSING it, so the
             * manifest went first, left every test green. That is the correct
             * result and not a gap in the suite — nothing in this loop is
             * order-dependent. accept() does not read the manifest when it
             * takes a CSV, acceptManifest() merges against what is on DISK
             * rather than against anything in this batch, and one zip carries
             * at most one manifest, so there is no pair of members whose order
             * changes the outcome.
             *
             * The comment it carried said the manifest had to land after the
             * files it describes. That reads well and was not true of this
             * code, which is the most expensive kind of comment there is: the
             * next reader would have preserved an ordering that guarantees
             * nothing while believing it guaranteed something.
             */
            foreach ($unpacked['files'] as $member) {
                /*
                 * A real UploadedFile, marked `test` so Symfony skips the
                 * is_uploaded_file() check — the bytes came out of a zip, not
                 * out of a POST, and they are on a path this code chose. Doing
                 * it this way rather than adding a second code path into
                 * accept() is the whole invariant: the CSV parse, the id-column
                 * check, the manifest reader and every refusal sentence are the
                 * ones the loose upload gets, because they are literally the
                 * same call.
                 */
                $inner = new UploadedFile($member['path'], $member['name'], null, null, true);

                try {
                    $accepted[] = $this->accept($inner) + ['from' => $member['name']];
                } catch (ImportUploadRejected $e) {
                    $refused[] = ['message' => $member['name'].' (inside the zip): '.$e->getMessage()];
                }
            }
        } finally {
            // Whatever happened, the scratch is gone. accept() MOVES the files
            // it keeps, so what is left here is only what was refused.
            ImportArchive::purge($unpacked['dir']);
        }

        return ['accepted' => $accepted, 'refused' => $refused];
    }

    /**
     * Where a zip is unpacked before any of it is believed.
     *
     * A SIBLING of the import directory, never inside it. ImportRunner::
     * reportUnreadFiles() globs the import directory and names everything no
     * importer opens, in the discard list the owner is asked to approve — the
     * same trap that moved the row-count sidecars out (see metaDirectory()).
     * A half-unpacked zip appearing in that list would be this lane repeating
     * a mistake this repository has already paid for.
     */
    public function incomingDirectory(): string
    {
        $dir = storage_path('app/import/incoming');

        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir;
    }

    /**
     * Accept one uploaded file, or say exactly why not.
     *
     * @param  string|null  $entity  what the owner said it is; null means work it out
     * @return array{entity: string, rows: int, bytes: int, merged?: bool}
     *
     * @throws ImportUploadRejected
     */
    public function accept(UploadedFile $file, ?string $entity = null): array
    {
        if (! $file->isValid()) {
            throw new ImportUploadRejected(
                'The upload did not arrive complete. This is almost always the file being larger than the '
                .'server allows — the limits are shown under the upload box.'
            );
        }

        $size = (int) $file->getSize();

        if ($size <= 0) {
            throw new ImportUploadRejected('That file is empty.');
        }

        if ($size > self::MAX_BYTES) {
            throw new ImportUploadRejected(
                'That file is '.self::humanBytes($size).'. The limit is '.self::humanBytes(self::MAX_BYTES)
                .' per file. Split the export and upload it in pieces — the importer resumes, so several '
                .'smaller order exports reach the same result as one large one.'
            );
        }

        $temp = (string) $file->getRealPath();

        /*
         * THE MANIFEST IS A FILE OF THE EXPORT AND ARRIVES THE SAME WAY, so it
         * is recognised here rather than through an endpoint of its own. The
         * owner drags nine CSVs and a manifest.json into the same box; asking
         * him to know which one goes in the other box would be a way of losing
         * the manifest.
         *
         * It is recognised by BOTH its name and its contents, and a CSV can
         * satisfy neither: a file called manifest.json, or a file whose first
         * bytes parse as a JSON object carrying a `format` key. A products
         * export does not begin with `{`.
         */
        if ($entity === null && $this->looksLikeManifest($temp, (string) $file->getClientOriginalName())) {
            return $this->acceptManifest($file, $temp);
        }

        $this->refuseNonText($temp);

        // Read the first data row through the importer's own reader, so this
        // check and the import agree about what the file says. A misaligned
        // file raises here, with its line number, instead of 40,000 rows in.
        try {
            $first = $this->firstRow($temp);
        } catch (RowRejected $e) {
            throw new ImportUploadRejected('That file is not readable as CSV: '.$e->getMessage());
        }

        /*
         * THE ADDRESSES GROUP, before resolveEntity() gets to refuse it.
         *
         * Placed HERE and not earlier on purpose: refuseNonText() and
         * firstRow() have already run, so a companion goes through exactly the
         * same "is this really a readable CSV" gate every entity does. And
         * placed here rather than in front of the filename table, because
         * companionFor() consults that table first and yields to it — an entity
         * can never be stolen by this, so the only behaviour that changes is a
         * refusal becoming an acceptance.
         */
        $companion = $this->companionFor($entity, (string) $file->getClientOriginalName());

        if ($companion !== null) {
            return $this->acceptCompanion($file, $companion, $first);
        }

        $entity = $this->resolveEntity($entity, (string) $file->getClientOriginalName(), $first);

        if ($first !== null) {
            $meta = self::ENTITIES[$entity];
            $found = false;

            foreach ($meta['id'] as $alias) {
                if (array_key_exists($alias, $first)) {
                    $found = true;
                    break;
                }
            }

            if (! $found) {
                throw new ImportUploadRejected(
                    'This does not look like the '.$meta['label'].' export: it has no '
                    .implode(' or ', array_map(static fn (string $a): string => '"'.$a.'"', $meta['id']))
                    .' column. That column is how each row is matched on a re-run, and without it a second '
                    .'import would insert a duplicate of every row. Columns found: '
                    .self::sample(array_keys($first))
                );
            }
        }

        // The destination is a literal from the table above. The name the
        // browser sent is not part of it and never touches the filesystem.
        $destination = $this->path($entity);

        if (is_file($destination)) {
            @unlink($destination);
        }

        $this->forgetStat($entity);

        $file->move($this->directory(), self::ENTITIES[$entity]['file']);

        @chmod($destination, 0664);

        $stat = $this->stat($entity);

        return ['entity' => $entity, 'rows' => $stat['rows'], 'bytes' => $stat['bytes']];
    }

    public function forget(string $entity): void
    {
        $path = $this->path($entity);

        if (is_file($path)) {
            @unlink($path);
        }

        $this->forgetStat($entity);

        /*
         * AND THE MANIFEST STOPS DESCRIBING IT.
         *
         * Removing a file means it is not part of the export on this shop any
         * more, so a manifest that still lists it is describing work that will
         * never happen. Before group zips this cost nothing — the owner
         * re-uploaded a manifest built from what was left and it REPLACED the
         * old one, so the entry went with it. Now that a manifest of the same
         * export MERGES (ImportManifest::merge(), and the long argument for it
         * there), an omission is silence rather than a deletion — which is the
         * whole point, because the Catalogue zip's manifest omits orders.csv
         * and must not delete the Sales zip's entry for it.
         *
         * That leaves exactly one case needing a statement rather than silence,
         * and this is it. Lane GF's
         * `it gives the catalogue stage no total when only some of its entities
         * have one` is what found it: a stale entry gives an ABSENT file a
         * denominator (denominator() reads `rows` from the manifest and there is
         * no counted number to disagree with it), and MigrationProgress then
         * sums a total over work that is not there — the bar running ahead of
         * the import and settling at 100% with a whole file still to come, which
         * is the thing that page was built to stop telling.
         *
         * So: pressing Remove says it out loud, and nothing else has to.
         */
        $this->forgetManifestEntry(self::ENTITIES[$entity]['file']);
    }

    /**
     * Drop one file from the manifest on disk, leaving the rest of it alone.
     *
     * Rewritten rather than deleted: the export id, the source and every other
     * file's counts are still true and are what the duplicate guard and the
     * history are built from.
     */
    private function forgetManifestEntry(string $file): void
    {
        $path = $this->manifestPath();
        $manifest = ImportManifest::read($path);

        if (! $manifest->usable() || ! $manifest->lists($file)) {
            return;
        }

        $raw = $manifest->raw();

        if (is_array($raw['files'] ?? null)) {
            unset($raw['files'][$file]);
        }

        if (is_array($raw['groups'] ?? null) && is_array($raw['groups']['files'] ?? null)) {
            $raw['groups']['files'] = array_values(array_filter(
                $raw['groups']['files'],
                static fn (mixed $f): bool => $f !== $file,
            ));
        }

        @file_put_contents($path, (string) json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        clearstatcache(true, $path);
    }

    /** What this PHP install will actually accept, which is often less than we allow. */
    public function serverLimits(): array
    {
        return [
            'upload_max_filesize' => (string) ini_get('upload_max_filesize'),
            'post_max_size' => (string) ini_get('post_max_size'),
            'max_execution_time' => (int) ini_get('max_execution_time'),
            'memory_limit' => (string) ini_get('memory_limit'),
            'our_max_bytes' => self::MAX_BYTES,
        ];
    }

    public static function humanBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024).' KB';
        }

        return round($bytes / 1024 / 1024, 1).' MB';
    }

    /* --------------------------------------------------------------- guts */

    /**
     * Is this the export's manifest rather than one of its CSV files?
     *
     * Two independent tests, either of which is enough, because the two failure
     * modes are opposite: a browser that sends `manifest (1).json` after a
     * second download still has JSON inside it, and a manifest saved by a tool
     * that mangled its contents is still named manifest.json and should be
     * refused AS a broken manifest rather than as an unrecognisable CSV.
     */
    private function looksLikeManifest(string $path, string $originalName): bool
    {
        $base = mb_strtolower(basename(str_replace('\\', '/', $originalName)));

        if ($base === ImportManifest::FILE || str_starts_with($base, 'manifest') && str_ends_with($base, '.json')) {
            return true;
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $head = (string) fread($handle, 4096);
        fclose($handle);

        if (! str_starts_with(ltrim($head, "\xEF\xBB\xBF \t\r\n"), '{')) {
            return false;
        }

        // The whole file, only once it is known to start like JSON and to be
        // small enough to be a manifest.
        clearstatcache(true, $path);

        if ((int) filesize($path) > ImportManifest::MAX_BYTES) {
            return false;
        }

        $decoded = json_decode((string) @file_get_contents($path), true);

        return is_array($decoded) && array_key_exists('format', $decoded);
    }

    /**
     * Store the manifest, or say exactly what is wrong with it.
     *
     * A manifest that this shop cannot read is refused AT UPLOAD, which is the
     * only place the owner is looking at the file he just chose. Letting it
     * land and refusing at Start instead would put the sentence four screens
     * away from the thing it is about.
     *
     * @return array{entity: string, rows: int, bytes: int, merged: bool}
     *
     * @throws ImportUploadRejected
     */
    private function acceptManifest(UploadedFile $file, string $temp): array
    {
        $manifest = ImportManifest::parse((string) @file_get_contents($temp));

        if ($manifest->refusal() !== null) {
            throw new ImportUploadRejected($manifest->refusal());
        }

        $destination = $this->manifestPath();

        /*
         * A SECOND MANIFEST OF THE SAME EXPORT IS MERGED, NOT SUBSTITUTED.
         *
         * Lane GL gives the owner one zip per group, each with its own
         * manifest.json and all of them carrying one `export_id`. Uploading
         * Catalogue and then Orders therefore hands this method two manifests
         * describing two halves of one export, and replacing is the wrong verb
         * for that: ImportManifest::merge()'s comment is the full argument, and
         * the short version is that after a replace products.csv is on the disk
         * and absent from the manifest beside it, which the contract defines to
         * mean "this export does not carry products".
         *
         * A manifest of a DIFFERENT export replaces, as it always did. So does
         * one with no `export_id` at all — two anonymous manifests are two
         * unknowns and merging them would join a January export to a September
         * one because neither said which it was.
         *
         * This is on the loose-upload path as well as the zip one, and that is
         * the point: a zip has to behave exactly as if its files had been
         * uploaded by hand, and two manifests dragged into the box by hand are
         * the same two manifests.
         */
        $existing = $this->manifest();
        $merged = null;

        if ($existing->usable() && ImportManifest::sameExport($existing, $manifest)) {
            $merged = ImportManifest::merge($existing, $manifest);
        }

        if (is_file($destination)) {
            @unlink($destination);
        }

        if ($merged !== null) {
            // Written rather than moved: the file that lands is neither of the
            // two that arrived.
            @file_put_contents($destination, (string) json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $file->move($this->directory(), ImportManifest::FILE);
        }

        @chmod($destination, 0664);

        clearstatcache(true, $destination);

        $landed = ImportManifest::read($destination);

        return [
            'entity' => 'manifest',
            'rows' => count($landed->files()),
            'bytes' => (int) filesize($destination),
            'merged' => $merged !== null,
        ];
    }

    /**
     * Refuse the things that are definitely not a CSV before parsing one.
     *
     * A NUL byte in the first block is the general test — no text export has
     * one, every binary does — and the three magic numbers turn the most common
     * honest mistakes into a sentence the owner can act on rather than a flat
     * refusal they will retry unchanged.
     *
     * @throws ImportUploadRejected
     */
    private function refuseNonText(string $path): void
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw new ImportUploadRejected('That file could not be read after uploading.');
        }

        $head = (string) fread($handle, 8192);
        fclose($handle);

        if (str_starts_with($head, "PK\x03\x04")) {
            throw new ImportUploadRejected(
                'That is a spreadsheet (.xlsx) or a zip, not a CSV. In Excel or Google Sheets choose '
                .'"Save as" / "Download" and pick CSV, then upload that.'
            );
        }

        if (str_starts_with($head, '%PDF')) {
            throw new ImportUploadRejected('That is a PDF. The importer reads CSV exports, not reports.');
        }

        if (preg_match('/^\s*<\?|^\s*<(!doctype|html)/i', $head) === 1) {
            throw new ImportUploadRejected(
                'That is a web page or a script, not a CSV export. If you saved a page from wp-admin, '
                .'use WooCommerce → Products → Export instead.'
            );
        }

        if (str_contains($head, "\0")) {
            throw new ImportUploadRejected(
                'That file is not text — it contains binary data. Export it as CSV and upload that.'
            );
        }

        if (trim($head) === '') {
            throw new ImportUploadRejected('That file has no content in it.');
        }
    }

    /**
     * The first DATA row, read exactly as the import will read it, or null when
     * the file is a header and nothing else.
     *
     * A header with no rows is accepted. It is what a delta export of a week
     * with no new customers looks like, and refusing it would make the owner
     * delete a file to prove there is nothing in it.
     *
     * @return array<string, string>|null
     *
     * @throws RowRejected
     */
    private function firstRow(string $path): ?array
    {
        foreach ((new CsvRowSource($path))->rows() as $cells) {
            return $cells;
        }

        return null;
    }

    /**
     * The entity a filename names, or null.
     *
     * Extracted out of resolveEntity() rather than copied, because
     * companionFor() has to ask the SAME question and a second copy of this
     * loop is a second copy that can drift — which is the drift the note on
     * `seo` above records the cost of.
     *
     * The filename is used ONLY as a key into the fixed table. It is never
     * joined to a path.
     */
    private function entityFromFilename(string $originalName): ?string
    {
        $base = self::normaliseName($originalName);

        foreach (self::ENTITIES as $name => $meta) {
            $stem = str_replace('.csv', '', $meta['file']);

            if (str_contains($base, $stem) || str_contains($base, str_replace('_', '', $stem))) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Is this one of the export's two companion files?
     *
     * THREE THINGS HAVE TO BE TRUE, and each one is a rule rather than a
     * convenience:
     *
     *  - THE OWNER DID NOT SAY WHAT IT IS. A dropdown choice is a statement
     *    about this file and it wins, exactly as it does for an entity. The
     *    dropdown lists entities only, so "I said products" can never be
     *    quietly overruled into a permalink file.
     *
     *  - NO ENTITY CLAIMS THE NAME. Asked first, so this can only ever turn a
     *    refusal into an acceptance and never turn one entity into another.
     *    Nothing in ENTITIES contains the stems below today and this does not
     *    rely on that staying true.
     *
     *  - THE NAME NAMES IT. Never the header. `media.csv` and `products.csv`
     *    both carry a `url`-ish column, and sniffing between them is the guess
     *    resolveEntity() refuses to make for the four ambiguous entities.
     */
    private function companionFor(?string $entity, string $originalName): ?string
    {
        if ($entity !== null && $entity !== '') {
            return null;
        }

        if ($this->entityFromFilename($originalName) !== null) {
            return null;
        }

        $base = self::normaliseName($originalName);

        foreach (self::COMPANIONS as $key => $meta) {
            $stem = str_replace('.csv', '', $meta['file']);

            if (str_contains($base, $stem)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * File a companion, with the same column guard an entity gets.
     *
     * WHY THE COLUMN CHECK IS NOT OPTIONAL HERE. Neither of these files is
     * imported, so neither produces a report the owner would read. A
     * `permalinks.csv` with no `permalink` column does not fail — it makes
     * `RedirectMap::fromPermalinks()` skip every row and propose nothing, and
     * the screen then shows a map that is missing precisely the addresses this
     * file exists to supply, with no sign that anything went wrong. That is
     * the shape CLAUDE.md names: a broken filter hiding a second bug.
     *
     * @param  array<string, string>|null  $first
     * @return array{entity: string, rows: int, bytes: int, companion: true}
     *
     * @throws ImportUploadRejected
     */
    private function acceptCompanion(UploadedFile $file, string $key, ?array $first): array
    {
        $meta = self::COMPANIONS[$key];

        if ($first !== null) {
            foreach ($meta['columns'] as $group) {
                $found = false;

                foreach ($group as $alias) {
                    if (array_key_exists($alias, $first)) {
                        $found = true;
                        break;
                    }
                }

                if (! $found) {
                    throw new ImportUploadRejected(
                        'This does not look like the '.$meta['label'].' export: it has no '
                        .implode(' or ', array_map(static fn (string $a): string => '"'.$a.'"', $group))
                        .' column. Without it this file would be read by '.$meta['read_by']
                        .' and produce nothing at all, which looks exactly like not having uploaded it. '
                        .'Columns found: '.self::sample(array_keys($first))
                    );
                }
            }
        }

        $destination = $this->companionPath($key);

        if (is_file($destination)) {
            @unlink($destination);
        }

        $this->forgetStatFile($destination, $key);

        // The destination is a literal from the table above. The name the
        // browser sent is not part of it and never touches the filesystem.
        $file->move($this->directory(), $meta['file']);

        @chmod($destination, 0664);

        $stat = $this->statFile($destination, $key);

        return ['entity' => $key, 'rows' => $stat['rows'], 'bytes' => $stat['bytes'], 'companion' => true];
    }

    /** Lower-cased basename with every run of non-alphanumerics folded to `_`. */
    private static function normaliseName(string $originalName): string
    {
        $base = mb_strtolower(basename(str_replace('\\', '/', $originalName)));

        return preg_replace('/[^a-z0-9]+/', '_', $base) ?? $base;
    }

    /**
     * Which of the six this is: what the owner said, else the filename, else
     * an unambiguous id column. Never a guess between two possibilities.
     *
     * @param  array<string, string>|null  $first
     *
     * @throws ImportUploadRejected
     */
    private function resolveEntity(?string $entity, string $originalName, ?array $first): string
    {
        if ($entity !== null && $entity !== '') {
            if (! isset(self::ENTITIES[$entity])) {
                throw new ImportUploadRejected('"'.$entity.'" is not one of the six things this imports.');
            }

            return $entity;
        }

        $named = $this->entityFromFilename($originalName);

        if ($named !== null) {
            return $named;
        }

        if ($first !== null) {
            $matches = [];

            foreach (self::ENTITIES as $name => $meta) {
                if (! $meta['unique']) {
                    continue;
                }

                foreach ($meta['id'] as $alias) {
                    if (array_key_exists($alias, $first)) {
                        $matches[$name] = true;
                        break;
                    }
                }
            }

            if (count($matches) === 1) {
                return (string) array_key_first($matches);
            }
        }

        throw new ImportUploadRejected(
            'Which export is this? Name the file one of '
            .implode(', ', array_map(static fn (array $m): string => $m['file'], self::ENTITIES))
            .', or choose what it is from the dropdown before uploading.'
        );
    }

    /**
     * Size, row count and fingerprint, computed once and cached beside the file.
     *
     * @return array{bytes: int, rows: int, fingerprint: string, uploaded_at: string}
     */
    private function stat(string $entity): array
    {
        return $this->statFile($this->path($entity), $entity);
    }

    /**
     * The same, for any file the workspace keeps, keyed by its own sidecar
     * name. Entities and companions both go through here so a companion's row
     * count is cached on exactly the same terms — size+mtime — as an entity's,
     * and a file replaced out of band still recounts.
     *
     * @return array{bytes: int, rows: int, fingerprint: string, uploaded_at: string}
     */
    private function statFile(string $path, string $key): array
    {
        $sidecar = $this->metaDirectory().'/'.$key.'.json';

        /*
         * A sidecar from before they moved out of the export folder. Removed
         * rather than read: it is one file, it is cheap to recompute, and
         * leaving it would leave ImportRunner still reporting it to the owner
         * as a file nothing opens.
         */
        $legacy = $path.'.meta.json';

        if (is_file($legacy)) {
            @unlink($legacy);
        }

        clearstatcache(true, $path);

        $bytes = (int) filesize($path);
        $mtime = (int) filemtime($path);

        if (is_file($sidecar)) {
            $cached = json_decode((string) @file_get_contents($sidecar), true);

            if (is_array($cached) && ($cached['bytes'] ?? null) === $bytes && ($cached['mtime'] ?? null) === $mtime) {
                return [
                    'bytes' => $bytes,
                    'rows' => (int) ($cached['rows'] ?? 0),
                    'fingerprint' => (string) ($cached['fingerprint'] ?? ''),
                    'uploaded_at' => (string) ($cached['uploaded_at'] ?? gmdate('c', $mtime)),
                ];
            }
        }

        $fresh = [
            'bytes' => $bytes,
            'mtime' => $mtime,
            'rows' => $this->countRows($path),
            // The same digest the checkpoint records, so the screen can say
            // "this is not the file the part-finished run was reading".
            'fingerprint' => (string) hash_file('sha256', $path),
            'uploaded_at' => gmdate('c', $mtime),
        ];

        @file_put_contents($sidecar, json_encode($fresh));

        unset($fresh['mtime']);

        return $fresh;
    }

    private function forgetStat(string $entity): void
    {
        $this->forgetStatFile($this->path($entity), $entity);
    }

    private function forgetStatFile(string $path, string $key): void
    {
        foreach ([$this->metaDirectory().'/'.$key.'.json', $path.'.meta.json'] as $sidecar) {
            if (is_file($sidecar)) {
                @unlink($sidecar);
            }
        }
    }

    /**
     * Data rows, counted the way the importer will count them.
     *
     * fgetcsv rather than substr_count($contents, "\n"): a product description
     * containing a newline inside quotes is ONE row, and counting newlines
     * would report a total the progress bar could never reach.
     */
    private function countRows(string $path): int
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return 0;
        }

        $rows = -1; // the header

        try {
            while (($cells = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                if ($cells === [null]) {
                    continue;
                }

                $rows++;
            }
        } finally {
            fclose($handle);
        }

        return max(0, $rows);
    }

    /** @param list<string> $columns */
    private static function sample(array $columns): string
    {
        $shown = array_slice($columns, 0, 8);
        $more = count($columns) - count($shown);

        return implode(', ', $shown).($more > 0 ? ' ... and '.$more.' more' : '');
    }

    /**
     * The entity order the importer itself uses.
     *
     * Read off ImportRunner rather than copied, so that if a later lane adds a
     * seventh entity this screen's own test fails loudly instead of the screen
     * silently never offering it.
     *
     * @return list<string>
     */
    public static function runnerOrder(): array
    {
        return ImportRunner::entityNames();
    }
}

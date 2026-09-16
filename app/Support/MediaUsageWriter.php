<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Media;
use App\Models\MediaUsageRecord;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps `media_usages` saying what App\Support\MediaUsage would derive.
 *
 * WHAT THIS IS FOR. The schema records an attachment as a URL string on the
 * owning row and nothing else, so "which product is this image on" has always
 * been answered by walking every product, brand and category and comparing
 * filenames. `media_usages` is the index over that walk; this class is the
 * only thing that writes it.
 *
 * AGREEMENT IS STRUCTURAL, NOT COINCIDENTAL. Every row this writes comes from
 * MediaUsage's own entries (MediaUsage::index() / ::indexForOwner()) filtered
 * by MediaUsage::matches() — the same predicate MediaUsage::verify() applies.
 * There is no second copy of the filename-and-suffix rule here to drift from
 * the first. MediaUsagesTest pins that equality over the awkward URL shapes
 * this store actually contains: absolute, root-relative, bare, cache-busted,
 * and the one parse_url() refuses outright.
 *
 * ── HOW IT IS KEPT UP TO DATE, and where it can still fall behind ──────────
 *
 * listen() registers model hooks on Product, Brand, Category and Media. Every
 * write to the four columns in this application today goes through Eloquent,
 * which is what makes hooks sufficient rather than hopeful. That was checked
 * rather than assumed, and here is the whole list as of this lane:
 *
 *   products.image / products.images
 *       ProductEditorApiController::apply() — `$product->save()`
 *       Services\Import\Entities\ProductImporter via ImportContext::apply(),
 *         which is also `$model->save()`
 *   brands.logo
 *       BrandsApiController::store()/update() — Eloquent create()/update()
 *       Services\Import\Entities\BrandImporter, same ImportContext::apply()
 *   categories.image
 *       CategoriesApiController::store()/update() — Eloquent create()/update()
 *       Services\Import\Entities\CategoryImporter, same ImportContext::apply()
 *
 * The query-builder writes that DO exist against those three tables were each
 * checked and none touches an image column: CatalogProductsApiController's
 * bulk status update (line ~553) writes `status`, its bulk price update
 * (~822) is restricted to `price`/`sale_price` by an `in:` rule, and the
 * position/path/depth updates in CategoriesApiController and
 * BrandsTreeApiController write only those columns.
 *
 * THE TWO KNOWN GAPS, stated because a silent one would be the whole problem:
 *
 *  1. A BULK Eloquent delete fires no model events. `Product::query()
 *     ->forceDelete()` appears in database/migrations/
 *     2026_08_27_100000_seed_demo_catalogue.php and in test setup, and leaves
 *     this table holding rows for owners that are gone. Those rows make the
 *     grid over-report usage; they cannot make it under-report, so the error
 *     lands on the safe side, and `media:usages-reconcile` clears them.
 *
 *  2. MediaBackfill inserts with `Media::query()->insert()`, a query-builder
 *     bulk insert that fires no `created` event either. That one is NOT left
 *     to a reconcile, because it is the dangerous direction: a media row with
 *     no usage rows looks unused. MediaBackfill::run() calls syncMedia() on
 *     exactly the rows it inserted. MediaUsagesTest's "it records usage for
 *     files the rescan catalogues, which insert without firing events" pins
 *     it.
 *
 * WHY THIS IS NOT THE AUTHORITY. The delete guard still calls
 * MediaUsage::verify(). A row missing from this table would let the Media
 * Library offer to delete an image that is on a live product page, and that
 * is the one failure on this screen a shopper sees. Being a moment behind on
 * a grid badge is not in the same category. See the header of
 * database/migrations/2026_10_10_000000_create_media_usages.php.
 */
final class MediaUsageWriter
{
    /** The model behind each owner_type string. */
    private const MODELS = [
        'product' => Product::class,
        'brand' => Brand::class,
        'category' => Category::class,
    ];

    /** Rows per insert. Matches MediaBackfill's chunking for the same reason. */
    private const CHUNK = 500;

    /**
     * Register the model hooks. Called once from AppServiceProvider::boot().
     */
    public static function listen(): void
    {
        foreach (self::MODELS as $kind => $class) {
            /*
             * `created` and `updated` rather than `saved`, because the two
             * need different treatment and `saved` cannot tell them apart.
             *
             * A new row always records: Eloquent only calls syncChanges() in
             * performUpdate(), so getChanges() is EMPTY after an insert and
             * asking it what moved would answer "nothing" for a product that
             * arrived with an image. (wasRecentlyCreated is no use here
             * either — it stays true for the rest of the instance's life, so
             * every later save on the same object would look like a create.)
             */
            $class::created(static function (Model $owner) use ($kind): void {
                self::syncOwner($kind, $owner);
            });

            /*
             * An edit records only when an image column actually moved. The
             * rows for an owner depend on nothing but its own image columns
             * and the `media` table, which the Media hooks below cover, so
             * re-deriving on a price change would add a delete and an insert
             * to each of 2,400 products on an import run, on a shared host,
             * for no change in the answer.
             */
            $class::updated(static function (Model $owner) use ($kind): void {
                if (! self::touchedAnImage($kind, $owner)) {
                    return;
                }

                self::syncOwner($kind, $owner);
            });

            /*
             * `deleted` covers a soft delete too, which is the point.
             * MediaUsage::index() goes through Product::query(), so the
             * SoftDeletingScope hides a trashed product and its image reads as
             * unused — tests/Feature/MediaLibraryTest.php pins that. This
             * table has to agree, so a trashed product's rows go.
             */
            $class::deleted(static function (Model $owner) use ($kind): void {
                self::forgetOwner($kind, (int) $owner->getKey());
            });
        }

        /*
         * Coming back out of the bin, which the two hooks above do NOT cover.
         *
         * SoftDeletes::restore() clears deleted_at and calls save(), so
         * `updated` does fire — but the only column it changed is deleted_at,
         * so touchedAnImage() says no and the rows stay deleted. The product
         * would be live on the shop with its image reading as unused.
         *
         * Worth recording how this was found, because the first version of
         * this file got it wrong in the other direction: while the hook was on
         * plain `saved` with no change check, a mutation that emptied this
         * closure stayed GREEN, so it was removed as dead code. Adding the
         * change check made it load-bearing again and the restore test went
         * red. The optimisation and this hook are one decision, not two.
         */
        Product::restored(static function (Product $product): void {
            self::syncOwner('product', $product);
        });

        /*
         * A new media row can be the missing half of an association that has
         * existed for months: the URL was already on the product, and the file
         * only just got catalogued. Recording usage at upload time alone would
         * leave every backfilled row looking unused.
         */
        Media::created(static function (Media $media): void {
            self::syncMedia([$media]);
        });

        Media::deleted(static function (Media $media): void {
            self::forgetMedia((int) $media->getKey());
        });
    }

    /**
     * Is there a table to write to yet?
     *
     * ASKED EVERY TIME, ON PURPOSE. The obvious optimisation is to remember
     * the answer, and it is wrong here — this is the Setting::map() trap
     * CLAUDE.md records, in a place where it takes the whole suite down.
     *
     * Two things make a remembered answer go stale:
     *
     *   - a remembered NO. The hooks are live while migrations run, and
     *     2026_08_27_100000_seed_demo_catalogue creates categories and
     *     products through Eloquent long before 2026_10_10_000000 makes
     *     anywhere to put the answer. So the negative has to be re-asked.
     *   - a remembered YES, which is the one that actually bit. Anything that
     *     runs `migrate:fresh` in a process that has already migrated — on
     *     MySQL, a test doing DDL inside RefreshDatabase's transaction
     *     triggers exactly that, because DDL implicitly commits and the trait
     *     rebuilds the database — drops this table and re-runs the migration
     *     set with the hooks still registered and the memo still saying yes.
     *     The demo seeder's first Eloquent create then tried to DELETE FROM a
     *     table that no longer existed, the migration aborted, and every test
     *     after it failed. That is how this comment came to be written.
     *
     * The cost is one catalogue-cheap existence check on the paths that are
     * about to issue two or three writes anyway, and those paths only run when
     * an image column actually moved. That is a price worth paying to remove a
     * whole class of stale-state failure.
     */
    private static function ready(): bool
    {
        return Schema::hasTable('media_usages');
    }

    /**
     * Did this save move one of the columns that carries an attachment?
     *
     * getChanges() is what the UPDATE actually wrote, so a save that assigns
     * the same URL back counts as no change — which it is. Only meaningful on
     * the `updated` event; see the note at the hooks about inserts.
     *
     * The column list here and the one MediaUsage::collect() reads have to
     * agree. The test "it leaves the table alone when a save touches no image
     * column" fails if they stop agreeing.
     */
    private static function touchedAnImage(string $kind, Model $owner): bool
    {
        $columns = match ($kind) {
            'product' => ['image', 'images'],
            'brand' => ['logo'],
            'category' => ['image'],
            default => [],
        };

        $changed = $owner->getChanges();

        foreach ($columns as $column) {
            if (array_key_exists($column, $changed)) {
                return true;
            }
        }

        return false;
    }

    /* ----------------------------------------------------------- one owner */

    /**
     * Rewrite the rows for one owner to match what it now references.
     *
     * Replace-in-full rather than diff: an edit that removes an image has to
     * remove its row, and "delete this owner's rows, insert what it uses now"
     * is one obviously-correct statement where a diff is three.
     */
    public static function syncOwner(string $kind, Model $owner): void
    {
        if (! isset(self::MODELS[$kind]) || ! self::ready()) {
            return;
        }

        $id = (int) $owner->getKey();

        if ($id <= 0) {
            return;
        }

        $rows = self::rowsFor(MediaUsage::indexForOwner($kind, $owner));

        DB::transaction(static function () use ($kind, $id, $rows): void {
            MediaUsageRecord::query()
                ->where('owner_type', $kind)
                ->where('owner_id', $id)
                ->delete();

            if ($rows !== []) {
                MediaUsageRecord::query()->insert(self::stamp($rows));
            }
        });
    }

    /** Drop every row for one owner. Returns how many went. */
    public static function forgetOwner(string $kind, int $id): int
    {
        if (! self::ready()) {
            return 0;
        }

        return MediaUsageRecord::query()
            ->where('owner_type', $kind)
            ->where('owner_id', $id)
            ->delete();
    }

    /* ----------------------------------------------------------- one media */

    /**
     * Record the owners of media rows that have just appeared.
     *
     * Additive: it inserts what these rows are used by and touches no other
     * media row. The catalogue walk happens once for the whole batch, which is
     * what makes a 2,000-file rescan one pass rather than two thousand.
     *
     * @param  iterable<Media|object>  $rows  anything with id, filename, path
     */
    public static function syncMedia(iterable $rows): int
    {
        if (! self::ready()) {
            return 0;
        }

        $rows = is_array($rows) ? $rows : iterator_to_array($rows);

        if ($rows === []) {
            return 0;
        }

        $index = MediaUsage::index();
        $written = 0;

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            $insert = [];
            $ids = [];

            foreach ($chunk as $media) {
                $id = (int) $media->id;

                if ($id <= 0) {
                    continue;
                }

                $ids[] = $id;

                foreach (self::ownersOf($index, (string) $media->filename, (string) $media->path) as $owner) {
                    $insert[] = [
                        'media_id' => $id,
                        'owner_type' => $owner['type'],
                        'owner_id' => $owner['id'],
                        'field' => $owner['column'],
                    ];
                }
            }

            if ($ids !== []) {
                // Clear first, so a re-run of a rescan replaces rather than
                // collides with the unique index.
                MediaUsageRecord::query()->whereIn('media_id', $ids)->delete();
            }

            $insert = self::dedupe($insert);

            if ($insert !== []) {
                MediaUsageRecord::query()->insert(self::stamp($insert));
                $written += count($insert);
            }
        }

        return $written;
    }

    /** Drop every row for one media file. Returns how many went. */
    public static function forgetMedia(int $mediaId): int
    {
        if (! self::ready()) {
            return 0;
        }

        return MediaUsageRecord::query()->where('media_id', $mediaId)->delete();
    }

    /* ------------------------------------------------------------ the lot */

    /**
     * Rebuild the whole table from the derivation.
     *
     * This is what the backfill migration runs and what
     * `php artisan media:usages-reconcile` runs. Idempotent by construction:
     * it computes the full correct set and writes exactly that, so running it
     * twice leaves the same rows and the unique index never sees a collision.
     *
     * @return array{added: int, removed: int, kept: int}
     */
    public static function rebuild(): array
    {
        $want = self::derive();
        $have = [];

        foreach (MediaUsageRecord::query()->cursor() as $row) {
            $have[self::signature((int) $row->media_id, (string) $row->owner_type, (int) $row->owner_id, (string) $row->field)] = (int) $row->id;
        }

        $addKeys = array_diff_key($want, $have);
        $removeIds = array_values(array_diff_key($have, $want));

        foreach (array_chunk(array_values($addKeys), self::CHUNK) as $chunk) {
            MediaUsageRecord::query()->insert(self::stamp($chunk));
        }

        foreach (array_chunk($removeIds, self::CHUNK) as $chunk) {
            MediaUsageRecord::query()->whereIn('id', $chunk)->delete();
        }

        return [
            'added' => count($addKeys),
            'removed' => count($removeIds),
            'kept' => count($have) - count($removeIds),
        ];
    }

    /**
     * What the table SHOULD hold, without writing anything.
     *
     * The reconcile command's --check mode reports the difference between this
     * and the table. Drift that nobody can see is the thing that makes a
     * recorded answer more dangerous than a derived one, so it is made
     * visible.
     *
     * @return array{missing: list<array<string, mixed>>, extra: list<array<string, mixed>>}
     */
    public static function drift(): array
    {
        $want = self::derive();
        $have = [];

        foreach (MediaUsageRecord::query()->cursor() as $row) {
            $have[self::signature((int) $row->media_id, (string) $row->owner_type, (int) $row->owner_id, (string) $row->field)] = [
                'media_id' => (int) $row->media_id,
                'owner_type' => (string) $row->owner_type,
                'owner_id' => (int) $row->owner_id,
                'field' => (string) $row->field,
            ];
        }

        return [
            'missing' => array_values(array_diff_key($want, $have)),
            'extra' => array_values(array_diff_key($have, $want)),
        ];
    }

    /* ------------------------------------------------------------ private */

    /**
     * The complete correct row set, keyed by signature.
     *
     * One catalogue walk and one pass over `media`. Every row comes out of
     * MediaUsage's own index filtered by MediaUsage::matches(), which is the
     * definition of "agrees with what the screen derives".
     *
     * @return array<string, array<string, mixed>>
     */
    private static function derive(): array
    {
        $index = MediaUsage::index();
        $want = [];

        foreach (Media::query()->select(['id', 'filename', 'path'])->cursor() as $media) {
            $id = (int) $media->id;

            if ($id <= 0) {
                continue;
            }

            foreach (self::ownersOf($index, (string) $media->filename, (string) $media->path) as $owner) {
                $want[self::signature($id, $owner['type'], $owner['id'], $owner['column'])] = [
                    'media_id' => $id,
                    'owner_type' => $owner['type'],
                    'owner_id' => $owner['id'],
                    'field' => $owner['column'],
                ];
            }
        }

        return $want;
    }

    /**
     * The index entries that genuinely name this media row.
     *
     * MediaUsage::matches() is the predicate MediaUsage::verify() uses, called
     * here on the same entries. That is the whole of the agreement guarantee.
     *
     * @param  array<string, list<array<string, mixed>>>  $index
     * @return list<array{type: string, id: int, column: string}>
     */
    private static function ownersOf(array $index, string $filename, string $path): array
    {
        $out = [];

        foreach ($index[MediaUsage::key($filename)] ?? [] as $entry) {
            if (! MediaUsage::matches((string) $entry['raw'], $filename, $path)) {
                continue;
            }

            $out[] = [
                'type' => (string) $entry['type'],
                'id' => (int) $entry['id'],
                'column' => (string) $entry['column'],
            ];
        }

        return $out;
    }

    /**
     * The rows one owner's index slice implies.
     *
     * The mirror of ownersOf(): that one asks "who uses this file", this one
     * asks "which files does this owner use", and both end at matches(). The
     * candidate media rows are narrowed in SQL and then filtered in PHP, so
     * the SQL only has to be generous, never exact.
     *
     * @param  array<string, list<array<string, mixed>>>  $index
     * @return list<array<string, mixed>>
     */
    private static function rowsFor(array $index): array
    {
        if ($index === []) {
            return [];
        }

        $keys = array_keys($index);
        $out = [];

        foreach (self::candidates($keys) as $media) {
            $id = (int) $media->id;

            if ($id <= 0) {
                continue;
            }

            foreach (self::ownersOf($index, (string) $media->filename, (string) $media->path) as $owner) {
                $out[] = [
                    'media_id' => $id,
                    'owner_type' => $owner['type'],
                    'owner_id' => $owner['id'],
                    'field' => $owner['column'],
                ];
            }
        }

        return self::dedupe($out);
    }

    /**
     * Media rows that might carry one of these basenames.
     *
     * Deliberately generous. `filename` is compared two ways because the two
     * engines disagree about `=`: MySQL's default collation is
     * case-insensitive and SQLite's is not, so the plain whereIn catches the
     * stored spelling and the LOWER() branch catches the rest. Whatever comes
     * back is filtered by MediaUsage::matches() afterwards, so a false
     * candidate costs nothing.
     *
     * The one case this can still miss is a filename whose case differs only
     * in non-ASCII characters on SQLite, where LOWER() is ASCII-only while
     * MediaUsage::key() uses mb_strtolower. That direction loses an index row
     * rather than inventing one — the grid under-badges, the delete guard is
     * unaffected because it does not read this table, and
     * `media:usages-reconcile`, which is pure PHP and shares key(), both
     * detects and fixes it.
     *
     * @param  list<string>  $keys  lower-cased basenames
     * @return \Illuminate\Support\Collection<int, Media>
     */
    private static function candidates(array $keys)
    {
        $spellings = [];

        foreach ($keys as $key) {
            $spellings[$key] = true;
            $spellings[mb_strtoupper($key)] = true;
        }

        return Media::query()
            ->select(['id', 'filename', 'path'])
            ->where(static function ($q) use ($keys, $spellings): void {
                $q->whereIn('filename', array_keys($spellings));
                $q->orWhereIn(DB::raw('LOWER(filename)'), $keys);
            })
            ->get();
    }

    /**
     * Drop rows the unique index would reject.
     *
     * One media row can be reachable twice from one owner and field — two
     * differently-shaped URLs in a gallery naming the same file — and the
     * table's grain is the set, not the count. See the migration header.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function dedupe(array $rows): array
    {
        $seen = [];

        foreach ($rows as $row) {
            $seen[self::signature((int) $row['media_id'], (string) $row['owner_type'], (int) $row['owner_id'], (string) $row['field'])] = $row;
        }

        return array_values($seen);
    }

    /** The unique index, as a string. */
    private static function signature(int $mediaId, string $type, int $ownerId, string $field): string
    {
        return $mediaId.'|'.$type.'|'.$ownerId.'|'.$field;
    }

    /**
     * Add timestamps to rows about to be inserted.
     *
     * insert() is a query-builder call, so Eloquent does not fill these in and
     * the columns are NOT NULL. On MySQL under STRICT_TRANS_TABLES — which
     * phpunit-mysql.xml pins deliberately — omitting them is an error rather
     * than a silent zero date.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function stamp(array $rows): array
    {
        $now = now();

        return array_map(
            static fn (array $row): array => $row + ['created_at' => $now, 'updated_at' => $now],
            $rows
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;

/**
 * WHERE AN UPLOADED IMAGE IS USED — derived, because nothing records it.
 *
 * READ THIS BEFORE CHANGING THE MEDIA SCREEN'S FILTERS.
 *
 * There is no foreign key from `media` to anything, and no pivot table. The
 * schema records an attachment as a URL STRING on the owning row, and only
 * that:
 *
 *     products.image       one URL
 *     products.images      a json list of URLs (the gallery)
 *     brands.logo          one URL
 *     categories.image     one URL
 *
 * Nothing else in the application associates a file with a product, a brand or
 * a category. `media.source_attachment_id` exists for the WooCommerce import
 * and is the only id-shaped column on the table — it points at a WordPress
 * attachment id, not at anything in this database, and nothing reads it.
 *
 * So "show me every image attached to a product" cannot be answered by a join.
 * It is answered by taking the four columns above, reducing each URL to the
 * file it names, and matching that against the media row's own filename. That
 * is a REAL match against REAL rows — not a filter that quietly returns
 * nothing, which is the failure mode CLAUDE.md records for the product
 * `status` filter and which this lane was told not to repeat.
 *
 * WHAT IT COSTS. Answering an attachment filter reads three columns off every
 * product, brand and category — about 2,600 rows on this store. One pass,
 * three queries, no joins.
 *
 * The paragraph that used to sit here said this happened "only when the
 * operator actually picks an attachment filter; the default grid does not call
 * it at all". That stopped being true when the grid started badging tiles:
 * MediaLibraryApiController::index() calls index() unconditionally on every
 * page of the library, and show() and destroy() call it once each. The cost is
 * paid on every render of the screen, not only on a filtered one.
 *
 * THE DURABLE FIX, now built (Lane BF). `media_usages` records the association
 * — media_id, owner_type, owner_id, field — and is written from the save paths
 * and rebuilt by a backfill migration. It is an INDEX over what this class
 * derives, not a replacement for it, and the split is deliberate:
 *
 *   - the grid's filter and badges read the table, which is both faster and
 *     EXACT where the filter here is basename-broad; but
 *   - the delete guard still calls verify() below, because a recorded row that
 *     has gone stale would offer to delete an image that is live on the shop,
 *     and that is the one error here that a shopper sees.
 *
 * matches() is the single predicate both halves apply, so the table records
 * what verify() would have said rather than a second opinion of its own. See
 * App\Support\MediaUsageWriter and the media:usages-reconcile command, which
 * re-derives the table from this class and can report any disagreement.
 *
 * MATCHING IS BY FILENAME, and the one place that is imprecise is named here
 * rather than left to be discovered. Two media rows can share a basename —
 * `logo.png` imported from two different WordPress year folders, say — and the
 * grid filter would show both when only one is referenced. Admin uploads cannot
 * collide (MediaUploadController names them `Ymd-His-<8 random>.ext`), and the
 * DETAIL and DELETE paths do not rely on the basename at all: verify() below
 * re-checks the full stored path, so "is this safe to delete" is exact even
 * where the grid filter is broad. Broad is a tolerable error here; empty is
 * not.
 *
 * The grid filter is no longer the broad one either: it now selects media ids
 * out of `media_usages`, which were recorded through matches() and so carry
 * the same full-path check verify() makes. This class keeps the basename
 * bucket because filenames() is still the honest derived answer and the
 * reconcile command compares the two.
 */
final class MediaUsage
{
    /** The owner kinds this can answer for, in the order the screen offers them. */
    public const TYPES = ['product', 'brand', 'category'];

    /**
     * Every reference in the store, keyed by the filename it points at.
     *
     * @param  string|null  $type   one of TYPES, or null for all three
     * @param  string|null  $owner  match only owners whose NAME contains this
     * @return array<string, list<array{type: string, id: int, name: string, field: string, column: string, path: string, raw: string}>>
     */
    public static function index(?string $type = null, ?string $owner = null): array
    {
        $owner = ($owner !== null && trim($owner) !== '') ? trim($owner) : null;
        $out = [];

        if ($type === null || $type === 'product') {
            $q = Product::query()->select(['id', 'name', 'image', 'images']);

            if ($owner !== null) {
                SearchTerms::whereLike($q, 'name', $owner);
            }

            foreach ($q->cursor() as $p) {
                self::collect($out, 'product', $p);
            }
        }

        if ($type === null || $type === 'brand') {
            $q = Brand::query()->select(['id', 'name', 'logo']);

            if ($owner !== null) {
                SearchTerms::whereLike($q, 'name', $owner);
            }

            foreach ($q->cursor() as $b) {
                self::collect($out, 'brand', $b);
            }
        }

        if ($type === null || $type === 'category') {
            $q = Category::query()->select(['id', 'name', 'image']);

            if ($owner !== null) {
                SearchTerms::whereLike($q, 'name', $owner);
            }

            foreach ($q->cursor() as $c) {
                self::collect($out, 'category', $c);
            }
        }

        return $out;
    }

    /**
     * The index entries for ONE owner row, in the shape index() returns.
     *
     * This exists so that recording a usage and deriving one are the same
     * code. A product save cannot afford index()'s walk of the whole
     * catalogue — during an import that would be quadratic — but it must
     * reach the same answer for the row it is saving, so it asks for that
     * row's slice and runs the same matches() over it.
     *
     * @return array<string, list<array{type: string, id: int, name: string, field: string, column: string, path: string, raw: string}>>
     */
    public static function indexForOwner(string $kind, object $row): array
    {
        $out = [];

        self::collect($out, $kind, $row);

        return $out;
    }

    /**
     * Fold one owner row's image columns into $out.
     *
     * The ONE place that knows which columns carry an attachment and what to
     * call them. index() and indexForOwner() share it, so a column added here
     * is seen by the derived answer and by the recorded one at once.
     *
     * @param  array<string, list<array<string, mixed>>>  $out
     */
    private static function collect(array &$out, string $kind, object $row): void
    {
        $id = (int) $row->id;
        $name = (string) ($row->name ?? '');

        $add = static function (string $label, string $column, mixed $raw) use (&$out, $kind, $id, $name): void {
            if (! is_string($raw)) {
                return;
            }

            $key = self::key($raw);

            if ($key === '') {
                return;
            }

            $out[$key][] = [
                'type' => $kind,
                'id' => $id,
                'name' => $name,
                // What the screen prints. Gallery entries are numbered, so
                // this renumbers when a gallery is reordered.
                'field' => $label,
                // The COLUMN the URL was read from, which does not renumber.
                // `media_usages`.field stores this one.
                'column' => $column,
                'path' => self::normalise($raw),
                // The URL exactly as the owning row stores it. verify() matches
                // on this through matches(), and so does the media_usages
                // index, so the recorded answer and the derived one cannot be
                // two different opinions. See matches().
                'raw' => $raw,
            ];
        };

        if ($kind === 'product') {
            $add('Main image', 'image', $row->image);

            $gallery = $row->images;

            foreach ((is_array($gallery) ? $gallery : []) as $i => $url) {
                $add('Gallery image '.((int) $i + 1), 'images', $url);
            }

            return;
        }

        if ($kind === 'brand') {
            $add('Logo', 'logo', $row->logo);

            return;
        }

        if ($kind === 'category') {
            $add('Category image', 'image', $row->image);
        }
    }

    /**
     * The filenames referenced by the owners matching $type / $owner.
     *
     * This is what the grid's attachment filter turns into `whereIn`. The list
     * is bounded by the catalogue — one entry per image on a product, brand or
     * category, so roughly 13,000 at the very worst on a 2,400-product store.
     * SQLite's variable ceiling is 32,766 and MySQL's placeholder ceiling is
     * 65,535, so the worst case sits well inside both; and a catalogue that did
     * outgrow them would make the driver raise, loudly, rather than return a
     * short answer nobody notices.
     *
     * @return list<string>
     */
    public static function filenames(?string $type = null, ?string $owner = null): array
    {
        return array_values(array_keys(self::index($type, $owner)));
    }

    /**
     * The references that genuinely point at THIS media row.
     *
     * Exact, unlike the basename bucket the grid filter uses: a candidate only
     * counts when the owner's stored URL ends with the media row's full stored
     * path, or when the media row has no directory part left to check.
     *
     * @param  array<string, list<array{type: string, id: int, name: string, field: string, path: string}>>  $index
     * @return list<array{type: string, id: int, name: string, field: string}>
     */
    public static function verify(array $index, string $filename, string $path): array
    {
        $candidates = $index[self::key($filename)] ?? [];

        $hits = [];

        foreach ($candidates as $c) {
            // matches(), not an inline re-check: the media_usages index calls
            // the same predicate, so a recorded usage and a derived one are
            // the same answer computed once rather than twice.
            if (! self::matches((string) ($c['raw'] ?? $c['path']), $filename, $path)) {
                continue;
            }

            $hits[] = ['type' => $c['type'], 'id' => $c['id'], 'name' => $c['name'], 'field' => $c['field']];
        }

        return $hits;
    }

    /**
     * Does an owner's stored URL name THIS media row?
     *
     * The one predicate behind both answers this codebase gives about an
     * attachment. verify() applies it to derive usage on the fly, and
     * App\Support\MediaUsageWriter applies it to record the same thing in
     * `media_usages`. Having exactly one implementation is what makes the
     * recorded table and the derived answer provably the same statement —
     * two copies of this logic would drift the first time either was edited.
     *
     * Two parts, and both matter:
     *
     *   - the basename must match, case-folded in PHP for the reason key()
     *     gives; and
     *   - the media row's full stored path must be a suffix of the URL, which
     *     is what tells two rows sharing a basename apart.
     *
     * The suffix half is skipped when the media row's path has no directory
     * part left — there is then nothing to check, and demanding a match would
     * make such a row impossible to find rather than merely broad.
     */
    public static function matches(string $rawUrl, string $filename, string $path): bool
    {
        $key = self::key($rawUrl);

        if ($key === '' || $key !== self::key($filename)) {
            return false;
        }

        $want = '/'.ltrim(self::normalise($path), '/');

        if (! str_contains(trim($want, '/'), '/')) {
            return true;
        }

        return str_ends_with('/'.ltrim(self::normalise($rawUrl), '/'), $want);
    }

    /**
     * The file a stored URL names: no scheme, no host, no query, lowercased.
     *
     * Lowercased in PHP because the match has to behave the same on MySQL,
     * whose default collation is case-insensitive, and on SQLite, whose `=` is
     * not. Doing it here means neither engine gets to decide.
     */
    public static function key(string $raw): string
    {
        $path = self::normalise($raw);

        return $path === '' ? '' : mb_strtolower(basename($path));
    }

    /**
     * A stored URL reduced to its path, with query string, fragment and
     * escaping removed.
     *
     * Public because matches() is public and the two are one idea; nothing
     * outside this class should be re-deriving a path any other way.
     */
    public static function normalise(string $raw): string
    {
        $raw = trim($raw);

        if ($raw === '') {
            return '';
        }

        // Cut the query and fragment before anything else: a cache-buster
        // (?v=3) would otherwise become part of the "filename".
        $raw = preg_replace('/[?#].*$/', '', $raw) ?? $raw;

        // Drop scheme and host when there is one, keeping the path.
        $parsed = parse_url($raw, PHP_URL_PATH);

        if (is_string($parsed) && $parsed !== '') {
            $raw = $parsed;
        }

        return trim(rawurldecode($raw));
    }
}

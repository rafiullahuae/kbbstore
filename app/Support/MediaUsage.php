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
 * WHAT IT COSTS, AND WHY THAT IS ACCEPTABLE. Answering an attachment filter
 * reads three columns off every product, brand and category — about 2,600 rows
 * on this store. One pass, three queries, no joins, and it happens only when
 * the operator actually picks an attachment filter; the default grid does not
 * call it at all.
 *
 * THE DURABLE FIX, deliberately NOT done here. The association should be
 * RECORDED, not derived: a `media_usages` table (media_id, owner_type,
 * owner_id, field) written when the product editor, the brand editor and the
 * category editor save. Those three save paths belong to other lanes, and
 * CLAUDE.md's ownership rule says a lane that needs another lane's file says so
 * rather than editing it. Until that exists this is the honest answer, and it
 * is honest about being derived rather than dressed up as a join.
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
     * @return array<string, list<array{type: string, id: int, name: string, field: string, path: string}>>
     */
    public static function index(?string $type = null, ?string $owner = null): array
    {
        $owner = ($owner !== null && trim($owner) !== '') ? trim($owner) : null;
        $out = [];

        $add = static function (string $kind, int $id, string $name, string $field, mixed $raw) use (&$out): void {
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
                'field' => $field,
                'path' => self::normalise($raw),
            ];
        };

        if ($type === null || $type === 'product') {
            $q = Product::query()->select(['id', 'name', 'image', 'images']);

            if ($owner !== null) {
                SearchTerms::whereLike($q, 'name', $owner);
            }

            foreach ($q->cursor() as $p) {
                $add('product', (int) $p->id, (string) $p->name, 'Main image', $p->image);

                foreach ((is_array($p->images) ? $p->images : []) as $i => $url) {
                    $add('product', (int) $p->id, (string) $p->name, 'Gallery image '.((int) $i + 1), $url);
                }
            }
        }

        if ($type === null || $type === 'brand') {
            $q = Brand::query()->select(['id', 'name', 'logo']);

            if ($owner !== null) {
                SearchTerms::whereLike($q, 'name', $owner);
            }

            foreach ($q->cursor() as $b) {
                $add('brand', (int) $b->id, (string) $b->name, 'Logo', $b->logo);
            }
        }

        if ($type === null || $type === 'category') {
            $q = Category::query()->select(['id', 'name', 'image']);

            if ($owner !== null) {
                SearchTerms::whereLike($q, 'name', $owner);
            }

            foreach ($q->cursor() as $c) {
                $add('category', (int) $c->id, (string) $c->name, 'Category image', $c->image);
            }
        }

        return $out;
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
        $want = '/'.ltrim(self::normalise($path), '/');
        $checkable = str_contains(trim($want, '/'), '/');

        $hits = [];

        foreach ($candidates as $c) {
            if ($checkable && ! str_ends_with('/'.ltrim($c['path'], '/'), $want)) {
                continue;
            }

            $hits[] = ['type' => $c['type'], 'id' => $c['id'], 'name' => $c['name'], 'field' => $c['field']];
        }

        return $hits;
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

    /** A stored URL reduced to its path, with query string, fragment and escaping removed. */
    private static function normalise(string $raw): string
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

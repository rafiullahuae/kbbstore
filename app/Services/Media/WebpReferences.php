<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where an image's address is written down, and re-pointing it. (Lane WP)
 *
 * AN ALLOWLIST, NOT A SCAN OF EVERY TEXT COLUMN. Each entry below was found by
 * reading the schema (every text/json column of every table) and the code
 * that writes it, not guessed. A column is here because it holds an image
 * address an owner or editor put there. Orders, order items, customers,
 * reviews, payments, carts and the audit trail are NOT here and cannot be
 * reached through this class: the order a customer placed and the photo a
 * customer attached are records of what happened, and a records table that
 * changes after the fact is a records table nobody can trust.
 *
 * Those that matter but must not be written are in GUARD_ONLY: read, never
 * written, so "Remove originals" refuses to delete a JPEG a review still shows.
 *
 * MATCHING IS ON THE WEB-ROOT-RELATIVE PATH, not on a URL. The same file is
 * spelled four ways across this database — `uploads/x.jpg` in media.path,
 * `https://shop/uploads/x.jpg` in a product, `/kbb-upgrade/uploads/x.jpg` on
 * staging, `uploads\/x.jpg` inside json_encode()d JSON — and the path is the
 * part all four share. What surrounds it is kept byte for byte.
 *
 * TWO GUARDS ON EVERY MATCH:
 *   - BOUNDARIES. `uploads/a.jpg` must not match inside `myuploads/a.jpg` or
 *     `uploads/a.jpg.webp`, so the character before and after is checked.
 *   - HOST. A full URL on ANOTHER host is not this shop's file, even when the
 *     path is the same — `https://kbeautybliss.com/wp-content/uploads/…/a.jpg`
 *     is the OLD site, which has no a.webp. Only a path, or a URL on this
 *     shop's own host, is rewritten.
 *
 * Rewrites go through the query builder, so no model event fires and no
 * `updated_at` moves: the row's content is the same picture at a new address.
 */
final class WebpReferences
{
    /**
     * table => [primary key, [columns]]
     *
     * @var array<string, array{0: string, 1: list<string>}>
     */
    public const COLUMNS = [
        // The catalogue. image_alts is a JSON map KEYED by image URL, so its
        // keys must move with the images or every custom alt text is lost.
        'products' => ['id', ['image', 'images', 'image_alts', 'seo', 'seo_json', 'description', 'short_description', 'how_to_use', 'ingredients', 'custom_tabs']],
        'product_variants' => ['id', ['image']],
        'attribute_values' => ['id', ['swatch_image']],
        'brands' => ['id', ['logo', 'banner', 'header_image', 'seo', 'description']],
        // header_layout (Lane CB): the category banner's own picture is a key in it.
        'categories' => ['id', ['image', 'banner', 'header_image', 'header_layout', 'seo', 'description']],
        'product_tabs' => ['id', ['body']],
        // Banners, Spotted, the homepage furniture.
        'banner_cards' => ['id', ['image', 'image_m', 'body']],
        'banner_sets' => ['id', ['bg_image']],
        'spotted_posts' => ['id', ['image']],
        'menu_items' => ['id', ['icon']],
        'blocks' => ['id', ['content']],
        // Pages and the Journal.
        'pages' => ['id', ['content', 'doc_json', 'css', 'seo']],
        'posts' => ['id', ['cover', 'body', 'excerpt', 'seo']],
        // Email templates and marketing drafts. mkt_campaigns.blocks_snapshot
        // is what a SENT campaign contained and is deliberately not here.
        'email_templates' => ['id', ['body', 'blocks']],
        'mkt_templates' => ['id', ['blocks']],
        'mkt_campaigns' => ['id', ['blocks']],
        // Site-wide: page banners, logos, og_default_image, module options.
        'settings' => ['key', ['value']],
        'module_settings' => ['id', ['value']],
        'translations' => ['id', ['value']],
    ];

    /**
     * Read for "is this original still in use?", never written.
     *
     * @var array<string, array{0: string, 1: list<string>}>
     */
    public const GUARD_ONLY = [
        'reviews' => ['id', ['images', 'content']],
        'ugc_videos' => ['id', ['poster_path', 'teaser_path', 'file_path']],
        'instagram_posts' => ['id', ['local_path']],
        'mkt_campaigns' => ['id', ['blocks_snapshot']],
    ];

    /** LIKE terms per query, so one query never grows without bound. */
    private const TERMS_PER_QUERY = 40;

    /**
     * Re-point every allowlisted reference.
     *
     * @param  array<string, string>  $map  from relative path => to relative path
     * @param  bool  $write  false for the dry run: count, change nothing
     * @return array{rows: int, replacements: int, columns: array<string, int>, paths: array<string, int>, samples: list<array{table: string, column: string, id: string, from: string, to: string}>}
     */
    public static function apply(array $map, bool $write): array
    {
        $out = ['rows' => 0, 'replacements' => 0, 'columns' => [], 'paths' => [], 'samples' => []];

        if ($map === []) {
            return $out;
        }

        $hosts = self::ownHosts();
        $needles = self::needles(array_keys($map));
        $schema = [];

        foreach (self::COLUMNS as $table => [$pk, $columns]) {
            foreach (self::present($table, $columns, $schema) as $column) {
                foreach (self::rowsMentioning($table, $pk, $column, $needles) as $row) {
                    $before = (string) $row->value;
                    $count = 0;
                    $hits = [];
                    $after = self::replaceIn($before, $map, $hosts, $count, false, $hits);

                    if ($count === 0 || $after === $before) {
                        continue;
                    }

                    $out['rows']++;
                    $out['replacements'] += $count;
                    $out['columns'][$table.'.'.$column] = ($out['columns'][$table.'.'.$column] ?? 0) + $count;

                    foreach ($hits as $path => $n) {
                        $out['paths'][$path] = ($out['paths'][$path] ?? 0) + $n;
                    }

                    if (count($out['samples']) < 20) {
                        $out['samples'][] = ['table' => $table, 'column' => $column, 'id' => (string) $row->pk,
                            'from' => self::excerpt($before, $map), 'to' => self::excerpt($after, array_flip($map))];
                    }

                    if ($write) {
                        // Matched on the old value as well, so a row somebody
                        // saved in the meantime is left alone rather than
                        // overwritten with what it said a moment ago.
                        DB::table($table)->where($pk, $row->pk)
                            ->whereRaw(self::equalsSql($column), [$before])
                            ->update([$column => $after]);
                    }
                }
            }
        }

        if ($write && isset($out['columns']['settings.value'])) {
            Setting::flushMap();
        }

        // These writes bypass Eloquent, so products.updated_at does not move and
        // the Google Shopping feed's cache stamp cannot see them (Lane SEO).
        if ($write && $out['replacements'] > 0) {
            \App\Services\Seo\MerchantFeed::forget();
        }

        return $out;
    }

    /**
     * Is this relative path still written anywhere — the allowlist AND the
     * read-only tables? Used before an original is deleted.
     */
    public static function referenced(string $relative): bool
    {
        $hosts = self::ownHosts();
        $needles = self::needles([$relative]);
        $schema = [];
        $probe = [$relative => $relative.'#'];

        foreach ([self::COLUMNS, self::GUARD_ONLY] as $set) {
            foreach ($set as $table => [$pk, $columns]) {
                foreach (self::present($table, $columns, $schema) as $column) {
                    foreach (self::rowsMentioning($table, $pk, $column, $needles) as $row) {
                        $count = 0;
                        self::replaceIn((string) $row->value, $probe, $hosts, $count);

                        if ($count > 0) {
                            return true;
                        }
                    }
                }
            }
        }

        // The media library itself.
        return DB::table('media')->where('path', $relative)->exists();
    }

    /**
     * WHO still writes this path down: the same walk as referenced() — the
     * allowlist and the read-only tables, the same boundary and host rules —
     * but it names the rows instead of stopping at the first, and it does not
     * count the Media Library's own row (a catalogued file is not a used one).
     *
     * Catalog → Products → edit asks this before a replaced picture leaves the
     * server (Lane RPL), and prints the answer as "kept: still used by …".
     * Read-only; at most $limit hits.
     *
     * @return list<array{table: string, column: string, id: string}>
     */
    public static function usedBy(string $relative, int $limit = 8): array
    {
        $hosts = self::ownHosts();
        $needles = self::needles([$relative]);
        $schema = [];
        $probe = [$relative => $relative.'#'];
        $out = [];

        foreach ([self::COLUMNS, self::GUARD_ONLY] as $set) {
            foreach ($set as $table => [$pk, $columns]) {
                foreach (self::present($table, $columns, $schema) as $column) {
                    foreach (self::rowsMentioning($table, $pk, $column, $needles) as $row) {
                        $count = 0;
                        self::replaceIn((string) $row->value, $probe, $hosts, $count);

                        if ($count > 0) {
                            $out[] = ['table' => $table, 'column' => $column, 'id' => (string) $row->pk];

                            if (count($out) >= $limit) {
                                return $out;
                            }
                        }
                    }
                }
            }
        }

        return $out;
    }

    /**
     * Replace every reference to a mapped path inside one string.
     *
     * Pure, so the boundary and host rules can be tested without a database.
     *
     * @param  array<string, string>  $map
     * @param  list<string>  $ownHosts  lower-case hosts that are this shop
     */
    public static function replaceIn(string $text, array $map, array $ownHosts, int &$count = 0, bool $anyHost = false, ?array &$hits = null): string
    {
        $count = 0;
        $hits ??= [];

        if ($text === '' || $map === []) {
            return $text;
        }

        $spellings = [];
        $origin = [];

        foreach ($map as $from => $to) {
            foreach (self::spell((string) $from, (string) $to) as $a => $b) {
                $spellings[$a] = $b;
                $origin[$a] = (string) $from;
            }
        }

        // Longest first, so `a-1.jpg` is never shadowed by a shorter overlap.
        uksort($spellings, static fn ($a, $b) => strlen($b) <=> strlen($a));

        $alternation = implode('|', array_map(static fn ($s) => preg_quote($s, '~'), array_keys($spellings)));
        $pattern = '~(?<![A-Za-z0-9_.%\-])('.$alternation.')(?![A-Za-z0-9_\-]|\.[A-Za-z0-9])~';

        $result = preg_replace_callback($pattern, static function (array $m) use ($text, $spellings, $origin, $ownHosts, $anyHost, &$count, &$hits): string {
            [$found, $offset] = $m[1];

            if (! $anyHost && ! self::isOwnReference($text, $offset, $ownHosts)) {
                return $found;
            }

            $count++;
            $hits[$origin[$found]] = ($hits[$origin[$found]] ?? 0) + 1;

            return $spellings[$found];
        }, $text, -1, $unused, PREG_OFFSET_CAPTURE);

        return is_string($result) ? $result : $text;
    }

    /**
     * The spellings a path is written in, each mapped to the same spelling of
     * its replacement: plain, JSON-escaped slashes, and percent-encoded.
     *
     * @return array<string, string>
     */
    private static function spell(string $from, string $to): array
    {
        $encode = static fn (string $p): string => implode('/', array_map('rawurlencode', explode('/', $p)));

        $out = [$from => $to, str_replace('/', '\/', $from) => str_replace('/', '\/', $to)];

        if ($encode($from) !== $from) {
            $out[$encode($from)] = $encode($to);
            $out[str_replace('/', '\/', $encode($from))] = str_replace('/', '\/', $encode($to));
        }

        return $out;
    }

    /**
     * Is the reference at $offset a bare path, or a URL on this shop's host?
     */
    private static function isOwnReference(string $text, int $offset, array $ownHosts): bool
    {
        // Walk back to the start of the token the path sits in.
        $start = $offset;

        while ($start > 0 && ! in_array($text[$start - 1], ['"', "'", ' ', "\n", "\r", "\t", '(', ')', '<', '>', ',', '=', '[', ']', '{', '}'], true)) {
            $start--;
        }

        $token = str_replace('\/', '/', substr($text, $start, $offset - $start));

        if (preg_match('~^(?:[a-z][a-z0-9+.\-]*:)?//([^/?#]+)~i', $token, $m) !== 1) {
            // No host in front of it: a path on this shop.
            return ! preg_match('~^[a-z][a-z0-9+.\-]*:~i', $token);
        }

        $host = strtolower((string) preg_replace('~^.*@|:\d+$~', '', $m[1]));

        return in_array($host, $ownHosts, true);
    }

    /** @return list<string> */
    public static function ownHosts(): array
    {
        $out = [];

        try {
            $site = Setting::map()['site_url'] ?? null;
        } catch (\Throwable) {
            $site = null;
        }

        foreach ([$site, config('app.url')] as $candidate) {
            $host = parse_url((string) $candidate, PHP_URL_HOST);

            if (is_string($host) && $host !== '') {
                $host = strtolower($host);
                $out[] = $host;
                $out[] = str_starts_with($host, 'www.') ? substr($host, 4) : 'www.'.$host;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * The LIKE needles: each path's basename, which every spelling contains.
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    private static function needles(array $paths): array
    {
        $out = [];

        foreach ($paths as $path) {
            $base = basename($path);
            $out[$base] = true;
            $encoded = rawurlencode($base);
            $out[$encoded] = true;
        }

        return array_keys($out);
    }

    /**
     * Rows of one column that mention any needle — one query per forty
     * needles, never one per file.
     *
     * @return iterable<object{pk: mixed, value: mixed}>
     */
    private static function rowsMentioning(string $table, string $pk, string $column, array $needles): iterable
    {
        $seen = [];

        foreach (array_chunk($needles, self::TERMS_PER_QUERY) as $chunk) {
            $query = DB::table($table)->select([$pk.' as pk', $column.' as value'])->whereNotNull($column);
            $query->where(function ($q) use ($column, $chunk): void {
                foreach ($chunk as $needle) {
                    $q->orWhereRaw(self::likeSql($column), ['%'.self::escapeLike($needle).'%']);
                }
            });

            foreach ($query->get() as $row) {
                $key = (string) $row->pk;

                if (! isset($seen[$key])) {
                    $seen[$key] = true;

                    yield $row;
                }
            }
        }
    }

    private static function likeSql(string $column): string
    {
        $wrapped = DB::getQueryGrammar()->wrap($column);

        // A MySQL JSON column compares as binary; cast so LIKE reads its text.
        return DB::getDriverName() === 'mysql'
            ? "CAST({$wrapped} AS CHAR) LIKE ? ESCAPE '!'"
            : "{$wrapped} LIKE ? ESCAPE '!'";
    }

    private static function equalsSql(string $column): string
    {
        $wrapped = DB::getQueryGrammar()->wrap($column);

        return DB::getDriverName() === 'mysql' ? "CAST({$wrapped} AS CHAR) = ?" : "{$wrapped} = ?";
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    /**
     * The columns of $columns this table really has. Memoised in $listing for
     * one call only — no static, so a long-lived process never answers with a
     * schema it read before a migration ran.
     *
     * @return list<string>
     */
    private static function present(string $table, array $columns, array &$listing): array
    {
        if (! isset($listing[$table])) {
            try {
                $listing[$table] = Schema::hasTable($table) ? Schema::getColumnListing($table) : [];
            } catch (\Throwable) {
                $listing[$table] = [];
            }
        }

        return array_values(array_intersect($columns, $listing[$table]));
    }

    private static function excerpt(string $text, array $map): string
    {
        foreach (array_keys($map) as $needle) {
            $at = strpos($text, basename((string) $needle));

            if ($at !== false) {
                $from = max(0, $at - 60);

                return ($from > 0 ? '…' : '').mb_strcut($text, $from, 160, 'UTF-8');
            }
        }

        return mb_strcut($text, 0, 160, 'UTF-8');
    }
}

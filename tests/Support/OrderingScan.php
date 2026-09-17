<?php

declare(strict_types=1);

namespace Tests\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Finds query chains that page or truncate a list whose ORDER BY does not
 * settle it.
 *
 * WHAT THE DEFECT IS. `LIMIT`, `OFFSET`, `paginate()` and `forPage()` all
 * assume the rows arrive in a decided order. Where the sort key ties, nothing
 * decides, and SQL does not promise the same answer twice: the database may
 * return a tied block in whatever order its chosen plan produced it. Two
 * queries meant to partition one list — page 1 and page 2, rail 1 and rail 2 —
 * are free to break the same tie two different ways, so a row lands in both or
 * in neither. The fix in every case is to end the ORDER BY on a key that
 * cannot tie, which on every table here is `id`.
 *
 * On this catalogue the ties are the normal case, not the edge: `total_sales`
 * is a counter sharing a few values across the tail, `rating` and
 * `review_count` are 0 or NULL for most products, `position` is 0 until
 * somebody reorders something, and `created_at` is identical for everything a
 * single import wrote.
 *
 * WHY THIS READS TOKENS RATHER THAN TEXT. A regex over PHP source reads
 * comments and string literals as if they were code, and this repository is
 * heavily commented — including by this very file, which names
 * `->orderByDesc('total_sales')->limit(4)` in prose several times above and
 * would otherwise report itself. `token_get_all()` is the parser PHP already
 * ships; T_COMMENT and T_DOC_COMMENT are dropped before anything is matched,
 * so only real calls are ever considered.
 *
 * WHAT IT CANNOT SEE, stated plainly so nobody mistakes a pass for a proof.
 * It only understands ONE method chain. Where the ordering is applied in one
 * statement and the paging in another — CollectionController's `match` arms
 * and its `paginate()` call, ShopController's `applySort()` and its
 * `forPage()`, ReviewWall's `sort()` — the two never appear in the same chain
 * and this scan says nothing at all about them. Those surfaces are covered
 * instead by the behavioural half of StableOrderingTest, which runs them
 * against a deliberately tied catalogue under both of the query plans the
 * database may choose and requires the same answer from each.
 */
final class OrderingScan
{
    /** Calls that take a slice of a list and therefore need the order settled. */
    private const SLICING = ['limit', 'offset', 'paginate', 'forPage', 'simplePaginate'];

    /** Calls that contribute to the ORDER BY. */
    private const ORDERING = ['orderBy', 'orderByDesc', 'orderByRaw', 'latest', 'oldest', 'inRandomOrder'];

    /**
     * Columns that cannot tie, so a chain ending on one is already total.
     *
     * `id` on every table. `code` is unique on `coupons`, `slug` on products,
     * categories and brands, and `term` and `source` are the GROUP BY key of
     * the aggregate they order — one row each, by construction.
     */
    private const TOTAL = ['/(^|\.)id$/', '/(^|\.)code$/', '/(^|\.)slug$/', '/(^|\.)term$/', '/(^|\.)source$/'];

    /**
     * @return list<array{file: string, line: int, why: string}>
     */
    public static function findings(string $root): array
    {
        $out = [];

        foreach (self::files($root) as $path) {
            foreach (self::chains(file_get_contents($path)) as $chain) {
                $why = self::verdict($chain);

                if ($why !== null) {
                    $out[] = ['file' => $path, 'line' => $why[0], 'why' => $why[1]];
                }
            }
        }

        usort($out, fn ($a, $b) => [$a['file'], $a['line']] <=> [$b['file'], $b['line']]);

        return $out;
    }

    /** @return list<string> */
    private static function files(string $root): array
    {
        $out = [];

        /** @var \SplFileInfo $f */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $out[] = $f->getPathname();
            }
        }

        sort($out);

        return $out;
    }

    /**
     * Every `->a(...)->b(...)` run in the file, as [name, first literal arg, line].
     *
     * Bracket depth is tracked so that a chain nested inside another call's
     * arguments — `Cache::remember($k, $ttl, fn () => Brand::query()->...)` is
     * the shape all over this app — is its own chain, and so that a `,`
     * between two entries of an array literal ends the chain before it rather
     * than merging two queries into one.
     *
     * @return list<list<array{0: string, 1: ?string, 2: int}>>
     */
    private static function chains(string $source): array
    {
        $tokens = [];

        foreach (token_get_all($source) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) {
                continue;
            }

            $tokens[] = $t;
        }

        $chains = [];
        $chain = [];
        $chainDepth = null;
        $depth = 0;

        $flush = function () use (&$chains, &$chain, &$chainDepth) {
            if ($chain !== []) {
                $chains[] = $chain;
            }

            $chain = [];
            $chainDepth = null;
        };

        for ($i = 0, $n = count($tokens); $i < $n; $i++) {
            $t = $tokens[$i];

            if (is_array($t) && $t[0] === T_OBJECT_OPERATOR) {
                $name = $tokens[$i + 1] ?? null;

                if (is_array($name) && $name[0] === T_STRING && ($tokens[$i + 2] ?? null) === '(') {
                    if ($chainDepth !== null && $chainDepth !== $depth) {
                        $flush();
                    }

                    $chainDepth = $depth;
                    $arg = $tokens[$i + 3] ?? null;

                    $chain[] = [
                        $name[1],
                        is_array($arg) && $arg[0] === T_CONSTANT_ENCAPSED_STRING ? trim($arg[1], "'\"") : null,
                        $name[2],
                    ];

                    continue;
                }
            }

            if (in_array($t, ['(', '[', '{'], true)) {
                $depth++;
                continue;
            }

            if (in_array($t, [')', ']', '}'], true)) {
                $depth--;

                if ($chainDepth !== null && $depth < $chainDepth) {
                    $flush();
                }

                continue;
            }

            if (($t === ';' || $t === ',') && ($chainDepth === null || $depth <= $chainDepth)) {
                $flush();
            }
        }

        $flush();

        return $chains;
    }

    /**
     * Null when the chain is fine, otherwise [line, reason].
     *
     * @param  list<array{0: string, 1: ?string, 2: int}>  $chain
     * @return array{0: int, 1: string}|null
     */
    private static function verdict(array $chain): ?array
    {
        $names = array_column($chain, 0);

        if (! array_intersect($names, self::SLICING) || ! array_intersect($names, self::ORDERING)) {
            return null;
        }

        $cut = null;

        foreach ($chain as $i => $call) {
            if (in_array($call[0], self::SLICING, true)) {
                $cut = $i;
                break;
            }
        }

        $last = null;

        foreach ($chain as $i => $call) {
            if ($i >= $cut) {
                break;
            }

            if (in_array($call[0], self::ORDERING, true)) {
                $last = $call;
            }
        }

        // Ordering applied elsewhere, or after the slice: out of scope here.
        if ($last === null) {
            return null;
        }

        // An explicit request for an arbitrary order is not this defect.
        if ($last[0] === 'inRandomOrder') {
            return null;
        }

        if ($last[0] === 'orderByRaw') {
            return [$last[2], 'a sliced query whose last ORDER BY key is orderByRaw(), which cannot be checked '
                . 'and is not a unique column: end the chain on id'];
        }

        if ($last[1] === null) {
            return [$last[2], $last[0] . '() with a computed column ends a sliced query: end the chain on id'];
        }

        foreach (self::TOTAL as $pattern) {
            if (preg_match($pattern, $last[1]) === 1) {
                return null;
            }
        }

        return [$last[2], "a sliced query whose last ORDER BY key is " . $last[0] . "('" . $last[1]
            . "'), which can tie: append a key that cannot, normally id"];
    }
}

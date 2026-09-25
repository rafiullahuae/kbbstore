<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\VariantPricing;
use Illuminate\Support\Facades\DB;

/**
 * THE HOLE THE MEMO HAS ALWAYS HAD, AND THE DAY SOMETHING FALLS INTO IT.
 *
 * ── THE DEFECT THIS IS ABOUT ────────────────────────────────────────────────
 *
 * App\Services\VariantPricing answers every price question about a variable
 * product from ONE grouped snapshot of `products` joined to `product_variants`,
 * taken the first time anything asks and then trusted. It is dropped by
 * Product::booted() and ProductVariant::booted(), which hang off Eloquent's
 * `saved` and `deleted` events.
 *
 * Eloquent events fire for `$model->save()` and `$model->delete()` and for
 * nothing else. A mass update — `Product::query()->whereIn(...)->update([...])`
 * — a mass delete, a `$product->variants()->delete()` and any `DB::table()`
 * write all reach the same rows and fire NOTHING. The snapshot survives them.
 *
 * What that looks like on the shop is not an exception and not a log line: it
 * is App\Models\Product::effectivePrice() answering **0 fils** for a product
 * that exists and is priced, because range() answered null for a row the
 * snapshot was taken before. That is the AED 0 the whole class was written to
 * remove, re-entering through its own memo. VariantPriceMemoFreshnessTest
 * pins the model-event half; this file is the half nothing can pin from inside
 * the class, because the defect is in code that has not been written yet.
 *
 * ── WHY A SCANNER AND NOT A CASE ────────────────────────────────────────────
 *
 * The previous round recorded "nothing in this application writes either table
 * that way today" and left a comment. That sentence was true when it was
 * written and was NOT true a round later: Admin\CatalogProductsApiController
 * ::bulkPrice() — the bulk price editor — now writes `products.price` and
 * `products.sale_price` for up to BULK_MAX rows through the query builder, one
 * statement per distinct resulting value. A comment cannot notice that. This
 * can, and it names the file and the line so whoever wrote it knows what to do.
 *
 * ── WHAT IT ASKS OF A WRITE SITE ────────────────────────────────────────────
 *
 * Only what the write costs. A write that CANNOT move the snapshot is left
 * alone: load() reads `products.type`, `products.price`, the parent's sale
 * window, `product_variants.price`, `product_variants.sale_price` and which
 * rows exist. A mass update of `position` or `brand_id` is invisible to it, so
 * demanding an invalidate() there would be noise that teaches people to silence
 * this file. Anything that writes a column load() reads — or that inserts or
 * removes rows in either table, or that sets a column this scanner cannot name
 * because the key is a variable — has to call VariantPricing::invalidate(), or
 * be listed in ALLOWED below with the reason it cannot matter.
 *
 * ── MUTATION NOTES, ALL RUN ─────────────────────────────────────────────────
 *
 * 1. Delete the `VariantPricing::invalidate()` line from
 *    Admin\CatalogProductsApiController::bulkPrice(). RUN: 1 failed — 'every
 *    query-builder write to the pricing tables drops the memo', naming
 *    app/Http/Controllers/Admin/CatalogProductsApiController.php:969 and the
 *    columns it writes.
 * 2. Add `Product::query()->whereIn('id', [1])->update(['sale_price' => 1]);`
 *    to a controller with no invalidate() beside it. RUN: 1 failed, naming that
 *    file and line. Removed again afterwards.
 * 3. Change the scanner's column list to drop `sale_price`. RUN: 0 failed
 *    today, and that is recorded rather than hidden: bulkPrice() writes its
 *    column through a VARIABLE (`$target`), so it is caught by the
 *    dynamic-key rule and not by the column list. The column list is what
 *    catches the NEXT write, which will name its column literally — mutation 2
 *    is the one that proves the list asserts anything, and it is why both rules
 *    are here.
 * 4. Empty the ALLOWED list. RUN: 1 failed, naming the two dataset/seed sites
 *    below — so the allowlist is load-bearing and not decoration.
 * 5. In the behavioural case at the foot of this file, delete the
 *    `VariantPricing::invalidate()` call. RUN: 1 failed, with the memo quoting
 *    a range for a variation that had been deleted out from under it.
 */

/**
 * The tables whose writes this file is about.
 *
 * `products` because load() filters on `type` and `price` and reads the sale
 * window off it; `product_variants` because the aggregate is a GROUP BY over
 * it.
 */
const MEMO_TABLES = ['products', 'product_variants'];

/**
 * The columns load() actually reads. A write that touches one of these moves
 * the snapshot; a write that does not, cannot.
 *
 * `deleted_at` is NOT here on purpose: load() applies no soft-delete filter, so
 * trashing a product leaves its range exactly where it was — which is correct,
 * because a trashed product is not rendered. Row INSERTS and hard DELETES are
 * caught by the verb rather than by a column, below.
 */
const MEMO_COLUMNS = [
    'type', 'price', 'sale_price', 'sale_starts_at', 'sale_ends_at', 'product_id',
];

/**
 * Write sites that reach a pricing table and deliberately do not invalidate.
 *
 * Each entry is [path, needle, reason]. The needle keeps an entry pinned to the
 * ONE statement it was written about: a second write appearing in the same file
 * is a new decision and reddens this file until somebody makes it.
 */
const ALLOWED = [
    [
        'app/Console/Commands/PageCostDataset.php',
        "DB::table('products')",
        'Builds the page-cost fixture: it deletes and re-inserts one simple '
        .'product by slug and creates no variation at all, so it cannot change '
        .'a row the snapshot holds (load() reads only variable parents with a '
        .'NULL price that have variations). It prices nothing in its own '
        .'process either — kbb:page-cost forks a child per measured page.',
    ],
    [
        'database/migrations/2026_08_27_100000_seed_demo_catalogue.php',
        'forceDelete()',
        'A migration. `php artisan migrate` resolves no storefront pricing, so '
        .'there is no snapshot in that process to go stale; and the rows it '
        .'removes are gone from the next process\'s snapshot by definition.',
    ],
];

/**
 * $source with every comment blanked out, line numbers intact.
 *
 * WITHOUT THIS THE SCANNER READS PROSE. Three files in this repository discuss
 * these very writes in their docblocks — App\Services\VariantPricing states
 * the boundary, App\Support\MediaUsageWriter explains what a bulk delete does
 * not fire — and a regex cannot tell an example from a statement. Blanking
 * comments token by token and keeping the newlines means a line number still
 * points where a developer will look.
 */
function memoGuardCode(string $source): string
{
    $out = '';

    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            $out .= str_repeat("\n", substr_count($token[1], "\n"));

            continue;
        }

        $out .= is_array($token) ? $token[1] : $token;
    }

    return $out;
}

/** Every .php file under the directories a write could hide in. */
function memoGuardFiles(): array
{
    $out = [];

    foreach (['app', 'database/seeders', 'database/migrations'] as $dir) {
        $path = base_path($dir);

        if (! is_dir($path)) {
            continue;
        }

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $out[] = $file->getPathname();
            }
        }
    }

    sort($out);

    return $out;
}

/**
 * The write statements in $source that reach a pricing table, as
 * [line, snippet, why].
 *
 * Deliberately crude and deliberately NOISY-SIDE: it starts from an anchor that
 * names a pricing table or its model, takes the chained expression up to the
 * statement's semicolon, and reports it when that chain carries a write verb.
 * A false positive costs somebody one line in ALLOWED with a reason; a false
 * negative costs a silent AED 0 on the shop.
 */
function memoGuardWrites(string $source): array
{
    $anchors = [
        "DB::table('products')", 'DB::table("products")',
        "DB::table('product_variants')", 'DB::table("product_variants")',
        'Product::', 'ProductVariant::', '->variants()',
    ];

    // insert/delete/upsert/truncate change WHICH ROWS EXIST, which no column
    // list can describe, so they always count.
    $rowVerbs = ['insert', 'insertGetId', 'insertOrIgnore', 'upsert', 'delete', 'forceDelete', 'truncate'];
    $columnVerbs = ['update', 'increment', 'decrement'];

    $found = [];

    foreach ($anchors as $anchor) {
        $offset = 0;

        while (($pos = strpos($source, $anchor, $offset)) !== false) {
            $offset = $pos + 1;

            $end = strpos($source, ';', $pos);
            $statement = substr($source, $pos, $end === false ? 600 : $end - $pos);
            $line = substr_count(substr($source, 0, $pos), "\n") + 1;

            foreach ($rowVerbs as $verb) {
                if (str_contains($statement, '->' . $verb . '(')) {
                    $found[$line] = [$line, $statement, 'it adds or removes rows'];

                    continue 2;
                }
            }

            foreach ($columnVerbs as $verb) {
                if (! str_contains($statement, '->' . $verb . '(')) {
                    continue;
                }

                // Which columns? A literal key can be read; `$target => ...`
                // cannot, and an unreadable key is treated as the worst case.
                preg_match_all("/'([a-z_]+)'\s*=>/i", $statement, $keys);
                $dynamic = (bool) preg_match('/\[\s*\$[a-z_]+\s*=>/i', $statement)
                    || preg_match_all('/=>/', $statement) > count($keys[1]);

                if ($dynamic) {
                    $found[$line] = [$line, $statement, 'the column it writes is a variable'];

                    continue 2;
                }

                $touched = array_intersect($keys[1], MEMO_COLUMNS);

                if ($touched !== []) {
                    $found[$line] = [$line, $statement, 'it writes ' . implode(', ', $touched)];

                    continue 2;
                }
            }
        }
    }

    ksort($found);

    return array_values($found);
}

it('names every table this guard is about, so the scanner cannot quietly narrow', function () {
    // A scanner is only as good as what it looks at. If either table were
    // dropped from MEMO_TABLES the file above would still pass and assert
    // nothing, so the two names are stated once and checked against the
    // statement App\Services\VariantPricing actually runs.
    $sql = '';

    DB::listen(function ($event) use (&$sql): void {
        if (str_contains($event->sql, 'v.product_id as pid')) {
            $sql = $event->sql;
        }
    });

    $parent = Product::create([
        'slug' => 'memo-guard-tables', 'name' => 'Cushion', 'status' => 'publish',
        'is_visible' => true, 'price' => null, 'stock_status' => 'instock', 'type' => 'variable',
    ]);
    ProductVariant::create(['product_id' => $parent->id, 'price' => 1000, 'stock_status' => 'instock']);

    app(VariantPricing::class)->range($parent->fresh());

    expect($sql)->not->toBe('', 'the grouped read never ran, so this case measured nothing');

    foreach (MEMO_TABLES as $table) {
        expect($sql)->toContain($table);
    }
});

it('drops the memo on every query-builder write to the pricing tables', function () {
    /*
     * THE DEFECT, AS A FUTURE WRITER WILL MEET IT. A mass update or a raw write
     * to `products`/`product_variants` fires no Eloquent event, so
     * VariantPricing keeps a snapshot taken before it — and a snapshot that has
     * not seen a row answers null, which effectivePrice() casts to 0 fils.
     *
     * The failure below names the file, the line and the reason, because the
     * person who reads it will be somebody who has just written the line and
     * does not know this class exists.
     */
    $offenders = [];

    foreach (memoGuardFiles() as $path) {
        $relative = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $path);
        $source = memoGuardCode((string) file_get_contents($path));

        foreach (memoGuardWrites($source) as [$line, $statement, $why]) {
            $allowed = false;

            foreach (ALLOWED as [$allowedPath, $needle, $reason]) {
                if ($relative === $allowedPath && str_contains($statement, $needle)) {
                    $allowed = true;

                    break;
                }
            }

            if ($allowed) {
                continue;
            }

            // The whole enclosing file is enough of a neighbourhood: a write
            // and its invalidation belong in the same method, and asking for
            // more precision than that would make this fail on a refactor
            // rather than on a defect.
            if (str_contains($source, 'VariantPricing::invalidate()')) {
                continue;
            }

            $offenders[] = $relative . ':' . $line . '  (' . $why . ')  '
                . trim(preg_replace('/\s+/', ' ', substr($statement, 0, 110)) ?? '');
        }
    }

    expect($offenders)->toBe([], implode("\n", array_merge(
        ['These writes reach `products` or `product_variants` without going through Eloquent,'],
        ['so App\Services\VariantPricing keeps a snapshot taken before them and a variable'],
        ['product can answer 0 fils. Call \App\Services\VariantPricing::invalidate() after the'],
        ['write, or add it to ALLOWED in this file with the reason it cannot matter:'],
        [''],
        $offenders,
    )));
});

it('goes stale exactly as described when a raw write is not announced', function () {
    /*
     * THE MECHANISM, DEMONSTRATED RATHER THAN ASSERTED, so the scanner above is
     * guarding something real. A relation delete is Builder::delete(): it
     * removes the rows and fires no model event.
     *
     * This is the case VariantPriceMemoFreshnessTest records as the boundary of
     * its own fix ("this case was written with the relation delete first and
     * stayed green against a stale answer"). Here it is asserted from the other
     * side — first that the memo really does go stale, then that one call puts
     * it right — which is what makes the scanner's demand a fix and not a
     * ritual.
     */
    $parent = Product::create([
        'slug' => 'memo-guard-stale', 'name' => 'Cushion', 'status' => 'publish',
        'is_visible' => true, 'price' => null, 'stock_status' => 'instock', 'type' => 'variable',
    ]);
    ProductVariant::create(['product_id' => $parent->id, 'price' => 4000, 'stock_status' => 'instock']);
    ProductVariant::create(['product_id' => $parent->id, 'price' => 12000, 'stock_status' => 'instock']);

    $pricing = app(VariantPricing::class);

    expect($pricing->range($parent->fresh()))->toBe([4000, 12000]);

    // No `saved`, no `deleted`, nothing.
    $parent->variants()->delete();

    expect($pricing->range($parent->fresh()))
        ->toBe([4000, 12000], 'the memo noticed a query-builder delete, so this case proves nothing');

    // And the one line every such write site owes.
    VariantPricing::invalidate();

    expect($pricing->range($parent->fresh()))->toBeNull();
    expect($parent->fresh()->effectivePrice())->toBe(0);
});

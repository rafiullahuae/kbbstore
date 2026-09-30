<?php

declare(strict_types=1);

namespace App\Services\Maintenance;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * "Delete un-wanted data and patches etc from the site." — the owner, before
 * he moves his real WooCommerce shop into this one.                 (Lane IE)
 *
 * ── WHY THIS IS NOT A SECOND PURGE ──────────────────────────────────────────
 *
 * Safety → Demo Content already deletes everything IT created, and it does it
 * the only correct way: `DemoContentController` writes the primary key of every
 * row a generator makes into `demo_seed_log`, and removal deletes exactly those
 * ids. It never asks "what looks like demo data". That screen is not replaced
 * and not duplicated — `demoSeedLog()` below only REPORTS its rows so the owner
 * sees one list, and the delete for them stays where it already works.
 *
 * What this class adds is the four things that screen cannot reach, because
 * nothing wrote them to `demo_seed_log`:
 *
 *   1. THE SEEDED DEMO CATALOGUE. `2026_08_27_100000_seed_demo_catalogue` runs
 *      `DemoCatalogueSeeder` on EVERY install, production included, long before
 *      that log table existed. 24 invented products on a fresh database.
 *   2. THE SEEDED DEMO REVIEWS. `2026_10_11_000002_seed_demo_reviews`, stamped
 *      `reviews.source = 'demo'` by DemoReviewsSeeder::SOURCE.
 *   3. APPLIED UPDATE PACKAGES. `UpdateRunner::archivePackage()` keeps a copy of
 *      every zip ever applied under `kbb-patch-archive/` and NOTHING prunes it,
 *      unlike the file backups which `BackupService::prune(5)` does bound.
 *   4. THE LOG FILES under `storage/logs`.
 *
 * ── THE RULE EVERY BUCKET OBEYS ─────────────────────────────────────────────
 *
 * A row is only ever deleted when it carries a marker that a row from
 * WooCommerce CANNOT carry, and the marker is checked in the DELETE itself —
 * not looked up first and trusted. `products.wc_id IS NULL` is that marker: the
 * importer upserts every product on `wc_id`, so a product that came from his
 * shop always has one and a product this repo invented never does. `sku LIKE
 * 'DEMO-%'` is required ON TOP of it, because "wc_id is null" alone would also
 * match a product he typed into this admin panel by hand.
 *
 * AND THEN A THIRD GUARD THAT IS NOT ABOUT MARKERS AT ALL: a product with an
 * `order_items` row against it has been SOLD, whatever its sku says, and
 * deleting it would tear a line item off a real order. Nothing that has been
 * sold is deleted here, and the preview says how many were held back and why.
 *
 * ── AND IT SHOWS BEFORE IT DELETES ──────────────────────────────────────────
 *
 * `preview()` writes nothing. `purge()` refuses unless the caller echoes back
 * the exact counts preview() returned, so a delete fired against a shop that has
 * changed since the owner looked at it does nothing at all and says so. The
 * counts ARE the confirmation: a separate opaque token would say the same thing
 * in a form nobody reading the request could check.
 */
final class PreMigrationCleanup
{
    /** Applied-package archives kept even when the bucket is purged. */
    public const KEEP_ARCHIVES = 5;

    /**
     * REVIEWS BEFORE PRODUCTS, AND THE ORDER IS THE REPORT'S HONESTY.
     *
     * `reviews.product_id` is a cascading foreign key, so deleting the demo
     * products takes their demo reviews with them. With products first the
     * review delete then matched nothing and reported `demo_reviews: 0` — two
     * rows gone and the screen saying none had been. The rows were the same
     * either way; the NUMBER the owner reads was wrong, on the one screen whose
     * whole job is to say exactly what it removed. Found by the count assertion
     * in tests/Feature/IePreMigrationCleanupTest.php going red, not by reading.
     *
     * purge() walks this array, not the caller's list, which is what makes the
     * order a property of the class rather than of the request.
     *
     * @var list<string>
     */
    public const BUCKETS = ['demo_reviews', 'demo_products', 'patch_archives', 'logs'];

    /**
     * What is here, what would go, and what is being held back.
     *
     * Writes nothing.
     *
     * @return array<string, mixed>
     */
    public function preview(): array
    {
        $buckets = [
            'demo_products' => $this->demoProducts(),
            'demo_reviews' => $this->demoReviews(),
            'patch_archives' => $this->patchArchives(),
            'logs' => $this->logs(),
        ];

        return [
            'buckets' => $buckets,
            'counts' => $this->countsOf($buckets),
            'demo_seed_log' => $this->demoSeedLog(),
        ];
    }

    /**
     * Delete the named buckets, and only if the shop still looks the way the
     * preview the caller is holding says it does.
     *
     * @param  list<string>  $keys
     * @param  array<string, int>  $expect  the `counts` map from preview()
     * @return array<string, mixed>
     */
    public function purge(array $keys, array $expect): array
    {
        $keys = array_values(array_intersect(self::BUCKETS, $keys));

        if ($keys === []) {
            return ['ok' => false, 'message' => 'Nothing was selected to delete.'];
        }

        $now = $this->countsOf([
            'demo_products' => $this->demoProducts(),
            'demo_reviews' => $this->demoReviews(),
            'patch_archives' => $this->patchArchives(),
            'logs' => $this->logs(),
        ]);

        /*
         * THE STALE-PREVIEW REFUSAL, and it is the guard that makes this
         * impossible to fire by accident. The caller has to echo back the exact
         * figures it was shown; a page left open while an import ran, a
         * bookmarked POST, or a replayed request all arrive with numbers that
         * no longer match and delete nothing.
         */
        foreach ($keys as $key) {
            if (($expect[$key] ?? null) !== $now[$key]) {
                return [
                    'ok' => false,
                    'stale' => true,
                    'message' => 'The shop has changed since that list was drawn — '
                        ."\"{$key}\" showed ".var_export($expect[$key] ?? null, true)
                        ." and now holds {$now[$key]}. Nothing was deleted. Look again.",
                    'counts' => $now,
                ];
            }
        }

        $removed = [];

        foreach ($keys as $key) {
            $removed[$key] = match ($key) {
                'demo_products' => $this->deleteDemoProducts(),
                'demo_reviews' => $this->deleteDemoReviews(),
                'patch_archives' => $this->deletePatchArchives(),
                'logs' => $this->deleteLogs(),
            };
        }

        return ['ok' => true, 'removed' => $removed];
    }

    /* ------------------------------------------------------------ the buckets */

    /**
     * THE QUERY, ONCE, so the preview and the delete cannot drift apart.
     *
     * Every caller below builds from this, which is why the preview's count is
     * a promise about the delete rather than a second opinion on it.
     */
    private function demoProductQuery(): \Illuminate\Database\Query\Builder
    {
        return DB::table('products')
            ->whereNull('wc_id')
            ->where('sku', 'like', 'DEMO-%')
            // Sold is sold. A line item is a real order's record of what was
            // bought; deleting the product would leave it pointing at nothing.
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('order_items')
                    ->whereColumn('order_items.product_id', 'products.id');
            });
    }

    /** @return array<string, mixed> */
    private function demoProducts(): array
    {
        if (! Schema::hasTable('products')) {
            return ['count' => 0, 'bytes' => 0, 'samples' => [], 'held_back' => 0, 'protected' => []];
        }

        $ids = $this->demoProductQuery()->orderBy('id')->pluck('id')->all();

        $heldBack = DB::table('products')
            ->whereNull('wc_id')
            ->where('sku', 'like', 'DEMO-%')
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('order_items')
                    ->whereColumn('order_items.product_id', 'products.id');
            })
            ->count();

        return [
            'count' => count($ids),
            'bytes' => 0,
            'label' => 'Demo products (invented placeholders, never in WooCommerce)',
            'samples' => DB::table('products')->whereIn('id', array_slice($ids, 0, 8))
                ->get(['id', 'sku', 'name'])->map(fn ($p) => "#{$p->id} {$p->sku} — {$p->name}")->all(),
            'held_back' => $heldBack,
            'protected' => [
                'products carrying a WooCommerce id (wc_id)' => DB::table('products')->whereNotNull('wc_id')->count(),
                'products with no DEMO- sku (typed in by hand)' => DB::table('products')
                    ->whereNull('wc_id')->where(function ($q) {
                        $q->whereNull('sku')->orWhere('sku', 'not like', 'DEMO-%');
                    })->count(),
                'demo products that have been SOLD, so kept' => $heldBack,
            ],
        ];
    }

    private function deleteDemoProducts(): int
    {
        // Re-evaluated inside the delete rather than replayed from the ids the
        // preview collected: a product sold between the two must not go.
        return DB::transaction(fn () => $this->demoProductQuery()->delete());
    }

    /** @return array<string, mixed> */
    private function demoReviews(): array
    {
        if (! Schema::hasTable('reviews') || ! Schema::hasColumn('reviews', 'source')) {
            return ['count' => 0, 'bytes' => 0, 'samples' => [], 'held_back' => 0, 'protected' => []];
        }

        return [
            'count' => $this->demoReviewQuery()->count(),
            'bytes' => 0,
            'label' => 'Demo reviews (seeded, stamped source = "demo")',
            'samples' => $this->demoReviewQuery()->orderBy('id')->limit(5)
                ->get(['id', 'author_name', 'rating'])
                ->map(fn ($r) => "#{$r->id} {$r->author_name} — {$r->rating}★")->all(),
            'held_back' => 0,
            'protected' => [
                'reviews from any other source (imported, or written on this shop)' => DB::table('reviews')
                    ->where('source', '!=', 'demo')->count(),
                'reviews carrying a WordPress comment id (source_id)' => DB::table('reviews')
                    ->whereNotNull('source_id')->count(),
            ],
        ];
    }

    private function demoReviewQuery(): \Illuminate\Database\Query\Builder
    {
        return DB::table('reviews')
            ->where('source', 'demo')
            // An imported review carries the WordPress comment id it came from.
            // Nothing stamped 'demo' should have one; if anything ever does, it
            // came from his shop and it stays.
            ->whereNull('source_id');
    }

    private function deleteDemoReviews(): int
    {
        return DB::transaction(fn () => $this->demoReviewQuery()->delete());
    }

    /** @return array<string, mixed> */
    private function patchArchives(): array
    {
        $files = $this->archiveFiles();
        $goes = array_slice($files, 0, max(0, count($files) - self::KEEP_ARCHIVES));

        return [
            'count' => count($goes),
            'bytes' => array_sum(array_column($goes, 'bytes')),
            'label' => 'Applied update packages, oldest first — the newest '
                .self::KEEP_ARCHIVES.' are always kept',
            'samples' => array_map(
                fn ($f) => basename($f['path']).' ('.$this->human($f['bytes']).')',
                array_slice($goes, 0, 8)
            ),
            'held_back' => min(count($files), self::KEEP_ARCHIVES),
            'protected' => [
                'newest packages kept' => min(count($files), self::KEEP_ARCHIVES),
                'total packages on disk' => count($files),
            ],
        ];
    }

    /** @return list<array{path: string, bytes: int}> Oldest first. */
    private function archiveFiles(): array
    {
        $disk = Storage::disk('local');

        if (! $disk->exists('kbb-patch-archive')) {
            return [];
        }

        $out = [];

        foreach ($disk->files('kbb-patch-archive') as $path) {
            if (! str_ends_with(strtolower($path), '.zip')) {
                continue;   // only ever a package zip, never anything else in there
            }

            $out[] = ['path' => $path, 'bytes' => $disk->size($path), 'at' => $disk->lastModified($path)];
        }

        usort($out, fn ($a, $b) => $a['at'] <=> $b['at']);

        return $out;
    }

    private function deletePatchArchives(): int
    {
        $files = $this->archiveFiles();
        $goes = array_slice($files, 0, max(0, count($files) - self::KEEP_ARCHIVES));
        $disk = Storage::disk('local');
        $n = 0;

        foreach ($goes as $f) {
            if ($disk->delete($f['path'])) {
                $n++;
            }
        }

        return $n;
    }

    /** @return array<string, mixed> */
    private function logs(): array
    {
        $files = $this->logFiles();

        return [
            'count' => count($files),
            'bytes' => array_sum(array_column($files, 'bytes')),
            'label' => 'Log files under storage/logs',
            'samples' => array_map(
                fn ($f) => basename($f['path']).' ('.$this->human($f['bytes']).')',
                array_slice($files, 0, 8)
            ),
            'held_back' => 0,
            'protected' => [],
        ];
    }

    /** @return list<array{path: string, bytes: int}> */
    private function logFiles(): array
    {
        $dir = storage_path('logs');

        if (! is_dir($dir)) {
            return [];
        }

        $out = [];

        foreach ((array) glob($dir.'/*.log') as $path) {
            if (! is_string($path) || ! is_file($path)) {
                continue;
            }

            $bytes = (int) filesize($path);

            /*
             * AN ALREADY-EMPTY LOG IS NOT AN ITEM TO DELETE.
             *
             * It was counted, and deleteLogs() then skipped it because there
             * was nothing to reclaim -- so the screen offered "2 items" and
             * reported "1 removed", on the one page whose entire job is to say
             * exactly what it removed. Counting only what will actually be
             * emptied makes the number a promise rather than an estimate.
             */
            if ($bytes === 0) {
                continue;
            }

            $out[] = ['path' => $path, 'bytes' => $bytes];
        }

        sort($out);

        return $out;
    }

    /**
     * Emptied rather than unlinked.
     *
     * Laravel's daily/single handler holds the file open; deleting it under a
     * running PHP-FPM leaves the process writing to an unlinked inode and the
     * shop stops logging until the pool recycles. Truncating reclaims the same
     * bytes and the next line still lands in a file somebody can read.
     */
    private function deleteLogs(): int
    {
        $n = 0;

        foreach ($this->logFiles() as $f) {
            if ($f['bytes'] > 0 && @file_put_contents($f['path'], '') !== false) {
                $n++;
            }
        }

        return $n;
    }

    /* ------------------------------------------------------------- reporting */

    /**
     * The Demo Content screen's own rows. REPORTED, NEVER DELETED HERE.
     *
     * @return array<string, mixed>
     */
    private function demoSeedLog(): array
    {
        if (! Schema::hasTable('demo_seed_log')) {
            return ['count' => 0, 'where' => 'Safety → Demo Content'];
        }

        return [
            'count' => DB::table('demo_seed_log')->count(),
            'where' => 'Safety → Demo Content',
            'note' => 'Deleted from that screen, which holds the id of every row it made. '
                .'This page only counts them so the whole picture is in one place.',
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $buckets
     * @return array<string, int>
     */
    private function countsOf(array $buckets): array
    {
        $out = [];

        foreach ($buckets as $key => $b) {
            $out[$key] = (int) $b['count'];
        }

        return $out;
    }

    private function human(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1048576) {
            return round($bytes / 1024, 1).' KB';
        }

        return round($bytes / 1048576, 1).' MB';
    }
}

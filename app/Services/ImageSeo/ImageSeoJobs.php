<?php

declare(strict_types=1);

namespace App\Services\ImageSeo;

use App\Models\Product;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Start, step, stop, resume and undo — the run behind the Rename and ALT tabs.
 * (Lane IR)
 *
 * NO QUEUE WORKER, NO TIMER. A job is a row with a cursor. The screen posts
 * "step" while the owner watches, each step does at most MAX_PRODUCTS products
 * or MAX_SECONDS of work and returns what it did, and the screen posts the
 * next one when that answer arrives. Closing the tab stops it; opening it
 * again shows a Resume button. Nothing runs that nobody asked for.
 *
 * WHY START TWICE CANNOT RENAME TWICE. Three separate reasons, any one enough:
 *   - the job's `token` is unique and comes from the screen's preview, so a
 *     second Start with it returns the first job;
 *   - every step holds one lock across the whole shop, and advances the
 *     cursor in the same breath as each product;
 *   - the planner re-plans each product just before it is renamed, and a
 *     picture already carrying its name plans as "already named".
 */
final class ImageSeoJobs
{
    public const MAX_PRODUCTS = 10;

    public const MAX_SECONDS = 8.0;

    public const LOCK = 'kbb.image-seo.step';

    private const LOG_MAX = 3000;

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $options
     */
    public static function start(string $kind, string $token, array $items, array $options, ?object $admin, ?int $undoOf = null): object
    {
        $existing = DB::table('image_seo_jobs')->where('token', $token)->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            $id = DB::table('image_seo_jobs')->insertGetId([
                'token' => $token,
                'kind' => $kind,
                'status' => 'running',
                'undo_of' => $undoOf,
                'items' => json_encode(array_values($items), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'options' => json_encode($options, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'position' => 0,
                'total' => count($items),
                'log' => '[]',
                'admin_id' => $admin->id ?? null,
                'admin_name' => isset($admin->name) ? mb_substr((string) $admin->name, 0, 120) : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // Two Starts in the same instant: the unique token let one in.
            $existing = DB::table('image_seo_jobs')->where('token', $token)->first();

            if ($existing === null) {
                throw $e;
            }

            return $existing;
        }

        return DB::table('image_seo_jobs')->where('id', $id)->first();
    }

    /**
     * Do the next bounded slice of a job.
     *
     * @return array{job: array, results: list<array>, busy?: bool}
     */
    public static function step(int $id, ?object $admin): array
    {
        $lock = Cache::lock(self::LOCK, 120);

        if (! $lock->get()) {
            $job = DB::table('image_seo_jobs')->where('id', $id)->first();

            return ['job' => $job ? self::view($job) : [], 'results' => [], 'busy' => true];
        }

        $changed = false;

        try {
            $job = DB::table('image_seo_jobs')->where('id', $id)->first();

            if ($job === null) {
                return ['job' => [], 'results' => []];
            }

            if ($job->status === 'done') {
                return ['job' => self::view($job), 'results' => []];
            }

            if ($job->status === 'stopped') {
                DB::table('image_seo_jobs')->where('id', $id)->update(['status' => 'running', 'updated_at' => now()]);
            }

            $items = json_decode((string) $job->items, true) ?: [];
            $options = json_decode((string) $job->options, true) ?: [];
            $started = microtime(true);
            $position = (int) $job->position;
            $results = [];
            $processed = 0;

            while ($position < count($items)) {
                $item = $items[$position];
                $context = ['job_id' => $id, 'admin_id' => $admin->id ?? null, 'admin_name' => $admin->name ?? null];

                $done = match ((string) $job->kind) {
                    'alt' => self::altOne($item),
                    'undo' => self::undoOne($item, $context + ['role' => 'undo']),
                    default => self::renameOne($item, $options, $context),
                };

                $processed++;
                $changed = $changed || array_filter($done, static fn ($r) => in_array($r['status'], ['renamed', 'repointed', 'alt', 'restored'], true)) !== [];
                $results = array_merge($results, $done);
                $position++;

                $counts = self::count($done);
                DB::table('image_seo_jobs')->where('id', $id)->update([
                    'position' => $position,
                    'renamed' => DB::raw('renamed + '.$counts['renamed']),
                    'skipped' => DB::raw('skipped + '.$counts['skipped']),
                    'failed' => DB::raw('failed + '.$counts['failed']),
                    'status' => $position >= count($items) ? 'done' : 'running',
                    'updated_at' => now(),
                ]);

                // Per product, so an alt undo always has its "before".
                self::appendLog($id, $done);

                if ($processed >= self::MAX_PRODUCTS || (microtime(true) - $started) >= self::MAX_SECONDS) {
                    break;
                }
            }

            $job = DB::table('image_seo_jobs')->where('id', $id)->first();

            return ['job' => self::view($job), 'results' => $results];
        } finally {
            $lock->release();

            if ($changed) {
                ImageSeo::flushCaches();
            }
        }
    }

    public static function stop(int $id): ?object
    {
        DB::table('image_seo_jobs')->where('id', $id)->where('status', 'running')->update(['status' => 'stopped', 'updated_at' => now()]);

        return DB::table('image_seo_jobs')->where('id', $id)->first();
    }

    /**
     * An undo job for a finished rename or alt job: its own unique token, so
     * pressing Undo twice is one undo.
     */
    public static function undo(int $id, ?object $admin): ?object
    {
        $job = DB::table('image_seo_jobs')->where('id', $id)->first();

        if ($job === null || ! in_array($job->kind, ['rename', 'alt'], true)) {
            return null;
        }

        if ($job->kind === 'alt') {
            $items = [];

            foreach (json_decode((string) $job->log, true) ?: [] as $entry) {
                if (($entry['status'] ?? '') === 'alt' && isset($entry['p'], $entry['before'], $entry['after'])) {
                    $items[] = ['p' => (int) $entry['p'], 'restore' => $entry['before'], 'expect' => $entry['after']];
                }
            }

            return self::start('alt', 'undo-'.$id, $items, ['undo' => true], $admin, $id);
        }

        $rows = DB::table('image_renames')->where('job_id', $id)->where('status', 'done')
            ->whereIn('role', ['main', 'gallery', 'variant'])->orderBy('id')->get(['id', 'product_id']);

        $items = [];

        foreach ($rows->groupBy('product_id') as $productId => $group) {
            $items[] = ['p' => (int) $productId, 'rows' => $group->pluck('id')->map(fn ($v) => (int) $v)->all()];
        }

        return self::start('undo', 'undo-'.$id, $items, [], $admin, $id);
    }

    /** @return array<string, mixed> */
    public static function view(object $job): array
    {
        $log = json_decode((string) $job->log, true) ?: [];

        return [
            'id' => (int) $job->id,
            'kind' => (string) $job->kind,
            'status' => (string) $job->status,
            'undo_of' => $job->undo_of === null ? null : (int) $job->undo_of,
            'position' => (int) $job->position,
            'total' => (int) $job->total,
            'renamed' => (int) $job->renamed,
            'skipped' => (int) $job->skipped,
            'failed' => (int) $job->failed,
            'by' => $job->admin_name,
            'created_at' => (string) $job->created_at,
            'updated_at' => (string) $job->updated_at,
            'log' => array_slice(array_map(static fn ($e) => array_diff_key($e, ['before' => 1, 'after' => 1]), $log), -200),
            'undone' => DB::table('image_seo_jobs')->where('undo_of', $job->id)->exists(),
        ];
    }

    /** @return list<array> recent jobs, newest first */
    public static function recent(int $limit = 12): array
    {
        if (! ImageFiles::hasTable('image_seo_jobs')) {
            return [];
        }

        return DB::table('image_seo_jobs')->orderByDesc('id')->limit($limit)
            ->get(['id', 'kind', 'status', 'undo_of', 'position', 'total', 'renamed', 'skipped', 'failed', 'admin_name', 'created_at', 'updated_at'])
            ->map(static fn ($j) => [
                'id' => (int) $j->id, 'kind' => (string) $j->kind, 'status' => (string) $j->status,
                'undo_of' => $j->undo_of === null ? null : (int) $j->undo_of, 'position' => (int) $j->position, 'total' => (int) $j->total,
                'renamed' => (int) $j->renamed, 'skipped' => (int) $j->skipped, 'failed' => (int) $j->failed,
                'by' => $j->admin_name, 'created_at' => (string) $j->created_at,
            ])->all();
    }

    /* ------------------------------------------------------------------ */

    /** @return list<array> */
    private static function renameOne(array $item, array $options, array $context): array
    {
        $product = Product::query()->with(['brand:id,name', 'category:id,name'])->find((int) ($item['p'] ?? 0));

        if ($product === null) {
            return [['p' => (int) ($item['p'] ?? 0), 'name' => '', 'rel' => '', 'to' => null, 'status' => 'skipped', 'reason' => 'the product no longer exists', 'refs' => 0, 'files' => 0]];
        }

        $only = isset($item['only']) && is_array($item['only']) ? [(int) $product->id => array_values(array_map('strval', $item['only']))] : [];
        $planner = new ImageSeoPlanner(
            in_array($options['strategy'] ?? '', ImageNamer::STRATEGIES, true) ? $options['strategy'] : ImageNamer::STRATEGY_VARIATIONS,
            (bool) ($options['include_shared'] ?? false),
        );
        $plan = $planner->plan([$product], $only)[0];
        $out = [];

        foreach ($plan['images'] as $image) {
            if (! in_array($image['action'], ['rename', 'repoint'], true) && $image['reason'] !== 'not selected') {
                $out[] = ['rel' => (string) ($image['rel'] ?? $image['url']), 'to' => $image['proposed_rel'],
                    'status' => $image['action'] === 'ok' ? 'ok' : 'skipped', 'reason' => $image['reason'], 'refs' => 0, 'files' => 0];
            }
        }

        $out = array_merge($out, (new ImageRenamer())->renameProduct($plan, $context));
        ImageSeo::rescore([(int) $product->id]);

        return array_map(static fn ($r) => ['p' => (int) $product->id, 'name' => (string) $product->name] + $r, $out);
    }

    /** @return list<array> */
    private static function undoOne(array $item, array $context): array
    {
        $rows = DB::table('image_renames')->whereIn('id', (array) ($item['rows'] ?? []))->where('status', 'done')->orderByDesc('id')->get();
        $productId = (int) ($item['p'] ?? 0);
        $images = [];
        $out = [];

        foreach ($rows as $row) {
            $current = (string) $row->new_path;
            $back = (string) $row->old_path;

            if (! ImageFiles::exists($current)) {
                $out[] = ['rel' => $current, 'to' => $back, 'status' => 'skipped', 'reason' => 'the renamed file is no longer there', 'refs' => 0, 'files' => 0];

                continue;
            }

            if (file_exists(public_path($back))) {
                $out[] = ['rel' => $current, 'to' => $back, 'status' => 'skipped', 'reason' => 'a file already uses the old name again', 'refs' => 0, 'files' => 0];

                continue;
            }

            $images[] = ['rel' => $current, 'proposed_rel' => $back, 'action' => 'rename', 'role' => (string) $row->role,
                'media_id' => $row->media_id, 'trusted' => true];
        }

        if ($images !== []) {
            $done = (new ImageRenamer())->renameProduct(['id' => $productId, 'images' => $images], $context);

            foreach ($done as $r) {
                $out[] = ['status' => $r['status'] === 'renamed' ? 'restored' : $r['status']] + $r;
            }

            // A picture given its old name back no longer carries the tick.
            if (ImageSeoPlanner::hasScore()) {
                DB::table('media')->whereIn('path', array_column($images, 'proposed_rel'))->update(['seo_renamed_at' => null]);
            }

            ImageSeo::rescore([$productId]);
        }

        $name = (string) DB::table('products')->where('id', $productId)->value('name');

        return array_map(static fn ($r) => ['p' => $productId, 'name' => $name] + $r, $out);
    }

    /**
     * Alt text for one product: the given map applied to pictures the product
     * still has, through Eloquent so every listener (the score, the usage
     * index) sees it. An undo item writes `restore` only where the alt is still
     * what this module wrote.
     *
     * @return list<array>
     */
    private static function altOne(array $item): array
    {
        $product = Product::query()->find((int) ($item['p'] ?? 0));

        if ($product === null) {
            return [['p' => (int) ($item['p'] ?? 0), 'name' => '', 'rel' => '', 'to' => null, 'status' => 'skipped', 'reason' => 'the product no longer exists', 'refs' => 0, 'files' => 0]];
        }

        $before = is_array($product->image_alts) ? $product->image_alts : [];
        $urls = array_values(array_filter(array_merge([$product->image], is_array($product->images) ? $product->images : []), 'is_string'));
        $after = $before;

        if (isset($item['restore'])) {
            foreach ((array) ($item['expect'] ?? []) as $url => $alt) {
                if (($after[$url] ?? null) === $alt) {
                    if (array_key_exists($url, (array) $item['restore'])) {
                        $after[$url] = $item['restore'][$url];
                    } else {
                        unset($after[$url]);
                    }
                }
            }
        } else {
            foreach ((array) ($item['alts'] ?? []) as $url => $alt) {
                $alt = AltText::clean((string) $alt);

                if (in_array($url, $urls, true) && AltText::acceptable($alt)) {
                    $after[(string) $url] = $alt;
                }
            }
        }

        if ($after === $before) {
            return [['p' => (int) $product->id, 'name' => (string) $product->name, 'rel' => '', 'to' => null, 'status' => 'skipped', 'reason' => 'nothing to change', 'refs' => 0, 'files' => 0]];
        }

        $product->image_alts = $after;
        $product->save();

        return [['p' => (int) $product->id, 'name' => (string) $product->name, 'rel' => '', 'to' => null, 'status' => 'alt',
            'reason' => count(array_diff_assoc($after, $before)).' alt text(s) '.(isset($item['restore']) ? 'restored' : 'written'), 'refs' => 0, 'files' => 0,
            'before' => array_intersect_key($before, array_diff_assoc($after, $before)), 'after' => array_diff_assoc($after, $before)]];
    }

    /** @return array{renamed: int, skipped: int, failed: int} */
    private static function count(array $results): array
    {
        $c = ['renamed' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($results as $r) {
            $c[match ($r['status']) {
                'renamed', 'repointed', 'alt', 'restored' => 'renamed',
                'rolled_back' => 'failed',
                default => 'skipped',
            }]++;
        }

        return $c;
    }

    private static function appendLog(int $id, array $results): void
    {
        if ($results === []) {
            return;
        }

        $current = json_decode((string) DB::table('image_seo_jobs')->where('id', $id)->value('log'), true) ?: [];
        $log = array_slice(array_merge($current, $results), -self::LOG_MAX);

        DB::table('image_seo_jobs')->where('id', $id)->update(['log' => json_encode($log, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
    }
}

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
 *     second Start with it returns the first job (and since Lane IS2 the
 *     preview IS the job row, in status "preview", flipped to running once);
 *   - every step holds one lock across the whole shop, and advances the
 *     cursor in the same breath as each product;
 *   - the planner re-plans each product just before it is renamed, and a
 *     picture already carrying its name plans as "already named".
 *
 * PREVIEW FIRST, ENFORCED HERE (Lane IS2). prepare() freezes the selection the
 * server resolved into a row in status "preview"; previewChunk() plans it 50
 * products a request and adds up what it found; begin() is the only way that
 * row becomes a run, and refuses one whose preview has not reached the end.
 * step() refuses a preview outright. So what the owner was shown is exactly
 * the list that runs, and nothing renames without a preview behind it.
 *
 * ONE SMALL ANSWER EVERY ~1.5 SECONDS. A step stops after MAX_PRODUCTS or
 * MAX_SECONDS, whichever first, and answers the counts (view(.., false): no
 * log) and only what that step did. The screen's progress bar is those
 * answers; there is no separate poll while this tab drives the run.
 *
 * KINDS: rename, alt, combo (rename the files, then write ALT text for the
 * same pictures, one product at a time), undo.
 */
final class ImageSeoJobs
{
    public const MAX_PRODUCTS = 50;

    public const MAX_SECONDS = 1.5;

    /** Products one preview request plans. */
    public const PREVIEW_CHUNK = 50;

    /** Products a preview lists in full on the screen; the counts cover all. */
    public const PREVIEW_SHOWN = 50;

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
    public static function step(int $id, ?object $admin, bool $resume = false): array
    {
        $lock = Cache::lock(self::LOCK, 120);

        if (! $lock->get()) {
            $job = DB::table('image_seo_jobs')->where('id', $id)->first();

            return ['job' => $job ? self::view($job, false) : [], 'results' => [], 'busy' => true];
        }

        $changed = false;

        try {
            $job = DB::table('image_seo_jobs')->where('id', $id)->first();

            if ($job === null) {
                return ['job' => [], 'results' => []];
            }

            if ($job->status === 'done') {
                return ['job' => self::view($job, false), 'results' => []];
            }

            // A preview is not a run: only begin() makes it one.
            if ($job->status === 'preview') {
                return ['job' => self::view($job, false), 'results' => [], 'refused' => 'This run has not been started. Preview it, then press Start.'];
            }

            // Stopped stays stopped until somebody presses Resume: a second tab
            // still stepping must not undo the Stop pressed in the first.
            if ($job->status === 'stopped') {
                if (! $resume) {
                    return ['job' => self::view($job, false), 'results' => []];
                }

                DB::table('image_seo_jobs')->where('id', $id)->update(['status' => 'running', 'updated_at' => now()]);
            }

            $items = json_decode((string) $job->items, true) ?: [];
            $options = json_decode((string) $job->options, true) ?: [];
            $started = microtime(true);
            $progress = self::hasProgressColumns();
            $position = (int) $job->position;
            $results = [];
            $processed = 0;

            while ($position < count($items)) {
                $item = $items[$position];
                $context = ['job_id' => $id, 'admin_id' => $admin->id ?? null, 'admin_name' => $admin->name ?? null];

                $done = match ((string) $job->kind) {
                    'alt' => self::altOne($item, $id),
                    'combo' => self::comboOne($item, $options, $context),
                    'undo' => self::undoOne($item, $context + ['role' => 'undo']),
                    default => self::renameOne($item, $options, $context),
                };

                $processed++;
                $changed = $changed || array_filter($done, static fn ($r) => in_array($r['status'], ['renamed', 'repointed', 'alt', 'restored'], true)) !== [];
                $results = array_merge($results, $done);
                $position++;

                $counts = self::count($done);
                $update = [
                    'position' => $position,
                    'renamed' => DB::raw('renamed + '.$counts['renamed']),
                    'skipped' => DB::raw('skipped + '.$counts['skipped']),
                    'failed' => DB::raw('failed + '.$counts['failed']),
                    'status' => $position >= count($items) ? 'done' : 'running',
                    'updated_at' => now(),
                ];

                if ($progress && $counts['alt_written'] > 0) {
                    $update['alt_written'] = DB::raw('alt_written + '.$counts['alt_written']);
                }

                // The cursor moves with each product: a step cut short by a
                // timeout resumes on the next product, never repeats one.
                DB::table('image_seo_jobs')->where('id', $id)->update($update);

                if ($processed >= self::MAX_PRODUCTS || (microtime(true) - $started) >= self::MAX_SECONDS) {
                    break;
                }
            }

            // Once per step, not per product: the log is display only (Undo
            // reads image_renames and image_alt_changes), and rewriting a
            // 3,000-entry column fifty times a step was the slowest thing in it.
            self::appendLog($id, $results);

            if ($progress) {
                DB::table('image_seo_jobs')->where('id', $id)->update(['elapsed_ms' => DB::raw('elapsed_ms + '.(int) round((microtime(true) - $started) * 1000))]);
            }

            $job = DB::table('image_seo_jobs')->where('id', $id)->first();

            // Every failure, and the last 20 other lines: the answer stays a few KB.
            $failures = array_filter($results, static fn ($r) => $r['status'] === 'rolled_back');
            $rest = array_slice(array_filter($results, static fn ($r) => $r['status'] !== 'rolled_back'), -20);

            return ['job' => self::view($job, false), 'results' => self::slim(array_merge(array_values($failures), array_values($rest)))];
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

    /* ------------------------------------------------- preview, then run */

    /**
     * The preview row: the selection the server resolved, frozen, in status
     * "preview". Nothing runs from it until begin(). Same token, same row.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $options
     */
    public static function prepare(string $kind, string $token, array $items, array $options, ?object $admin): object
    {
        $existing = DB::table('image_seo_jobs')->where('token', $token)->first();

        if ($existing !== null) {
            return $existing;
        }

        // Previews nobody started: kept a day, so the table does not grow.
        DB::table('image_seo_jobs')->where('status', 'preview')->where('created_at', '<', now()->subDay())->delete();

        try {
            $id = DB::table('image_seo_jobs')->insertGetId([
                'token' => $token,
                'kind' => $kind,
                'status' => 'preview',
                'items' => json_encode(array_values($items), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'options' => json_encode($options + ['preview' => self::emptyPreview()], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'position' => 0,
                'total' => count($items),
                'log' => '[]',
                'admin_id' => $admin->id ?? null,
                'admin_name' => isset($admin->name) ? mb_substr((string) $admin->name, 0, 120) : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            $existing = DB::table('image_seo_jobs')->where('token', $token)->first();

            if ($existing === null) {
                throw $e;
            }

            return $existing;
        }

        return DB::table('image_seo_jobs')->where('id', $id)->first();
    }

    /**
     * Plan the next PREVIEW_CHUNK products of a preview and add up what they
     * would do. Writes nothing to the shop. `$offset` must be where the last
     * chunk stopped (or 0 to start over), so a repeated request cannot count a
     * product twice.
     *
     * @return array{at: int, total: int, done: bool, counts: array<string, mixed>, items: list<array>}
     */
    public static function previewChunk(object $job, int $offset): array
    {
        $items = json_decode((string) $job->items, true) ?: [];
        $options = json_decode((string) $job->options, true) ?: [];
        $counts = $offset === 0 ? self::emptyPreview() : (($options['preview'] ?? null) ?: self::emptyPreview());
        $total = count($items);
        $shown = [];

        if ($offset !== (int) $counts['at']) {
            return ['at' => (int) $counts['at'], 'total' => $total, 'done' => (int) $counts['at'] >= $total, 'counts' => self::publicCounts($counts), 'items' => []];
        }

        $slice = array_slice($items, $offset, self::PREVIEW_CHUNK);
        $ids = array_map(static fn ($i) => (int) $i['p'], $slice);
        $only = [];

        foreach ($slice as $item) {
            if (isset($item['only']) && is_array($item['only'])) {
                $only[(int) $item['p']] = array_values(array_map('strval', $item['only']));
            }
        }

        $products = Product::query()->whereIn('id', $ids)->with(['brand:id,name', 'category:id,name'])
            ->get(['id', 'name', 'slug', 'sku', 'status', 'brand_id', 'category_id', 'image', 'images', 'image_alts'])
            ->keyBy('id');
        $ordered = collect($ids)->map(fn ($id) => $products->get($id))->filter()->values();
        $plans = self::planner($options)->plan($ordered, $only);
        $combo = $job->kind === 'combo';

        foreach ($plans as $plan) {
            $product = $products->get($plan['id']);
            $alts = $combo ? self::altPlan($product, $plan, $options, $only[$plan['id']] ?? null) : ['write' => [], 'kept' => 0];
            $counts['products']++;
            $counts['alt'] += count($alts['write']);
            $counts['kept'] += $alts['kept'];
            $rows = [];

            foreach ($plan['images'] as $image) {
                if ($image['reason'] === 'not selected') {
                    continue;
                }

                $key = self::reasonKey($image);
                $counts[$key === 'rename' ? 'rename' : ($key === 'already named' ? 'ok' : 'skip')]++;

                if ($key !== 'rename') {
                    $counts['reasons'][$key] = ($counts['reasons'][$key] ?? 0) + 1;
                }

                $rows[] = ['action' => $image['action'], 'rel' => $image['rel'], 'url' => $image['url'], 'thumb' => $image['thumb'],
                    'proposed_rel' => $image['proposed_rel'], 'reason' => $image['reason'], 'alt' => $alts['write'][$image['url']] ?? null];
            }

            if (count($shown) + $offset < self::PREVIEW_SHOWN) {
                $shown[] = ['id' => $plan['id'], 'name' => $plan['name'], 'images' => $rows];
            }
        }

        // Products that no longer exist count as looked at.
        $counts['at'] = $offset + count($slice);
        $options['preview'] = $counts;
        DB::table('image_seo_jobs')->where('id', $job->id)->where('status', 'preview')
            ->update(['options' => json_encode($options, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);

        return ['at' => $counts['at'], 'total' => $total, 'done' => $counts['at'] >= $total, 'counts' => self::publicCounts($counts), 'items' => $shown];
    }

    /**
     * Turn a finished preview into a running job. The caller holds the start
     * lock and has checked nothing else is running.
     *
     * @return array{job: object|null, error: string|null, started: bool}
     */
    public static function begin(string $token): array
    {
        $job = DB::table('image_seo_jobs')->where('token', $token)->first();

        if ($job === null) {
            return ['job' => null, 'error' => 'preview', 'started' => false];
        }

        if ($job->status !== 'preview') {
            return ['job' => $job, 'error' => null, 'started' => false];   // pressed twice: the same run
        }

        $counts = (json_decode((string) $job->options, true) ?: [])['preview'] ?? self::emptyPreview();

        if ((int) $counts['at'] < (int) $job->total) {
            return ['job' => $job, 'error' => 'preview', 'started' => false];
        }

        if ((int) $counts['rename'] + ($job->kind === 'combo' ? (int) $counts['alt'] : 0) === 0) {
            return ['job' => $job, 'error' => 'nothing', 'started' => false];
        }

        // Compare-and-set: of two Starts that got this far, one flips it.
        $flipped = DB::table('image_seo_jobs')->where('id', $job->id)->where('status', 'preview')
            ->update(['status' => 'running', 'position' => 0, 'created_at' => now(), 'updated_at' => now()]);

        return ['job' => DB::table('image_seo_jobs')->where('id', $job->id)->first(), 'error' => null, 'started' => $flipped === 1];
    }

    /**
     * The ALT texts a combined run would write for one product, the same code
     * for its preview and its run: the template and options chosen on the ALT
     * tab, ALT text somebody already wrote kept when `keep` is on, only the
     * pictures selected, only where something changes. Pictures that exist
     * only on a variant are left out, as the ALT tab's own Apply leaves them.
     *
     * @param  array<string, mixed>  $plan  one ImageSeoPlanner::plan() entry for $product
     * @param  array<string, mixed>  $options
     * @param  list<string>|null  $only
     * @return array{write: array<string, string>, kept: int}
     */
    public static function altPlan(Product $product, array $plan, array $options, ?array $only): array
    {
        $template = in_array($options['template'] ?? '', AltText::TEMPLATES, true) ? (string) $options['template'] : 'variations';
        $proposed = AltText::propose($plan['brand'], (string) $plan['name'], $plan['category'], count($plan['images']), $template,
            mb_substr((string) ($options['first'] ?? ''), 0, 200), mb_substr((string) ($options['rest'] ?? ''), 0, 200));
        $keep = (bool) ($options['keep'] ?? true);
        $current = is_array($product->image_alts) ? $product->image_alts : [];
        $urls = array_values(array_filter(array_merge([$product->image], is_array($product->images) ? $product->images : []), 'is_string'));
        $write = [];
        $kept = 0;

        foreach ($plan['images'] as $i => $image) {
            if ($only !== null && ($image['rel'] === null || ! in_array($image['rel'], $only, true))) {
                continue;
            }

            if (! in_array($image['url'], $urls, true)) {
                continue;
            }

            $have = trim((string) ($current[$image['url']] ?? ''));

            if ($keep && $have !== '') {
                $kept++;

                continue;
            }

            $alt = AltText::clean((string) ($proposed[$i] ?? ''));

            if (AltText::acceptable($alt) && $alt !== $have) {
                $write[(string) $image['url']] = $alt;
            }
        }

        return ['write' => $write, 'kept' => $kept];
    }

    /**
     * An undo job for a finished rename, alt or combined job: its own unique
     * token, so pressing Undo twice is one undo. A combined run is undone ALT
     * first (its ledger is keyed by the renamed addresses), then the names.
     */
    public static function undo(int $id, ?object $admin): ?object
    {
        $job = DB::table('image_seo_jobs')->where('id', $id)->first();

        if ($job === null || ! in_array($job->kind, ['rename', 'alt', 'combo'], true) || $job->status === 'preview') {
            return null;
        }

        $alts = self::altLedger($job);

        if ($job->kind === 'alt') {
            $items = [];

            foreach ($alts as $productId => $entries) {
                foreach ($entries as $entry) {
                    $items[] = ['p' => $productId] + $entry;
                }
            }

            return self::start('alt', 'undo-'.$id, $items, ['undo' => true], $admin, $id);
        }

        $rows = DB::table('image_renames')->where('job_id', $id)->where('status', 'done')
            ->whereIn('role', ['main', 'gallery', 'variant'])->orderBy('id')->get(['id', 'product_id']);

        $byProduct = [];

        foreach ($rows->groupBy('product_id') as $productId => $group) {
            $byProduct[(int) $productId] = ['p' => (int) $productId, 'rows' => $group->pluck('id')->map(fn ($v) => (int) $v)->all()];
        }

        foreach ($alts as $productId => $entries) {
            $byProduct[$productId] = ($byProduct[$productId] ?? ['p' => $productId, 'rows' => []]) + ['alt' => $entries];
        }

        return self::start('undo', 'undo-'.$id, array_values($byProduct), [], $admin, $id);
    }

    /**
     * What an alt-writing run changed, per product: from image_alt_changes,
     * or, for a run made before that table, from its log.
     *
     * @return array<int, list<array{restore: array, expect: array}>>
     */
    private static function altLedger(object $job): array
    {
        $out = [];

        if (ImageFiles::hasTable('image_alt_changes')) {
            foreach (DB::table('image_alt_changes')->where('job_id', $job->id)->orderBy('id')->get(['product_id', 'before', 'after']) as $row) {
                $out[(int) $row->product_id][] = ['restore' => json_decode((string) $row->before, true) ?: [], 'expect' => json_decode((string) $row->after, true) ?: []];
            }
        }

        if ($out === [] && $job->kind === 'alt') {
            foreach (json_decode((string) $job->log, true) ?: [] as $entry) {
                if (($entry['status'] ?? '') === 'alt' && isset($entry['p'], $entry['before'], $entry['after'])) {
                    $out[(int) $entry['p']][] = ['restore' => $entry['before'], 'expect' => $entry['after']];
                }
            }
        }

        return $out;
    }

    /**
     * A run, for the screen. `$log` false is the step's answer: counts only,
     * a few hundred bytes, sent every ~1.5 seconds while a run is going.
     *
     * @return array<string, mixed>
     */
    public static function view(object $job, bool $log = true): array
    {
        $out = [
            'id' => (int) $job->id,
            'kind' => (string) $job->kind,
            'status' => (string) $job->status,
            'undo_of' => $job->undo_of === null ? null : (int) $job->undo_of,
            'position' => (int) $job->position,
            'total' => (int) $job->total,
            'renamed' => (int) $job->renamed,
            'skipped' => (int) $job->skipped,
            'failed' => (int) $job->failed,
            'alt_written' => (int) ($job->alt_written ?? 0),
            'elapsed_ms' => (int) ($job->elapsed_ms ?? 0),
            'by' => $job->admin_name,
            'created_at' => (string) $job->created_at,
            'updated_at' => (string) $job->updated_at,
            'undone' => DB::table('image_seo_jobs')->where('undo_of', $job->id)->exists(),
        ];

        if ($log) {
            $entries = json_decode((string) $job->log, true) ?: [];
            $out['log'] = self::slim(array_slice($entries, -200));
            $out['failures'] = self::slim(array_slice(array_values(array_filter($entries, static fn ($e) => ($e['status'] ?? '') === 'rolled_back')), -50));
        }

        return $out;
    }

    /** @return list<array> recent jobs, newest first; previews are not runs */
    public static function recent(int $limit = 12): array
    {
        if (! ImageFiles::hasTable('image_seo_jobs')) {
            return [];
        }

        $columns = ['id', 'kind', 'status', 'undo_of', 'position', 'total', 'renamed', 'skipped', 'failed', 'admin_name', 'created_at', 'updated_at'];

        return DB::table('image_seo_jobs')->where('status', '!=', 'preview')->orderByDesc('id')->limit($limit)
            ->get(self::hasProgressColumns() ? array_merge($columns, ['alt_written', 'elapsed_ms']) : $columns)
            ->map(static fn ($j) => [
                'id' => (int) $j->id, 'kind' => (string) $j->kind, 'status' => (string) $j->status,
                'undo_of' => $j->undo_of === null ? null : (int) $j->undo_of, 'position' => (int) $j->position, 'total' => (int) $j->total,
                'renamed' => (int) $j->renamed, 'skipped' => (int) $j->skipped, 'failed' => (int) $j->failed,
                'alt_written' => (int) ($j->alt_written ?? 0), 'elapsed_ms' => (int) ($j->elapsed_ms ?? 0),
                'by' => $j->admin_name, 'created_at' => (string) $j->created_at,
            ])->all();
    }

    /* ------------------------------------------------------------------ */

    /** @return array<string, mixed> */
    private static function emptyPreview(): array
    {
        return ['at' => 0, 'products' => 0, 'rename' => 0, 'ok' => 0, 'skip' => 0, 'alt' => 0, 'kept' => 0, 'reasons' => []];
    }

    /** @return array<string, mixed> */
    private static function publicCounts(array $counts): array
    {
        return ['products' => (int) $counts['products'], 'rename' => (int) $counts['rename'], 'ok' => (int) $counts['ok'], 'skip' => (int) $counts['skip'],
            'alt' => (int) $counts['alt'], 'kept' => (int) $counts['kept'], 'reasons' => (array) $counts['reasons']];
    }

    /**
     * Why a picture will not be renamed, as one of a few plain reasons the
     * screen can add up: "nothing to rename: 20 already named, 7 shared".
     */
    private static function reasonKey(array $image): string
    {
        $reason = (string) ($image['reason'] ?? '');

        return match (true) {
            in_array($image['action'], ['rename', 'repoint'], true) => 'rename',
            $image['action'] === 'ok' => 'already named',
            str_starts_with($reason, 'shared with') => 'shared with other products',
            str_starts_with($reason, 'the same file as') => 'the same file twice on one product',
            str_starts_with($reason, 'on another website') => 'on another website',
            str_starts_with($reason, 'the file is missing') => 'file missing from the server',
            str_starts_with($reason, 'the product title has no English') => 'title has no English words',
            default => 'other',
        };
    }

    private static function planner(array $options): ImageSeoPlanner
    {
        return new ImageSeoPlanner(
            in_array($options['strategy'] ?? '', ImageNamer::STRATEGIES, true) ? $options['strategy'] : ImageNamer::STRATEGY_VARIATIONS,
            (bool) ($options['include_shared'] ?? false),
        );
    }

    /**
     * Log entries for the screen: the alt before/after maps stay in the
     * ledger, and one answer carries at most 200 lines.
     *
     * @return list<array>
     */
    private static function slim(array $results): array
    {
        return array_map(static fn ($e) => array_diff_key($e, ['before' => 1, 'after' => 1]), array_slice(array_values($results), -200));
    }

    /**
     * Lane IS2's two counters exist (2027_10_11_100000). Asked, not memoised:
     * once a step and once a History list, and a static here would outlive
     * a test (StaticMemoIsolationTest).
     */
    private static function hasProgressColumns(): bool
    {
        try {
            return \Illuminate\Support\Facades\Schema::hasColumn('image_seo_jobs', 'alt_written');
        } catch (\Throwable) {
            return false;
        }
    }


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

    /**
     * Rename files + ALT text, one product: the rename exactly as a rename run
     * does it, then the product read again (its addresses are the new ones)
     * and the ALT text altPlan() gives for the pictures that were selected.
     *
     * @return list<array>
     */
    private static function comboOne(array $item, array $options, array $context): array
    {
        $out = self::renameOne($item, $options, $context);
        $product = Product::query()->with(['brand:id,name', 'category:id,name'])->find((int) ($item['p'] ?? 0));

        if ($product === null) {
            return $out;
        }

        $only = null;

        if (isset($item['only']) && is_array($item['only'])) {
            // The pictures he picked, under the names they have now.
            $moved = [];

            foreach ($out as $r) {
                if (in_array($r['status'], ['renamed', 'repointed'], true) && is_string($r['to'] ?? null)) {
                    $moved[(string) $r['rel']] = $r['to'];
                }
            }

            $only = array_values(array_map(static fn ($rel) => $moved[(string) $rel] ?? (string) $rel, $item['only']));
        }

        $plan = self::planner($options)->plan([$product], [])[0];
        $alts = self::altPlan($product, $plan, $options, $only)['write'];

        if ($alts === []) {
            return $out;
        }

        return array_merge($out, self::altOne(['p' => (int) $product->id, 'alts' => $alts], (int) ($context['job_id'] ?? 0)));
    }

    /** @return list<array> */
    private static function undoOne(array $item, array $context): array
    {
        $productId = (int) ($item['p'] ?? 0);
        $out = [];

        // A combined run's ALT text first: what it wrote is keyed by the
        // renamed addresses, which the rename back then carries along.
        foreach ((array) ($item['alt'] ?? []) as $entry) {
            foreach (self::altOne(['p' => $productId, 'restore' => (array) ($entry['restore'] ?? []), 'expect' => (array) ($entry['expect'] ?? [])], (int) ($context['job_id'] ?? 0)) as $r) {
                if ($r['status'] === 'alt') {
                    $out[] = array_diff_key($r, ['p' => 1, 'name' => 1]);
                }
            }
        }

        $rows = DB::table('image_renames')->whereIn('id', (array) ($item['rows'] ?? []))->where('status', 'done')->orderByDesc('id')->get();
        $images = [];

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
    private static function altOne(array $item, int $jobId = 0): array
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

        $changed = array_diff_assoc($after, $before);
        $removed = array_diff_key($before, $after);
        $was = array_intersect_key($before, $changed + $removed);

        // The ledger Undo reads: written with the change, never capped.
        if ($jobId > 0 && ImageFiles::hasTable('image_alt_changes')) {
            DB::table('image_alt_changes')->insert(['job_id' => $jobId, 'product_id' => (int) $product->id,
                'before' => json_encode($was, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'after' => json_encode($changed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'created_at' => now()]);
        }

        $n = count($changed + $removed);

        return [['p' => (int) $product->id, 'name' => (string) $product->name, 'rel' => '', 'to' => null, 'status' => 'alt',
            'reason' => $n.' alt text(s) '.(isset($item['restore']) ? 'restored' : 'written'), 'refs' => 0, 'files' => 0, 'n' => $n,
            'before' => $was, 'after' => $changed]];
    }

    /**
     * Files renamed, pictures skipped, files rolled back, and ALT texts
     * written: an "alt" entry stands for one product and carries how many.
     *
     * @return array{renamed: int, skipped: int, failed: int, alt_written: int}
     */
    private static function count(array $results): array
    {
        $c = ['renamed' => 0, 'skipped' => 0, 'failed' => 0, 'alt_written' => 0];

        foreach ($results as $r) {
            if ($r['status'] === 'alt') {
                $c['alt_written'] += max(1, (int) ($r['n'] ?? 1));

                continue;
            }

            $c[match ($r['status']) {
                'renamed', 'repointed', 'restored' => 'renamed',
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

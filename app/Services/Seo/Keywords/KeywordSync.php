<?php

declare(strict_types=1);

namespace App\Services\Seo\Keywords;

use App\Services\Seo\IndexNow;
use App\Services\Seo\SeoSettings;
use App\Support\Locale;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sync: refresh the bank, then compose every selected page, in SMALL STEPS.
 *
 * Shared hosting kills a request at 30–60 seconds, and a catalogue of 2,400
 * products in two languages is far more work than that. So a sync is a ROW
 * (seo_keyword_runs) with a cursor, and the screen calls step() again and
 * again; each call does at most STEP_SECONDS of work and returns progress.
 * Close the tab half way and the run is still there — Resume carries on from
 * the cursor. Nothing is ever one long request.
 *
 *   bank phase     (Run only) site-search terms, the lexicon, Search Console,
 *                  then Autocomplete seeds at ≤1 request/second, capped.
 *   compose phase  per type, per locale, a chunk of pages at a time.
 *
 * A DRY RUN skips the bank phase (it uses the bank as it stands) and writes no
 * page — it counts what would change and keeps the first 40 diffs to show.
 *
 * Nothing reaches the storefront until the run finishes: pages read through
 * PageKeywords, which keys its cache by `seo_kw_live`, and that stamp only moves
 * when a Run completes or is undone.
 */
final class KeywordSync
{
    /** Pages per chunk. Public so a test can force a run across many steps. */
    public static int $chunk = 40;

    public const DIFFS = 40;

    public const URLS = 500;

    public static float $stepSeconds = 12.0;

    /** Back to the shipped tuning — Tests\Support\StaticMemos calls this between tests. */
    public static function resetTuning(): void
    {
        self::$stepSeconds = 12.0;
        self::$chunk = 40;
    }

    /** @param list<string> $types */
    public static function start(array $types, bool $dry, ?int $by): object
    {
        $types = array_values(array_intersect(EntityCatalog::TYPES, $types));
        if ($types === []) {
            throw new \InvalidArgumentException('Choose at least one kind of page.');
        }

        $running = DB::table('seo_keyword_runs')->where('status', 'running')->where('dry', false)->orderByDesc('id')->first();
        if ($running !== null && ! $dry) {
            return self::present($running);
        }

        $locales = self::locales();
        $seeds = $dry ? [] : SeedPlanner::plan(KeywordConfig::options(), $locales);
        $total = count($seeds) + ($dry ? 0 : 3);
        foreach ($types as $t) {
            $total += EntityCatalog::count($t) * count($locales);
        }

        $id = DB::table('seo_keyword_runs')->insertGetId([
            'dry' => $dry,
            'status' => 'running',
            'phase' => $dry ? 'compose' : 'bank',
            'scope' => json_encode($types),
            'cursor' => json_encode([
                'seeds' => $seeds, 'seed_i' => 0, 'prep' => 0,
                'locales' => $locales, 't' => 0, 'l' => 0, 'after' => 0,
            ]),
            'counts' => json_encode([
                'total' => $total, 'done' => 0, 'created' => 0, 'changed' => 0, 'unchanged' => 0,
                'locked' => 0, 'clashes' => 0, 'fetched' => 0, 'failed' => 0, 'requests' => 0,
            ]),
            'report' => json_encode(['diffs' => [], 'urls' => [], 'notes' => []]),
            'started_by' => $by,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return self::present(DB::table('seo_keyword_runs')->find($id));
    }

    public static function step(int $runId): object
    {
        $run = DB::table('seo_keyword_runs')->find($runId);
        if ($run === null) {
            throw new \InvalidArgumentException('No such sync.');
        }
        if ($run->status !== 'running') {
            return self::present($run);
        }

        $cur = json_decode((string) $run->cursor, true) ?: [];
        $counts = json_decode((string) $run->counts, true) ?: [];
        $report = json_decode((string) $run->report, true) ?: ['diffs' => [], 'urls' => [], 'notes' => []];
        $types = json_decode((string) $run->scope, true) ?: [];
        $phase = (string) $run->phase;
        $deadline = microtime(true) + self::$stepSeconds;

        try {
            if ($phase === 'bank') {
                $phase = self::bankStep($cur, $counts, $report, $deadline);
            }
            if ($phase === 'compose' && ((string) $run->phase === 'compose' || microtime(true) < $deadline)) {
                $phase = self::composeStep((int) $run->id, (bool) $run->dry, $types, $cur, $counts, $report, $deadline);
            }
        } catch (\Throwable $e) {
            Log::warning('SEO keywords: sync step failed', ['run' => $run->id, 'error' => $e->getMessage()]);
            DB::table('seo_keyword_runs')->where('id', $run->id)->update([
                'status' => 'failed', 'cursor' => json_encode($cur), 'counts' => json_encode($counts),
                'report' => json_encode($report + ['error' => mb_substr($e->getMessage(), 0, 300)]),
                'updated_at' => now(),
            ]);

            return self::present(DB::table('seo_keyword_runs')->find($run->id));
        }

        $update = [
            'phase' => $phase, 'cursor' => json_encode($cur), 'counts' => json_encode($counts),
            'report' => json_encode($report), 'updated_at' => now(),
        ];

        if ($phase === 'done') {
            $update['status'] = 'done';
            $update['finished_at'] = now();
            $counts['done'] = $counts['total'];
            $update['counts'] = json_encode($counts);
        }

        DB::table('seo_keyword_runs')->where('id', $run->id)->update($update);

        if ($phase === 'done' && ! $run->dry) {
            KeywordConfig::stampLive();
            self::announce($report['urls'] ?? []);
        }

        return self::present(DB::table('seo_keyword_runs')->find($run->id));
    }

    /**
     * The Run that Undo would reverse: the LATEST applied one, and only while
     * it has not been undone. One level deep, on purpose — each row keeps the
     * one state it replaced, so undoing the run before that would delete rows
     * whose earlier history is already gone.
     */
    public static function undoable(): ?object
    {
        $run = DB::table('seo_keyword_runs')->where('dry', false)->orderByDesc('id')->first();

        return $run !== null && in_array($run->status, ['done', 'failed'], true) ? $run : null;
    }

    /** Put back every page the last applied Run changed. */
    public static function undo(): ?object
    {
        $run = self::undoable();
        if ($run === null) {
            return null;
        }

        DB::transaction(static function () use ($run): void {
            // Two passes. Restoring primaries row by row could briefly put one
            // keyword on two rows, which the unique index refuses, so every
            // restored row first goes back WITHOUT its primary, and the
            // primaries follow once none of this run's rows holds one.
            $primaries = [];
            DB::table('seo_page_keywords')->where('run_id', $run->id)->orderBy('id')
                ->chunkById(200, static function ($rows) use (&$primaries): void {
                    foreach ($rows as $row) {
                        $prev = is_string($row->previous) ? json_decode($row->previous, true) : null;
                        if (! is_array($prev)) {
                            DB::table('seo_page_keywords')->where('id', $row->id)->delete();

                            continue;
                        }
                        DB::table('seo_page_keywords')->where('id', $row->id)->update([
                            'layers' => $prev['layers'] ?? null, 'keywords' => $prev['keywords'] ?? null,
                            'primary_kw' => null, 'suggest' => $prev['suggest'] ?? null, 'links' => $prev['links'] ?? null,
                            'clash' => $prev['clash'] ?? null, 'run_id' => $prev['run_id'] ?? null,
                            'generated_at' => $prev['generated_at'] ?? null, 'previous' => null,
                        ]);
                        if (is_string($prev['primary_kw'] ?? null)) {
                            $primaries[(int) $row->id] = $prev['primary_kw'];
                        }
                    }
                });
            foreach ($primaries as $id => $kw) {
                DB::table('seo_page_keywords')->where('id', $id)->update(['primary_kw' => $kw]);
            }
            DB::table('seo_keyword_runs')->where('id', $run->id)->update(['status' => 'undone', 'updated_at' => now()]);
        });

        KeywordConfig::stampLive();

        return self::present(DB::table('seo_keyword_runs')->find($run->id));
    }

    public static function locales(): array
    {
        $out = ['en'];
        if (KeywordConfig::options()['arabic'] && in_array('ar', Locale::enabledCodes(), true)) {
            $out[] = 'ar';
        }

        return $out;
    }

    public static function present(?object $run): object
    {
        if ($run === null) {
            return (object) [];
        }
        $counts = json_decode((string) $run->counts, true) ?: [];
        $report = json_decode((string) $run->report, true) ?: [];

        return (object) [
            'id' => (int) $run->id,
            'dry' => (bool) $run->dry,
            'status' => (string) $run->status,
            'phase' => (string) $run->phase,
            'scope' => json_decode((string) $run->scope, true) ?: [],
            'counts' => $counts,
            'percent' => ($counts['total'] ?? 0) > 0 ? (int) min(100, floor(100 * ($counts['done'] ?? 0) / $counts['total'])) : 100,
            'diffs' => $report['diffs'] ?? [],
            'notes' => $report['notes'] ?? [],
            'error' => $report['error'] ?? null,
            'started_at' => (string) $run->created_at,
            'finished_at' => $run->finished_at ? (string) $run->finished_at : null,
        ];
    }

    /* ------------------------------------------------------------ bank */

    private static function bankStep(array &$cur, array &$counts, array &$report, float $deadline): string
    {
        // 0: site search + lexicon, 1: Search Console, 2: seeds
        // Every step does at least one unit of work, however small its budget,
        // so the cursor always moves.
        $did = false;
        if (($cur['prep'] ?? 0) === 0) {
            $counts['fetched'] += SeedPlanner::bankSiteAndLexicon();
            $cur['prep'] = 1;
            $counts['done'] += 2;
            $did = true;
        }

        if (($cur['prep'] ?? 0) === 1 && (! $did || microtime(true) < $deadline)) {
            $did = true;
            $gsc = app(SearchConsoleSource::class);
            if ($gsc->configured()) {
                $n = SeedPlanner::bankSearchConsole($gsc->rows());
                $counts['fetched'] += $n;
                $report['notes'][] = $gsc->error !== '' ? 'Search Console: '.$gsc->error : 'Search Console: '.$n.' queries.';
            }
            $cur['prep'] = 2;
            $counts['done'] += 1;
        }

        $ac = app(AutocompleteSource::class);
        $on = KeywordConfig::options()['autocomplete'];
        $seeds = $cur['seeds'] ?? [];
        [$req0, $fail0] = [$ac->requests, $ac->failures];

        // At least one seed per step, however small the budget, so a step
        // always moves the cursor.
        $first = ! $did;
        while (($cur['prep'] ?? 0) >= 2 && ($cur['seed_i'] ?? 0) < count($seeds) && ($first || microtime(true) < $deadline)) {
            $first = false;
            [$seed, $locale] = $seeds[$cur['seed_i']];
            if ($on) {
                $rows = [];
                foreach ($ac->suggest($seed, $locale) as $rank => $s) {
                    $rows[] = ['term' => $s, 'source' => 'autocomplete', 'score' => KeywordBank::scoreAutocomplete($rank), 'metrics' => ['seed' => $seed, 'rank' => $rank + 1]];
                }
                $counts['fetched'] += KeywordBank::put($locale, $rows);
            }
            $cur['seed_i']++;
            $counts['done']++;
        }

        $counts['requests'] += $ac->requests - $req0;
        $counts['failed'] += $ac->failures - $fail0;

        return ($cur['seed_i'] ?? 0) >= count($seeds) && ($cur['prep'] ?? 0) >= 2 ? 'compose' : 'bank';
    }

    /* ------------------------------------------------------------ compose */

    private static function composeStep(int $runId, bool $dry, array $types, array &$cur, array &$counts, array &$report, float $deadline): string
    {
        $locales = $cur['locales'] ?? ['en'];
        $site = SeoSettings::firstFilled(SeoSettings::get('seo_site_name', ''), SeoSettings::get('store_name', ''), 'K-Beauty Bliss');
        // Never the old name, even on a shop whose settings still carry it (Lane BR).
        if (\App\Support\BrandName::anyCount($site) > 0) {
            $site = \App\Support\BrandName::NAME;
        }
        $kbeautyOne = \App\Support\BrandName::on(SeoSettings::map(), \App\Support\BrandName::KBEAUTY_ONE);
        $bank = null;
        $owners = null;
        $bankLocale = null;

        $first = true;
        while ($first || microtime(true) < $deadline) {
            $first = false;
            if (($cur['t'] ?? 0) >= count($types)) {
                return 'done';
            }
            $type = $types[$cur['t']];
            $locale = $locales[$cur['l'] ?? 0] ?? 'en';

            if ($bankLocale !== $locale) {
                $bank = KeywordBank::index($locale);
                $owners = [];
                foreach (DB::table('seo_page_keywords')->where('locale', $locale)->whereNotNull('primary_kw')
                    ->get(['primary_kw', 'entity_type', 'entity_id']) as $o) {
                    $owners[$o->primary_kw] = $o->entity_type.':'.$o->entity_id;
                }
                $bankLocale = $locale;
            }

            [$profiles, $next] = EntityCatalog::chunk($type, $locale, (int) ($cur['after'] ?? 0), self::$chunk);

            $existing = [];
            if ($profiles !== []) {
                foreach (DB::table('seo_page_keywords')->where('entity_type', $type)->where('locale', $locale)
                    ->whereIn('entity_id', array_column($profiles, 'id'))->get() as $row) {
                    $existing[$row->entity_id] = $row;
                }
            }

            foreach ($profiles as $p) {
                $counts['done']++;
                $old = $existing[$p['id']] ?? null;

                if ($old !== null && $old->locked) {
                    $counts['locked']++;

                    continue;
                }

                $r = KeywordComposer::compose($p, $bank, $owners, $site, $kbeautyOne);
                $links = in_array($type, ['category', 'brand'], true) ? self::links($type, (int) $p['id'], $locale) : [];

                if ($r['clash'] !== null) {
                    $counts['clashes']++;
                }

                $oldKeywords = $old ? (json_decode((string) $old->keywords, true) ?: []) : [];
                $same = $old !== null && $oldKeywords === $r['keywords'] && $old->primary_kw === $r['primary']
                    && (json_decode((string) $old->links, true) ?: []) === $links;

                if ($same) {
                    $counts['unchanged']++;

                    continue;
                }
                $counts[$old ? 'changed' : 'created']++;

                if ($dry) {
                    if (count($report['diffs']) < self::DIFFS) {
                        $report['diffs'][] = [
                            'entity' => $type.':'.$p['id'], 'locale' => $locale, 'name' => mb_substr((string) $p['display'], 0, 120),
                            'before' => $oldKeywords, 'after' => $r['keywords'],
                            'primary_before' => $old?->primary_kw, 'primary_after' => $r['primary'],
                        ];
                    }

                    continue;
                }

                if ($old !== null && $old->primary_kw !== null && ($owners[$old->primary_kw] ?? null) === $type.':'.$p['id']) {
                    unset($owners[$old->primary_kw]);
                }
                if ($r['primary'] !== null) {
                    $owners[$r['primary']] = $type.':'.$p['id'];
                }

                DB::table('seo_page_keywords')->upsert([[
                    'entity_type' => $type,
                    'entity_id' => $p['id'],
                    'locale' => $locale,
                    'layers' => json_encode($r['layers'], JSON_UNESCAPED_UNICODE),
                    'keywords' => json_encode($r['keywords'], JSON_UNESCAPED_UNICODE),
                    'primary_kw' => $r['primary'],
                    'locked' => false,
                    'suggest' => json_encode($r['suggest'], JSON_UNESCAPED_UNICODE),
                    'links' => json_encode($links, JSON_UNESCAPED_UNICODE),
                    'clash' => $r['clash'] !== null ? mb_substr($r['clash'], 0, 200) : null,
                    'run_id' => $runId,
                    'previous' => $old !== null ? json_encode(self::snapshot($old), JSON_UNESCAPED_UNICODE) : null,
                    'generated_at' => now(),
                ]], ['entity_type', 'entity_id', 'locale'], ['layers', 'keywords', 'primary_kw', 'suggest', 'links', 'clash', 'run_id', 'previous', 'generated_at']);

                if (count($report['urls']) < self::URLS && $locale === 'en') {
                    $report['urls'][] = (string) $p['path'];
                }
            }

            if ($next === null) {
                $cur['after'] = 0;
                $cur['l'] = ($cur['l'] ?? 0) + 1;
                if ($cur['l'] >= count($locales)) {
                    $cur['l'] = 0;
                    $cur['t'] = ($cur['t'] ?? 0) + 1;
                }
            } else {
                $cur['after'] = $next;
            }
        }

        return ($cur['t'] ?? 0) >= count($types) ? 'done' : 'compose';
    }

    private static function snapshot(object $row): array
    {
        return [
            'layers' => $row->layers, 'keywords' => $row->keywords, 'primary_kw' => $row->primary_kw,
            'suggest' => $row->suggest, 'links' => $row->links, 'clash' => $row->clash,
            'run_id' => $row->run_id, 'generated_at' => $row->generated_at,
        ];
    }

    /**
     * Popular searches for a category or brand: its six best sellers, each
     * labelled with that product's own primary keyword (its name when it has
     * none yet) and linked to the product. Real pages, descriptive anchors.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function links(string $type, int $id, string $locale): array
    {
        $q = DB::table('products')->where('status', 'publish')->where('is_visible', true)
            ->orderByDesc('total_sales')->orderBy('id')->limit(6);
        $type === 'brand'
            ? $q->where('brand_id', $id)
            : $q->where(static function ($w) use ($id): void {
                $w->where('category_id', $id)->orWhereIn('id', DB::table('category_product')->where('category_id', $id)->select('product_id'));
            });
        $products = $q->get(['id', 'slug', 'name']);

        if ($products->isEmpty()) {
            return [];
        }

        $primaries = DB::table('seo_page_keywords')->where('entity_type', 'product')->where('locale', $locale)
            ->whereIn('entity_id', $products->pluck('id')->map(static fn ($v) => (string) $v)->all())
            ->pluck('primary_kw', 'entity_id');

        $out = [];
        foreach ($products as $p) {
            $label = KeywordText::clean($primaries[(string) $p->id] ?? null) ?? KeywordText::clean(EntityCatalog::core((string) $p->name));
            if ($label !== null) {
                $out[] = [$label, \App\Support\UrlScheme::product((string) $p->slug)];
            }
        }

        return $out;
    }

    /** Tell IndexNow (Bing, Yandex, Seznam…) which pages changed, if the owner has it on. */
    private static function announce(array $paths): void
    {
        if ($paths === [] || ! IndexNow::enabled()) {
            return;
        }

        try {
            $base = rtrim(SeoSettings::get('site_url', (string) config('app.url')), '/');
            IndexNow::submit(array_map(static fn ($p) => $base.$p, array_slice($paths, 0, self::URLS)));
        } catch (\Throwable $e) {
            Log::info('SEO keywords: IndexNow skipped', ['reason' => $e->getMessage()]);
        }
    }
}

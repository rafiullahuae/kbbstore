<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Page;
use App\Models\Post;
use App\Models\Product;
use App\Services\Seo\Keywords\EntityCatalog;
use App\Services\Seo\Keywords\KeywordBank;
use App\Services\Seo\Keywords\KeywordConfig;
use App\Services\Seo\Keywords\KeywordSync;
use App\Services\Seo\Keywords\KeywordText;
use App\Services\Seo\Keywords\Lexicon;
use App\Services\Seo\Keywords\SearchConsoleSource;
use App\Services\Seo\SeoSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Store → SEO Keywords. Every route is in routes/seo-keywords-admin.php, inside
 * the admin-api group, and every one is mapped in AdminCapabilities:
 *
 *   GET  …                 seo_keywords.view   owner, manager
 *   everything else        seo_keywords.sync   owner only
 *
 * Nothing here returns the Search Console key: the screen gets `connected` and
 * the service account's email, and the key goes in through PUT /gsc and is
 * never read back by any route. Every term this controller returns came out of
 * KeywordText::clean(); the screen still escapes every one.
 */
class SeoKeywordsApiController extends Controller
{
    private const LOCALES = ['en', 'ar'];

    public function overview(): JsonResponse
    {
        $s = SeoSettings::map();
        $o = KeywordConfig::options();

        $bank = [];
        foreach (DB::table('seo_keywords')->groupBy('source', 'locale')->get(['source', 'locale', DB::raw('count(*) as n')]) as $r) {
            $bank[$r->locale][$r->source] = (int) $r->n;
        }

        $have = [];
        foreach (DB::table('seo_page_keywords')->where('locale', 'en')->groupBy('entity_type')
            ->get(['entity_type', DB::raw('count(*) as n')]) as $r) {
            $have[$r->entity_type] = (int) $r->n;
        }
        $coverage = [];
        foreach (EntityCatalog::TYPES as $t) {
            $total = EntityCatalog::count($t);
            $coverage[] = ['type' => $t, 'label' => EntityCatalog::LABELS[$t], 'total' => $total, 'done' => min($total, $have[$t] ?? 0)];
        }

        $clashes = DB::table('seo_page_keywords')->whereNotNull('clash')->orderBy('entity_type')->orderBy('id')
            ->limit(20)->get(['entity_type', 'entity_id', 'locale', 'clash']);
        $clashNames = self::names($clashes);
        $clashes = $clashes->map(static fn ($c) => [
            'entity_type' => $c->entity_type, 'entity_id' => $c->entity_id, 'locale' => $c->locale, 'clash' => $c->clash,
            'name' => $clashNames[$c->entity_type.':'.$c->entity_id] ?? null,
        ]);

        $last = DB::table('seo_keyword_runs')->orderByDesc('id')->first();
        $running = DB::table('seo_keyword_runs')->where('status', 'running')->orderByDesc('id')->first();
        $undoable = KeywordSync::undoable();

        return response()->json([
            'live' => (string) ($s[KeywordConfig::LIVE] ?? '') !== '',
            'switches' => ['meta' => KeywordConfig::metaOn($s), 'popular' => KeywordConfig::popularOn($s)],
            'options' => $o,
            'gsc' => ['connected' => KeywordConfig::hasGscKey(), 'email' => KeywordConfig::gscEmail(), 'property' => $o['gsc_property']],
            'locales' => KeywordSync::locales(),
            'bank' => $bank,
            'coverage' => $coverage,
            'clash_count' => DB::table('seo_page_keywords')->whereNotNull('clash')->count(),
            'clashes' => $clashes,
            'last' => $last ? KeywordSync::present($last) : null,
            'running' => $running ? KeywordSync::present($running) : null,
            'undo' => $undoable ? ['id' => (int) $undoable->id, 'at' => (string) $undoable->finished_at] : null,
            'types' => EntityCatalog::LABELS,
            'lexicon' => [
                'types' => array_map(static fn ($v, $k) => [$k, $v[0]], Lexicon::TYPES, array_keys(Lexicon::TYPES)),
                'ingredients' => array_map(static fn ($v, $k) => [$k, $v], Lexicon::INGREDIENTS, array_keys(Lexicon::INGREDIENTS)),
                'concerns' => array_values(array_map(static fn ($v) => [$v[0], $v[2]], Lexicon::CONCERNS)),
                'industry' => Lexicon::INDUSTRY,
                'uae' => Lexicon::UAE,
            ],
        ]);
    }

    public function bank(Request $request): JsonResponse
    {
        $d = $request->validate([
            'q' => ['nullable', 'string', 'max:60'],
            'source' => ['nullable', 'string', 'in:all,'.implode(',', KeywordBank::SOURCES)],
            'locale' => ['nullable', 'string', 'in:'.implode(',', self::LOCALES)],
            'page' => ['nullable', 'integer', 'min:1', 'max:2000'],
        ]);

        $q = DB::table('seo_keywords')->where('locale', $d['locale'] ?? 'en');
        if (($d['source'] ?? 'all') !== 'all') {
            $q->where('source', $d['source']);
        }
        if (($find = KeywordText::norm((string) ($d['q'] ?? ''))) !== '') {
            $q->where('term', 'like', '%'.addcslashes($find, '%_\\').'%');
        }

        $page = (int) ($d['page'] ?? 1);
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('score')->orderBy('term')->forPage($page, 50)->get(['term', 'source', 'score', 'metrics', 'fetched_at']);

        return response()->json([
            'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / 50)),
            'rows' => $rows->map(static function ($r) {
                $m = is_string($r->metrics) ? (json_decode($r->metrics, true) ?: []) : [];

                return [
                    'term' => $r->term, 'source' => $r->source, 'score' => (int) $r->score,
                    'impressions' => isset($m['impressions']) ? (int) $m['impressions'] : null,
                    'clicks' => isset($m['clicks']) ? (int) $m['clicks'] : null,
                    'position' => isset($m['position']) ? (float) $m['position'] : null,
                    'hits' => isset($m['hits']) ? (int) $m['hits'] : null,
                    'fetched_at' => $r->fetched_at,
                ];
            })->all(),
        ]);
    }

    public function pages(Request $request): JsonResponse
    {
        $d = $request->validate([
            'type' => ['nullable', 'string', 'in:all,'.implode(',', EntityCatalog::TYPES)],
            'locale' => ['nullable', 'string', 'in:'.implode(',', self::LOCALES)],
            'filter' => ['nullable', 'string', 'in:all,clash,locked,noprimary'],
            'q' => ['nullable', 'string', 'max:60'],
            'page' => ['nullable', 'integer', 'min:1', 'max:2000'],
        ]);

        $q = DB::table('seo_page_keywords')->where('locale', $d['locale'] ?? 'en');
        if (($d['type'] ?? 'all') !== 'all') {
            $q->where('entity_type', $d['type']);
        }
        match ($d['filter'] ?? 'all') {
            'clash' => $q->whereNotNull('clash'),
            'locked' => $q->where('locked', true),
            'noprimary' => $q->whereNull('primary_kw'),
            default => null,
        };
        if (($find = KeywordText::norm((string) ($d['q'] ?? ''))) !== '') {
            $q->where('keywords', 'like', '%'.addcslashes($find, '%_\\').'%');
        }

        $page = (int) ($d['page'] ?? 1);
        $total = (clone $q)->count();
        $rows = $q->orderBy('entity_type')->orderBy('id')->forPage($page, 25)->get();

        $names = self::names($rows);

        return response()->json([
            'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / 25)),
            'rows' => $rows->map(static fn ($r) => [
                'type' => $r->entity_type, 'id' => $r->entity_id, 'locale' => $r->locale,
                'name' => $names[$r->entity_type.':'.$r->entity_id] ?? $r->entity_type.' '.$r->entity_id,
                'layers' => json_decode((string) $r->layers, true) ?: [],
                'keywords' => json_decode((string) $r->keywords, true) ?: [],
                'primary' => $r->primary_kw, 'locked' => (bool) $r->locked, 'clash' => $r->clash,
                'suggest' => json_decode((string) $r->suggest, true) ?: null,
                'applicable' => $r->locale === 'en' && in_array($r->entity_type, ['product', 'category', 'brand', 'page', 'post'], true) && $r->entity_id !== 'home',
                'generated_at' => $r->generated_at,
            ])->all(),
        ]);
    }

    /** Edit one page's keywords by hand. Saving locks the page, so a sync leaves it alone. */
    public function savePage(Request $request): JsonResponse
    {
        $d = $request->validate([
            'type' => ['required', 'string', 'in:'.implode(',', EntityCatalog::TYPES)],
            'id' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/'],
            'locale' => ['required', 'string', 'in:'.implode(',', self::LOCALES)],
            'keywords' => ['present', 'array', 'max:10'],
            'keywords.*' => ['nullable', 'string', 'max:120'],
            'primary' => ['nullable', 'string', 'max:120'],
            'locked' => ['required', 'boolean'],
        ]);

        $row = DB::table('seo_page_keywords')->where('entity_type', $d['type'])->where('entity_id', $d['id'])->where('locale', $d['locale'])->first();
        if ($row === null) {
            return response()->json(['message' => 'Run a sync for this page first.'], 404);
        }

        $kw = [];
        foreach ($d['keywords'] as $k) {
            $c = KeywordText::clean($k);
            if ($c !== null && ! in_array($c, $kw, true)) {
                $kw[] = $c;
            }
        }
        $primary = KeywordText::clean($d['primary'] ?? null);
        if ($primary !== null && ! in_array($primary, $kw, true)) {
            array_unshift($kw, $primary);
            $kw = array_slice($kw, 0, 10);
        }

        if ($primary !== null) {
            $owner = DB::table('seo_page_keywords')->where('locale', $d['locale'])->where('primary_kw', $primary)
                ->where('id', '!=', $row->id)->first(['entity_type', 'entity_id']);
            if ($owner !== null) {
                return response()->json(['message' => 'That primary keyword already belongs to '.$owner->entity_type.' '.$owner->entity_id.'. One page per primary keyword.'], 422);
            }
        }

        DB::table('seo_page_keywords')->where('id', $row->id)->update([
            'keywords' => json_encode($kw, JSON_UNESCAPED_UNICODE),
            'primary_kw' => $primary,
            'locked' => (bool) $d['locked'],
            'clash' => null,
        ]);

        $live = (string) (SeoSettings::map()[KeywordConfig::LIVE] ?? '');
        if ($live !== '') {
            Cache::forget('seo_kw:'.$live.':'.$d['type'].':'.$d['id'].':'.$d['locale']);
        }

        return response()->json(['ok' => true, 'keywords' => $kw, 'primary' => $primary, 'locked' => (bool) $d['locked']]);
    }

    /** One click: the page's suggested title and/or description become its SEO title/description. */
    public function apply(Request $request): JsonResponse
    {
        $d = $request->validate([
            'type' => ['required', 'string', 'in:product,category,brand,page,post'],
            'id' => ['required', 'integer', 'min:1'],
            'fields' => ['required', 'array', 'min:1', 'max:2'],
            'fields.*' => ['string', 'in:title,desc'],
        ]);

        $row = DB::table('seo_page_keywords')->where('entity_type', $d['type'])->where('entity_id', (string) $d['id'])->where('locale', 'en')->first();
        $suggest = $row ? (json_decode((string) $row->suggest, true) ?: []) : [];
        if ($suggest === []) {
            return response()->json(['message' => 'No suggestion for this page yet — run a sync.'], 404);
        }

        $model = match ($d['type']) {
            'product' => Product::query()->find($d['id']),
            'category' => Category::query()->find($d['id']),
            'brand' => Brand::query()->find($d['id']),
            'page' => Page::query()->find($d['id']),
            'post' => Post::query()->find($d['id']),
        };
        if ($model === null) {
            return response()->json(['message' => 'That page no longer exists.'], 404);
        }

        $seo = is_array($model->seo) ? $model->seo : [];
        foreach (array_unique($d['fields']) as $f) {
            $v = trim(strip_tags((string) ($suggest[$f] ?? '')));
            if ($v !== '') {
                $seo[$f] = mb_substr($v, 0, $f === 'title' ? 70 : 160);
            }
        }
        $model->seo = $seo;
        $model->save();

        return response()->json(['ok' => true, 'seo' => array_intersect_key($seo, ['title' => 1, 'desc' => 1])]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $d = $request->validate([
            'meta' => ['sometimes', 'boolean'],
            'popular' => ['sometimes', 'boolean'],
            'autocomplete' => ['sometimes', 'boolean'],
            'arabic' => ['sometimes', 'boolean'],
            'seed_cap' => ['sometimes', 'integer', 'min:0', 'max:'.KeywordConfig::MAX_SEEDS],
            'gsc_property' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);

        if (array_key_exists('gsc_property', $d)) {
            $p = trim((string) $d['gsc_property']);
            if ($p !== '' && ! SearchConsoleSource::validProperty($p)) {
                return response()->json(['message' => 'The property is "sc-domain:yourshop.ae" or the exact address, ending in /, e.g. "https://yourshop.ae/".'], 422);
            }
            $d['gsc_property'] = $p;
        }

        if (array_key_exists('meta', $d)) {
            KeywordConfig::setSwitch(KeywordConfig::META, (bool) $d['meta']);
        }
        if (array_key_exists('popular', $d)) {
            KeywordConfig::setSwitch(KeywordConfig::POPULAR, (bool) $d['popular']);
        }
        KeywordConfig::saveOptions(array_intersect_key($d, KeywordConfig::DEFAULT_OPTIONS));

        return $this->overview();
    }

    public function saveGsc(Request $request): JsonResponse
    {
        $d = $request->validate(['key' => ['required', 'string', 'max:10000']]);

        try {
            $email = KeywordConfig::saveGscKey($d['key']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'connected' => true, 'email' => $email]);
    }

    public function forgetGsc(): JsonResponse
    {
        KeywordConfig::forgetGscKey();

        return response()->json(['ok' => true, 'connected' => false]);
    }

    public function testGsc(SearchConsoleSource $gsc): JsonResponse
    {
        if (! $gsc->configured()) {
            return response()->json(['ok' => false, 'message' => 'Add the key and the property first.'], 422);
        }
        $rows = $gsc->rows(28);

        return response()->json([
            'ok' => $gsc->error === '',
            'message' => $gsc->error !== '' ? $gsc->error : count($rows).' search queries in the last 28 days.',
        ]);
    }

    public function startSync(Request $request): JsonResponse
    {
        $d = $request->validate([
            'types' => ['required', 'array', 'min:1', 'max:6'],
            'types.*' => ['string', 'in:'.implode(',', EntityCatalog::TYPES)],
            'dry' => ['required', 'boolean'],
        ]);

        return response()->json(KeywordSync::start($d['types'], (bool) $d['dry'], (int) $request->user('admin')?->getKey() ?: null));
    }

    public function step(int $run): JsonResponse
    {
        try {
            return response()->json(KeywordSync::step($run));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }
    }

    public function undo(): JsonResponse
    {
        $run = KeywordSync::undo();

        return $run === null
            ? response()->json(['message' => 'There is no sync to undo.'], 404)
            : response()->json($run);
    }

    /** Display names for one page of rows: one query per type present. */
    private static function names($rows): array
    {
        $ids = [];
        foreach ($rows as $r) {
            $ids[$r->entity_type][] = $r->entity_id;
        }

        $out = [];
        $map = ['product' => [Product::class, 'name'], 'category' => [Category::class, 'name'], 'brand' => [Brand::class, 'name'], 'page' => [Page::class, 'title'], 'post' => [Post::class, 'title']];
        foreach ($ids as $type => $list) {
            if (isset($map[$type])) {
                [$cls, $col] = $map[$type];
                foreach ($cls::query()->whereIn('id', array_filter($list, 'ctype_digit'))->pluck($col, 'id') as $id => $name) {
                    $out[$type.':'.$id] = (string) $name;
                }
            }
            if ($type === 'collection' || $type === 'page') {
                foreach ($list as $id) {
                    $out[$type.':'.$id] ??= ucwords(str_replace('-', ' ', $id));
                }
            }
        }

        return $out;
    }
}

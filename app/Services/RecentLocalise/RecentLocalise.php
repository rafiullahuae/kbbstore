<?php

declare(strict_types=1);

namespace App\Services\RecentLocalise;

use App\Models\Product;
use App\Models\Translation;
use App\Services\ConcernSync\ConcernSync;
use App\Services\Translation\TranslationStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Catalog -> Translate & categorise: the newest products, into Arabic and into
 * the skin-concern collections. (Lane LT)
 *
 * THE SCOPE is "newest first" exactly as the shop's own Newest sort and New
 * Arrivals rail read it (GridSections 'newest': created_at desc, then id
 * desc), published products only: the newest N, or those added within the
 * last X days.
 *
 * THE RULES IT KEEPS, both MachineTranslationRunner's:
 *  1. machine output is a DRAFT. TranslationStore::map() reads published rows
 *     only, so nothing here reaches a shopper until approve() runs, which only
 *     the owner's "Approve all" (or `--approve` on the command) calls;
 *  2. a field with ANY translation row -- draft or published, typed or
 *     machine -- is never re-sent and never overwritten by the run. approve()
 *     publishes drafts only; a published row is not touched.
 *
 * Unlike MachineTranslationRunner it DOES send HTML fields: every answer has
 * to pass HtmlStructure (identical tag/attribute sequence, numbers kept) or it
 * is rejected and reported, never written.
 *
 * Cost is flat in the catalogue: report() is four queries whatever the scope
 * size (products, translations, concern links, concern categories).
 */
final class RecentLocalise
{
    public const LOCALE = 'ar';

    public const GROUP = 'products';

    /** The product fields with an Arabic slot. TranslationEstimate::CONTENT[Product]. */
    public const FIELDS = ['name', 'short_description', 'description', 'ingredients', 'how_to_use'];

    public const DEFAULT_COUNT = 100;

    public const MAX_COUNT = 500;

    /** Source characters one press of Translate (one step) sends: three requests in parallel. */
    public const STEP_CHARS = 18000;

    /** Items rejected by the checks, remembered so a run does not re-buy them. */
    private const REJECTED_KEY = 'kbb.lt.rejected';

    /** USD per million tokens [input, output], list prices; an ESTIMATE. */
    public const PRICES = [
        'claude-sonnet-5-5' => [2.0, 10.0],
        'claude-opus-5-5' => [4.0, 20.0],
        'claude-haiku-5-5' => [0.10, 0.50],
    ];

    /**
     * The scoped product ids, newest first.
     *
     * @return list<int>
     */
    public static function scope(int $count = self::DEFAULT_COUNT, ?int $days = null): array
    {
        $count = max(1, min($count, self::MAX_COUNT));

        $q = Product::query()->where('status', 'publish');

        if ($days !== null && $days > 0) {
            $q->where('created_at', '>=', now()->subDays(min($days, 3650)));
        }

        return $q->orderByDesc('created_at')->orderByDesc('id')->limit($count)
            ->pluck('id')->map(static fn ($v) => (int) $v)->all();
    }

    /**
     * What each scoped product lacks: per field 'none' (no English), 'missing',
     * 'draft', 'stale' (a draft whose English changed since) or 'published';
     * and which concern collections it is in.
     *
     * @param  list<int>  $ids
     */
    public static function report(array $ids): array
    {
        if ($ids === []) {
            return ['products' => [], 'totals' => self::totals([])];
        }

        $products = Product::query()->whereIn('id', $ids)
            ->select(array_merge(['id', 'name', 'slug', 'brand_id', 'image', 'created_at'], array_diff(self::FIELDS, ['name'])))
            ->with('brand:id,name')->get()->keyBy('id');

        $rows = self::translationRows($ids);
        $concerns = ConcernSync::concerns();
        $links = self::concernLinks($ids, $concerns);

        $out = [];
        foreach ($ids as $id) {
            $p = $products[$id] ?? null;
            if ($p === null) {
                continue;
            }

            $fields = [];
            foreach (self::FIELDS as $f) {
                $fields[$f] = self::state((string) ($p->getAttribute($f) ?? ''), $rows[$id][$f] ?? null);
            }

            $out[] = [
                'id' => $id,
                'name' => ConcernSync::plain((string) $p->name, 300),
                'brand' => $p->brand ? ConcernSync::plain((string) $p->brand->name, 120) : '',
                'image' => self::safeImage((string) ($p->image ?? '')),
                'added' => $p->created_at?->format('Y-m-d'),
                'fields' => $fields,
                'concerns' => $links[$id] ?? [],
            ];
        }

        return ['products' => $out, 'totals' => self::totals($out)];
    }

    /**
     * The fields the run would send: English with letters in it, no
     * translation row of any status, not rejected for this same English.
     *
     * @param  list<int>  $ids
     * @return list<array{key:string, item_id:int, field:string, english:string}>
     */
    public static function pending(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = self::translationRows($ids);
        $rejected = self::rejected();
        $products = Product::query()->whereIn('id', $ids)->select(array_merge(['id'], self::FIELDS))->get()->keyBy('id');

        $out = [];
        foreach ($ids as $id) {
            $p = $products[$id] ?? null;
            if ($p === null) {
                continue;
            }

            foreach (self::FIELDS as $f) {
                $english = (string) ($p->getAttribute($f) ?? '');
                if (! self::hasWords($english) || isset($rows[$id][$f])) {
                    continue;
                }

                $key = $id.'.'.$f;
                if (isset($rejected[$key]) && $rejected[$key]['hash'] === sha1($english)) {
                    continue;
                }

                $out[] = ['key' => $key, 'item_id' => $id, 'field' => $f, 'english' => $english];
            }
        }

        return $out;
    }

    /**
     * What the run would cost, BEFORE anything is spent. Tokens are estimated
     * from characters (English ~3.5 a token in; Arabic ~1.8 a token out, plus
     * a quarter for the model's thinking at low effort, plus the instructions
     * on every request) and priced at list rates. The Anthropic console is
     * the authority; the screen says "estimate".
     *
     * @param  list<array{english:string, field:string}>  $pending
     */
    public static function estimate(array $pending, string $model): array
    {
        $tooLong = 0;
        $send = [];
        foreach ($pending as $p) {
            if (mb_strlen($p['english'], 'UTF-8') > ClaudeTranslator::MAX_FIELD_CHARS) {
                $tooLong++;
                continue;
            }
            $send[] = $p + ['key' => ''];
        }

        $chars = array_sum(array_map(static fn ($p) => mb_strlen($p['english'], 'UTF-8'), $send));
        $batches = count(ClaudeTranslator::batches($send));
        $in = (int) ceil($chars / 3.5) + $batches * 700;
        $out = (int) ceil($chars / 1.8 * 1.25);
        [$pi, $po] = self::PRICES[$model] ?? self::PRICES['claude-sonnet-5-5'];

        return [
            'fields' => count($send),
            'characters' => $chars,
            'requests' => $batches,
            'input_tokens' => $in,
            'output_tokens' => $out,
            'usd' => round($in / 1e6 * $pi + $out / 1e6 * $po, 2),
            'model' => $model,
            'too_long' => $tooLong,
        ];
    }

    /**
     * One step of the run: up to STEP_CHARS of pending fields, translated,
     * checked and stored as DRAFTS. The screen presses this again until
     * `remaining` is 0 or the owner presses Stop; the command loops it. A
     * stopped run resumes where it stopped, because pending() is read from
     * the database each time.
     *
     * @param  list<int>  $ids
     */
    public static function step(array $ids, string $key, string $model): array
    {
        $pending = self::pending($ids);
        $take = [];
        $chars = 0;
        $tooLong = [];

        foreach ($pending as $p) {
            $len = mb_strlen($p['english'], 'UTF-8');
            if ($len > ClaudeTranslator::MAX_FIELD_CHARS) {
                $tooLong[$p['key']] = $p;
                continue;
            }
            if ($take !== [] && $chars + $len > self::STEP_CHARS) {
                break;
            }
            $take[] = $p;
            $chars += $len;
        }

        $byKey = [];
        foreach ($take as $p) {
            $byKey[$p['key']] = $p;
        }

        $got = ClaudeTranslator::translate(ClaudeTranslator::batches($take), $key, $model);

        foreach ($got['ok'] as $k => $arabic) {
            $p = $byKey[$k];
            TranslationStore::put(
                self::LOCALE, self::GROUP, $p['item_id'], $p['field'], $arabic,
                Translation::STATUS_DRAFT, Translation::SOURCE_MACHINE, englishSource: $p['english'],
            );
        }

        $rejected = [];
        foreach ($got['rejected'] as $k => $why) {
            $rejected[$k] = ['why' => $why, 'hash' => sha1($byKey[$k]['english'])];
        }
        foreach ($tooLong as $k => $p) {
            $rejected[$k] = ['why' => 'longer than '.number_format(ClaudeTranslator::MAX_FIELD_CHARS).' characters: type this one by hand', 'hash' => sha1($p['english'])];
        }
        self::remember($rejected);

        $names = $rejected === [] ? [] : Product::query()->whereIn('id', array_unique(array_map(static fn ($k) => (int) $k, array_keys($rejected))))->pluck('name', 'id');
        $report = [];
        foreach ($rejected as $k => $r) {
            [$pid, $field] = explode('.', $k, 2);
            $report[] = ['product_id' => (int) $pid, 'name' => ConcernSync::plain((string) ($names[(int) $pid] ?? ''), 200), 'field' => $field, 'why' => $r['why']];
        }

        return [
            'translated' => count($got['ok']),
            'rejected' => $report,
            'sent_characters' => $chars,
            // A failed batch sent nothing usable and leaves its fields pending;
            // the screen stops the run on an error rather than re-buying it.
            'error' => $got['error'],
            'remaining' => count(self::pending($ids)),
        ];
    }

    /**
     * Side by side, for the review screen: English and Arabic per field.
     *
     * @param  list<int>  $ids  one page of the scope
     */
    public static function review(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $products = Product::query()->whereIn('id', $ids)->select(array_merge(['id', 'slug', 'image'], self::FIELDS))->get()->keyBy('id');
        $rows = self::translationRows($ids, true);

        $out = [];
        foreach ($ids as $id) {
            $p = $products[$id] ?? null;
            if ($p === null) {
                continue;
            }

            $fields = [];
            foreach (self::FIELDS as $f) {
                $english = (string) ($p->getAttribute($f) ?? '');
                $row = $rows[$id][$f] ?? null;
                if (! self::hasWords($english) && $row === null) {
                    continue;
                }
                $fields[] = [
                    'field' => $f,
                    'html' => TranslationStore::isRichField(self::GROUP, $f),
                    'en' => $english,
                    'ar' => $row?->value,
                    'state' => self::state($english, $row),
                    'source' => $row?->source,
                ];
            }

            $out[] = ['id' => $id, 'slug' => (string) $p->slug, 'image' => self::safeImage((string) ($p->image ?? '')), 'fields' => $fields];
        }

        return $out;
    }

    /**
     * The owner's own edit from the review screen. Typed by a human, so it is
     * `manual`; a draft stays a draft until approved, a published row stays
     * published (he is correcting what is live). Rich fields are sanitised by
     * TranslationStore::put(), as every writer's are.
     */
    public static function saveEdit(int $productId, string $field, string $value): ?Translation
    {
        if (! in_array($field, self::FIELDS, true)) {
            return null;
        }

        $p = Product::query()->select(array_merge(['id'], self::FIELDS))->find($productId);
        if ($p === null) {
            return null;
        }

        $row = Translation::query()->where(['locale' => self::LOCALE, 'group' => self::GROUP, 'item_id' => $productId, 'field' => $field])->first();
        $status = $row?->status === Translation::STATUS_PUBLISHED ? Translation::STATUS_PUBLISHED : Translation::STATUS_DRAFT;

        return TranslationStore::put(
            self::LOCALE, self::GROUP, $productId, $field, $value, $status, Translation::SOURCE_MANUAL,
            englishSource: (string) ($p->getAttribute($field) ?? ''),
        );
    }

    /**
     * Publish the DRAFTS of exactly these products. A published row is not
     * touched (never overwrite what a human approved); a draft whose English
     * changed since it was translated is skipped and counted, because it
     * translates words the product no longer says.
     *
     * Saved one row at a time through the model, so Translation's hook does
     * what every other writer gets: the store's caches are flushed and an
     * Arabic name enters the Arabic search index.
     *
     * @param  list<int>  $ids
     * @return array{published:int, stale:int, products:int}
     */
    public static function approve(array $ids): array
    {
        if ($ids === []) {
            return ['published' => 0, 'stale' => 0, 'products' => 0];
        }

        $english = Product::query()->whereIn('id', $ids)->select(array_merge(['id'], self::FIELDS))->get()->keyBy('id');
        $drafts = Translation::query()->where('locale', self::LOCALE)->where('group', self::GROUP)
            ->whereIn('item_id', $ids)->whereIn('field', self::FIELDS)
            ->where('status', Translation::STATUS_DRAFT)->get();

        $published = 0;
        $stale = 0;
        $touched = [];

        DB::transaction(function () use ($drafts, $english, &$published, &$stale, &$touched) {
            foreach ($drafts as $row) {
                $p = $english[(int) $row->item_id] ?? null;
                if ($p === null || trim((string) $row->value) === '') {
                    continue;
                }
                if ($row->isStaleAgainst((string) ($p->getAttribute($row->field) ?? ''))) {
                    $stale++;
                    continue;
                }

                $row->status = Translation::STATUS_PUBLISHED;
                $row->reviewed_at = now();
                $row->save();
                $published++;
                $touched[(int) $row->item_id] = true;
            }
        });

        return ['published' => $published, 'stale' => $stale, 'products' => count($touched)];
    }

    /** Forget the rejected list, so "Retry rejected" sends them again. */
    public static function forgetRejected(): void
    {
        Cache::forget(self::REJECTED_KEY);
    }

    /** @return array<string, array{why:string, hash:string}> */
    public static function rejected(): array
    {
        $r = Cache::get(self::REJECTED_KEY);

        return is_array($r) ? $r : [];
    }

    /** @param list<int> $ids  @return array<int, array<string, Translation>> */
    private static function translationRows(array $ids, bool $withValue = false): array
    {
        $cols = ['item_id', 'field', 'status', 'source', 'source_hash'];
        if ($withValue) {
            $cols[] = 'value';
        }

        $out = [];
        foreach (Translation::query()->where('locale', self::LOCALE)->where('group', self::GROUP)
            ->whereIn('item_id', $ids)->whereIn('field', self::FIELDS)->get($cols) as $row) {
            $out[(int) $row->item_id][(string) $row->field] = $row;
        }

        return $out;
    }

    /** @return array<int, list<string>> product id => concern slugs */
    private static function concernLinks(array $ids, array $concerns): array
    {
        if ($concerns === []) {
            return [];
        }

        $slugById = [];
        foreach ($concerns as $slug => $c) {
            $slugById[$c['id']] = $slug;
        }

        $out = [];
        foreach (DB::table('category_product')->whereIn('product_id', $ids)->whereIn('category_id', array_keys($slugById))
            ->get(['product_id', 'category_id']) as $r) {
            $out[(int) $r->product_id][] = $slugById[(int) $r->category_id];
        }

        return $out;
    }

    private static function state(string $english, ?Translation $row): string
    {
        if ($row === null) {
            return self::hasWords($english) ? 'missing' : 'none';
        }
        if ($row->status === Translation::STATUS_PUBLISHED) {
            return 'published';
        }

        return $row->isStaleAgainst($english) ? 'stale' : 'draft';
    }

    private static function totals(array $rows): array
    {
        $t = ['products' => count($rows), 'no_concern' => 0, 'missing' => array_fill_keys(self::FIELDS, 0), 'draft' => 0, 'published' => 0];
        foreach ($rows as $r) {
            if ($r['concerns'] === []) {
                $t['no_concern']++;
            }
            foreach ($r['fields'] as $f => $s) {
                if ($s === 'missing') {
                    $t['missing'][$f]++;
                } elseif ($s === 'draft' || $s === 'stale') {
                    $t['draft']++;
                } elseif ($s === 'published') {
                    $t['published']++;
                }
            }
        }

        return $t;
    }

    private static function hasWords(string $english): bool
    {
        return preg_match('/\p{L}/u', strip_tags($english)) === 1;
    }

    private static function safeImage(string $image): string
    {
        return preg_match('#^(https?://|/)[^\s"\'<>]*$#i', $image) === 1 && ! str_starts_with($image, '//') ? $image : '';
    }

    private static function remember(array $rejected): void
    {
        if ($rejected === []) {
            return;
        }

        Cache::put(self::REJECTED_KEY, array_slice($rejected + self::rejected(), 0, 5000, true), now()->addDays(30));
    }
}

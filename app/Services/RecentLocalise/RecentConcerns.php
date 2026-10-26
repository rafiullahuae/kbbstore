<?php

declare(strict_types=1);

namespace App\Services\RecentLocalise;

use App\Models\Product;
use App\Services\ConcernSync\ClaudeConcernSuggester;
use App\Services\ConcernSync\ConcernRules;
use App\Services\ConcernSync\ConcernSync;

/**
 * Skin-concern suggestions for the NEWEST products, whether or not they are
 * already in a concern collection. (Lane LT)
 *
 * "Shop by skin" on this shop is the seven Shop by Skin Concern collections
 * (ConcernSync::CONCERNS -- ordinary categories, drawn on the homepage by
 * HomeConcerns' chips and ShopByConcern's tabs). Skin TYPE exists only as an
 * answer in the skin quiz, never on a product; two of the seven ARE skin
 * types (oily-skin, redness-sensitive-skin), so no second mechanism is built.
 *
 * Research per product: name, brand, the shop's own category names (product
 * type), short description, ingredients and description, as plain text capped
 * at ConcernSync::TEXT_CAP. ClaudeConcernSuggester validates every slug and id;
 * without a key, or when a batch fails, ConcernRules answers, labelled.
 *
 * ONLY ADDS: apply() is ConcernSync::apply(), which never removes a link and
 * never re-orders one, so a collection the owner curated by hand keeps every
 * product he put there. A suggestion for a collection the product is already
 * in is dropped before it is shown.
 */
final class RecentConcerns
{
    /** Products per Suggest press: two Claude batches. */
    public const PAGE = ConcernSync::PAGE;

    /**
     * @param  list<int>  $ids  at most PAGE
     * @return array{engine:string, note:?string, products:list<array<string,mixed>>}
     */
    public static function suggest(array $ids, array $concerns, ?string $key, string $model, bool $rulesOnly = false): array
    {
        $ids = array_slice(array_values(array_unique(array_map('intval', $ids))), 0, self::PAGE);
        $products = self::products($ids, $concerns);

        $byClaude = [];
        $failed = [];
        $note = $key === null ? 'No Claude API key saved, so these are rule-based suggestions.' : null;

        if ($products !== [] && $key !== null && ! $rulesOnly) {
            @set_time_limit(200);
            $got = ClaudeConcernSuggester::suggest(array_values($products), $concerns, $key, $model);
            $byClaude = $got['suggestions'];
            $failed = array_flip($got['failed']);
            if ($got['error'] !== null) {
                $note = 'Claude could not answer ('.$got['error'].'), so some suggestions are rule-based.';
            }
        } elseif ($rulesOnly) {
            $note = 'Rule-based suggestions, as asked.';
        }

        $rows = [];
        foreach ($products as $id => $p) {
            $fromClaude = isset($byClaude[$id]) && ! isset($failed[$id]);
            $suggested = $fromClaude ? $byClaude[$id] : ConcernRules::suggest($p, $concerns);
            $have = array_flip($p['concerns']);
            $rows[] = [
                'id' => $id,
                'name' => $p['name'],
                'brand' => $p['brand'],
                'type' => $p['type'],
                'concerns' => $p['concerns'],
                'source' => $fromClaude ? 'claude' : 'rules',
                'suggestions' => array_values(array_filter($suggested, static fn ($s) => ! isset($have[$s['slug']]))),
            ];
        }

        $engine = $byClaude === [] ? 'rules' : 'claude';

        return ['engine' => $engine, 'note' => $note, 'products' => $rows];
    }

    /** @return array<int, array<string,mixed>> in the order asked */
    public static function products(array $ids, array $concerns): array
    {
        if ($ids === []) {
            return [];
        }

        $concernIds = array_flip(array_column($concerns, 'id'));
        $slugById = [];
        foreach ($concerns as $slug => $c) {
            $slugById[$c['id']] = $slug;
        }

        $rows = Product::query()->whereIn('id', $ids)
            ->select(['id', 'name', 'brand_id', 'short_description', 'description', 'ingredients'])
            ->with(['categories:id,name', 'brand:id,name'])->get()->keyBy('id');

        $out = [];
        foreach ($ids as $id) {
            $p = $rows[$id] ?? null;
            if ($p === null) {
                continue;
            }

            $types = [];
            $in = [];
            foreach ($p->categories as $c) {
                if (isset($concernIds[$c->id])) {
                    $in[] = $slugById[$c->id];
                } else {
                    $types[] = ConcernSync::plain((string) $c->name, 60);
                }
            }

            $out[$id] = [
                'id' => $id,
                'name' => ConcernSync::plain((string) $p->name, 300),
                'brand' => $p->brand ? ConcernSync::plain((string) $p->brand->name, 120) : '',
                'type' => implode(', ', array_slice($types, 0, 6)),
                'short' => ConcernSync::plain((string) $p->short_description),
                'description' => ConcernSync::plain((string) $p->description),
                'ingredients' => ConcernSync::plain((string) ($p->ingredients ?? '')),
                'concerns' => $in,
            ];
        }

        return $out;
    }

    /** ConcernSync::apply(): only adds. */
    public static function apply(array $concerns, array $links): array
    {
        return ConcernSync::apply($concerns, $links);
    }
}

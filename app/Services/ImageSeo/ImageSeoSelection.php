<?php

declare(strict_types=1);

namespace App\Services\ImageSeo;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * What the owner has selected on the Find tab, resolved on the SERVER. (Lane IS2)
 *
 * The owner: "the pagination should not be there, or if it's there, the select
 * mean all results should be selected, and the visit between pagination should
 * not be able to un-select in case i un-select anything."
 *
 * Two shapes, and the browser sends only the shape, never a list it built:
 *
 *   mode "all"  every product matching filter F (the Find tab's own search,
 *               ImageSeo::query()), EXCEPT the ids he unticked. 5,000 matches
 *               cost the browser one small object, and paging cannot lose or
 *               re-tick anything because a page is not where the selection
 *               lives.
 *   mode "ids"  the products he ticked one by one, from any search.
 *
 * plus `only`: per product, the pictures he left ticked when he unticked some.
 *
 * Counts shown in the bar and the ids a run works on both come from here, by a
 * query chunked on the primary key. Nothing the browser counted is trusted.
 *
 * FILTER KEYS ARE CLOSED: `array:q,brand,category,filter` refuses any other key,
 * every value is clamped by its rule, and the term reaches SQL only as a bound
 * LIKE parameter (ImageSeo::query()).
 */
final class ImageSeoSelection
{
    /** The most products one selection may name: a run's items row stays bounded. */
    public const MAX = 10000;

    private const CHUNK = 1000;

    /** Products the filter matched before the exceptions (mode "all"), after ids(). */
    public int $matched = 0;

    /** @param array<int, list<string>> $only */
    private function __construct(
        private readonly string $mode,
        private readonly array $ids,
        private readonly array $filter,
        private readonly array $except,
        private readonly array $only,
    ) {
    }

    /** @return array<string, array<int, mixed>> rules for a request's `selection` */
    public static function rules(): array
    {
        return [
            'selection' => ['required', 'array:mode,ids,filter,except,only'],
            'selection.mode' => ['required', 'in:ids,all'],
            'selection.ids' => ['nullable', 'array', 'max:'.self::MAX],
            'selection.ids.*' => ['integer', 'min:1'],
            'selection.filter' => ['nullable', 'array:q,brand,category,filter'],
            'selection.filter.q' => ['nullable', 'string', 'max:120'],
            'selection.filter.brand' => ['nullable', 'integer', 'min:0', 'max:2147483647'],
            'selection.filter.category' => ['nullable', 'integer', 'min:0', 'max:2147483647'],
            'selection.filter.filter' => ['nullable', 'in:'.implode(',', array_filter(ImageSeo::FILTERS))],
            'selection.except' => ['nullable', 'array', 'max:'.self::MAX],
            'selection.except.*' => ['integer', 'min:1'],
            'selection.only' => ['nullable', 'array', 'max:500'],
            'selection.only.*.p' => ['required', 'integer', 'min:1'],
            'selection.only.*.rels' => ['required', 'array', 'min:1', 'max:60'],
            'selection.only.*.rels.*' => ['string', 'max:600'],
        ];
    }

    /** @param array<string, mixed> $data the validated `selection` */
    public static function from(array $data): self
    {
        $ints = static fn ($list) => array_values(array_unique(array_map('intval', is_array($list) ? $list : [])));
        $filter = is_array($data['filter'] ?? null) ? $data['filter'] : [];
        $only = [];

        foreach ((array) ($data['only'] ?? []) as $entry) {
            $only[(int) $entry['p']] = array_values(array_unique(array_map('strval', (array) $entry['rels'])));
        }

        return new self(
            ($data['mode'] ?? 'ids') === 'all' ? 'all' : 'ids',
            $ints($data['ids'] ?? []),
            [
                'q' => trim(mb_substr((string) ($filter['q'] ?? ''), 0, 120)),
                'brand' => max(0, (int) ($filter['brand'] ?? 0)),
                'category' => max(0, (int) ($filter['category'] ?? 0)),
                'filter' => in_array($filter['filter'] ?? '', ImageSeo::FILTERS, true) ? (string) ($filter['filter'] ?? '') : '',
            ],
            array_flip($ints($data['except'] ?? [])),
            $only,
        );
    }

    /**
     * The product ids, in id order, that exist and are selected. One query per
     * thousand products; null when the selection is larger than MAX.
     *
     * @return list<int>|null
     */
    public function ids(): ?array
    {
        $out = [];

        if ($this->mode === 'all') {
            $over = false;

            ImageSeo::query($this->filter)->select('products.id')
                ->chunkById(self::CHUNK, function ($rows) use (&$out, &$over): bool {
                    foreach ($rows as $row) {
                        $this->matched++;

                        if (! isset($this->except[(int) $row->id])) {
                            $out[] = (int) $row->id;
                        }
                    }

                    $over = count($out) > self::MAX;

                    return ! $over;
                }, 'products.id', 'id');

            return $over ? null : $out;
        }

        if (count($this->ids) > self::MAX) {
            return null;
        }

        foreach (array_chunk($this->ids, self::CHUNK) as $chunk) {
            foreach (Product::query()->whereIn('id', $chunk)->pluck('id') as $id) {
                $this->matched++;

                if (! isset($this->except[(int) $id])) {
                    $out[] = (int) $id;
                }
            }
        }

        sort($out);

        return $out;
    }

    /** @return array<int, list<string>> product id => the pictures to act on */
    public function only(): array
    {
        return $this->only;
    }

    /**
     * Job items for these ids: `only` carried for the products that have it.
     *
     * @param  list<int>  $ids
     * @return list<array{p: int, only?: list<string>}>
     */
    public function items(array $ids): array
    {
        return array_map(fn (int $id) => isset($this->only[$id]) ? ['p' => $id, 'only' => $this->only[$id]] : ['p' => $id], $ids);
    }

    /**
     * The bar's numbers: products, and the pictures on this shop among them
     * (what a rename can act on). Two queries per 500 products.
     *
     * @param  list<int>  $ids
     */
    public function pictures(array $ids): int
    {
        $total = 0;

        foreach (array_chunk($ids, 500) as $chunk) {
            $variants = DB::table('product_variants')->whereIn('product_id', $chunk)
                ->whereNotNull('image')->where('image', '!=', '')
                ->get(['product_id', 'image'])->groupBy('product_id');

            foreach (Product::query()->whereIn('id', $chunk)->get(['id', 'image', 'images']) as $product) {
                $urls = array_merge([$product->image], is_array($product->images) ? $product->images : [], $variants->get($product->id, collect())->pluck('image')->all());
                $rels = [];

                foreach ($urls as $url) {
                    if (is_string($url) && ($rel = ImageFiles::local($url)) !== null) {
                        $rels[$rel] = true;
                    }
                }

                $only = $this->only[(int) $product->id] ?? null;
                $total += $only === null ? count($rels) : count(array_intersect_key($rels, array_flip($only)));
            }
        }

        return $total;
    }
}

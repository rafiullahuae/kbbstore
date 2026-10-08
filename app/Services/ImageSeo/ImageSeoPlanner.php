<?php

declare(strict_types=1);

namespace App\Services\ImageSeo;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * What Catalog → Image SEO would do to each picture of some products. (Lane IR)
 *
 * Used three ways, and it is the SAME code each time, which is the point: the
 * Find tab shows it, the Rename tab's preview shows it, and the job step
 * re-plans each product immediately before renaming it. So what the owner saw
 * is what happens — and if the catalogue moved in between (a picture added in
 * the product editor), the step acts on the catalogue as it is now, not on a
 * plan that has gone stale.
 *
 * QUERY COST IS FLAT IN THE NUMBER OF PRODUCTS: variants, library rows, usage
 * counts and ledger rows are each ONE query for the whole set, whether it is
 * three products or forty (ImageSeoQueryBudgetTest pins it).
 */
final class ImageSeoPlanner
{
    /** @var array<string, true> paths already promised to an earlier product in this plan */
    private array $claimed = [];

    public function __construct(
        private readonly string $strategy = ImageNamer::STRATEGY_VARIATIONS,
        private readonly bool $includeShared = false,
    ) {
    }

    /**
     * @param  iterable<Product>  $products  with `brand` and `category` loaded
     * @param  array<int, list<string>|null>  $only  product id => the image paths to act on (null = all)
     * @return list<array<string, mixed>>
     */
    public function plan(iterable $products, array $only = []): array
    {
        $products = collect($products)->values();

        if ($products->isEmpty()) {
            return [];
        }

        $variantImages = DB::table('product_variants')
            ->whereIn('product_id', $products->pluck('id')->all())
            ->whereNotNull('image')->where('image', '!=', '')
            ->orderBy('position')->orderBy('id')
            ->get(['product_id', 'image'])
            ->groupBy('product_id');

        // Every picture of every product, in gallery order.
        $lists = [];
        $rels = [];

        foreach ($products as $product) {
            $seen = [];
            $list = [];
            $urls = array_merge([$product->image], is_array($product->images) ? $product->images : []);

            foreach ($urls as $i => $url) {
                if (is_string($url) && trim($url) !== '' && ! isset($seen[$url])) {
                    $seen[$url] = true;
                    $list[] = ['url' => $url, 'role' => $list === [] ? 'main' : 'gallery'];
                }
            }

            foreach ($variantImages->get($product->id, collect()) as $v) {
                if (! isset($seen[$v->image])) {
                    $seen[$v->image] = true;
                    $list[] = ['url' => (string) $v->image, 'role' => 'variant'];
                }
            }

            $relSeen = [];

            foreach ($list as $at => &$image) {
                $image['rel'] = ImageFiles::local($image['url']);
                $image['exists'] = $image['rel'] !== null && ImageFiles::exists($image['rel']);

                // The same FILE written two ways (absolute and root-relative):
                // renamed once, with the first; the rewrite reaches both spellings.
                $image['same_as'] = $image['rel'] !== null ? ($relSeen[$image['rel']] ?? null) : null;

                if ($image['rel'] !== null && $image['same_as'] === null) {
                    $relSeen[$image['rel']] = $at + 1;
                }

                if ($image['rel'] !== null) {
                    $rels[$image['rel']] = true;
                }
            }
            unset($image);

            $lists[$product->id] = $list;
        }

        $scored = self::hasScore();
        $media = $rels === [] ? collect() : DB::table('media')
            ->whereIn('path', array_keys($rels))
            ->get($scored ? ['id', 'path', 'seo_score', 'seo_renamed_at'] : ['id', 'path'])
            ->keyBy('path');

        // WebP twins for every picture at once (one query, not one per image).
        if ($rels !== [] && ImageFiles::hasTable('webp_conversions')) {
            $keys = array_keys($rels);
            $rows = DB::table('webp_conversions')
                ->where(fn ($q) => $q->whereIn('from_path', $keys)->orWhereIn('to_path', $keys))
                ->get(['from_path', 'to_path']);

            foreach ($keys as $rel) {
                $this->twins[$rel] = null;
            }

            foreach ($rows as $row) {
                foreach ([[(string) $row->from_path, (string) $row->to_path], [(string) $row->to_path, (string) $row->from_path]] as [$a, $b]) {
                    if (isset($rels[$a]) && $b !== '' && $b !== $a && \dirname($a) === \dirname($b) && ImageFiles::exists($b)) {
                        $this->twins[$a] = $b;
                    }
                }
            }
        }

        $shared = $media->isEmpty() || ! ImageFiles::hasTable('media_usages') ? collect() : DB::table('media_usages')
            ->whereIn('media_id', $media->pluck('id')->all())
            ->where('owner_type', 'product')
            ->selectRaw('media_id, count(distinct owner_id) as n')
            ->groupBy('media_id')
            ->pluck('n', 'media_id');

        // Ledger: where a missing file went, and every name an old file used
        // to have (a name Google has indexed for another picture is not reused).
        $candidates = $this->candidates($products, $lists);
        $ledger = ! ImageFiles::hasTable('image_renames') ? collect() : collect(array_chunk(array_keys($rels + $candidates), 4000))
            ->flatMap(fn (array $chunk) => DB::table('image_renames')->whereIn('old_path', $chunk)->where('status', 'done')
                ->orderBy('id')->get(['old_path', 'new_path']))
            ->keyBy('old_path');

        $out = [];

        foreach ($products as $product) {
            $out[] = $this->product($product, $lists[$product->id], $media, $shared, $ledger, $only[$product->id] ?? null);
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $list
     * @return array<string, mixed>
     */
    private function product(Product $product, array $list, $media, $shared, $ledger, ?array $only): array
    {
        $brand = $product->brand?->name;
        $name = (string) $product->name;
        $total = count($list);
        $mine = [];

        foreach ($list as $image) {
            if ($image['rel'] !== null) {
                $mine[$image['rel']] = true;
            }
        }

        $taken = function (string $stem, int $i) use ($list, $ledger, $mine): bool {
            $image = $list[$i] ?? null;

            if ($image === null || $image['rel'] === null) {
                return false;
            }

            foreach ($this->targetsFor($image['rel'], $stem) as $target) {
                if ($target === $image['rel'] || $target === $this->twinOf($image['rel'])) {
                    continue;
                }

                if (isset($this->claimed[$target]) || isset($ledger[$target]) || is_file(public_path($target))) {
                    return true;
                }
            }

            return false;
        };

        $stems = ImageNamer::names($brand, $name, $total, $this->strategy, $taken);
        $alts = [];

        foreach ($list as $i => $image) {
            $alts[$i] = $product->altFor($image['url'], $i, $total);
        }

        $images = [];
        $scores = [];

        foreach ($list as $i => $image) {
            $rel = $image['rel'];
            $row = $rel !== null ? $media->get($rel) : null;
            $stem = $stems[$i] ?? null;
            $written = is_array($product->image_alts) && trim((string) ($product->image_alts[$image['url']] ?? '')) !== '';
            $others = array_values(array_diff_key($alts, [$i => true]));
            $now = ImageScore::score($rel ?? (string) parse_url($image['url'], PHP_URL_PATH), $brand, $name, $alts[$i], $written, $others);

            $plan = [
                'index' => $i,
                'role' => $image['role'],
                'url' => $image['url'],
                'rel' => $rel,
                'filename' => basename((string) ($rel ?? parse_url($image['url'], PHP_URL_PATH))),
                'media_id' => $row?->id,
                'shared' => $row ? max(1, (int) ($shared[$row->id] ?? 1)) : 1,
                'renamed' => $row !== null && ! empty($row->seo_renamed_at ?? null),
                'alt' => $alts[$i],
                'alt_written' => $written,
                'score' => $now['score'],
                'ten' => $now['ten'],
                'tick' => $now['tick'],
                'lost' => $now['lost'],
                'reasons' => ImageScore::reasons($now['lost']),
                'thumb' => $this->thumb($image['url']),
                'proposed' => null,
                'proposed_rel' => null,
                'proposed_url' => null,
                'score_after' => null,
                'action' => 'skip',
                'reason' => null,
            ];

            $scores[] = $now['score'];

            if (($image['same_as'] ?? null) !== null) {
                $plan['reason'] = 'the same file as picture '.$image['same_as'].' — renamed with it';
            } elseif ($only !== null && $rel !== null && ! in_array($rel, $only, true)) {
                $plan['reason'] = 'not selected';
            } elseif ($rel === null) {
                $plan['reason'] = 'on another website, not on this shop — bring it across first (Store Import → pictures)';
            } elseif (! $image['exists']) {
                $moved = $ledger[$rel]->new_path ?? null;

                if ($moved !== null && ImageFiles::exists($moved)) {
                    $plan['action'] = 'repoint';
                    $plan['proposed_rel'] = $moved;
                    $plan['proposed'] = basename($moved);
                    $plan['proposed_url'] = self::swapBasename($image['url'], basename($moved));
                    $plan['reason'] = 'already renamed; this product still points at the old name';
                } else {
                    $plan['reason'] = 'the file is missing from the server';
                }
            } elseif ($stem === null) {
                $plan['reason'] = 'the product title has no English words to name it by';
            } elseif ($plan['shared'] > 1 && ! $this->includeShared) {
                $plan['reason'] = 'shared with '.($plan['shared'] - 1).' other product(s) — tick "Include shared pictures" to rename it';
            } else {
                $target = $this->targetsFor($rel, $stem)[0];

                if ($target === $rel) {
                    $plan['action'] = 'ok';
                    $plan['proposed'] = basename($target);
                    $plan['proposed_rel'] = $target;
                    $plan['reason'] = 'already named';
                } else {
                    $plan['action'] = 'rename';
                    $plan['proposed'] = basename($target);
                    $plan['proposed_rel'] = $target;
                    $plan['proposed_url'] = self::swapBasename($image['url'], basename($target));
                    $this->claimed[$target] = true;

                    foreach (array_slice($this->targetsFor($rel, $stem), 1) as $twin) {
                        $this->claimed[$twin] = true;
                    }
                }

                $after = ImageScore::score($target, $brand, $name, $alts[$i], $written, $others);
                $plan['score_after'] = $after['score'];
            }

            $images[] = $plan;
        }

        return [
            'id' => (int) $product->id,
            'name' => $name,
            'brand' => $brand,
            'sku' => $product->sku,
            'slug' => $product->slug,
            'status' => $product->status,
            'category' => $product->category?->name,
            'images' => $images,
            'lowest' => $scores === [] ? null : min($scores),
            'average' => $scores === [] ? null : (int) round(array_sum($scores) / count($scores)),
            'to_rename' => count(array_filter($images, static fn ($p) => in_array($p['action'], ['rename', 'repoint'], true))),
        ];
    }

    /**
     * The new path for the picture, then its WebP twin's new path when it has
     * one: both must be free.
     *
     * @return list<string>
     */
    public function targetsFor(string $rel, string $stem): array
    {
        $dir = \dirname($rel);
        $extension = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
        $out = [$dir.'/'.$stem.'.'.$extension];
        $twin = $this->twinOf($rel);

        if ($twin !== null) {
            $out[] = $dir.'/'.$stem.'.'.strtolower(pathinfo($twin, PATHINFO_EXTENSION));
        }

        return $out;
    }

    /** @var array<string, string|null> */
    private array $twins = [];

    private function twinOf(string $rel): ?string
    {
        if (! array_key_exists($rel, $this->twins)) {
            $siblings = ImageFiles::siblings($rel, $rel);
            $this->twins[$rel] = $siblings[0]['from'] ?? null;
        }

        return $this->twins[$rel];
    }

    /**
     * Every path any product here could be given, so the ledger is asked once.
     *
     * @return array<string, true>
     */
    private function candidates($products, array $lists): array
    {
        $out = [];

        foreach ($products as $product) {
            $list = $lists[$product->id];
            $count = count($list);
            $g = ImageNamer::groups($product->brand?->name, (string) $product->name);
            $stems = ImageNamer::orders($g);
            $first = $stems[0] ?? '';

            for ($n = 2; $n <= max(3, $count + 1); $n++) {
                $stems[] = $first.'-'.$n;
            }

            foreach ($list as $image) {
                if ($image['rel'] === null) {
                    continue;
                }

                $dir = \dirname($image['rel']);
                $extension = strtolower(pathinfo($image['rel'], PATHINFO_EXTENSION));

                foreach ($stems as $stem) {
                    if ($stem !== '') {
                        $out[$dir.'/'.$stem.'.'.$extension] = true;
                    }
                }
            }
        }

        return $out;
    }

    private function thumb(string $url): string
    {
        try {
            $relative = (string) preg_replace('#^[a-z][a-z0-9+.\-]*://[^/]*#i', '', $url);

            if ($relative === '' || ! str_starts_with($relative, '/')) {
                return $url;
            }

            $thumb = \App\Support\ImageVariants::variantUrl($relative, 200);

            return $thumb === $relative ? $url : $thumb;
        } catch (\Throwable) {
            return $url;
        }
    }

    public static function swapBasename(string $url, string $basename): string
    {
        $at = strrpos($url, '/');

        return $at === false ? $basename : substr($url, 0, $at + 1).rawurlencode($basename);
    }

    public static function hasScore(): bool
    {
        try {
            return \Illuminate\Support\Facades\Schema::hasColumn('media', 'seo_score');
        } catch (\Throwable) {
            return false;
        }
    }
}

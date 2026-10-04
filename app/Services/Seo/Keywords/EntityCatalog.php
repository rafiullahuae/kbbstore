<?php

declare(strict_types=1);

namespace App\Services\Seo\Keywords;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Page;
use App\Models\Post;
use App\Models\Product;
use App\Support\ConcernCollections;
use App\Support\ProductTitle;
use App\Support\RoutineConcerns;
use App\Support\UrlScheme;
use Illuminate\Support\Facades\DB;

/**
 * Every page that can carry keywords, read a chunk at a time and reduced to a
 * PROFILE — the handful of facts the composer mixes: its name, brand, product
 * type, key ingredients, concerns and address.
 *
 * Constant queries per chunk whatever the chunk size: products are one select
 * with brand and category eager-loaded (plus translations on Arabic), brands
 * add one grouped query for "what does this brand mostly sell".
 *
 * Each chunk is keyed by an opaque `after` cursor: the last id for tables, an
 * offset for the two short fixed lists (collections, pages).
 */
final class EntityCatalog
{
    public const TYPES = ['product', 'category', 'brand', 'collection', 'page', 'post'];

    public const LABELS = [
        'product' => 'Products', 'category' => 'Categories', 'brand' => 'Brands',
        'collection' => 'Collections', 'page' => 'Pages', 'post' => 'Blog posts',
    ];

    /** The curated listings CollectionController serves, with their addresses. */
    private const COLLECTIONS = [
        'shop' => ['Shop all', '/shop/'],
        'new-in' => ['New In', '/new-in/'],
        'best-sellers' => ['Best Sellers', '/best-sellers/'],
        'super-sale' => ['Super Sale', '/super-sale/'],
        'under-54' => ['Under 54 AED', '/everything-under-54-aed/'],
    ];

    /**
     * @return array{0: list<array>, 1: ?int} profiles, next cursor (null = type finished)
     */
    public static function chunk(string $type, string $locale, int $after, int $limit): array
    {
        return match ($type) {
            'product' => self::products($locale, $after, $limit),
            'category' => self::categories($locale, $after, $limit),
            'brand' => self::brands($locale, $after, $limit),
            'collection' => self::sliceList(self::collectionList($locale), $after, $limit),
            'page' => self::pages($locale, $after, $limit),
            'post' => self::posts($locale, $after, $limit),
            default => [[], null],
        };
    }

    /** How many entities a type has — for the progress bar. */
    public static function count(string $type): int
    {
        return match ($type) {
            'product' => Product::query()->visible()->count(),
            'category' => Category::query()->count(),
            'brand' => Brand::query()->count(),
            'collection' => count(self::COLLECTIONS) + count(ConcernCollections::slugs()),
            'page' => 1 + Page::query()->where('status', 'published')->count(),
            'post' => Post::query()->where('status', 'published')->count(),
            default => 0,
        };
    }

    /* ------------------------------------------------------------------ */

    private static function products(string $locale, int $after, int $limit): array
    {
        $q = Product::query()->visible()
            ->where('id', '>', $after)->orderBy('id')->limit($limit)
            ->select(['id', 'slug', 'name', 'brand_id', 'category_id', 'short_description', 'ingredients', 'routine_concerns'])
            ->with(['brand:id,name', 'category:id,name']);

        if ($locale !== 'en') {
            $q->with('translations');
        }

        $out = [];
        $last = null;
        foreach ($q->get() as $p) {
            $last = (int) $p->id;
            $name = (string) $p->t('name', $locale);
            $brand = (string) ($p->brand?->name ?? '');
            $cat = (string) ($p->category?->name ?? '');
            $facts = Lexicon::recognise(
                $p->name.' '.mb_substr(strip_tags((string) $p->short_description), 0, 400).' '.mb_substr(strip_tags((string) $p->ingredients), 0, 600),
                RoutineConcerns::clean($p->routine_concerns ?? null)
            );
            // WHAT IT IS comes from its own name first and its category second —
            // never from its description, where a moisturiser that "pairs well
            // with your SPF" would otherwise become a sunscreen.
            $facts['kind'] = Lexicon::recognise((string) $p->name)['kind'] ?? Lexicon::recognise($cat)['kind'];
            $out[] = [
                'type' => 'product', 'id' => (string) $p->id, 'locale' => $locale,
                'name' => $name,
                'display' => ProductTitle::full($brand, $name),
                'brand' => $brand,
                'core' => self::core($name, $brand),
                'category' => $cat,
                'path' => UrlScheme::product((string) $p->slug),
                'brand_id' => $p->brand_id ? (int) $p->brand_id : null,
                'category_id' => $p->category_id ? (int) $p->category_id : null,
            ] + $facts;
        }

        return [$out, count($out) < $limit ? null : $last];
    }

    private static function categories(string $locale, int $after, int $limit): array
    {
        $q = Category::query()->where('id', '>', $after)->orderBy('id')->limit($limit)
            ->select(['id', 'slug', 'name', 'path']);
        if ($locale !== 'en') {
            $q->with('translations');
        }

        $out = [];
        $last = null;
        foreach ($q->get() as $c) {
            $last = (int) $c->id;
            $name = (string) $c->t('name', $locale);
            $out[] = [
                'type' => 'category', 'id' => (string) $c->id, 'locale' => $locale,
                'name' => $name, 'display' => $name, 'brand' => '', 'core' => KeywordText::norm($name),
                'category' => $c->name,
                'path' => UrlScheme::collection((string) ($c->path ?: $c->slug)),
            ] + Lexicon::recognise((string) $c->name);
        }

        return [$out, count($out) < $limit ? null : $last];
    }

    private static function brands(string $locale, int $after, int $limit): array
    {
        $q = Brand::query()->where('id', '>', $after)->orderBy('id')->limit($limit)->select(['id', 'slug', 'name']);
        if ($locale !== 'en') {
            $q->with('translations');
        }
        $brands = $q->get();

        // What each brand mostly sells: one grouped query for the whole chunk.
        $mix = [];
        if ($brands->isNotEmpty()) {
            $rows = DB::table('products')
                ->join('categories', 'categories.id', '=', 'products.category_id')
                ->whereIn('products.brand_id', $brands->pluck('id'))
                ->where('products.status', 'publish')
                ->groupBy('products.brand_id', 'categories.name')
                ->orderByDesc(DB::raw('count(*)'))
                ->get(['products.brand_id', 'categories.name', DB::raw('count(*) as n')]);
            foreach ($rows as $r) {
                $mix[(int) $r->brand_id][] = (string) $r->name;
            }
        }

        $out = [];
        $last = null;
        foreach ($brands as $b) {
            $last = (int) $b->id;
            $name = (string) $b->t('name', $locale);
            $types = [];
            foreach (array_slice($mix[(int) $b->id] ?? [], 0, 6) as $catName) {
                $t = Lexicon::recognise($catName)['kind'];
                if ($t !== null && ! in_array($t, $types, true)) {
                    $types[] = $t;
                }
            }
            $out[] = [
                'type' => 'brand', 'id' => (string) $b->id, 'locale' => $locale,
                'name' => $name, 'display' => $name, 'brand' => (string) $b->name, 'core' => KeywordText::norm((string) $b->name),
                'category' => '', 'path' => UrlScheme::brand((string) $b->slug),
                'kind' => $types[0] ?? null, 'kinds' => array_slice($types, 0, 3),
                'ingredients' => [], 'concerns' => [], 'skin' => [],
            ];
        }

        return [$out, count($out) < $limit ? null : $last];
    }

    private static function collectionList(string $locale): array
    {
        $list = [];
        foreach (self::COLLECTIONS as $key => [$label, $path]) {
            $list[] = [
                'type' => 'collection', 'id' => $key, 'locale' => $locale, 'name' => $label, 'display' => $label,
                'brand' => '', 'core' => KeywordText::norm($label), 'category' => '', 'path' => $path,
            ] + Lexicon::recognise($label);
        }
        foreach (ConcernCollections::slugs() as $slug) {
            $label = RoutineConcerns::adminLabel($slug);
            $list[] = [
                'type' => 'collection', 'id' => 'concern-'.$slug, 'locale' => $locale, 'name' => $label, 'display' => $label,
                'brand' => '', 'core' => KeywordText::norm($label), 'category' => '', 'path' => '/concern/'.$slug.'/',
            ] + Lexicon::recognise($label, [$slug]);
        }

        return $list;
    }

    private static function pages(string $locale, int $after, int $limit): array
    {
        $list = [[
            'type' => 'page', 'id' => 'home', 'locale' => $locale, 'name' => 'Home', 'display' => 'Home',
            'brand' => '', 'core' => '', 'category' => '', 'path' => '/',
            'kind' => null, 'ingredients' => [], 'concerns' => [], 'skin' => [],
        ]];

        $q = Page::query()->where('status', 'published')->orderBy('id')->select(['id', 'slug', 'title']);
        if ($locale !== 'en') {
            $q->with('translations');
        }
        foreach ($q->limit(200)->get() as $p) {
            $title = (string) $p->t('title', $locale);
            $list[] = [
                'type' => 'page', 'id' => (string) $p->id, 'locale' => $locale, 'name' => $title, 'display' => $title,
                'brand' => '', 'core' => KeywordText::norm($title), 'category' => '', 'path' => '/'.trim((string) $p->slug, '/').'/',
            ] + Lexicon::recognise($title);
        }

        return self::sliceList($list, $after, $limit);
    }

    private static function posts(string $locale, int $after, int $limit): array
    {
        $q = Post::query()->where('status', 'published')->where('id', '>', $after)->orderBy('id')->limit($limit)
            ->select(['id', 'slug', 'title', 'excerpt']);
        if ($locale !== 'en') {
            $q->with('translations');
        }

        $out = [];
        $last = null;
        foreach ($q->get() as $p) {
            $last = (int) $p->id;
            $title = (string) $p->t('title', $locale);
            $out[] = [
                'type' => 'post', 'id' => (string) $p->id, 'locale' => $locale, 'name' => $title, 'display' => $title,
                'brand' => '', 'core' => KeywordText::norm($title), 'category' => '',
                'path' => UrlScheme::article((string) $p->slug),
            ] + Lexicon::recognise($p->title.' '.mb_substr(strip_tags((string) $p->excerpt), 0, 300));
        }

        return [$out, count($out) < $limit ? null : $last];
    }

    private static function sliceList(array $list, int $after, int $limit): array
    {
        $slice = array_slice($list, $after, $limit);
        $next = $after + count($slice);

        return [$slice, $next >= count($list) ? null : $next];
    }

    /**
     * The product's own name without its size, pack count or brand prefix:
     * "COSRX Advanced Snail 96 Mucin Power Essence 100ml" → "advanced snail 96 mucin power essence".
     */
    public static function core(string $name, string $brand = ''): string
    {
        $n = KeywordText::norm($name);
        $n = (string) preg_replace('/\b\d+(\.\d+)?\s?(ml|g|gr|oz|ea|pcs|sheets?|pads?|patches|capsules|x)\b/u', ' ', $n);
        $n = (string) preg_replace('/\(.*?\)|\[.*?\]/u', ' ', $n);
        $b = KeywordText::norm($brand);
        if ($b !== '' && str_starts_with($n, $b.' ')) {
            $n = substr($n, strlen($b) + 1);
        }

        return trim((string) preg_replace('/\s+/u', ' ', $n), ' -+,');
    }
}

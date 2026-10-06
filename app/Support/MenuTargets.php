<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Page;
use App\Models\Post;
use Illuminate\Support\Collection;

/**
 * What a Mega Menu item can point at, and the address each one has TODAY.
 * (Lane MX)
 *
 * The owner: "in the mega menu, i need a full list of categories, brands,
 * pages etc etc. same like in wordpress, to directly click and add to specific
 * menu / columns. so i will avoid to paste the manual links mostely."
 *
 * ── HOW A PICKED ITEM IS STORED ─────────────────────────────────────────────
 *
 * As a REFERENCE and an address: `target_type` + `target_id` (columns the
 * schema has carried since the first migration, and the ones the WordPress
 * import already writes for the same four kinds) plus `url`, the canonical
 * path at the moment it was added — the same site-relative shape every other
 * menu row holds (`/collections/skincare/toners/`), never the base-path
 * prefixed one, because the templates add that with Url::to().
 *
 * NavigationService asks livePaths() for the current address of every
 * picked row, so renaming a category or a brand's slug moves the header link
 * with it. The stored `url` is the fallback for a target that has since been
 * deleted or unpublished, and what the editor shows.
 *
 * ONLY ROWS THIS SCREEN WROTE ARE RESOLVED LIVE. A row the WordPress import
 * wrote carries the same `target_type` values, and also a `source_post_id`.
 * Its address is the import's to keep — it re-resolves it on every delta pass —
 * and the owner may have typed his own over it on the Mega Menu screen, which
 * `update()` does without touching `target_type`. Resolving those live would
 * quietly undo his edit, so isPicked() requires `source_post_id` to be null.
 *
 * Every lookup is one query per KIND, whatever the number of rows: the list
 * the panel opens with, the add request and the header's cache miss all cost
 * the same with three categories or three hundred.
 */
final class MenuTargets
{
    /** The kinds stored as a reference. A collection and a custom link are an address only. */
    public const TYPES = ['category', 'brand', 'page', 'article'];

    /**
     * The shop's fixed listings — every curated listing route the storefront
     * registers, plus the directory pages a header usually links to. Keyed by
     * a stable id the picker sends back; the path is a constant here, never a
     * request value.
     *
     * Each one is offered only when its route is registered on this shop
     * (collections()), so a listing that is not mounted cannot become a
     * header link to a 404.
     */
    public const COLLECTIONS = [
        'shop' => ['Shop all', '/shop/', 'shop'],
        'new-in' => ['New In', '/new-in/', 'collection.new'],
        'best-sellers' => ['Best Sellers', '/best-sellers/', 'collection.best'],
        'super-sale' => ['Super Sale', '/super-sale/', 'collection.sale'],
        'under-54' => ['Everything Under 54 AED', '/everything-under-54-aed/', 'collection.budget'],
        'brands' => ['All brands', '/brands/', 'brands.index'],
        'blog' => ['Blog', '/blog/', 'journal'],
        'skin-quiz' => ['Skin quiz', '/skin-quiz/', 'skin-quiz'],
        'reviews' => ['Reviews', '/reviews/', 'review-wall'],
        'wishlist' => ['My wishlist', '/my-wishlist/', 'wishlist'],
    ];

    /** True for a row this screen created from a picked category, brand, page or article. */
    public static function isPicked(object $item): bool
    {
        return in_array($item->target_type ?? null, self::TYPES, true)
            && ($item->target_id ?? null) !== null
            && ($item->source_post_id ?? null) === null;
    }

    /**
     * item id => the address its target has now, for every picked row in
     * $items whose target still exists and is still public. A row missing
     * from the answer keeps its stored `url`.
     *
     * @param  iterable<object>  $items
     * @return array<int, string>
     */
    public static function livePaths(iterable $items): array
    {
        $want = [];

        foreach ($items as $item) {
            if (self::isPicked($item)) {
                $want[$item->target_type][(int) $item->target_id][] = (int) $item->id;
            }
        }

        if ($want === []) {
            return [];   // no picked rows, no queries: an untouched menu costs what it did
        }

        $out = [];

        foreach ($want as $type => $byTarget) {
            foreach (self::paths($type, array_keys($byTarget)) as $targetId => $path) {
                foreach ($byTarget[$targetId] as $itemId) {
                    $out[$itemId] = $path;
                }
            }
        }

        return $out;
    }

    /**
     * target id => [name, path] for the public rows of one kind among $ids.
     * One query. A draft page or article, a page with no route and an id that
     * does not exist are simply absent.
     *
     * @param  list<int>  $ids
     * @return array<int, array{0: string, 1: string}>
     */
    public static function resolve(string $type, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if ($ids === []) {
            return [];
        }

        return match ($type) {
            'category' => self::categories(Category::query()->whereIn('id', $ids)->get(['id', 'name', 'slug', 'path', 'parent_id'])),
            'brand' => Brand::query()->whereIn('id', $ids)->get(['id', 'name', 'slug'])
                ->mapWithKeys(fn (Brand $b) => [(int) $b->id => [self::text($b->name), UrlScheme::brand((string) $b->slug)]])->all(),
            'page' => self::pages(Page::query()->whereIn('id', $ids)->where('status', 'published')->get(['id', 'title', 'slug'])),
            'article' => Post::query()->whereIn('id', $ids)->where('status', 'published')->get(['id', 'title', 'slug'])
                ->mapWithKeys(fn (Post $p) => [(int) $p->id => [PageTitle::decoded($p->title), UrlScheme::article((string) $p->slug)]])->all(),
            default => [],
        };
    }

    /** @param list<int> $ids @return array<int, string> */
    private static function paths(string $type, array $ids): array
    {
        return array_map(fn (array $hit) => $hit[1], self::resolve($type, $ids));
    }

    /**
     * Every public target, for the panel. Only id, name, url and (categories)
     * parent: the panel needs nothing else, and a projection that carries
     * less cannot leak more. Drafts are left out because the header could not
     * link to them — the editor has never listed them either.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public static function catalogue(): array
    {
        $cats = Category::query()->orderBy('position')->orderBy('name')->get(['id', 'name', 'slug', 'path', 'parent_id']);
        $catPaths = self::categories($cats);

        $categories = $cats->map(fn (Category $c) => [
            'id' => (int) $c->id,
            'name' => $catPaths[(int) $c->id][0],
            'url' => $catPaths[(int) $c->id][1],
            'parent' => $c->parent_id === null ? null : (int) $c->parent_id,
        ])->values()->all();

        $brands = Brand::query()->orderBy('name')->get(['id', 'name', 'slug'])->map(fn (Brand $b) => [
            'id' => (int) $b->id,
            'name' => self::text($b->name),
            'url' => UrlScheme::brand((string) $b->slug),
        ])->sortBy(fn ($b) => mb_strtolower($b['name']))->values()->all();

        $pages = collect(self::pages(Page::query()->where('status', 'published')->orderBy('title')->get(['id', 'title', 'slug'])))
            ->map(fn (array $hit, int $id) => ['id' => $id, 'name' => $hit[0], 'url' => $hit[1]])->values()->all();

        $posts = Post::query()->where('status', 'published')->latest('published_at')->latest('id')->get(['id', 'title', 'slug'])
            ->map(fn (Post $p) => ['id' => (int) $p->id, 'name' => PageTitle::decoded($p->title), 'url' => UrlScheme::article((string) $p->slug)])
            ->values()->all();

        return [
            'categories' => $categories,
            'brands' => $brands,
            'pages' => $pages,
            'posts' => $posts,
            'collections' => self::collections(),
        ];
    }

    /** @return list<array{id: string, name: string, url: string}> */
    public static function collections(): array
    {
        $out = [];

        foreach (self::COLLECTIONS as $key => [$name, $path, $route]) {
            if (\Illuminate\Support\Facades\Route::has($route)) {
                $out[] = ['id' => $key, 'name' => $name, 'url' => $path];
            }
        }

        return $out;
    }

    /**
     * id => [name, path]. The cached `path` column when the import filled it;
     * otherwise walked through the parents already in hand, so a nested
     * category costs no query of its own. (Category::buildPath() would ask the
     * database once per level.)
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private static function categories(Collection $cats): array
    {
        $byId = $cats->keyBy(fn ($c) => (int) $c->id);
        $out = [];

        foreach ($byId as $id => $c) {
            $path = trim((string) $c->path, '/');

            if ($path === '') {
                $segments = [(string) $c->slug];
                $node = $c;
                $guard = 0;

                while ($node->parent_id !== null && $guard++ < 10) {
                    $node = $byId->get((int) $node->parent_id) ?? Category::query()->find($node->parent_id, ['id', 'slug', 'parent_id']);
                    if ($node === null) {
                        break;
                    }
                    array_unshift($segments, (string) $node->slug);
                }

                $path = implode('/', $segments);
            }

            $out[$id] = [self::text($c->name), UrlScheme::collection($path)];
        }

        return $out;
    }

    /**
     * id => [title, path] for published pages the router actually serves. A
     * page row with no route is a 404 on the shop (PageEditorApiController's
     * header has the story), so it is not offered as a link.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private static function pages(Collection $pages): array
    {
        $routed = RoutedPages::paths();
        $out = [];

        foreach ($pages as $p) {
            $path = $routed[(string) $p->slug] ?? null;

            if ($path !== null) {
                $out[(int) $p->id] = [PageTitle::decoded($p->title), $path];
            }
        }

        return $out;
    }

    private static function text(?string $raw): string
    {
        return html_entity_decode(strip_tags((string) $raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * A typed address the picker's "Custom link" may store: a path on this
     * shop (`/x/`, not `//host`, which is somebody else's host wearing this
     * page's scheme) or an https URL. Nothing else — no http, no mailto, and
     * never a `javascript:`. Read after the controls and entities a browser
     * would strip, so `java&#x09;script:` and `/\evil.test` are refused too.
     */
    public static function customUrlAllowed(?string $raw): bool
    {
        $url = trim((string) $raw);

        if ($url === '' || mb_strlen($url) > 255 || preg_match('/[\x00-\x20\x7F\\\\]/u', $url)) {
            return false;
        }

        $decoded = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($decoded !== $url) {
            return false;   // an entity in a typed address is only ever a disguise
        }

        if (str_starts_with($url, '/')) {
            return ! str_starts_with($url, '//');
        }

        if (! preg_match('#^https://#i', $url)) {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' && SafeUrl::web($url) === $url;
    }
}

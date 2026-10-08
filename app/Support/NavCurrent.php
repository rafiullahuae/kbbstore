<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\Request;

/**
 * Which desktop menu item is "the page you are on". (Lane MN, 2.60.441)
 *
 * The owner: "when i go to any page/category or brand etc from the top menu in
 * desktop, it's not highlighting etc as open or current page." Nothing marked
 * it: partials/nav-bar.blade.php printed every link the same on every page, and
 * kbb.css had no rule for a current item to style. Not set, not styled.
 *
 * ── HOW IT DECIDES, ONCE PER REQUEST, WITH NO QUERY ─────────────────────────
 *
 * Everything it reads is already in memory: the menu tree the bar is about to
 * print, the request's own path (canonical: SetLocaleFromPath has stripped /ar
 * and ResolveLocaleSlugs has turned an Arabic slug back into the English one,
 * both before the router), and the category rows the page's controller already
 * loaded for its breadcrumbs. It never touches the database and never reads a
 * setting, which NavCurrentTest pins.
 *
 * Every link in a top-level item — the item itself and every link in its panel
 * — is scored against the page, and the item scores as its best link:
 *
 *   4  the link IS this page, and its own query string is a subset of the
 *      page's (`/shop/?orderby=date` on /shop/?orderby=date)
 *   3  the link IS this page (path equal; the page's query is ignored)
 *   2  the link is a category ABOVE this page: a category page's parents, or
 *      a product page's most specific category (ProductCategory) and every
 *      category above it — nearer beats further
 *   1  the page sits UNDER the link's path, segment by segment (/brands/ for
 *      /brands/cosrx/, /blog/ for an article) — longer beats shorter
 *
 * The highest score wins and ONLY ONE top-level item is marked; a tie goes to
 * the first in the menu. At the same score the item's own link beats a link in
 * its panel, so a "Brands" item beats an "All brands" link inside Shop.
 * A link carrying a query string the page does not have never matches at all —
 * otherwise "New In" (/shop/?orderby=date) would light up on /shop/.
 *
 * The answer is written back onto a COPY of the tree as `current`: 'page' on a
 * link that is this page, 'true' on one that contains it — the two values of
 * aria-current the template prints, each a constant of this class.
 */
final class NavCurrent
{
    public const PAGE = 'page';

    public const TRAIL = 'true';

    private const EXACT_QUERY = 4;

    private const EXACT = 3;

    private const ANCESTOR = 2;

    private const PREFIX = 1;

    /** Old addresses a hand-made menu row may still carry, onto the scheme. */
    private const LEGACY = [
        UrlScheme::LEGACY_COLLECTION_BASE => UrlScheme::COLLECTION_BASE,
        UrlScheme::LEGACY_BRAND_INDEX => UrlScheme::BRAND_BASE,
        UrlScheme::LEGACY_BRAND_BASE => UrlScheme::BRAND_BASE,
        UrlScheme::LEGACY_BLOG_INDEX => UrlScheme::BLOG_BASE,
    ];

    /** What a link prints: one of two constants, or nothing. */
    private const ATTR = [self::PAGE => ' aria-current="page"', self::TRAIL => ' aria-current="true"'];

    /**
     * The attribute for one link of the tree mark() returned, leading space
     * included, or ''. Printed raw, so it is only ever one of ATTR's constants;
     * it sits immediately before the tag's `>` so a link that is not current
     * prints exactly the bytes it printed before this existed.
     *
     * @param  array<string, mixed>  $link
     */
    public static function attr(array $link): string
    {
        return self::ATTR[$link['current'] ?? ''] ?? '';
    }

    /** The two non-default looks, as a class on `.mbar`; '' for the shipped line. */
    private const STYLE_CLASS = ['dot' => ' nc-dot', 'text' => ' nc-text'];

    /** The shipped colour, which kbb.css already carries: printed only when moved. */
    public const DEFAULT_COLOUR = '#C13E63';

    /**
     * The style class for `.mbar`, leading space included; '' with the switch
     * off or on the default style. A constant of this class whatever was saved.
     *
     * @param  array<string, mixed>  $settings  HeaderSettings::all()
     */
    public static function barClass(array $settings): string
    {
        if (! ($settings['nav_current'] ?? true)) {
            return '';
        }

        return self::STYLE_CLASS[(string) ($settings['nav_current_style'] ?? 'line')] ?? '';
    }

    /**
     * `--nav-cur:#RRGGBB` for `.mbar`'s style, or null: with the switch off, at
     * the default colour, or for anything that is not a strict hex.
     *
     * @param  array<string, mixed>  $settings  HeaderSettings::all()
     */
    public static function barStyle(array $settings): ?string
    {
        $colour = (string) ($settings['nav_current_colour'] ?? self::DEFAULT_COLOUR);

        if (! ($settings['nav_current'] ?? true) || strcasecmp($colour, self::DEFAULT_COLOUR) === 0
            || ! preg_match('/^#[0-9A-Fa-f]{6}$/', $colour)) {
            return null;
        }

        return '--nav-cur:' . $colour;
    }

    /**
     * What the page is, from what the request and the view already hold.
     *
     * @param  mixed  $category  the shop view's `$category` (a Category on a category page)
     * @param  mixed  $product  the product view's `$product`
     * @param  mixed  $crumbTrail  the product view's `$crumbTrail` (root first)
     * @return array{path: string, query: array<string, mixed>, host: string, trail: list<string>}
     */
    public static function here(Request $request, mixed $category = null, mixed $product = null, mixed $crumbTrail = null): array
    {
        $trail = [];
        // By route, not by variable alone: a layout inherits every variable
        // its page defined, a loop's `$product` included, so only the two
        // pages that ARE a category or a product are read as one.
        $route = $request->route()?->getName();

        if ($route === 'collection' && $category instanceof Category) {
            $trail = self::categoryTrail($category);
        } elseif ($route === 'product.show' && $product instanceof Product) {
            $cats = is_array($crumbTrail) ? $crumbTrail : ProductCategory::trail($product);
            $leaf = end($cats);

            if ($leaf instanceof Category) {
                // The product's own category IS the nearest ancestor, so it
                // leads; its parents follow it, nearest first.
                $trail = self::categoryTrail($leaf, true, array_reverse(array_slice($cats, 0, -1)));
            }
        }

        return [
            'path' => self::path($request->getPathInfo()),
            'query' => $request->query->all(),
            'host' => strtolower($request->getHost()),
            'trail' => $trail,
        ];
    }

    /**
     * The tree with the winning top-level item, and the link in its panel that
     * won it, marked. Unchanged (the same array) when nothing matches.
     *
     * @param  list<array<string, mixed>>  $nav
     * @param  array{path: string, query: array<string, mixed>, host?: string, trail?: list<string>}  $here
     * @return list<array<string, mixed>>
     */
    public static function mark(array $nav, array $here): array
    {
        $rank = array_flip(array_reverse(array_values($here['trail'] ?? [])));
        $best = 0;
        $winner = null;

        foreach ($nav as $i => $item) {
            $own = self::score($item['url'] ?? null, $here, $rank);
            // Own link first: at an equal score the item's own address wins.
            $score = $own > 0 ? $own * 2 + 1 : 0;
            $at = [];

            foreach (self::links($item['children'] ?? []) as [$where, $url]) {
                $s = self::score($url, $here, $rank);

                if ($s > 0 && $s * 2 > $score) {
                    $score = $s * 2;
                    $at = $where;
                }
            }

            if ($score > $best) {
                $best = $score;
                $winner = [$i, $at];
            }
        }

        if ($winner === null) {
            return $nav;
        }

        [$i, $at] = $winner;
        $exact = intdiv($best, 2) >= self::EXACT * 1000;

        if ($at === []) {
            $nav[$i]['current'] = $exact ? self::PAGE : self::TRAIL;

            return $nav;
        }

        $nav[$i]['current'] = self::TRAIL;
        $node = &$nav[$i];

        foreach ($at as $k) {
            $node = &$node['children'][$k];
        }

        $node['current'] = $exact ? self::PAGE : self::TRAIL;
        unset($node);

        return $nav;
    }

    /**
     * Every link below a top-level item, with the key path that reaches it.
     *
     * @param  array<int|string, mixed>  $children
     * @param  list<int|string>  $prefix
     * @return \Generator<int, array{0: list<int|string>, 1: mixed}>
     */
    private static function links(array $children, array $prefix = []): \Generator
    {
        foreach ($children as $k => $child) {
            if (! is_array($child)) {
                continue;
            }

            $where = [...$prefix, $k];

            yield [$where, $child['url'] ?? null];

            if (! empty($child['children']) && count($where) < 4) {
                yield from self::links($child['children'], $where);
            }
        }
    }

    /**
     * One link against the page: level × 1000 plus how specific the hit is.
     *
     * @param  array<string, int>  $rank
     */
    private static function score(mixed $url, array $here, array $rank): int
    {
        $link = self::parse($url, (string) ($here['host'] ?? ''));

        if ($link === null) {
            return 0;
        }

        [$path, $query] = $link;

        if ($query !== []) {
            // A link with its own query is one listing of a page, never a
            // section: it is this page only when the page asks for the same.
            if ($path !== $here['path']) {
                return 0;
            }

            foreach ($query as $key => $value) {
                if (! array_key_exists($key, $here['query']) || (string) (is_array($value) ? '' : $value) !== (string) (is_array($here['query'][$key]) ? '' : $here['query'][$key])) {
                    return 0;
                }
            }

            return self::EXACT_QUERY * 1000;
        }

        if ($path === $here['path']) {
            return self::EXACT * 1000;
        }

        if (isset($rank[$path])) {
            return self::ANCESTOR * 1000 + 1 + $rank[$path];
        }

        if ($path !== '/' && str_starts_with($here['path'], $path . '/')) {
            return self::PREFIX * 1000 + min(999, strlen($path));
        }

        return 0;
    }

    /**
     * A menu address as [path, query], or null when it is not on this shop.
     *
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    private static function parse(mixed $url, string $host): ?array
    {
        if (! is_string($url)) {
            return null;
        }

        $url = trim($url);

        if ($url === '' || $url[0] === '#') {
            return null;
        }

        $parts = parse_url($url);

        if ($parts === false) {
            return null;
        }

        if (isset($parts['scheme']) || isset($parts['host'])) {
            $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));

            if (! in_array($scheme, ['http', 'https'], true) || strtolower((string) ($parts['host'] ?? '')) !== $host || $host === '') {
                return null;
            }
        }

        $query = [];

        if (isset($parts['query']) && $parts['query'] !== '') {
            parse_str($parts['query'], $query);
        }

        return [self::path((string) ($parts['path'] ?? '/')), $query];
    }

    /** Lower case, no trailing slash, legacy prefixes onto the scheme; '/' for home. */
    private static function path(string $path): string
    {
        $bare = trim(strtolower(rawurldecode($path)), '/');

        if ($bare === '') {
            // Home is '/', never '': an empty path would be a prefix of every
            // page and light Home everywhere (NavCurrentTest).
            return '/';
        }

        $path = '/' . $bare . '/';

        foreach (self::LEGACY as $old => $new) {
            if (str_starts_with($path, $old)) {
                $path = $new . substr($path, strlen($old));
                break;
            }
        }

        return rtrim($path, '/') ?: '/';
    }

    /**
     * A category's ancestors as collection paths, nearest first. From the
     * `path` column (its parents are its prefixes) and from any parent rows
     * already loaded — ShopController::ancestorsOf() hands each one its parent,
     * which is how a short-address ancestor (its own slug, not a prefix) is
     * found. Never lazy-loads: an unloaded relation is simply not followed.
     *
     * @param  list<mixed>  $more  further categories, nearest first
     * @return list<string>
     */
    private static function categoryTrail(Category $category, bool $self = false, array $more = []): array
    {
        $out = [];
        $add = static function (?string $path) use (&$out): void {
            if ($path !== null && $path !== '' && ! in_array($path, $out, true)) {
                $out[] = $path;
            }
        };

        $own = self::categoryPath($category);

        if ($self) {
            $add($own);
        }

        $node = $category;

        for ($guard = 0; $guard < 10 && $node->relationLoaded('parent') && $node->parent instanceof Category; $guard++) {
            $node = $node->parent;
            $add(self::categoryPath($node));
        }

        foreach ($more as $cat) {
            if ($cat instanceof Category) {
                $add(self::categoryPath($cat));
            }
        }

        if ($own !== null) {
            $segments = explode('/', trim(substr($own, strlen(rtrim(UrlScheme::COLLECTION_BASE, '/'))), '/'));

            while (count($segments) > 1) {
                array_pop($segments);
                $add(self::path(UrlScheme::collection(implode('/', $segments))));
            }
        }

        return $out;
    }

    private static function categoryPath(Category $category): ?string
    {
        $path = (string) ($category->getAttribute('path') ?? '');

        if ($path === '') {
            // A row with no cached path: its slug is its address only when it
            // has no parent. Anything else would need a query to rebuild.
            if ($category->getAttribute('parent_id') !== null) {
                return null;
            }

            $path = (string) ($category->getAttribute('slug') ?? '');
        }

        return $path === '' ? null : self::path(UrlScheme::collection($path));
    }
}

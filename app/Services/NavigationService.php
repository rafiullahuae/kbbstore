<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Menu;
use App\Services\Translation\TranslationStore;
use App\Support\BrandUrls;
use App\Support\Color;
use App\Support\Locale;
use App\Support\SafeUrl;
use Illuminate\Support\Facades\Cache;

/**
 * Navigation for the header and mobile drawer.
 *
 * The WordPress theme hard-coded this tree inside header.php — which is why the
 * Master Plan listed "swap two hard-coded blocks for module hooks" as Phase 1's
 * outstanding work. Here it comes from the database, and the hard-coded version
 * survives only as a fallback so the storefront still renders before the menu
 * import has run. When the Mega Menu module lands in Phase 7 it feeds this same
 * structure rather than replacing it.
 */
class NavigationService
{
    /**
     * 'primary' | 'mobile' | 'footer' -> the matching boolean column. A menu
     * can have any combination of these checked at once — the old design
     * assumed exactly one, modelled as a single `location` string; this
     * still guarantees only one menu holds a given slot (the admin
     * controller enforces that on save), it just no longer forces a menu
     * to be only one thing.
     */
    private const SLOT_COLUMN = ['primary' => 'show_desktop', 'mobile' => 'show_mobile', 'footer' => 'show_footer'];

    /*
     * Injected for filterVisible()'s module check. Resolved from the container
     * everywhere this service is used (StoreComposer takes it as a dependency
     * and nothing constructs it by hand), so autowiring covers every call site.
     */
    public function __construct(private SettingsService $settings) {}

    public function menu(string $location): array
    {
        return Cache::remember("kbb.nav.$location", 300, function () use ($location) {
            $column = self::SLOT_COLUMN[$location] ?? null;
            $menu = $column ? Menu::with('allItems')->where($column, true)->first() : null;

            if (! $menu || $menu->allItems->isEmpty()) {
                return $this->fallback($location);
            }

            return $this->tree($menu->allItems);
        });
    }

    /** Flat rows to a nested tree, preserving position ordering.
     *
     * ── TWO IMPORTED VALUES ARE MADE SAFE HERE, AND HERE ON PURPOSE ─────────
     *
     * EIGHT href sites and SEVEN style sites read this tree — four links and
     * four colours in nav-bar.blade.php, three links and four colours in
     * mobile-menu-item.blade.php, one link in footer.blade.php. Gating them in
     * the templates means writing the same conditional eight times and getting
     * it right eight times; the first cut of this fix did exactly one of them
     * and called the hole closed. So it happens at the one place every one of
     * those sites reads from, which is here.
     *
     * The URL: `{{ }}` escapes &, <, >, " and ', and `javascript:alert(1)`
     * holds none of them, so it reaches an href BYTE FOR BYTE and the browser
     * runs it. `//evil.test/x` is somebody else's host wearing this page's
     * scheme. SafeUrl::href() reads the scheme the way a browser does —
     * entities decoded and controls stripped first — and answers '/' for one
     * this shop will not follow, which the templates' own `Url::to($url ?? '/')`
     * then turns into this shop's home. A path, a query or a fragment comes
     * back unchanged, so every address a real menu carries renders byte for
     * byte as it did before.
     *
     * The colour: a `style=` attribute is a CSS context, not an HTML one. The
     * escaper turns `'` into `&#39;` and the HTML parser hands the `'` straight
     * back before CSS reads the attribute, so a stored colour can close the
     * declaration and open its own. Color::isValidHex() is the gate the
     * Appearance screens already use; a value it refuses draws the row plain.
     *
     * WHERE THESE COME FROM, which sets the severity: not from a shopper.
     * MegaMenuApiController validates both on save (`regex:/^#[0-9a-fA-F]{6}$/`
     * for the colour), so the admin cannot type either one in. They come from
     * the WORDPRESS IMPORT, which writes menu_items straight from a database
     * this shop did not author — which is why this landed before the import
     * rather than after it.
     *
     * Inside the cached closure deliberately, unlike `visibility` and the
     * label translation below: this answer does not depend on who is asking or
     * what language they read, so caching it cannot bake one visitor's state
     * into another's. It also means the scheme probe runs once per five
     * minutes per menu rather than once per item per request.
     *
     * `visibility` rides along unfiltered here on purpose — this whole tree
     * is cached for five minutes across every visitor. Filtering by auth
     * state inside the cached closure would bake whichever visitor's login
     * state happened to trigger the cache miss into everyone else's menu
     * for the next five minutes. The filter has to happen per-request,
     * after the cache read, in the template that actually renders it.
     */
    private function tree($items, ?int $parentId = null): array
    {
        return $items
            ->where('parent_id', $parentId)
            ->sortBy('position')
            ->map(fn ($item) => [
                'id' => $item->id,
                'label' => $item->label,
                'url' => SafeUrl::href($item->url, '/'),
                'icon' => $item->icon,
                'badge' => $item->badge,
                'highlight_color' => Color::isValidHex($item->highlight_color) ? $item->highlight_color : null,
                'visibility' => $item->visibility ?? 'always',
                'new_tab' => (bool) ($item->new_tab ?? false),
                'columns' => $item->columns,
                'children' => $this->tree($items, $item->id),
            ])
            ->values()
            ->all();
    }

    /**
     * Minimal safety net so nothing renders empty pre-import. Deliberately not a
     * copy of the theme's full tree — this is a fallback, not a place to author
     * navigation.
     */
    private function fallback(string $location): array
    {
        if ($location !== 'primary') {
            return [];
        }

        return [
            ['label' => 'Home', 'url' => '/', 'icon' => null, 'badge' => null, 'highlight_color' => null, 'visibility' => 'always', 'new_tab' => false, 'children' => []],
            ['label' => 'New In', 'url' => '/shop/?orderby=date', 'icon' => null, 'badge' => 'NEW', 'highlight_color' => null, 'visibility' => 'always', 'new_tab' => false, 'children' => []],
            ['label' => 'Best Sellers', 'url' => '/shop/?orderby=popularity', 'icon' => null, 'badge' => null, 'highlight_color' => null, 'visibility' => 'always', 'new_tab' => false, 'children' => []],
            ['label' => 'Shop', 'url' => '/shop/', 'icon' => null, 'badge' => null, 'highlight_color' => null, 'visibility' => 'always', 'new_tab' => false, 'children' => []],
        ];
    }

    /**
     * Applied per-request, after the (shared, cached) tree is read — see the
     * comment on tree() above for why this can't happen inside the cache
     * itself. An item with no `visibility` key at all (the hard-coded
     * fallback array, or older cached data from before this field existed)
     * is treated as 'always', not filtered out.
     */
    public function filterVisible(array $items, bool $loggedIn): array
    {
        /*
         * A SWITCHED-OFF MODULE MUST NOT LEAVE A LINK BEHIND — Lane EH.
         *
         * `brands` is a real switch now: with it off, BrandController aborts 404
         * for the directory, every brand page and both legacy redirects. The
         * menu is authored separately and knew nothing about that, so the
         * primary nav's "Brands" item went on pointing at
         * /korean-skincare-brands/ from the header of every page in the shop —
         * a dead link the shopper finds by clicking, which is a louder trace
         * than the section it was meant to replace.
         *
         * Resolved HERE, and here specifically, for the reason tree()'s own
         * comment gives about `visibility`: this method is the per-request
         * filter that runs AFTER the five-minute shared cache is read. Doing it
         * inside the cached closure would bake whichever visitor's module state
         * triggered the cache miss into every other visitor's menu — and unlike
         * a login state, the owner can flip this one in the admin and would
         * watch the header ignore him for five minutes.
         *
         * Resolved once per call rather than per item: moduleEnabled() is cheap
         * (one cached map) but this recurses over every item at every level.
         */
        $hideBrands = ! $this->settings->moduleEnabled('brands', true);

        /*
         * THE LABELS ARE TRANSLATED HERE, AND HERE FOR THE SAME REASON THE
         * VISIBILITY FILTER IS.
         *
         * menu() caches one tree for five minutes and shares it with every
         * visitor. Translating inside that cached closure would bake whichever
         * visitor's LANGUAGE triggered the cache miss into everybody else's
         * header — an Arabic menu on the English site for five minutes, or the
         * reverse — which is exactly the trap tree()'s own comment records
         * about `visibility` and filterVisible()'s about the brands module.
         *
         * So the cached tree stays language-neutral (it carries the English
         * label and the row's `id`) and the swap happens per request, after the
         * cache read, in the same pass that is already walking every item.
         */
        $locale = Locale::current();

        return $this->filterItems(
            $items,
            $loggedIn,
            $hideBrands,
            $locale === Locale::DEFAULT ? null : $locale,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  string|null  $locale  the language to translate labels into, or
     *   null for English — which is the column, so nothing is looked up at all
     *   and an English header is byte-identical.
     */
    private function filterItems(array $items, bool $loggedIn, bool $hideBrands, ?string $locale = null): array
    {
        $out = [];

        foreach ($items as $item) {
            $vis = $item['visibility'] ?? 'always';

            if ($vis === 'guest' && $loggedIn) {
                continue;
            }
            if ($vis === 'auth' && ! $loggedIn) {
                continue;
            }

            /*
             * Dropped whole, children included. The observed shape is a top-level
             * "Brands" item that itself points at the directory, so removing it
             * takes its per-brand leaves with it; a per-brand leaf under some
             * other parent is matched on its own URL by the same rule.
             *
             * Only the module's OWN addresses are matched — /shop/?filter_brands=
             * is a shop listing and keeps working with the module off, because
             * the module gates brand PAGES, not the catalogue's brand facet.
             */
            if ($hideBrands && BrandUrls::matches($item['url'] ?? null)) {
                continue;
            }

            /*
             * ONE HASH LOOKUP PER ITEM AND NO QUERIES. `menu_items.label` is
             * short text, so it is in the cached map that __() has already
             * loaded for this request — see TranslationStore::LONG_FIELDS for
             * what deliberately is not.
             *
             * get() and not a pre-built id => label array: the general form
             * walks the whole map to answer, and a header has about twenty
             * items against a map of a couple of thousand entries.
             *
             * Blank means untranslated, so an item with no Arabic label keeps
             * its English one. A fallback item from fallback() has no `id` at
             * all and is never matched, which is right: it is not a row and
             * there is nothing in `translations` that could be about it.
             */
            $id = (int) ($item['id'] ?? 0);

            if ($locale !== null && $id > 0) {
                $label = TranslationStore::get($locale, 'menu_items', $id, 'label');

                if ($label !== null && $label !== '') {
                    $item['label'] = $label;
                }
            }

            $item['children'] = $this->filterItems($item['children'] ?? [], $loggedIn, $hideBrands, $locale);
            $out[] = $item;
        }

        return $out;
    }

    public function flush(): void
    {
        foreach (['primary', 'mobile', 'footer'] as $location) {
            Cache::forget("kbb.nav.$location");
        }
    }
}

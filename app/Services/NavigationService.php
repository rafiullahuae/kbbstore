<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Menu;
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
                'url' => $item->url,
                'icon' => $item->icon,
                'badge' => $item->badge,
                'highlight_color' => $item->highlight_color,
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
        $out = [];

        foreach ($items as $item) {
            $vis = $item['visibility'] ?? 'always';

            if ($vis === 'guest' && $loggedIn) {
                continue;
            }
            if ($vis === 'auth' && ! $loggedIn) {
                continue;
            }

            $item['children'] = $this->filterVisible($item['children'] ?? [], $loggedIn);
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

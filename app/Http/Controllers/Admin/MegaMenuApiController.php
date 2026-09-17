<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Services\NavigationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Store → Modules → Mega Menu.
 *
 * The panel itself (nav-bar.blade.php, NavigationService) already worked —
 * this was the missing half: a way to actually put content into it. Before
 * this screen, the only way to change what's in the header nav was directly
 * in the database.
 *
 * Depth in the tree carries the meaning, not a stored "type" column:
 *   top item, no children              -> plain link
 *   top item, children with no grandchildren -> simple dropdown
 *   top item, children that have their own children -> mega panel,
 *     each child a column, each grandchild a link in that column
 * NavigationService already draws this exact distinction reading the tree
 * back out; this controller only has to preserve it going in.
 *
 * Multiple menus, added after the first version shipped: a `Menu` row's
 * `location` — 'primary' (desktop header), 'mobile', 'footer', or null
 * (not currently plugged into anything) — decides what NavigationService
 * actually serves. Exactly one menu can hold a given location at a time,
 * the same way only one thing can occupy a physical slot on the site;
 * assigning a location here always steals it from whoever had it.
 */
class MegaMenuApiController extends Controller
{
    private const LOCATIONS = ['primary', 'mobile', 'footer'];

    public function __construct(private NavigationService $nav) {}

    public function menus(): JsonResponse
    {
        try {
            $this->ensureColumns();
            $this->ensureMenuColumns();

            $menus = Menu::withCount('allItems')->orderBy('id')->get()->map(fn ($m) => [
                'id' => $m->id,
                'name' => $m->name,
                'show_desktop' => (bool) $m->show_desktop,
                'show_mobile' => (bool) $m->show_mobile,
                'show_footer' => (bool) $m->show_footer,
                'item_count' => $m->all_items_count,
            ]);

            return response()->json(['ok' => true, 'menus' => $menus]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function createMenu(Request $request): JsonResponse
    {
        try {
            $this->ensureMenuColumns();

            $data = $request->validate(['name' => ['required', 'string', 'max:60']]);

            $menu = Menu::create([
                'name' => $data['name'],
                'slug' => $this->uniqueSlug($data['name']),
            ]);

            return response()->json(['ok' => true, 'id' => $menu->id]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function updateMenu(Request $request, Menu $menu): JsonResponse
    {
        try {
            $this->ensureMenuColumns();

            $data = $request->validate([
                'name' => ['required', 'string', 'max:60'],
                'show_desktop' => ['nullable', 'boolean'],
                'show_mobile' => ['nullable', 'boolean'],
                'show_footer' => ['nullable', 'boolean'],
            ]);

            // Exactly one menu per slot — checking a box here always steals
            // that slot from whichever other menu currently holds it, the
            // same way only one thing can occupy a physical spot on the
            // site. Each slot is independent: a menu can hold none, one,
            // two, or all three at once.
            foreach (['show_desktop' => 'primary', 'show_mobile' => 'mobile', 'show_footer' => 'footer'] as $field => $unused) {
                if (! empty($data[$field])) {
                    Menu::where($field, true)->where('id', '!=', $menu->id)->update([$field => false]);
                }
            }

            $menu->update([
                'name' => $data['name'],
                'show_desktop' => $data['show_desktop'] ?? false,
                'show_mobile' => $data['show_mobile'] ?? false,
                'show_footer' => $data['show_footer'] ?? false,
            ]);
            $this->nav->flush();

            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function destroyMenu(Menu $menu): JsonResponse
    {
        try {
            $menu->delete();
            $this->nav->flush();

            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    /**
     * A full copy — every item, at every depth, in the same order — under a
     * new name. Not assigned to any slot: the source menu is very likely
     * already live somewhere, and a duplicate silently taking over its
     * spot would be a surprising side effect of what's meant to be a safe
     * starting point for a variant.
     */
    public function duplicateMenu(Menu $menu): JsonResponse
    {
        try {
            $this->ensureColumns();
            $this->ensureMenuColumns();

            $base = 'Copy of ' . $menu->name;
            $name = $base;
            $n = 2;
            while (Menu::where('name', $name)->exists()) {
                $name = $base . ' (' . $n++ . ')';
            }

            $copy = Menu::create([
                'name' => $name,
                'slug' => $this->uniqueSlug($name),
                'show_desktop' => false, 'show_mobile' => false, 'show_footer' => false,
            ]);

            $this->duplicateItems($menu->id, $copy->id, null);

            return response()->json(['ok' => true, 'id' => $copy->id]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    private function duplicateItems(int $fromMenuId, int $toMenuId, ?int $fromParentId, ?int $toParentId = null): void
    {
        $items = MenuItem::where('menu_id', $fromMenuId)->where('parent_id', $fromParentId)->orderBy('position')->get();

        foreach ($items as $item) {
            $copy = MenuItem::create([
                'menu_id' => $toMenuId,
                'parent_id' => $toParentId,
                'label' => $item->label,
                'url' => $item->url,
                'icon' => $item->icon,
                'badge' => $item->badge,
                'highlight_color' => $item->highlight_color,
                'visibility' => $item->visibility,
                'new_tab' => $item->new_tab,
                'position' => $item->position,
            ]);

            $this->duplicateItems($fromMenuId, $toMenuId, $item->id, $copy->id);
        }
    }

    /**
     * Builds a real menu matching kbeautybliss.com's actual live navigation
     * — pulled directly from the site, same order, not invented. Creates a
     * fresh menu rather than overwriting whatever's currently assigned, and
     * assigns it to both the desktop header and the mobile menu at once —
     * one menu, both slots, which is exactly what checking both boxes in
     * Menu settings already does; this just does it for you as a starting
     * point instead of building 30-odd items by hand.
     */
    public function loadDemo(Request $request): JsonResponse
    {
        try {
            $this->ensureColumns();
            $this->ensureMenuColumns();

            $name = 'K-Beauty Bliss Menu';
            $slug = $this->uniqueSlug($name);

            // Steals both slots from whatever currently holds them, same
            // exclusivity rule as Menu settings — a demo menu that's
            // supposed to actually show has to really be the one showing.
            Menu::where('show_desktop', true)->update(['show_desktop' => false]);
            Menu::where('show_mobile', true)->update(['show_mobile' => false]);

            $menu = Menu::create([
                'name' => $name, 'slug' => $slug,
                'show_desktop' => true, 'show_mobile' => true, 'show_footer' => false,
            ]);

            $pos = 0;
            $top = fn (array $attrs) => MenuItem::create(array_merge([
                'menu_id' => $menu->id, 'parent_id' => null, 'position' => $pos++,
            ], $attrs));
            $child = fn (int $parentId, int &$p, array $attrs) => MenuItem::create(array_merge([
                'menu_id' => $menu->id, 'parent_id' => $parentId, 'position' => $p++,
            ], $attrs));

            // A simple flat dropdown, not a mega panel — a two-column
            // panel here (Trending Brands / All Brands) sounded reasonable
            // but rendered with one column empty and looked broken.
            // Confirmed by actually rendering it before deciding: this flat
            // version is the one that looks right.
            $brands = $top(['label' => 'Brands', 'url' => '/korean-skincare-brands/']);
            $p = 0;
            foreach ([
                'Anua' => 'anua', 'Axis-Y' => 'axis-y', 'Beauty of Joseon' => 'beauty-of-joseon',
                'BIODANCE' => 'biodance', 'Celimax' => 'celimax', 'COSRX' => 'cosrx',
                'Dr.Althea' => 'dr-althea', 'EQQUALBERRY' => 'eqqualberry', 'Goodal' => 'goodal',
                "I'm from" => 'im-from', 'LANEIGE' => 'laneige', 'MEDICUBE' => 'medicube',
                'numbuzin' => 'numbuzin', 'Shiseido' => 'shiseido', 'SKIN 1004' => 'skin-1004',
                'SOME BY MI' => 'some-by-mi', 'VT Cosmetics' => 'vt-cosmetics',
            ] as $label => $slug2) {
                $child($brands->id, $p, ['label' => $label, 'url' => '/shop/?filter_brands=' . $slug2]);
            }

            /*
             * Category addresses are /product-category/{slug}/ — URL Contract
             * U-03 — not the flat /toners/ form the live WordPress site used.
             * Seeded flat, every one of these fell through routes/
             * kbb-brands-blog.php's `/{slug}/` catch-all to PageController@post,
             * which looks for a BLOG POST by that slug and 404s. Repaired on
             * existing shops by 2026_11_07_000000_repoint_menu_category_urls;
             * corrected here so re-seeding cannot bring it back.
             *
             * The slug is kept exactly as the live site spells it rather than
             * resolved against the categories table: see App\Support\
             * LegacyCategoryUrls for why remapping at seed time is the unsafe
             * direction.
             */
            $skincare = $top(['label' => 'Skincare', 'url' => '/product-category/skincare/']);
            $p = 0;
            foreach ([
                'Cleansing Oils' => '/product-category/cleansing-oils/',
                'Face Washes' => '/product-category/face-washes/',
                'Exfoliators' => '/product-category/exfoliators/',
                'Toners' => '/product-category/toners/',
                'Face Serums' => '/product-category/face-serums/',
                'Eye Care' => '/product-category/eye-care/',
                'Face Masks' => '/product-category/face-masks/',
                'Moisturizers' => '/product-category/moisturizers/',
                'Lip Care' => '/product-category/lip-care/',
                'Sunscreens' => '/product-category/sunscreens/',
            ] as $label => $url) {
                $child($skincare->id, $p, ['label' => $label, 'url' => $url]);
            }

            // These four also live one level down inside Skincare above —
            // the real site gives its most-visited categories a top-level
            // shortcut in addition to their place in the Skincare dropdown,
            // not instead of it. That duplication is real and intentional
            // on kbeautybliss.com itself, not a mistake being copied here.
            $top(['label' => 'Sunscreens', 'url' => '/product-category/sunscreens/']);
            $top(['label' => 'Moisturizers', 'url' => '/product-category/moisturizers/']);
            $top(['label' => 'Toners', 'url' => '/product-category/toners/']);
            $top(['label' => 'Lip Care', 'url' => '/product-category/lip-care/']);
            $top(['label' => 'Hair Care', 'url' => '/product-category/hair-care/']);
            $top(['label' => 'Skincare Sets', 'url' => '/product-category/skincare-sets/']);
            $top(['label' => 'Super Sale', 'url' => '/super-sale/', 'highlight_color' => '#E23A4E']);
            $top(['label' => 'Beauty Devices', 'url' => '/product-category/beauty-devices/']);
            $top(['label' => 'Everything Under 54 AED', 'url' => '/everything-under-54-aed/']);
            $top(['label' => 'Blog', 'url' => '/skincare-guide/']);

            $this->nav->flush();

            return response()->json(['ok' => true, 'id' => $menu->id]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function show(Request $request): JsonResponse
    {
        try {
            $this->ensureColumns();
            $this->ensureMenuColumns();

            $menu = $this->resolveMenu($request->query('menu_id'));

            $items = MenuItem::where('menu_id', $menu->id)
                ->orderBy('position')
                ->get(['id', 'parent_id', 'label', 'url', 'icon', 'badge', 'position', 'highlight_color', 'visibility', 'new_tab']);

            return response()->json([
                'module_on' => app(\App\Services\SettingsService::class)->moduleEnabled('mega_menu', false),
                'menu_id' => $menu->id,
                'tree' => $this->tree($items, null),
            ]);
        } catch (\Throwable $e) {
            // This endpoint has thrown a raw 500 before with nothing useful
            // reaching the person who hit it — the real message sat in a log
            // file nobody could get to quickly. This is an admin-only,
            // auth-gated screen, so surfacing the actual exception message
            // here directly is safe and is the fastest path to a fix the
            // next time something is wrong, instead of another round of
            // guessing from outside.
            return $this->errorResponse($e);
        }
    }

    private function errorResponse(\Throwable $e): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'errors' => [$e->getMessage()],
            'where' => basename($e->getFile()) . ':' . $e->getLine(),
        ], 500);
    }

    /**
     * Self-healing: if the 2.60.13 migration didn't actually land — a
     * partial deploy, a migration that silently no-op'd on this specific
     * database, anything — add the columns directly rather than leaving
     * the screen broken until someone re-runs migrations by hand. Cheap to
     * check on every load; `Schema::hasColumn` is one lightweight metadata
     * query, not a real cost worth avoiding here.
     */
    private function ensureColumns(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasColumn('menu_items', 'highlight_color')) {
            \Illuminate\Support\Facades\Schema::table('menu_items', function ($t) {
                $t->string('highlight_color', 9)->nullable()->after('badge');
                $t->string('visibility', 10)->default('always')->after('highlight_color');
                $t->boolean('new_tab')->default(false)->after('visibility');
            });
        }

        if (! \Illuminate\Support\Facades\Schema::hasColumn('menu_items', 'columns')) {
            \Illuminate\Support\Facades\Schema::table('menu_items', function ($t) {
                $t->unsignedTinyInteger('columns')->nullable()->after('new_tab');
            });
        }
    }

    /** Same self-healing reasoning as ensureColumns() above, for the menus table's own migration. */
    private function ensureMenuColumns(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasColumn('menus', 'show_desktop')) {
            \Illuminate\Support\Facades\Schema::table('menus', function ($t) {
                $t->boolean('show_desktop')->default(false);
                $t->boolean('show_mobile')->default(false);
                $t->boolean('show_footer')->default(false);
            });

            \Illuminate\Support\Facades\DB::table('menus')->where('location', 'primary')->update(['show_desktop' => true]);
            \Illuminate\Support\Facades\DB::table('menus')->where('location', 'mobile')->update(['show_mobile' => true]);
            \Illuminate\Support\Facades\DB::table('menus')->where('location', 'footer')->update(['show_footer' => true]);
        }
    }

    /** items keyed by parent_id, recursively — three levels deep is as far as the storefront ever reads. */
    private function tree($items, ?int $parentId, int $depth = 0): array
    {
        if ($depth > 2) {
            return [];
        }

        return $items->where('parent_id', $parentId)->values()->map(fn ($i) => [
            'id' => $i->id,
            'label' => $i->label,
            'url' => $i->url,
            'icon' => $i->icon,
            'badge' => $i->badge,
            'highlight_color' => $i->highlight_color,
            'visibility' => $i->visibility,
            'new_tab' => (bool) $i->new_tab,
            'children' => $this->tree($items, $i->id, $depth + 1),
        ])->all();
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $this->ensureColumns();

            $data = $request->validate([
                'menu_id' => ['required', 'integer', 'exists:menus,id'],
                'parent_id' => ['nullable', 'integer', 'exists:menu_items,id'],
                'label' => ['required', 'string', 'max:60'],
                'url' => ['nullable', 'string', 'max:255'],
                'icon' => ['nullable', 'string', 'max:10'],
                'badge' => ['nullable', 'string', 'max:20'],
                'highlight_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
                'visibility' => ['nullable', 'in:always,guest,auth'],
                'new_tab' => ['nullable', 'boolean'],
                'columns' => ['nullable', 'integer', 'min:1', 'max:6'],
            ]);

            $depth = $this->depthOf($data['parent_id'] ?? null);

            if ($depth >= 3) {
                return response()->json(['ok' => false, 'errors' => ['The panel only reads three levels deep — top item, column, link. Nothing below a link is ever shown.']], 422);
            }

            $position = MenuItem::where('menu_id', $data['menu_id'])
                ->where('parent_id', $data['parent_id'] ?? null)
                ->max('position');

            $item = MenuItem::create([
                'menu_id' => $data['menu_id'],
                'parent_id' => $data['parent_id'] ?? null,
                'label' => $data['label'],
                'url' => $data['url'] ?? null,
                'icon' => $data['icon'] ?? null,
                'badge' => $data['badge'] ?? null,
                'highlight_color' => $data['highlight_color'] ?? null,
                'visibility' => $data['visibility'] ?? 'always',
                'new_tab' => $data['new_tab'] ?? false,
                'columns' => $data['columns'] ?? null,
                'position' => ($position ?? -1) + 1,
            ]);

            $this->nav->flush();

            return response()->json(['ok' => true, 'id' => $item->id]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function update(Request $request, MenuItem $item): JsonResponse
    {
        try {
            $this->ensureColumns();

            $data = $request->validate([
                'label' => ['required', 'string', 'max:60'],
                'url' => ['nullable', 'string', 'max:255'],
                'icon' => ['nullable', 'string', 'max:10'],
                'badge' => ['nullable', 'string', 'max:20'],
                'highlight_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
                'visibility' => ['nullable', 'in:always,guest,auth'],
                'new_tab' => ['nullable', 'boolean'],
                'columns' => ['nullable', 'integer', 'min:1', 'max:6'],
            ]);

            $item->update([
                'label' => $data['label'],
                'url' => $data['url'] ?? null,
                'icon' => $data['icon'] ?? null,
                'badge' => $data['badge'] ?? null,
                'highlight_color' => $data['highlight_color'] ?? null,
                'visibility' => $data['visibility'] ?? 'always',
                'new_tab' => $data['new_tab'] ?? false,
                'columns' => $data['columns'] ?? null,
            ]);
            $this->nav->flush();

            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    /** Cascades to children and grandchildren — the DB foreign keys do this, not application code. */
    public function destroy(MenuItem $item): JsonResponse
    {
        $item->delete();
        $this->nav->flush();

        return response()->json(['ok' => true]);
    }

    /**
     * Whole sibling group's order, sent as an ordered list of ids after a
     * drag. One statement per row rather than one query per row still isn't
     * one query, but a nav tree tops out at a few dozen rows total across
     * every group combined — not worth a bulk-update query builder for that.
     */
    public function reorder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:menu_items,id'],
        ]);

        DB::transaction(function () use ($data) {
            foreach ($data['ids'] as $position => $id) {
                MenuItem::where('id', $id)->update(['position' => $position]);
            }
        });

        $this->nav->flush();

        return response()->json(['ok' => true]);
    }

    /**
     * Drag-and-drop between branches, not just reordering within one — moves
     * an item (and whatever it already has under it) to a new parent and a
     * new position among its new siblings in one call.
     */
    public function move(Request $request, MenuItem $item): JsonResponse
    {
        $data = $request->validate([
            'parent_id' => ['nullable', 'integer', 'exists:menu_items,id'],
            'ids' => ['required', 'array'],
            'ids.*' => ['integer', 'exists:menu_items,id'],
        ]);

        $newParentId = $data['parent_id'] ?? null;

        if ($newParentId === $item->id) {
            return response()->json(['ok' => false, 'errors' => ["An item can't be its own parent."]], 422);
        }

        if ($newParentId !== null) {
            $newParent = MenuItem::find($newParentId);
            if ($newParent && $newParent->menu_id !== $item->menu_id) {
                return response()->json(['ok' => false, 'errors' => ["Items can't move between different menus this way — build the second menu separately."]], 422);
            }
        }

        // A dropped item can't land inside its own subtree — that would
        // orphan the branch from the tree entirely, not just misplace it.
        $descendant = $newParentId;
        while ($descendant !== null) {
            if ($descendant === $item->id) {
                return response()->json(['ok' => false, 'errors' => ["Can't drop an item inside its own branch."]], 422);
            }
            $descendant = MenuItem::find($descendant)?->parent_id;
        }

        $ownDepth = $this->subtreeDepth($item->id);
        $targetDepth = $this->depthOf($newParentId);

        if ($targetDepth + 1 + $ownDepth > 3) {
            return response()->json(['ok' => false, 'errors' => ['That would push what\'s nested under this item past the third level, which the panel never reads.']], 422);
        }

        DB::transaction(function () use ($item, $newParentId, $data) {
            $item->update(['parent_id' => $newParentId]);

            foreach ($data['ids'] as $position => $id) {
                MenuItem::where('id', $id)->update(['position' => $position]);
            }
        });

        $this->nav->flush();

        return response()->json(['ok' => true]);
    }

    /** How many levels deep this item's own children go — 0 for a leaf, 1 if it has children but no grandchildren, and so on. */
    private function subtreeDepth(int $id): int
    {
        $children = MenuItem::where('parent_id', $id)->pluck('id');

        if ($children->isEmpty()) {
            return 0;
        }

        return 1 + $children->map(fn ($childId) => $this->subtreeDepth($childId))->max();
    }

    private function depthOf(?int $parentId): int
    {
        $depth = 0;

        while ($parentId !== null) {
            $parent = MenuItem::find($parentId);

            if (! $parent) {
                break;
            }

            $depth++;
            $parentId = $parent->parent_id;
        }

        return $depth;
    }

    /**
     * Which menu the screen is looking at. An explicit id wins; otherwise
     * the first menu that exists; otherwise a fresh one is created and
     * assigned to the desktop header, since a brand-new install with zero
     * menus should not show an empty screen with no way to get started.
     */
    private function resolveMenu(?string $menuId): Menu
    {
        if ($menuId) {
            $menu = Menu::find((int) $menuId);
            if ($menu) {
                return $menu;
            }
        }

        return Menu::orderBy('id')->first() ?? Menu::create([
            'slug' => $this->uniqueSlug('Primary Navigation'),
            'name' => 'Primary Navigation',
            'show_desktop' => true,
        ]);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'menu';
        $slug = $base;
        $n = 2;

        while (Menu::where('slug', $slug)->exists()) {
            $slug = $base . '-' . $n++;
        }

        return $slug;
    }
}

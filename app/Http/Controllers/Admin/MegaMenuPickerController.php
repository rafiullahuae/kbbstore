<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MenuItem;
use App\Services\NavigationService;
use App\Services\SettingsService;
use App\Support\MenuTargets;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Store → Mega Menu → Add items: the WordPress-style list of everything a
 * menu can link to, and the one request that files several of them into a
 * column.                                                            (Lane MX)
 *
 * Both endpoints sit under admin-api/mega-menu/**, so AdminCapabilities gives
 * them the menu editor's own capability (content.manage) with no entry of
 * their own; MegaMenuPickerTest pins that a role without it is refused.
 *
 * sources(): ONE request when the panel opens, everything in it. The search
 * box filters in the browser, so there is no request per keystroke, and the
 * query count is the same for three categories as for three hundred.
 *
 * pick(): {menu_id, parent_id, items: [{type, id} | {type: 'custom', label, url}]}.
 * Every item is resolved on the server from its id — the client sends no
 * address for anything but a custom link — and a custom link is a path on
 * this shop or an https URL, checked here whatever the browser said.
 */
class MegaMenuPickerController extends Controller
{
    /** One add request at most; the panel's "tick all" on a long list is still one click. */
    public const MAX_ITEMS = 100;

    public function __construct(private NavigationService $nav) {}

    public function sources(SettingsService $settings): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'groups' => MenuTargets::catalogue(),
            /*
             * The Brands module off drops every /brands/{slug}/ link from the
             * header (NavigationService::filterItems). Said on the panel, so a
             * brand added while it is off is not a mystery.
             */
            'brands_module_on' => $settings->moduleEnabled('brands', true),
        ]);
    }

    public function pick(Request $request): JsonResponse
    {
        $data = $request->validate([
            'menu_id' => ['required', 'integer', 'exists:menus,id'],
            'parent_id' => ['nullable', 'integer', 'exists:menu_items,id'],
            'items' => ['required', 'array', 'min:1', 'max:'.self::MAX_ITEMS],
            'items.*' => ['required', 'array'],
            'items.*.type' => ['required', 'string', 'in:'.implode(',', [...MenuTargets::TYPES, 'collection', 'custom'])],
            'items.*.id' => ['nullable'],
            'items.*.label' => ['nullable', 'string', 'max:60'],
            'items.*.url' => ['nullable', 'string', 'max:255'],
        ]);

        $menuId = (int) $data['menu_id'];
        $parentId = isset($data['parent_id']) ? (int) $data['parent_id'] : null;

        if ($parentId !== null) {
            $parent = MenuItem::query()->find($parentId, ['id', 'menu_id', 'parent_id']);

            // A parent from another menu would file the rows where neither menu draws them.
            if ((int) $parent->menu_id !== $menuId) {
                return $this->refuse('That column is on a different menu.');
            }

            // Top item (0) or one of its columns (1); under a link (2) nothing is ever shown.
            $depth = 1;
            for ($p = $parent->parent_id, $guard = 0; $p !== null && $guard < 5; $guard++) {
                $depth++;
                $p = MenuItem::query()->whereKey($p)->value('parent_id');
            }
            if ($depth >= 3) {
                return $this->refuse('The panel only reads three levels deep — top item, column, link. Pick a column instead.');
            }
        }

        /*
         * Resolve before writing anything: one query per kind, and the label
         * and address come from the row, never from the request.
         */
        $wanted = [];
        foreach ($data['items'] as $item) {
            if (in_array($item['type'], MenuTargets::TYPES, true)) {
                $wanted[$item['type']][] = (int) ($item['id'] ?? 0);
            }
        }
        $found = [];
        foreach ($wanted as $type => $ids) {
            $found[$type] = MenuTargets::resolve($type, $ids);
        }
        $collections = collect(MenuTargets::collections())->keyBy('id');

        $rows = [];
        foreach ($data['items'] as $n => $item) {
            $type = $item['type'];

            if ($type === 'custom') {
                $label = trim((string) ($item['label'] ?? ''));
                $url = trim((string) ($item['url'] ?? ''));

                if ($label === '') {
                    return $this->refuse('Give the custom link a label.');
                }
                if (! MenuTargets::customUrlAllowed($url)) {
                    return $this->refuse('A custom link must be a path on this shop (starting with /) or an https:// address.');
                }

                $rows[] = ['label' => $label, 'url' => $url, 'target_type' => null, 'target_id' => null];

                continue;
            }

            if ($type === 'collection') {
                $hit = $collections->get((string) ($item['id'] ?? ''));
                if ($hit === null) {
                    return $this->refuse('That listing is not on this shop.');
                }
                $rows[] = ['label' => $this->label($hit['name']), 'url' => $hit['url'], 'target_type' => null, 'target_id' => null];

                continue;
            }

            $id = (int) ($item['id'] ?? 0);
            $hit = $found[$type][$id] ?? null;
            if ($hit === null) {
                return $this->refuse("Item ".($n + 1)." is no longer on the shop (deleted, or not published) — reopen the list and try again.");
            }

            $rows[] = ['label' => $this->label($hit[0]), 'url' => $hit[1], 'target_type' => $type, 'target_id' => $id];
        }

        $created = DB::transaction(function () use ($rows, $menuId, $parentId) {
            $position = MenuItem::query()->where('menu_id', $menuId)->where('parent_id', $parentId)->max('position');
            $position = $position === null ? 0 : (int) $position + 1;
            $out = [];

            foreach ($rows as $row) {
                $out[] = MenuItem::query()->create($row + [
                    'menu_id' => $menuId,
                    'parent_id' => $parentId,
                    'visibility' => 'always',
                    'new_tab' => false,
                    'position' => $position++,
                ]);
            }

            return $out;
        });

        $this->nav->flush();

        return response()->json([
            'ok' => true,
            // The editor's own node shape (MegaMenuApiController::tree), so the
            // board draws the new rows without a re-fetch.
            'items' => array_map(fn (MenuItem $m) => [
                'id' => (int) $m->id,
                'label' => $m->label,
                'url' => $m->url,
                'icon' => null,
                'badge' => null,
                'highlight_color' => null,
                'visibility' => 'always',
                'new_tab' => false,
                'parked' => false,
                'translations' => null,
                'children' => [],
            ], $created),
        ]);
    }

    /** A name longer than the label column's 60 characters is cut, not refused: the owner can edit it after. */
    private function label(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');

        return mb_strlen($name) > 60 ? rtrim(mb_substr($name, 0, 59)).'…' : $name;
    }

    private function refuse(string $why): JsonResponse
    {
        return response()->json(['ok' => false, 'errors' => [$why]], 422);
    }
}

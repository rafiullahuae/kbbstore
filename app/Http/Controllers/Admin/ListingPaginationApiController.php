<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Services\SiteLayout;
use App\Support\ListingPagination;
use App\Support\Url;
use App\Support\UrlScheme;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Catalog → Pagination.                                              (Lane PG)
 *
 *     GET  admin-api/pagination   the switch, the overrides, and every
 *                                 category, brand and listing page the picker
 *                                 searches — once, so typing costs nothing
 *     POST admin-api/pagination   save {on, overrides}
 *
 * Both behind `pagination.manage` (AdminCapabilities::RULES); a path missing
 * from that map is owner-only, so a mistake fails closed.
 *
 * FLAT IN THE CATALOGUE: the read is one query for categories and one for
 * brands whatever either holds (ListingPaginationTest renders with 3 and 40).
 */
class ListingPaginationApiController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json($this->payload());
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate([
            'on' => ['required', 'boolean'],
            'overrides' => ['present', 'array'],
        ]);

        $errors = ListingPagination::save((bool) $data['on'], (array) $data['overrides']);

        if ($errors !== []) {
            return response()->json([
                'ok' => false,
                'error' => 'Not saved — '.implode(' ', array_slice(array_unique(array_values($errors)), 0, 3)),
                'rejected' => array_keys($errors),
            ], 422);
        }

        return response()->json(['ok' => true] + $this->payload());
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $categories = Category::query()
            ->orderBy('path')->orderBy('name')->orderBy('id')
            ->get(['id', 'name', 'slug', 'path'])
            ->map(fn (Category $c) => [
                'id' => (int) $c->id,
                'name' => (string) $c->name,
                'path' => (string) ($c->path ?: $c->slug),
                'url' => Url::to(UrlScheme::collection((string) ($c->path ?: $c->slug))),
            ])->values()->all();

        $brands = Brand::query()
            ->orderBy('name')->orderBy('id')
            ->get(['id', 'name', 'slug'])
            ->map(fn (Brand $b) => [
                'id' => (int) $b->id,
                'name' => (string) $b->name,
                'url' => Url::to(UrlScheme::brand((string) $b->slug)),
            ])->values()->all();

        $pages = [];
        foreach (ListingPagination::pages() as $key => [$label, $path]) {
            $pages[] = ['key' => $key, 'label' => $label, 'path' => $path, 'url' => Url::to($path)];
        }

        return [
            'on' => ListingPagination::globalOn(),
            'overrides' => ListingPagination::overrides(),
            'cap' => ListingPagination::CAP,
            'brandAll' => (bool) app(SiteLayout::class)->get('brand_all'),
            'pages' => $pages,
            'categories' => $categories,
            'brands' => $brands,
        ];
    }
}

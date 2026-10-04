<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Media;
use App\Models\Product;
use App\Services\PageBanners;
use App\Services\SettingsService;
use App\Support\SuperSale;
use App\Support\Url;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pages → Page banners. (Lane SS)
 *
 *   GET  /admin-api/page-banners   the library, which page shows which, and
 *                                  the Super Sale product source
 *   POST /admin-api/page-banners   save all three, all or nothing
 *
 * Both behind `pagebanners.manage` (AdminCapabilities), which fails closed:
 * a role without it gets a 403 before this class is constructed.
 */
class PageBannersApiController extends Controller
{
    public function __construct(private PageBanners $banners, private SettingsService $settings) {}

    public function show(): JsonResponse
    {
        return response()->json($this->payload());
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate([
            'banners' => ['present', 'array'],
            'assign' => ['present', 'array'],
            'super_sale_source' => ['required', 'string', 'max:40'],
        ]);

        $source = $data['super_sale_source'];
        $sourceOk = isset(SuperSale::OPTIONS[$source])
            || (preg_match('/^category:([1-9]\d{0,9})$/', $source, $m) === 1
                && Category::query()->whereKey((int) $m[1])->exists());

        $in = ['banners' => $this->withSizes($data['banners']), 'assign' => $data['assign']];
        [, $rejected] = PageBanners::sanitize($in, true);

        if (! $sourceOk) {
            $rejected['super_sale_source'] = 'Super Sale products';
        }

        if ($rejected !== []) {
            return response()->json([
                'ok' => false,
                'error' => 'Not saved — check: '.implode(', ', array_unique(array_values($rejected))),
                'rejected' => array_keys($rejected),
            ], 422);
        }

        $this->banners->save($in);
        $this->settings->set(SuperSale::KEY, $source);

        return response()->json(['ok' => true] + $this->payload());
    }

    /**
     * Each picture's own width and height, read off the Media Library row it
     * came from, so the page can reserve the box before the bytes arrive. A
     * picture not in the library (a pasted address) simply has none, and the
     * page draws it without — it shifts once, it does not break. At most one
     * query per picture, and only on save.
     */
    private function withSizes(array $banners): array
    {
        foreach ($banners as $n => $b) {
            if (! is_array($b)) {
                continue;
            }
            foreach (['d', 'm'] as $dev) {
                $banners[$n]['w_'.$dev] = null;
                $banners[$n]['h_'.$dev] = null;
                $url = trim((string) ($b['img_'.$dev] ?? ''));
                if ($url === '' || strlen($url) > 500) {
                    continue;
                }
                $path = (string) parse_url($url, PHP_URL_PATH);
                $candidates = [];
                foreach (['/wp-content/uploads/', '/uploads/'] as $root) {
                    $at = strpos($path, $root);
                    if ($at !== false) {
                        $rest = substr($path, $at + strlen($root));
                        $candidates[] = $rest;
                        $candidates[] = ltrim($root, '/').$rest;
                        $candidates[] = $root.$rest;
                        break;
                    }
                }
                if ($candidates === []) {
                    continue;
                }
                $media = Media::query()->whereIn('path', $candidates)->first(['path', 'width', 'height']);
                if ($media && $media->width && $media->height && $media->url() === $url) {
                    $banners[$n]['w_'.$dev] = (int) $media->width;
                    $banners[$n]['h_'.$dev] = (int) $media->height;
                }
            }
        }

        return $banners;
    }

    private function payload(): array
    {
        $this->banners->forget();
        $all = $this->banners->all();

        // A content page by its own title, where it has one: one query.
        $keys = PageBanners::pageKeys();
        $titles = \App\Models\Page::query()
            ->whereIn('slug', array_map(static fn ($k) => substr($k, 5), array_filter(array_keys($keys), static fn ($k) => str_starts_with($k, 'page:'))))
            ->pluck('title', 'slug');

        $pages = [];
        foreach ($keys as $key => [$label, $path]) {
            $title = str_starts_with($key, 'page:') ? trim(html_entity_decode(strip_tags((string) ($titles[substr($key, 5)] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
            $pages[] = ['key' => $key, 'label' => $title !== '' ? $title : $label, 'path' => $path, 'url' => Url::to($path)];
        }

        $numbers = [];
        foreach (PageBanners::NUMBERS as $k => [$min, $max, $default, $label]) {
            $numbers[] = ['key' => $k, 'min' => $min, 'max' => $max, 'default' => $default, 'label' => $label];
        }
        $colours = [];
        foreach (PageBanners::COLOURS as $k => [$default, $label]) {
            $colours[] = ['key' => $k, 'default' => $default, 'label' => $label];
        }

        return [
            'banners' => $all['banners'],
            'assign' => (object) $all['assign'],
            'pages' => $pages,
            'numbers' => $numbers,
            'colours' => $colours,
            'blank' => PageBanners::blank('new', 'New banner'),
            'limits' => ['banners' => PageBanners::MAX_BANNERS, 'items' => PageBanners::MAX_ITEMS, 'text' => PageBanners::MAX_TEXT],
            'breakpoint' => PageBanners::BREAKPOINT,
            'css' => PageBanners::CSS,
            'icon' => PageBanners::ICON,
            'super_sale' => $this->superSale(),
        ];
    }

    /** The source select, and what it resolves to on this shop right now. */
    private function superSale(): array
    {
        $source = SuperSale::source($this->settings);
        $categories = Category::query()->orderBy('name')->get(['id', 'name', 'slug']);

        $options = [];
        foreach (SuperSale::OPTIONS as $value => $label) {
            $options[] = ['value' => $value, 'label' => $label];
        }
        foreach ($categories as $c) {
            $options[] = ['value' => 'category:'.$c->id, 'label' => 'Category: '.$c->name];
        }

        $campaign = SuperSale::campaign($this->settings);
        $count = $campaign === null ? 0 : SuperSale::apply(Product::query()->visible(), $campaign)->reorder()->count();
        $found = $campaign === null || ($campaign[0] === 'slug'
            ? $categories->contains('slug', SuperSale::CATEGORY_SLUG)
            : $categories->contains('id', $campaign[1]));

        return [
            'source' => $source,
            'options' => $options,
            'category_found' => $found,
            'products' => $count,
            'falls_back' => $campaign !== null && $count === 0,
        ];
    }
}

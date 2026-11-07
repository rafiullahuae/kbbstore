<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Product;
use App\Services\Cutouts\ProductCutouts;
use App\Services\SettingsService;
use App\Support\OccasionBanner;
use App\Support\Url;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Appearance -> Occasion banner (Lane OHB). routes/occasion-banner-admin.php.
 *
 * The screen builds its live preview in the browser from what show() hands it
 * (the templates, the stylesheet, the pool with its cut-out URLs), so typing
 * costs no request. The server is asked only to search, to save and to make
 * one product's cut-out at a time.
 *
 * THE SNAPSHOT. save() and cutout() write, for every pool product, what the
 * homepage needs to draw it -- name, brand, the cut-out files' widths -- into
 * the setting, read from the products table and the disk HERE, so a homepage
 * view never queries for the banner. The browser sends only ids, heights and
 * the "white product" tick; everything else is the server's own.
 */
final class OccasionBannerController extends Controller
{
    private const SEARCH_CAP = 20;

    private const SUGGEST_CAP = 40;

    public function __construct(private SettingsService $settings) {}

    public function show(Request $request): JsonResponse
    {
        $c = $this->stored();

        return response()->json([
            'ok' => true,
            'config' => $this->forScreen($c),
            'catalogue' => OccasionBanner::adminCatalogue(),
            'can_manage' => $this->canManage($request),
            'base' => Url::base(),
        ]);
    }

    public function products(Request $request): JsonResponse
    {
        $q = trim(mb_substr((string) $request->query('q', ''), 0, 80));
        if (mb_strlen($q) < 2) {
            return response()->json(['ok' => true, 'products' => []]);
        }
        $like = '%'.addcslashes($q, '%_\\').'%';
        $rows = Product::query()
            ->select(['id', 'name', 'brand_id', 'image'])
            ->visible()
            ->with('brand:id,name')
            ->where(fn ($w) => $w->where('name', 'like', $like)
                ->orWhere('sku', $q)
                ->orWhereIn('brand_id', Brand::query()->select('id')->where('name', 'like', $like)))
            ->orderByDesc('total_sales')->orderByDesc('id')
            ->limit(self::SEARCH_CAP)
            ->get();

        return response()->json(['ok' => true, 'products' => $rows->map(fn (Product $p) => $this->card($p))->values()->all()]);
    }

    /** The shop's best sellers, one per brand -- "choose rest any most selling in our website". */
    public function suggest(): JsonResponse
    {
        $rows = Product::query()
            ->select(['id', 'name', 'brand_id', 'image'])
            ->visible()
            ->whereNotNull('image')->where('image', '!=', '')
            ->with('brand:id,name')
            ->orderByDesc('total_sales')->orderByDesc('id')
            ->limit(400)
            ->get();
        $out = [];
        $seen = [];
        foreach ($rows as $p) {
            $b = (int) $p->brand_id;
            if ($b > 0 && isset($seen[$b])) {
                continue;
            }
            $seen[$b] = true;
            $out[] = $this->card($p);
            if (count($out) >= self::SUGGEST_CAP) {
                break;
            }
        }

        return response()->json(['ok' => true, 'products' => $out]);
    }

    public function save(Request $request): JsonResponse
    {
        $in = $request->input('config');
        if (! is_array($in)) {
            return response()->json(['ok' => false, 'message' => 'Nothing to save.'], 422);
        }
        $clean = OccasionBanner::sanitize($in);
        $clean['pool'] = $this->snapshot($clean['pool']);
        $this->settings->set(OccasionBanner::KEY, $clean);

        return response()->json(['ok' => true, 'config' => $this->forScreen($clean)]);
    }

    /** Make (or remake) one product's cut-out. One request per product; the screen walks the pool. */
    public function cutout(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['required', 'integer', 'min:1'],
            'white' => ['sometimes', 'boolean'],
            'remake' => ['sometimes', 'boolean'],
        ]);
        $p = Product::query()->select(['id', 'name', 'brand_id', 'image'])->with('brand:id,name')->find((int) $data['id']);
        if ($p === null) {
            return response()->json(['ok' => false, 'message' => 'That product no longer exists.'], 404);
        }
        $img = self::imagePath((string) $p->image);
        if ($img === '') {
            return response()->json(['ok' => false, 'message' => 'This product has no photo.'], 422);
        }
        if (! empty($data['remake'])) {
            ProductCutouts::forget($img);
        }
        $r = ProductCutouts::has($img) ? ['ok' => true] : ProductCutouts::make($img, (bool) ($data['white'] ?? false));
        if (! $r['ok']) {
            return response()->json(['ok' => false, 'message' => 'Not cut out: '.($r['reason'] ?? 'unknown reason').'.', 'product' => $this->card($p)], 422);
        }

        $this->resnap($p, $img, isset($data['white']) ? (bool) $data['white'] : null);

        return response()->json(['ok' => true, 'ms' => $r['ms'] ?? 0, 'product' => $this->card($p)]);
    }

    /**
     * "Upload cut-out": the owner's own transparent PNG or WebP for one
     * product. ProductCutouts::upload() validates it from its bytes and
     * re-encodes it through GD; from then on Prepare / Remake put it back
     * rather than cutting over it.
     */
    public function fromMedia(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['required', 'integer', 'min:1'],
            'url' => ['required', 'string', 'max:500'],
        ]);
        $p = Product::query()->select(['id', 'name', 'brand_id', 'image'])->with('brand:id,name')->find((int) $data['id']);
        $img = $p === null ? '' : self::imagePath((string) $p->image);
        if ($img === '') {
            return response()->json(['ok' => false, 'message' => 'That product has no photo.'], 422);
        }
        $r = ProductCutouts::fromMedia($img, (string) $data['url']);
        if (! $r['ok']) {
            return response()->json(['ok' => false, 'message' => 'Not used: '.($r['reason'] ?? 'unknown reason').'.'], 422);
        }
        $this->resnap($p, $img, null);

        return response()->json(['ok' => true, 'product' => $this->card($p)]);
    }

    /** "Use automatic": drop the hand-made cut-out for this product and cut one automatically. */
    public function automatic(Request $request): JsonResponse
    {
        $data = $request->validate(['id' => ['required', 'integer', 'min:1'], 'white' => ['sometimes', 'boolean']]);
        $p = Product::query()->select(['id', 'name', 'brand_id', 'image'])->with('brand:id,name')->find((int) $data['id']);
        $img = $p === null ? '' : self::imagePath((string) $p->image);
        if ($img === '') {
            return response()->json(['ok' => false, 'message' => 'That product has no photo.'], 422);
        }
        $r = ProductCutouts::automatic($img, (bool) ($data['white'] ?? false));
        $this->resnap($p, $img, isset($data['white']) ? (bool) $data['white'] : null);
        if (! $r['ok']) {
            return response()->json(['ok' => false, 'message' => 'Not cut out: '.($r['reason'] ?? 'unknown reason').'.', 'product' => $this->card($p)], 422);
        }

        return response()->json(['ok' => true, 'product' => $this->card($p)]);
    }

    /** Keep the stored pool's snapshot in step, if the product is in it. */
    private function resnap(Product $p, string $img, ?bool $white): void
    {
        $c = $this->stored();
        $changed = false;
        foreach ($c['pool'] as $i => $item) {
            if ($item['id'] === $p->id) {
                $c['pool'][$i]['cut'] = OccasionBanner::cutSnapshot($img);
                $c['pool'][$i]['white'] = $white ?? $item['white'];
                $changed = true;
            }
        }
        if ($changed) {
            $this->settings->set(OccasionBanner::KEY, $c);
        }
    }

    /** The stored setting, normalised. */
    private function stored(): array
    {
        return OccasionBanner::config([OccasionBanner::KEY => $this->settings->get(OccasionBanner::KEY)]);
    }

    /**
     * Rebuild every pool row's snapshot from the products table (ONE query)
     * and the disk; a product that is gone or hidden leaves the pool.
     *
     * @param  list<array<string,mixed>>  $pool
     * @return list<array<string,mixed>>
     */
    private function snapshot(array $pool): array
    {
        if ($pool === []) {
            return [];
        }
        $rows = Product::query()->select(['id', 'name', 'brand_id', 'image'])->visible()
            ->with('brand:id,name')->whereIn('id', array_column($pool, 'id'))->get()->keyBy('id');
        $out = [];
        foreach ($pool as $item) {
            $p = $rows->get($item['id']);
            if ($p === null) {
                continue;
            }
            $img = self::imagePath((string) $p->image);
            $out[] = [
                'id' => (int) $p->id,
                'cm' => $item['cm'],
                'white' => $item['white'],
                'name' => mb_substr((string) $p->name, 0, 120),
                'brand' => mb_substr((string) ($p->brand?->name ?? ''), 0, 60),
                'brand_id' => (int) $p->brand_id,
                'image' => mb_substr($img, 0, 300),
                'cut' => $img === '' ? null : OccasionBanner::cutSnapshot($img),
            ];
        }

        return $out;
    }

    /** The config as the screen edits it: every pool row with its cut-out URLs for the preview. */
    private function forScreen(array $c): array
    {
        foreach ($c['pool'] as $i => $item) {
            $c['pool'][$i]['cut_url'] = $item['cut'] === null ? null : OccasionBanner::cutUrls($item['cut']);
            $c['pool'][$i]['cut_own'] = $item['cut'] !== null && ProductCutouts::isOwn((string) $item['image']);
        }

        return $c;
    }

    private function card(Product $p): array
    {
        $img = self::imagePath((string) $p->image);
        $cut = $img === '' ? null : OccasionBanner::cutSnapshot($img);

        return [
            'id' => (int) $p->id,
            'name' => (string) $p->name,
            'brand' => (string) ($p->brand?->name ?? ''),
            'brand_id' => (int) $p->brand_id,
            'image' => $img,
            'cut' => $cut,
            'cut_url' => $cut === null ? null : OccasionBanner::cutUrls($cut),
            'cut_own' => $cut !== null && ProductCutouts::isOwn($img),
        ];
    }

    /** The product's photo as a path on this site (what ProductCutouts reads), or ''. */
    public static function imagePath(string $image): string
    {
        $image = trim($image);
        if ($image === '') {
            return '';
        }
        if (str_starts_with($image, '/') || preg_match('#^[a-z][a-z0-9+.-]*://#i', $image) === 1) {
            return $image;
        }

        return Url::media($image);
    }

    private function canManage(Request $request): bool
    {
        $admin = $request->user('admin');

        return $admin instanceof \App\Models\AdminUser && \App\Support\AdminRoles::can($admin, 'occasionbanner.manage');
    }
}

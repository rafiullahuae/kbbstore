<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\Cutouts\ProductCutouts;
use App\Services\FreeGifts;
use App\Services\SettingsService;
use App\Support\GiftStrip;
use App\Support\Locale;
use App\Support\Url;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Appearance -> Free gifts strip.                                   (Lane GST)
 *
 * Four endpoints, one capability (`giftstrip.manage`, mapped in
 * AdminCapabilities for the bare path and its children; a path left off that
 * map is owner-only, so a mistake fails closed):
 *
 *   GET  gift-strip           the setting, the five designs' CSS, and every
 *                             product the strip can draw with its cut-out
 *                             state -- everything the screen's live preview
 *                             is built from, so changing a control asks the
 *                             server nothing.
 *   POST gift-strip           save, and rebuild the snapshot the shop reads.
 *   GET  gift-strip/products  "Pick products" search: 2 characters, 20 rows.
 *   POST gift-strip/cutout    make ONE product's cut-out ("Prepare cut-outs"
 *                             calls it once per product, one after another).
 *
 * Nothing returns a model: every row is built here from named columns.
 */
class GiftStripApiController extends Controller
{
    /** The keys a save may carry. `snap` is the server's alone. */
    private const KEYS = ['on', 'desktop', 'mobile', 'style', 'icon', 'anim', 'url', 'source', 'texts', 'picks', 'heights', 'white'];

    public function __construct(private SettingsService $settings, private FreeGifts $gifts) {}

    public function show(): JsonResponse
    {
        return response()->json($this->payload($this->config()));
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate(['settings' => ['required', 'array']]);
        $in = $data['settings'];

        $unknown = array_diff(array_keys($in), self::KEYS);
        if ($unknown !== []) {
            return response()->json(['ok' => false, 'error' => 'Unknown setting: ' . implode(', ', $unknown)], 422);
        }

        // A link that is not a path on this shop or an http(s) address is
        // refused out loud rather than quietly replaced.
        if (isset($in['url']) && is_string($in['url']) && trim($in['url']) !== '' && GiftStrip::url($in['url']) !== trim($in['url'])) {
            return response()->json(['ok' => false, 'error' => 'The link must start with / (a page of this shop) or https://'], 422);
        }

        $cfg = GiftStrip::normalise(['snap' => []] + $in);
        $cfg['snap'] = $this->snapshot($cfg);
        $this->settings->set(GiftStrip::KEY, $cfg);

        return response()->json(['ok' => true] + $this->payload($cfg));
    }

    public function products(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['rows' => []]);
        }
        $like = '%' . addcslashes(mb_substr($q, 0, 60), '%_\\') . '%';
        $rows = Product::query()
            ->where(static fn ($w) => $w->where('name', 'like', $like)->orWhere('sku', 'like', $like))
            ->where('status', 'publish')
            ->whereNotNull('image')->where('image', '!=', '')
            ->orderBy('name')->orderBy('id')->limit(20)
            ->get(['id', 'name', 'image']);

        $cfg = $this->config();

        return response()->json(['rows' => $rows->map(fn (Product $p) => $this->row($p, $cfg, false))->values()->all()]);
    }

    public function cutout(Request $request): JsonResponse
    {
        $data = $request->validate(['id' => ['required', 'integer', 'min:1'], 'white' => ['sometimes', 'boolean']]);
        $p = Product::query()->whereKey((int) $data['id'])->first(['id', 'name', 'image']);
        if ($p === null || trim((string) $p->image) === '') {
            return response()->json(['ok' => false, 'error' => 'That product has no photo.'], 422);
        }

        $made = ProductCutouts::make(trim((string) $p->image), (bool) ($data['white'] ?? false));
        if (! ($made['ok'] ?? false)) {
            return response()->json(['ok' => false, 'error' => 'Not cut out: ' . ($made['reason'] ?? 'unknown')], 422);
        }

        // Remember the tick he cut it with, so the next run cuts it the same.
        $cfg = $this->config();
        $white = array_values(array_diff($cfg['white'], [(int) $p->id]));
        if ($data['white'] ?? false) {
            $white[] = (int) $p->id;
        }
        $cfg['white'] = $white;
        $cfg['snap'] = $this->snapshot($cfg);
        $this->settings->set(GiftStrip::KEY, $cfg);

        return response()->json(['ok' => true, 'ms' => (int) ($made['ms'] ?? 0), 'cut' => ProductCutouts::for(trim((string) $p->image))]);
    }

    private function config(): array
    {
        return GiftStrip::normalise($this->settings->get(GiftStrip::KEY));
    }

    /**
     * What the shop reads instead of querying: the live gifts, then his picks,
     * each product once. Two queries for the live gifts (products with their
     * translations, variations when named) and one for the picks.
     */
    private function snapshot(array $cfg): array
    {
        $rows = [];
        foreach (GiftStrip::liveProducts($this->gifts) as $p) {
            $rows[(int) $p->id] = GiftStrip::snapRow($p);
        }
        $missing = array_values(array_diff($cfg['picks'], array_keys($rows)));
        if ($missing !== []) {
            foreach (Product::query()->with('translations')->whereIn('id', $missing)->get(['id', 'name', 'image']) as $p) {
                $rows[(int) $p->id] = GiftStrip::snapRow($p);
            }
        }

        return array_slice(array_values(array_filter($rows, static fn (array $r) => $r['image'] !== '')), 0, GiftStrip::MAX_SNAP);
    }

    private function row(Product $p, array $cfg, bool $live): array
    {
        $image = trim((string) $p->image);
        $cut = $image === '' ? null : ProductCutouts::for($image);

        return [
            'id' => (int) $p->id,
            'name' => (string) $p->name,
            'thumb' => $image,
            'cut' => $cut,
            'cm' => GiftStrip::heightFor($cfg, (int) $p->id, (string) $p->name),
            'white' => in_array((int) $p->id, $cfg['white'], true),
            'live' => $live,
        ];
    }

    private function payload(array $cfg): array
    {
        $live = [];
        foreach (GiftStrip::liveProducts($this->gifts) as $p) {
            $live[] = $this->row($p, $cfg, true);
        }
        $liveIds = array_column($live, 'id');
        $picked = [];
        $need = array_values(array_diff($cfg['picks'], $liveIds));
        $byId = $need === [] ? [] : Product::query()->whereIn('id', $need)->get(['id', 'name', 'image'])->keyBy('id')->all();
        foreach ($cfg['picks'] as $id) {
            if (in_array($id, $liveIds, true)) {
                $picked[] = $live[array_search($id, $liveIds, true)];
            } elseif (isset($byId[$id])) {
                $picked[] = $this->row($byId[$id], $cfg, false);
            }
        }

        $css = [];
        foreach (GiftStrip::STYLES as $k => $name) {
            $css[$k] = GiftStrip::STYLE_CSS[$k];
        }
        unset($cfg['snap']);

        return [
            'values' => $cfg,
            'live' => $live,
            'picked' => $picked,
            'gifts_on' => $this->giftsOn(),
            'preview' => [
                'styles' => GiftStrip::STYLES,
                'base' => GiftStrip::BASE_CSS,
                'css' => $css,
                'symbol' => GiftStrip::GIFT_SYMBOL,
                'icon' => GiftStrip::GIFT_ICON,
                'texts' => GiftStrip::TEXTS,
                'default_url' => GiftStrip::DEFAULT_URL,
                'per_side' => GiftStrip::PER_SIDE,
                'max_picks' => GiftStrip::MAX_PICKS,
                'h_min' => GiftStrip::H_MIN,
                'h_max' => GiftStrip::H_MAX,
                'rtl' => Locale::rtlEnabled(),
                'home' => Url::to('/'),
            ],
        ];
    }

    /** Whether the gifts are on for shoppers (Marketing -> Free gifts): the strip says nothing while they are not. */
    private function giftsOn(): string
    {
        $saved = $this->settings->get(FreeGifts::KEY);
        $mode = FreeGifts::modeOf(is_array($saved) ? $saved : []);

        return in_array($mode, ['live', 'testing', 'off'], true) ? $mode : 'off';
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\CartRecoveryReminder;
use App\Mail\OrderPaymentReminder;
use App\Models\Order;
use App\Models\Product;
use App\Services\FreeGifts;
use App\Services\GiftOffers\GiftClock;
use App\Services\GiftOffers\GiftOffers;
use App\Services\GiftOffers\GiftOfferStats;
use App\Support\CustomerLocale;
use App\Support\ImageVariants;
use App\Support\Money;
use App\Support\StoreTime;
use App\Support\Url;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Growth & Marketing -> Gift offers (Lane GO). routes/gift-offers-admin.php.
 *
 * giftoffers.view: the screen, the product search, the live preview (renders
 * the unsaved draft it is sent and stores nothing) and the report.
 * giftoffers.manage: every write and the test send. Both owner and manager;
 * EnforceAdminCapability refuses everybody else before this class runs.
 *
 * Every product row the screen gets is the allowlist in productRow(): never
 * a model (products carry wc_id, sku and total_sales).
 */
class GiftOffersController extends Controller
{
    /**
     * A fresh instance per action: Laravel keeps a controller on its route, and
     * a configuration memoised (or a preview's draft) by an earlier request
     * must never decide a later one (the Free gifts lesson, Lane FG2).
     */
    private GiftOffers $offers;

    private function fresh(): void
    {
        $this->offers = app(GiftOffers::class);
    }

    public function show(): JsonResponse
    {
        $this->fresh();
        return response()->json($this->payload());
    }

    /** POST settings {config}: the switches, hours, minutes, timer, window, popup, texts. */
    public function settings(Request $request): JsonResponse
    {
        $this->fresh();
        $request->validate([
            'config' => ['required', 'array'],
            'config.texts' => ['sometimes', 'array'],
            'config.texts.*' => ['nullable', 'string', 'max:' . GiftOffers::TEXT_MAX],
        ]);

        $this->offers->saveConfig((array) $request->input('config'));

        return response()->json($this->payload());
    }

    /** POST ranges | PUT ranges/{id}: a range and its products, in one save. */
    public function saveRange(Request $request, ?int $id = null): JsonResponse
    {
        $this->fresh();
        $data = $request->validate([
            'min' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'max' => ['nullable', 'numeric', 'min:0', 'max:1000000', 'gte:min'],
            'enabled' => ['boolean'],
            'items' => ['present', 'array', 'max:' . GiftOffers::MAX_ITEMS],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'items.*.role' => ['required', Rule::in(['primary', 'backup'])],
            'items.*.allocation' => ['required', 'integer', 'min:0', 'max:' . GiftOffers::MAX_ALLOCATION],
        ], [
            'max.gte' => 'The range\'s top must be at least its start.',
        ]);

        $items = array_values($data['items']);

        if ($items !== [] && count(array_filter($items, static fn ($i) => $i['role'] === 'primary')) !== 1) {
            return response()->json(['message' => 'Choose exactly one primary product; every other product is a backup.'], 422);
        }

        if ($id === null && DB::table('gift_offer_ranges')->count() >= GiftOffers::MAX_RANGES) {
            return response()->json(['message' => 'At most ' . GiftOffers::MAX_RANGES . ' ranges.'], 422);
        }

        if ($id !== null && ! DB::table('gift_offer_ranges')->where('id', $id)->exists()) {
            return response()->json(['message' => 'That range no longer exists.'], 404);
        }

        // The primary first, then the backups in the order the owner put them.
        usort($items, static fn ($a, $b) => ($a['role'] === 'primary' ? 0 : 1) <=> ($b['role'] === 'primary' ? 0 : 1));

        DB::transaction(function () use (&$id, $data, $items): void {
            $row = [
                'min_fils' => (int) round((float) $data['min'] * 100),
                'max_fils' => $data['max'] === null ? null : (int) round((float) $data['max'] * 100),
                'enabled' => (bool) ($data['enabled'] ?? true),
                'updated_at' => now(),
            ];

            if ($id === null) {
                $id = (int) DB::table('gift_offer_ranges')->insertGetId($row + ['sort' => (int) DB::table('gift_offer_ranges')->max('sort') + 1, 'created_at' => now()]);
            } else {
                DB::table('gift_offer_ranges')->where('id', $id)->update($row);
            }

            // An item that stays (same product and variation) keeps its id and
            // how many were given, so an edit never refills an allocation.
            $existing = DB::table('gift_offer_items')->where('range_id', $id)->get()
                ->keyBy(static fn ($i) => $i->product_id . ':' . (int) $i->variant_id);
            $keep = [];

            foreach ($items as $sort => $item) {
                $key = (int) $item['product_id'] . ':' . (int) ($item['variant_id'] ?? 0);
                $values = ['role' => $item['role'], 'sort' => $sort, 'allocation' => (int) $item['allocation'], 'updated_at' => now()];

                if (isset($existing[$key]) && ! isset($keep[$existing[$key]->id])) {
                    DB::table('gift_offer_items')->where('id', $existing[$key]->id)->update($values);
                    $keep[$existing[$key]->id] = true;

                    continue;
                }

                $keep[DB::table('gift_offer_items')->insertGetId($values + [
                    'range_id' => $id,
                    'product_id' => (int) $item['product_id'],
                    'variant_id' => ($item['variant_id'] ?? null) ?: null,
                    'used' => 0,
                    'created_at' => now(),
                ])] = true;
            }

            DB::table('gift_offer_items')->where('range_id', $id)->whereNotIn('id', array_keys($keep) ?: [0])->delete();
        });

        $this->offers->compile();

        return response()->json($this->payload() + ['saved' => $id]);
    }

    public function deleteRange(int $id): JsonResponse
    {
        $this->fresh();
        DB::transaction(function () use ($id): void {
            DB::table('gift_offer_items')->where('range_id', $id)->delete();
            DB::table('gift_offer_ranges')->where('id', $id)->delete();
        });
        $this->offers->compile();

        return response()->json($this->payload());
    }

    /** GET products?q= -- the Free gifts picker's own search, the same allowlisted rows. */
    public function products(Request $request): JsonResponse
    {
        return app(FreeGiftsController::class)->products($request);
    }

    /** GET report?from&to&by -- one query over gift_offer_stats. */
    public function report(Request $request): JsonResponse
    {
        $this->fresh();
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'by' => ['nullable', Rule::in(GiftOfferStats::BY)],
        ]);
        $today = StoreTime::today();
        $labels = [];

        foreach (DB::table('gift_offer_ranges')->get(['id', 'min_fils', 'max_fils']) as $r) {
            $labels[(int) $r->id] = FreeGifts::rangeLabel(['min' => (int) $r->min_fils, 'max' => $r->max_fils === null ? null : (int) $r->max_fils]);
        }

        return response()->json(GiftOfferStats::report(
            (string) ($data['from'] ?? $today->subDays(6)->format('Y-m-d')),
            (string) ($data['to'] ?? $today->format('Y-m-d')),
            (string) ($data['by'] ?? 'day'),
            $labels,
        ));
    }

    /**
     * POST preview {config, place, locale, range_id?, bag?}: the REAL email
     * Blade (or the real popup markup and script) drawn from the unsaved
     * draft. Stores nothing: the draft is used for this request only, the
     * claim link is a preview token that claims nothing, and the clock is
     * drawn inline.
     */
    public function preview(Request $request): JsonResponse
    {
        $this->fresh();
        $data = $this->previewInput($request);
        $this->offers->useConfig((array) $request->input('config', []));
        $saved = 0;

        $html = CustomerLocale::render($data['locale'], function () use ($data, &$saved): string {
            [$offer, $example] = $this->sample($data);

            if ($data['place'] === 'popup') {
                return $offer === null ? $this->note('No range gives a gift yet: add a range and a product under Ranges & products.') : $this->popupPage($this->offers->popupView($offer), $example);
            }

            if ($offer === null) {
                return $this->note('No range gives a gift yet, so the email goes out exactly as it does today, with no gift block.');
            }

            $view = $this->offers->emailView($offer, GiftOffers::previewToken(), $data['place'], CarbonImmutable::now()->addHours($this->offers->config()['hold_hours']));

            if ($view['clock'] !== null) {
                // Drawn inline for the preview; the size reported is the sent email's, with its short clock address.
                $inline = 'data:image/gif;base64,' . base64_encode(GiftClock::render($this->offers->config()['hold_hours'] * 3600));
                $saved = strlen($inline) - strlen((string) $view['clock']);
                $view['clock'] = $inline;
            }

            $mail = $this->mailFor($data['place'], $view, null);

            return $mail === null ? $this->note('There is no order yet to draw the pending reminder with.') : $mail->render();
        });

        return response()->json(['html' => $html, 'bytes' => strlen($html) - $saved]);
    }

    /** POST test {to, place, locale, range_id?}: a real send, its claim marked test (never allocation, never the report). */
    public function test(Request $request): JsonResponse
    {
        $this->fresh();
        $data = $this->previewInput($request, true);
        $place = $data['place'];

        if ($place === 'popup') {
            return response()->json(['ok' => false, 'message' => 'The popup is shown on the shop, not sent: use Live preview to try it.'], 422);
        }

        $result = CustomerLocale::render($data['locale'], function () use ($data, $place): array {
            $order = null;

            if ($place !== 'cart') {
                $samples = app(\App\Services\Orders\SampleOrder::class);
                $order = $samples->current() ?? $samples->create($data['locale']);
                $order->loadMissing('items');
            }

            $offer = $place === 'cart'
                ? $this->offers->issue('cart', 0, ['test' => true, 'range_id' => $data['range_id'] ?? $this->firstRangeId()])
                : $this->offers->issue($place, 0, ['test' => true, 'order' => $order, 'range_id' => $data['range_id'] ?? $this->firstRangeId()]);

            if ($offer === null) {
                return ['ok' => false, 'message' => 'Nothing was sent: no range gives a gift right now (a range with a product in stock and allocation left).'];
            }

            $mail = $this->mailFor($place, $offer, $order);

            return $mail === null ? ['ok' => false, 'message' => 'Nothing was sent.'] : app(\App\Services\Mail\MailTester::class)->send($data['to'], $mail, 'test.gift_offer.' . $place);
        });

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }

    /* ------------------------------------------------------------ internals */

    private function previewInput(Request $request, bool $test = false): array
    {
        return $request->validate(array_merge([
            'place' => ['required', Rule::in(GiftOffers::PLACES)],
            'locale' => ['required', Rule::in(['en', 'ar'])],
            'range_id' => ['nullable', 'integer', 'min:1'],
            'bag' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'config' => [$test ? 'nullable' : 'sometimes', 'array'],
        ], $test ? ['to' => ['required', 'email:rfc', 'max:190']] : []));
    }

    private function firstRangeId(): ?int
    {
        foreach ($this->offers->ranges() as $r) {
            if ($this->offers->resolve($r) !== null) {
                return $r['id'];
            }
        }

        return null;
    }

    /**
     * The offer a preview draws: the range or bag asked for, else the first
     * range that gives something, else -- so the designs can be seen before
     * any range exists -- the newest product in stock, marked as an example.
     *
     * @return array{0: ?array, 1: bool}
     */
    private function sample(array $data): array
    {
        $offer = $this->offers->sampleOffer($data['range_id'] ?? null, isset($data['bag']) ? (int) round((float) $data['bag'] * 100) : null);

        if ($offer !== null) {
            return [$offer, false];
        }

        $p = Product::query()->visible()->inStock()->where('type', '<>', 'variable')->with('brand:id,name')->orderByDesc('id')->first();

        return $p === null ? [null, false] : [[
            'range' => ['id' => 0, 'min' => 0, 'max' => null, 'items' => []],
            'item_id' => 0, 'product' => $p, 'variant' => null, 'product_id' => (int) $p->id, 'variant_id' => null,
            'value' => max(0, (int) $p->effectivePrice()),
        ], true];
    }

    private function mailFor(string $place, array $offer, ?Order $order): ?\Illuminate\Mail\Mailable
    {
        if ($place === 'cart') {
            $items = Product::query()->visible()->inStock()->orderByDesc('id')->limit(2)->get(['id', 'name', 'slug', 'price', 'sale_price', 'sale_starts_at', 'sale_ends_at', 'type'])
                ->map(static fn (Product $p) => [
                    'name' => CustomerLocale::productName($p->id, (string) $p->name),
                    'slug' => (string) $p->slug, 'quantity' => 1, 'unit_price' => max(0, (int) $p->effectivePrice()), 'setContents' => [],
                ])->all();
            $words = app(\App\Services\CartRecovery::class)->messageWording(\App\Support\Locale::isDefault() ? 'en' : 'ar');

            return new CartRecoveryReminder(
                $words['subject'] ?? 'Your basket is saved',
                $words['body'] ?? 'Your message from Cart Tracking → Reminder emails goes here.',
                $items,
                Url::external('/cart/'),
                Url::external('/mail-preferences/'),
                $offer,
            );
        }

        $order ??= Order::query()->with('items')->orderByDesc('id')->first();

        return $order === null ? null : new OrderPaymentReminder($order, $place === 'reminder_2' ? 2 : 1, $offer);
    }

    private function popupPage(array $p, bool $example): string
    {
        $rtl = ! \App\Support\Locale::isDefault();
        $card = view('partials.gift-offers.popup-css')->render() . view('partials.gift-offers.popup-card', ['p' => $p])->render();
        $js = (string) @file_get_contents(resource_path('js/kbb/gift-offer-popup.js'));

        return '<!doctype html><html lang="' . ($rtl ? 'ar' : 'en') . '" dir="' . ($rtl ? 'rtl' : 'ltr') . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<base href="' . e(Url::external('/')) . '">'
            . '<style>body{margin:0;min-height:100vh;font-family:Outfit,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;background:#fff8f5}.pg{padding:70px 16px}.pg i{display:block;height:14px;border-radius:7px;background:#f1e4e8;margin:12px 0}.pg i.b{height:90px;border-radius:12px}.ex{position:fixed;left:8px;bottom:8px;z-index:2147483647;font:600 11px system-ui;background:#2a2228;color:#fff;border-radius:6px;padding:4px 8px}</style></head><body>'
            . '<div class="pg"><i></i><i class="b"></i><i></i><i class="b"></i><i></i></div>'
            . ($example ? '<div class="ex">Example product: no range gives a gift yet</div>' : '')
            . '<template data-kgo-pop data-preview data-r="0" data-min="' . (int) $p['minutes'] . '">' . $card . '</template>'
            . '<script>' . $js . '</script><script>window.kbbGo.open("preview")</script></body></html>';
    }

    private function note(string $text): string
    {
        return '<!doctype html><html><body style="margin:0;padding:28px;font:14px/1.5 system-ui,sans-serif;color:#5b6472;background:#fff">' . e($text) . '</body></html>';
    }

    private function payload(): array
    {
        $ranges = DB::table('gift_offer_ranges')->orderBy('min_fils')->orderBy('sort')->orderBy('id')->get();
        $items = DB::table('gift_offer_items')->orderByRaw("CASE WHEN role = 'primary' THEN 0 ELSE 1 END")->orderBy('sort')->orderBy('id')->get();
        $products = $items->isEmpty() ? collect() : Product::query()->with('brand:id,name')->whereIn('id', $items->pluck('product_id')->unique()->all())
            ->get(['id', 'name', 'slug', 'brand_id', 'image', 'type', 'status', 'stock_status', 'manage_stock', 'stock', 'price', 'sale_price', 'sale_starts_at', 'sale_ends_at'])
            ->keyBy('id');
        $variants = FreeGifts::variants($items->pluck('variant_id')->filter()->all());
        $byRange = $items->groupBy('range_id');

        return [
            'config' => $this->offers->freshConfig(),
            'defaults' => array_map(static fn (array $t) => ['en' => $t[0], 'ar' => $t[1]], GiftOffers::TEXTS),
            'choices' => [
                'places' => GiftOfferStats::PLACES, 'designs' => GiftOffers::DESIGNS, 'styles' => GiftOffers::STYLES,
                'words' => GiftOffers::WORDS, 'audiences' => GiftOffers::AUDIENCES, 'timers' => GiftOffers::TIMERS, 'windows' => GiftOffers::WINDOWS,
                'hold' => GiftOffers::HOLD, 'minutes' => GiftOffers::MINUTES, 'max_items' => GiftOffers::MAX_ITEMS, 'max_allocation' => GiftOffers::MAX_ALLOCATION,
            ],
            'live_clock' => GiftClock::available(),
            'can_manage' => ($admin = auth('admin')->user()) instanceof \App\Models\AdminUser
                && \App\Support\AdminRoles::can($admin, 'giftoffers.manage'),
            'ranges' => $ranges->map(function ($r) use ($byRange, $products, $variants) {
                $range = ['id' => (int) $r->id, 'min' => (int) $r->min_fils, 'max' => $r->max_fils === null ? null : (int) $r->max_fils];
                $compiled = $this->offers->rangeById((int) $r->id);
                $giving = $compiled === null ? null : $this->offers->resolve($compiled);

                return [
                    'id' => (int) $r->id,
                    'min' => intdiv((int) $r->min_fils, 100),
                    'max' => $r->max_fils === null ? null : intdiv((int) $r->max_fils, 100),
                    'label' => FreeGifts::rangeLabel($range),
                    'enabled' => (bool) $r->enabled,
                    'giving' => $giving === null ? null : (int) $giving['item_id'],
                    'items' => ($byRange[$r->id] ?? collect())->map(fn ($i) => [
                        'id' => (int) $i->id,
                        'product_id' => (int) $i->product_id,
                        'variant_id' => $i->variant_id === null ? null : (int) $i->variant_id,
                        'role' => $i->role === 'primary' ? 'primary' : 'backup',
                        'allocation' => (int) $i->allocation,
                        'used' => (int) $i->used,
                        'left' => max(0, (int) $i->allocation - (int) $i->used),
                        'product' => $this->productRow($products[$i->product_id] ?? null, $i->variant_id ? ($variants[(int) $i->variant_id] ?? null) : null),
                    ])->values()->all(),
                ];
            })->values()->all(),
        ];
    }

    /** The allowlist: what the screen draws for a chosen product, never the model. */
    private function productRow(?Product $p, ?\App\Models\ProductVariant $v): ?array
    {
        if ($p === null) {
            return null;
        }

        $image = (string) (($v !== null && trim((string) $v->image) !== '' ? $v->image : null) ?? $p->image ?? '');
        $stock = $v !== null ? FreeGifts::variantAvailable($p, $v) : ($p->status === 'publish' && $p->type !== 'variable' && $p->stock_status === 'instock' ? ($p->manage_stock ? max(0, (int) $p->stock) : PHP_INT_MAX) : 0);

        return [
            'name' => $v !== null ? FreeGifts::giftName((string) $p->name, $v) : (string) $p->name,
            'brand' => (string) ($p->brand?->name ?? ''),
            'thumb' => $image !== '' ? ImageVariants::variantUrl($image, 200) : '',
            'stock' => $stock === PHP_INT_MAX ? null : $stock,
            'in_stock' => $stock > 0,
            'status' => (string) $p->status,
            'price' => Money::plain(max(0, (int) ($v ?? $p)->effectivePrice())),
        ];
    }
}

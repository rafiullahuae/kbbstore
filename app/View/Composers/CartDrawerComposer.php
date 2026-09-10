<?php

declare(strict_types=1);

namespace App\View\Composers;

use App\Models\Product;
use App\Services\CartService;
use App\Services\SettingsService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Fills the mini-cart drawer on every page render.
 *
 * The drawer used to be an empty shell that only JavaScript populated, after an
 * add. So on a hard refresh the header badge showed a count — that comes from
 * the server — while the drawer itself was blank until you added something new.
 *
 * Rule 27: when the cart cookie is absent there is nothing to show and no query
 * runs at all, which covers every first visit and every crawler.
 */
class CartDrawerComposer
{
    private const LINE_COLUMNS = [
        'id', 'wc_id', 'slug', 'name', 'brand_id', 'price', 'sale_price',
        'sale_starts_at', 'sale_ends_at', 'stock_status', 'image', 'type',
    ];

    public function __construct(
        private CartService $carts,
        private SettingsService $settings,
        private Request $request,
    ) {}

    public function compose(View $view): void
    {
        $empty = [
            'item_count' => 0, 'subtotal' => 0, 'discount' => 0, 'total' => 0,
            'coupon_code' => null, 'shipping' => 0,
            'free_shipping_threshold' => 0, 'free_shipping_remaining' => 0,
            'free_shipping_percent' => 0, 'free_shipping_unlocked' => false,
        ];

        if (! $this->request->hasCookie(CartService::COOKIE)) {
            $view->with(['items' => collect(), 'totals' => $empty, 'promo' => '', 'browsed' => collect()]);

            return;
        }

        $cart = $this->carts->current($this->request, create: false)?->load([
            'items' => fn ($q) => $q->orderBy('id'),
            'items.product' => fn ($q) => $q->select(self::LINE_COLUMNS),
            'items.product.brand:id,name,slug',
            'items.variant:id,product_id,sku,price,sale_price,image,stock_status',
            'coupon:id,code,type,amount',
        ]);

        $view->with([
            'items' => $cart?->items ?? collect(),
            'totals' => $cart ? $this->carts->totals($cart) : $empty,
            'promo' => $this->settings->moduleEnabled('minicart_promo', true)
                ? (string) $this->settings->get('minicart_promo', '')
                : '',
            'browsed' => $this->browsed($cart),
        ]);
    }

    private function browsed($cart)
    {
        $ids = array_filter(array_map('intval', explode(',', (string) $this->request->cookie('kbb_viewed', ''))));

        if ($ids === []) {
            return collect();
        }

        $inCart = $cart ? $cart->items->pluck('product_id')->all() : [];
        $ids = array_slice(array_values(array_diff($ids, $inCart)), 0, 6);

        if ($ids === []) {
            return collect();
        }

        return Product::query()->select(self::LINE_COLUMNS)->visible()
            ->whereIn('id', $ids)->with('brand:id,name,slug')->get()
            ->sortBy(fn ($p) => array_search($p->id, $ids, true))->values();
    }
}

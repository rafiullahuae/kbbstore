<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Order;
use App\Services\CouponExhausted;
use App\Services\CouponService;
use App\Services\Mail\OrderStatusMailPolicy;
use App\Services\Payments\CodRefusals;
use App\Services\SettingsService;
use App\Support\Money;
use App\Support\OrderPaymentPanel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Store -> Orders -> (an order): change its payment method, and put a coupon
 * on it or take one off. (2.60.641)
 *
 * The owner, 4 November: "I can not change the order payment method manually,
 * for example pending or failed order to Cash on Delivery, Cash on Delivery to
 * Paid orders etc. ... if i select COD, the cod amount will be added auto, and
 * also i should have option to apply coupons inside the existing orders."
 *
 * WHAT IT NEVER DOES: call a gateway. Nothing here talks to Tabby, Tamara or
 * Stripe; it writes the order's own columns, the order note, and -- for a
 * payment taken outside the checkout -- goes through ManualPayment::record(),
 * the same write "Mark as paid" makes. Status moves go through
 * OrderStatus::moveTo() (stock, coupon release and reclaim, mail), never a
 * column write.
 *
 * WHEN THE METHOD MAY CHANGE: while no money is recorded against the order
 * (`paid_at` and `captured_at` both empty) and it is not trashed, cancelled,
 * refunded or completed. A paid order's method is history; changing it would
 * put a card payment on the books as cash. Tabby and Tamara set `paid_at` on
 * authorisation, so a live authorisation is never stranded either. A FAILED
 * Tabby order has neither, which is exactly the "failed to COD" case.
 *
 * THE COD FEE: `fee_total` is the gift-wrap fee (`gift_fee`) plus the payment
 * method's surcharge, which only COD has (CheckoutController, ManualOrderBuilder).
 * Switching TO cash on delivery adds today's `cod_fee` setting (the owner can
 * untick it); switching AWAY takes the order's own COD part off. Anything
 * else in `fee_total` (an imported WooCommerce fee) is left alone.
 *
 * COUPONS are priced by CouponService on a cart built from THIS ORDER's lines
 * at the prices they were sold at -- never today's prices -- inside a
 * transaction that always rolls back (ManualOrderBuilder::quote()'s pattern).
 * The code's own rules all apply: dates, usage limits, per-customer limits,
 * minimum and maximum, products, brands, categories, sale items. The use is
 * recorded with CouponService::recordRedemption() in the transaction that
 * writes the order. A coupon taken off or replaced gives its use back and its
 * redemption row is detached from the order (released, order_id cleared), so
 * a later revive of this order can never take the OLD code back
 * (CouponService::reclaimRedemptions() reclaims every released row of an order).
 * Only an unpaid order takes a coupon: a paid one would need money back, which
 * is a refund. A free-delivery code takes its discount off; the delivery
 * charge on an existing order is left as it is, and the operator is told.
 */
final class OrderPaymentEdit
{
    /** Statuses whose method may change (and that may take a coupon), money not yet recorded. */
    public const OPEN_STATUSES = ['draft', 'pending', 'onhold', 'failed', 'processing', 'shipped'];

    /** A coupon is not put on an order that has already left. */
    public const COUPON_STATUSES = ['draft', 'pending', 'onhold', 'failed', 'processing'];

    public const CODE_MAX = 60;

    public function __construct(
        private ManualPayment $payments,
        private OrderStatus $status,
        private CouponService $coupons,
        private SettingsService $settings,
        private OrderTotalsRecalc $recalc,
    ) {}

    /* ----------------------------------------------------------- the screen */

    /** What the order screen needs to draw the two controls. No query beyond the order's own row. */
    public function state(Order $order): array
    {
        $codFee = $this->codFeeSetting();

        return [
            'change' => ['allowed' => ($r = $this->changeBlock($order)) === null, 'reason' => $r],
            'coupon' => ['allowed' => ($c = $this->couponBlock($order)) === null, 'reason' => $c],
            'cod_fee_aed' => Money::toAed($codFee),
            'order_cod_fee_aed' => Money::toAed($this->codPart($order)),
            'unpaid_status' => in_array((string) $order->status, OrderPaymentPanel::UNPAID_STATUSES, true),
        ];
    }

    /** Why the method cannot change, or null when it can. */
    public function changeBlock(Order $order): ?string
    {
        if ($order->trashed()) {
            return 'This order is in the trash.';
        }

        if (! in_array((string) $order->status, self::OPEN_STATUSES, true)) {
            return sprintf('This order is %s, so its payment method is history.', $order->status);
        }

        if ($order->paid_at !== null || $order->captured_at !== null) {
            return 'This order is already paid, so its payment method is history. Refund it to take the money back.';
        }

        return null;
    }

    /** Why a coupon cannot go on or off, or null when it can. */
    public function couponBlock(Order $order): ?string
    {
        if ($order->trashed()) {
            return 'This order is in the trash.';
        }

        if (! in_array((string) $order->status, self::COUPON_STATUSES, true)) {
            return sprintf('This order is %s, so its coupon cannot change.', $order->status);
        }

        if ($order->paid_at !== null || $order->captured_at !== null) {
            return 'This order is already paid. A coupon now would need money back: refund the difference instead.';
        }

        return null;
    }

    /** Does the COD refusals list hold this order's customer? Read only to warn; the operator decides. */
    public function codRefused(Order $order): bool
    {
        try {
            return app(CodRefusals::class)->blocks((string) $order->email, (string) $order->phone);
        } catch (\Throwable) {
            return false;
        }
    }

    /* -------------------------------------------------------- the method */

    /**
     * @param  array{method:string, cod_fee?:bool, status?:?string, paid?:bool, reference?:?string, paid_at?:?CarbonImmutable, notify?:?bool}  $in
     * @return array{ok: bool, message: string}
     *
     * @throws OrderReviveRefused  passed through: nothing was written.
     */
    public function changeMethod(Order $order, array $in, string $by): array
    {
        if (($why = $this->changeBlock($order)) !== null) {
            return ['ok' => false, 'message' => $why];
        }

        $methods = collect($this->payments->methodsFor($order))->keyBy('id');
        $method = (string) $in['method'];

        if (! $methods->has($method)) {
            return ['ok' => false, 'message' => 'Choose one of this shop\'s payment methods.'];
        }

        $to = $in['status'] ?? null;
        $to = ($to === null || $to === '' || $to === (string) $order->status) ? null : (string) $to;

        if ($to !== null && ($to !== 'processing' || ! in_array((string) $order->status, OrderPaymentPanel::UNPAID_STATUSES, true))) {
            return ['ok' => false, 'message' => 'Only an unpaid order (pending, on hold, draft or failed) moves to Processing here.'];
        }

        $paid = (bool) ($in['paid'] ?? false);

        /*
         * Processing means "paid" for every method but cash on delivery: the
         * payment panel, the receipt and the revenue figures all read it that
         * way. So an unpaid card / Tabby / Tamara order does not move there
         * from here -- record the payment (Paid) or leave the status alone.
         * Found in Chromium: the move turned an unpaid Tabby order into
         * "Paid with Tabby".
         */
        if ($to !== null && $method !== 'cod' && ! $paid) {
            return ['ok' => false, 'message' => 'Only a Cash on delivery order, or one you mark as paid, moves to Processing here.'];
        }

        $was = trim((string) $order->payment_method);
        $wasTitle = $order->paymentLabel();
        $title = (string) $methods[$method]['title'];

        $oldCod = $this->codPart($order);
        $gift = max(0, (int) $order->gift_fee);
        $other = max(0, (int) $order->fee_total - $gift - $oldCod);
        $newCod = ($method === 'cod' && ($in['cod_fee'] ?? true)) ? $this->codFeeSetting() : 0;
        $newFee = $gift + $other + $newCod;

        $newRef = $method !== 'cod' ? trim((string) ($in['reference'] ?? '')) : '';

        if ($method === $was && $newFee === (int) $order->fee_total && ! $paid && $to === null && ($newRef === '' || $newRef === (string) $order->transaction_id)) {
            return ['ok' => false, 'message' => 'Nothing to change: the order already has that method and fee.'];
        }

        if ($to !== null || $paid) {
            app(OrderStatusMailPolicy::class)->decideFor($order, $in['notify'] ?? null);
        }

        $totalBefore = (int) $order->total;

        try {
            return DB::transaction(function () use ($order, $method, $title, $was, $wasTitle, $newFee, $oldCod, $newCod, $paid, $in, $by, $to, $totalBefore) {
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->first();

            if ($locked === null || ($why = $this->changeBlock($locked)) !== null) {
                return ['ok' => false, 'message' => $why ?? 'That order could not be updated.'];
            }

            /*
             * The owner, 4 November: "if i select card payment, then the system
             * should ask the payment id (optional), same for tabby and tamara."
             * Not paid yet: the ID he typed is kept on the order as its
             * transaction ID. Paid: ManualPayment::record() writes it, as Mark
             * as paid always has. Cash on delivery has none.
             */
            $ref = trim((string) ($in['reference'] ?? ''));
            $fill = ['payment_method' => $method, 'payment_method_title' => $title, 'fee_total' => $newFee];

            if (! $paid && $method !== 'cod' && $ref !== '') {
                $fill['transaction_id'] = $ref;
            }

            $locked->forceFill($fill)->save();
            $this->recalc->recalc($locked);
            $locked->refresh();

            $parts = [];
            if ($method !== $was) {
                $parts[] = sprintf('%s → %s', $wasTitle !== '' ? $wasTitle : 'none', $title);
            }
            if ($newCod !== $oldCod) {
                $parts[] = $newCod > $oldCod
                    ? sprintf('COD fee %s added', Money::plain($newCod - $oldCod, Money::minorExponent()))
                    : sprintf('COD fee %s removed', Money::plain($oldCod - $newCod, Money::minorExponent()));
            }
            if (! $paid && $method !== 'cod' && $ref !== '') {
                $parts[] = 'payment ID ' . $ref;
            }
            if ((int) $locked->total !== $totalBefore) {
                $parts[] = sprintf('total %s → %s', Money::plain($totalBefore, Money::minorExponent()), Money::plain((int) $locked->total, Money::minorExponent()));
            }

            if ($parts !== []) {
                $locked->notes()->create([
                    'content' => 'Payment method changed by ' . $by . ': ' . implode('; ', $parts) . '.',
                    'author' => $by,
                    'is_customer_note' => false,
                ]);
            }

            if ($paid) {
                $r = $this->payments->record($locked, $method, $in['reference'] ?? null, $in['paid_at'] ?? CarbonImmutable::now(), $by, $to);

                if (! $r['ok']) {
                    throw new OrderPaymentEditRefused($r['message']);
                }

                return ['ok' => true, 'message' => 'Payment method changed and payment recorded.'];
            }

            if ($to !== null && $this->status->moveTo($locked, $to, by: $by, reason: 'Payment method changed on the order screen.') === null) {
                throw new OrderPaymentEditRefused('That order could not be moved to ' . $to . '.');
            }

            return ['ok' => true, 'message' => 'Payment method changed.'];
            });
        } catch (OrderPaymentEditRefused $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /* ----------------------------------------------------------- coupons */

    /** @return array{ok: bool, message: string} */
    public function applyCoupon(Order $order, string $code, string $by): array
    {
        if (($why = $this->couponBlock($order)) !== null) {
            return ['ok' => false, 'message' => $why];
        }

        $code = trim($code);

        if ($code === '') {
            return ['ok' => false, 'message' => 'Type a coupon code.'];
        }

        if ($order->coupon_code !== null && mb_strtolower(trim((string) $order->coupon_code)) === mb_strtolower($code)) {
            return ['ok' => false, 'message' => 'That code is already on this order.'];
        }

        $priced = $this->price($order, $code);

        if (! $priced['ok']) {
            return $priced;
        }

        /** @var Coupon $coupon */
        $coupon = $priced['coupon'];
        $discount = (int) $priced['discount'];

        try {
            return DB::transaction(function () use ($order, $coupon, $discount, $by, $priced) {
                $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->first();

                if ($locked === null || ($why = $this->couponBlock($locked)) !== null) {
                    return ['ok' => false, 'message' => $why ?? 'That order could not be updated.'];
                }

                $before = (int) $locked->total;
                $old = trim((string) $locked->coupon_code);
                $this->detachRedemptions((int) $locked->getKey());
                $this->coupons->recordRedemption($coupon, $discount, (int) $locked->getKey(), $locked->customer_id !== null ? (int) $locked->customer_id : null, $locked->email);

                $locked->forceFill([
                    'coupon_code' => $coupon->code,
                    'discount_total' => max(0, (int) $locked->bundle_discount) + $discount,
                ])->save();
                $this->recalc->recalc($locked, 'the coupon changed');
                $locked->refresh();

                $locked->notes()->create([
                    'content' => sprintf(
                        'Coupon %s applied by %s%s: %s off; total %s → %s.%s',
                        $coupon->code,
                        $by,
                        $old !== '' ? ' in place of ' . $old : '',
                        Money::plain($discount, Money::minorExponent()),
                        Money::plain($before, Money::minorExponent()),
                        Money::plain((int) $locked->total, Money::minorExponent()),
                        $priced['free_shipping'] ? ' The code also gives free delivery; the delivery charge on this order was left as it was.' : '',
                    ),
                    'author' => $by,
                    'is_customer_note' => false,
                ]);

                return ['ok' => true, 'message' => 'Coupon ' . $coupon->code . ' applied: ' . Money::plain($discount) . ' off.'
                    . ($priced['free_shipping'] ? ' Delivery was left as it was.' : '')];
            });
        } catch (CouponExhausted) {
            return ['ok' => false, 'message' => 'That code has been fully redeemed.'];
        }
    }

    /** @return array{ok: bool, message: string} */
    public function removeCoupon(Order $order, string $by): array
    {
        if (($why = $this->couponBlock($order)) !== null) {
            return ['ok' => false, 'message' => $why];
        }

        if (trim((string) $order->coupon_code) === '') {
            return ['ok' => false, 'message' => 'This order has no coupon.'];
        }

        return DB::transaction(function () use ($order, $by) {
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->first();

            if ($locked === null || ($why = $this->couponBlock($locked)) !== null) {
                return ['ok' => false, 'message' => $why ?? 'That order could not be updated.'];
            }

            $code = trim((string) $locked->coupon_code);
            $before = (int) $locked->total;
            $this->detachRedemptions((int) $locked->getKey());

            $locked->forceFill([
                'coupon_code' => null,
                'discount_total' => max(0, (int) $locked->bundle_discount),
            ])->save();
            $this->recalc->recalc($locked, 'the coupon changed');
            $locked->refresh();

            $locked->notes()->create([
                'content' => sprintf('Coupon %s removed by %s; total %s → %s.', $code, $by,
                    Money::plain($before, Money::minorExponent()), Money::plain((int) $locked->total, Money::minorExponent())),
                'author' => $by,
                'is_customer_note' => false,
            ]);

            return ['ok' => true, 'message' => 'Coupon ' . $code . ' removed.'];
        });
    }

    /**
     * Price $code against this order's own lines at their own prices, writing
     * nothing: the draft cart lives in a transaction that always rolls back.
     *
     * @return array{ok: bool, message: string, coupon?: Coupon, discount?: int, free_shipping?: bool}
     */
    private function price(Order $order, string $code): array
    {
        $out = null;

        try {
            DB::transaction(function () use ($order, $code, &$out) {
                $cart = Cart::create([
                    'token' => (string) Str::uuid(),
                    'customer_id' => $order->customer_id,
                    'currency' => 'AED',
                    'status' => 'active',
                    'shipping_country' => strtoupper((string) ($order->shipping_address['country'] ?? '')) ?: null,
                    'last_activity_at' => now(),
                ]);

                foreach ($order->items()->whereNotNull('product_id')->get(['product_id', 'product_variant_id', 'quantity', 'unit_price']) as $line) {
                    CartItem::create([
                        'cart_id' => $cart->id,
                        'product_id' => (int) $line->product_id,
                        'product_variant_id' => $line->product_variant_id,
                        'quantity' => max(1, (int) $line->quantity),
                        'unit_price' => max(0, (int) $line->unit_price),
                    ]);
                }

                $cart = $cart->fresh(['items' => fn ($q) => $q->orderBy('id'), 'items.product.brand']);

                if ($cart->items->isEmpty()) {
                    $out = ['ok' => false, 'message' => 'This order has no products a coupon can apply to.'];
                } else {
                    $check = $this->coupons->validate($code, $cart, $order->email ?: null);

                    if (! $check['ok']) {
                        $out = ['ok' => false, 'message' => (string) $check['error']];
                    } else {
                        $discount = $this->coupons->discountFor($check['coupon'], $cart);
                        $out = $discount > 0
                            ? ['ok' => true, 'message' => '', 'coupon' => $check['coupon'], 'discount' => $discount, 'free_shipping' => $this->coupons->grantsFreeShipping($check['coupon'])]
                            : ['ok' => false, 'message' => 'That code takes nothing off this order.'];
                    }
                }

                throw new OrderPaymentEditRollback();
            });
        } catch (OrderPaymentEditRollback) {
            // Expected: the draft cart, its lines and the id they used are gone.
        }

        return $out ?? ['ok' => false, 'message' => 'That code could not be checked.'];
    }

    /**
     * Give back the uses this order holds and detach their rows from it.
     * Released through CouponService (the one place a use is handed back), then
     * order_id cleared on the released rows so a later revive of this order
     * (reclaimRedemptions) cannot take an old code back. The rows stay, as the
     * code's history.
     */
    private function detachRedemptions(int $orderId): void
    {
        $this->coupons->releaseRedemptions($orderId);

        CouponRedemption::query()->where('order_id', $orderId)->whereNotNull('released_at')
            ->update(['order_id' => null, 'updated_at' => now()]);
    }

    /* ----------------------------------------------------------- helpers */

    private function codFeeSetting(): int
    {
        return max(0, (int) $this->settings->get('cod_fee', 0));
    }

    /** The COD part of this order's fee: whatever is not the gift-wrap fee, on a COD order. */
    private function codPart(Order $order): int
    {
        return trim((string) $order->payment_method) === 'cod'
            ? max(0, (int) $order->fee_total - max(0, (int) $order->gift_fee))
            : 0;
    }
}

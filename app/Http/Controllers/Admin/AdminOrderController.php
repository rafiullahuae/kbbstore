<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderNote;
use App\Models\Refund;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Backs the detailed admin order page (built from Rafi's WooCommerce
 * reference screenshot). Kept separate from the older AdminController
 * order() method rather than extending it — that method has real, wrong
 * column references (`$o->delivery`, `$o->cod_fee`, `$o->ship_method`,
 * `$i->qty`, none of which exist on these models) that silently return
 * null instead of erroring, quietly showing zeros in the old order modal.
 * Not fixed here since it's a separate, pre-existing bug outside this
 * page's scope — flagged rather than silently inherited into new code.
 */
class AdminOrderController extends Controller
{
    /** Actions that are real right now vs. visible-but-not-wired-up (email requires SMTP, not configured — see the dashboard's own health panel). */
    private const REAL_ACTIONS = ['cancel', 'duplicate'];
    private const PLACEHOLDER_ACTIONS = ['resend_confirmation', 'email_invoice'];

    public function show(int $id, \App\Support\VatDisplay $vat): JsonResponse
    {
        $order = Order::withTrashed()->with(['items', 'notes'])->find($id);

        if ($order === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $customer = $order->customer;

        return response()->json([
            'id' => $order->id,
            'order_number' => $order->order_number ?? (string) $order->id,
            'status' => $order->status,
            'trashed' => $order->trashed(),
            'editable' => in_array($order->status, self::EDITABLE_STATUSES, true),
            'created_at' => optional($order->created_at)->toAtomString(),
            'currency' => $order->currency,

            'payment_method' => $order->payment_method,
            'payment_method_title' => $order->payment_method_title,
            'transaction_id' => $order->transaction_id,
            'paid_at' => optional($order->paid_at)->toAtomString(),
            'ip_address' => $order->ip_address,

            'billing_address' => $order->billing_address,
            'shipping_address' => $order->shipping_address,
            'email' => $order->email,
            'phone' => $order->phone,

            'customer' => $customer ? [
                'id' => $customer->id,
                'name' => $customer->name,
                'email' => $customer->email,
            ] : null,

            'items' => $order->items->map(fn ($i) => [
                'id' => $i->id,
                'name' => $i->name,
                'brand' => $i->brand,
                'sku' => $i->sku,
                'variant_attributes' => $i->variant_attributes,
                'quantity' => $i->quantity,
                'unit_price_aed' => Money::toAed($i->unit_price),
                'total_aed' => Money::toAed($i->total),
                'image' => $i->product?->image,
                'product_slug' => $i->product?->slug,
            ]),

            'shipping_method' => $order->shipping_method,
            'coupon_code' => $order->coupon_code,
            'customer_note' => $order->customer_note,
            'is_gift' => (bool) $order->is_gift,
            'gift_note' => $order->gift_note,
            'gift_fee_aed' => Money::toAed((int) $order->gift_fee),
            'whatsapp_optin' => $order->whatsapp_optin,

            'subtotal_aed' => Money::toAed($order->subtotal),
            'discount_total_aed' => Money::toAed($order->discount_total),
            'shipping_total_aed' => Money::toAed($order->shipping_total),
            'fee_total_aed' => Money::toAed($order->fee_total),
            'total_aed' => Money::toAed($order->total),
            // VAT is a display line only (decision D-64) — never stored, never
            // added to the total, computed fresh from the real order total
            // via the same VatDisplay service the checkout page itself uses,
            // so the two can never quietly drift apart. VatDisplay's own
            // 'formatted' field is raw HTML meant for server-rendered Blade
            // (Money::format() wraps it in <span> tags) — wrong for this JSON
            // response, so only the label and a converted AED amount are used.
            'vat' => (function () use ($vat, $order) {
                $line = $vat->line($order->total);

                return $line ? ['label' => $line['label'], 'amount_aed' => Money::toAed($line['amount'])] : null;
            })(),

            'invoice_number' => $order->invoice_number,
            'invoiced_at' => optional($order->invoiced_at)->toAtomString(),

            'refunds' => $order->refunds()->latest()->get()->map(fn (Refund $r) => [
                'id' => $r->id,
                'amount_aed' => Money::toAed($r->amount),
                'reason' => $r->reason,
                'refunded_by' => $r->refunded_by,
                'created_at' => optional($r->created_at)->toAtomString(),
            ]),
            'refunded_total_aed' => Money::toAed((int) $order->refunds()->sum('amount')),

            'notes' => $order->notes->map(fn (OrderNote $n) => [
                'id' => $n->id,
                'author' => $n->author,
                'is_customer_note' => $n->is_customer_note,
                'content' => $n->content,
                'created_at' => optional($n->created_at)->toAtomString(),
            ]),

            'customer_history' => $this->customerHistory($order->customer_id, $order->email),

            // Order attribution: the panel Rafi's reference shows, kept honest
            // rather than faked — none of source/device/session-views is
            // tracked by anything in this app yet (confirmed directly: the
            // `origin` column exists in the schema but nothing anywhere ever
            // writes to it, not even on new checkout orders today). Shown as
            // null so the page can render "Not tracked yet" instead of a
            // fabricated number.
            'attribution' => [
                'origin' => $order->origin,
                'device_type' => null,
                'session_page_views' => null,
            ],

            'actions' => [
                'real' => self::REAL_ACTIONS,
                'placeholder' => self::PLACEHOLDER_ACTIONS,
            ],
        ]);
    }

    public function addNote(Request $request, int $id): JsonResponse
    {
        $order = Order::find($id);
        if ($order === null) return response()->json(['error' => 'not_found'], 404);

        $data = $request->validate(['content' => ['required', 'string', 'max:5000']]);

        $note = $order->notes()->create([
            'content' => $data['content'],
            'author' => auth('admin')->user()?->name ?? 'Admin',
            'is_customer_note' => false,
        ]);

        return response()->json(['ok' => true, 'note' => [
            'id' => $note->id, 'author' => $note->author, 'content' => $note->content,
            'is_customer_note' => false, 'created_at' => $note->created_at->toAtomString(),
        ]]);
    }

    /**
     * Records a refund — there is no payment-gateway API integrated yet
     * (Tabby/Tamara/Stripe are all still open per the master plan), so this
     * cannot actually move money back to a customer. It creates a real,
     * permanent record of the refund having happened and who logged it,
     * the same ledger entry a real gateway integration would eventually
     * write to as well — not a fake action, just an honestly partial one.
     */
    public function refund(Request $request, int $id): JsonResponse
    {
        $order = Order::find($id);
        if ($order === null) return response()->json(['error' => 'not_found'], 404);

        $data = $request->validate([
            'amount_aed' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $amountFils = (int) round($data['amount_aed'] * 100);
        $alreadyRefunded = (int) $order->refunds()->sum('amount');

        if ($alreadyRefunded + $amountFils > $order->total) {
            return response()->json(['ok' => false, 'message' => 'That would refund more than the order total.'], 422);
        }

        $refund = $order->refunds()->create([
            'amount' => $amountFils,
            'reason' => $data['reason'] ?? null,
            'refunded_by' => auth('admin')->user()?->name ?? 'Admin',
        ]);

        // A full refund also moves the order status — a partial one doesn't,
        // since the order is still legitimately in progress for the rest.
        if ($alreadyRefunded + $amountFils >= $order->total) {
            $order->update(['status' => 'refunded']);
        }

        return response()->json([
            'ok' => true,
            'refund_id' => $refund->id,
            'status' => $order->status,
            'refunded_total_aed' => Money::toAed($alreadyRefunded + $amountFils),
        ]);
    }

    public function trash(int $id): JsonResponse
    {
        $order = Order::find($id);
        if ($order === null) return response()->json(['error' => 'not_found'], 404);

        $order->delete();

        return response()->json(['ok' => true]);
    }

    public function restore(int $id): JsonResponse
    {
        $order = Order::withTrashed()->find($id);
        if ($order === null) return response()->json(['error' => 'not_found'], 404);

        $order->restore();

        return response()->json(['ok' => true]);
    }

    public function updateAddress(Request $request, int $id): JsonResponse
    {
        $order = Order::find($id);
        if ($order === null) return response()->json(['error' => 'not_found'], 404);

        $data = $request->validate([
            'type' => ['required', 'in:billing,shipping'],
            'address' => ['required', 'array'],
        ]);

        $order->update([($data['type'] === 'billing' ? 'billing_address' : 'shipping_address') => $data['address']]);

        return response()->json(['ok' => true]);
    }

    /**
     * Cancel and duplicate are real; resend/email actions are visible but
     * refuse with a clear reason, matching the same "placeholder, wired
     * later" pattern already agreed for the invoice/shipping-label PDFs —
     * shown rather than hidden, since Rafi should be able to see what the
     * page is building toward, but never made to look like it worked when
     * it didn't.
     */
    public function runAction(Request $request, int $id): JsonResponse
    {
        $order = Order::find($id);
        if ($order === null) return response()->json(['error' => 'not_found'], 404);

        $data = $request->validate(['action' => ['required', 'string']]);
        $action = $data['action'];

        if (in_array($action, self::PLACEHOLDER_ACTIONS, true)) {
            return response()->json(['ok' => false, 'message' => 'Not available yet — outbound email is not configured for this store.'], 422);
        }

        if ($action === 'cancel') {
            $order->update(['status' => 'cancelled']);
            return response()->json(['ok' => true, 'status' => 'cancelled']);
        }

        if ($action === 'duplicate') {
            $new = $order->replicate(['order_number', 'invoice_number', 'transaction_id', 'paid_at', 'completed_at', 'deleted_at']);
            $new->order_number = $this->nextOrderNumber();
            $new->status = 'draft';
            $new->save();

            foreach ($order->items as $item) {
                $new->items()->create($item->replicate()->toArray());
            }

            return response()->json(['ok' => true, 'new_order_id' => $new->id, 'new_order_number' => $new->order_number]);
        }

        return response()->json(['ok' => false, 'message' => 'Unknown action.'], 422);
    }

    /** Only orders that haven't shipped are safe to change the contents of — once packed, editing the line items doesn't reflect reality. */
    private const EDITABLE_STATUSES = ['draft', 'pending', 'processing', 'onhold'];

    private function assertEditable(Order $order): ?JsonResponse
    {
        if (!in_array($order->status, self::EDITABLE_STATUSES, true)) {
            return response()->json(['ok' => false, 'message' => 'This order is no longer editable — it has already shipped or been closed out.'], 422);
        }

        return null;
    }

    /** Recomputes subtotal/total from the order's own line items after any add/update/remove — never trusts a client-sent total. */
    private function recalcTotals(Order $order): void
    {
        $subtotal = (int) $order->items()->sum('total');
        $order->subtotal = $subtotal;
        $order->total = $subtotal + $order->shipping_total + $order->fee_total + $order->tax_total - $order->discount_total;
        $order->save();
    }

    public function addItem(Request $request, int $id): JsonResponse
    {
        $order = Order::find($id);
        if ($order === null) return response()->json(['error' => 'not_found'], 404);
        if ($blocked = $this->assertEditable($order)) return $blocked;

        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        $product = \App\Models\Product::find($data['product_id']);
        $unitPrice = (int) ($product->sale_price ?: $product->price);

        $item = $order->items()->create([
            'product_id' => $product->id,
            'name' => $product->name,
            'brand' => $product->brand?->name,
            'sku' => $product->sku,
            'quantity' => $data['quantity'],
            'unit_price' => $unitPrice,
            'subtotal' => $unitPrice * $data['quantity'],
            'total' => $unitPrice * $data['quantity'],
        ]);

        $this->recalcTotals($order);

        return response()->json(['ok' => true, 'item_id' => $item->id, 'total_aed' => Money::toAed($order->total)]);
    }

    public function updateItem(Request $request, int $id, int $itemId): JsonResponse
    {
        $order = Order::find($id);
        if ($order === null) return response()->json(['error' => 'not_found'], 404);
        if ($blocked = $this->assertEditable($order)) return $blocked;

        $item = $order->items()->find($itemId);
        if ($item === null) return response()->json(['error' => 'not_found'], 404);

        $data = $request->validate([
            'quantity' => ['sometimes', 'integer', 'min:1'],
            'unit_price_aed' => ['sometimes', 'numeric', 'min:0'],
        ]);

        if (isset($data['quantity'])) $item->quantity = $data['quantity'];
        if (isset($data['unit_price_aed'])) $item->unit_price = (int) round($data['unit_price_aed'] * 100);
        $item->subtotal = $item->unit_price * $item->quantity;
        $item->total = $item->subtotal;
        $item->save();

        $this->recalcTotals($order);

        return response()->json(['ok' => true, 'total_aed' => Money::toAed($order->total)]);
    }

    public function removeItem(int $id, int $itemId): JsonResponse
    {
        $order = Order::find($id);
        if ($order === null) return response()->json(['error' => 'not_found'], 404);
        if ($blocked = $this->assertEditable($order)) return $blocked;

        $item = $order->items()->find($itemId);
        if ($item === null) return response()->json(['error' => 'not_found'], 404);
        if ($order->items()->count() <= 1) {
            return response()->json(['ok' => false, 'message' => 'An order needs at least one item — remove the whole order instead if it should not exist.'], 422);
        }

        $item->delete();
        $this->recalcTotals($order);

        return response()->json(['ok' => true, 'total_aed' => Money::toAed($order->total)]);
    }

    private function nextOrderNumber(): string
    {
        $max = (int) Order::withTrashed()->max('id');

        return 'KBB-' . str_pad((string) ($max + 1000), 5, '0', STR_PAD_LEFT);
    }

    private function customerHistory(?int $customerId, string $email): array
    {
        $query = $customerId
            ? Order::withTrashed()->where('customer_id', $customerId)
            : Order::withTrashed()->where('email', $email);

        $orders = $query->get(['id', 'total', 'status']);
        $real = $orders->whereIn('status', Order::REAL_STATUSES);

        return [
            'total_orders' => $orders->count(),
            'total_revenue_aed' => Money::toAed((int) $real->sum('total')),
            'average_order_value_aed' => $real->count() > 0 ? Money::toAed((int) round($real->sum('total') / $real->count())) : 0,
        ];
    }
}

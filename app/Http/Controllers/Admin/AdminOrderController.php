<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderNote;
use App\Models\Refund;
use App\Services\Payments\PaymentCapturer;
use App\Services\Payments\PaymentRefunder;
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
    /** Actions that are real right now vs. visible-but-not-wired-up. */
    private const REAL_ACTIONS = ['cancel', 'duplicate', 'resend_confirmation', 'email_invoice'];
    /*
     * 'resend_confirmation' left the placeholder list when order email was
     * built; 'email_invoice' has now left it too. The store has a real invoice
     * — an invoice number allocated out of the unique sequence, and a document
     * rendered from the order_items snapshot by App\Services\Invoices — so the
     * button sends one instead of apologising for not having one.
     *
     * The list itself stays rather than being deleted. It is the mechanism for
     * saying "visible, and honest about not working yet", and the next
     * half-built action should use it rather than reinventing it. Empty is the
     * correct state of an honest list with nothing to declare, and
     * InvoiceEmailTest pins that 'email_invoice' is not on it.
     */
    private const PLACEHOLDER_ACTIONS = [];

    public function show(
        int $id,
        \App\Support\VatDisplay $vat,
        PaymentCapturer $capturer,
        PaymentRefunder $refunder,
    ): JsonResponse
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
            // Where the two printable documents live, built by the controller
            // that serves them so there is one definition of each path and the
            // admin console never has to assemble one out of string pieces.
            // Both are inside the admin-api group, i.e. behind auth:admin; see
            // the InvoiceController header for why there is no public link.
            // Opening the invoice URL is what allocates invoice_number above,
            // so an order that reads "Not yet invoiced" here has genuinely
            // never had one issued.
            'invoice_url' => \App\Http\Controllers\Admin\InvoiceController::invoiceUrl($order->id),
            'packing_slip_url' => \App\Http\Controllers\Admin\InvoiceController::packingSlipUrl($order->id),

            'refunds' => $order->refunds()->latest()->get()->map(fn (Refund $r) => [
                'id' => $r->id,
                'amount_aed' => Money::toAed((int) $r->amount),
                'reason' => $r->reason,
                'refunded_by' => $r->refunded_by,
                // A failed refund is shown, not hidden. The screen greys it
                // and the merchant can see that an attempt was made and that
                // no money went back — which is the whole reason failures are
                // recorded rather than swallowed.
                'status' => $r->status,
                'failure_code' => $r->failure_code,
                'provider_ref' => $r->provider_ref,
                'created_at' => optional($r->created_at)->toAtomString(),
            ]),
            // Only refunds that hold money. Summing every row would count
            // failures, which would quietly reduce what can still be refunded.
            'refunded_total_aed' => Money::toAed($refunder->refundedFils($order)),
            'refundable_aed' => Money::toAed(max(0, $refunder->capturedFils($order) - $refunder->refundedFils($order))),

            // Capture: whether this order's money has actually been taken.
            // Never calls a provider — see PaymentCapturer::status().
            'settlement' => $capturer->status($order) + [
                'refundable_fils' => max(0, $refunder->capturedFils($order) - $refunder->refundedFils($order)),
            ],

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
     * Refund, full or partial — and now really a refund.
     *
     * This method used to write a `refunds` row and stop there, because no
     * gateway had a refund path to call: honestly partial, and documented as
     * such. It now hands the work to PaymentRefunder, which calls the gateway
     * and records the attempt either way. Three things moved OUT of here as a
     * result, and none of them by accident:
     *
     *   THE CEILING. It was `order.total` and it is now the CAPTURED amount
     *   minus what is already refunded, computed inside a locked transaction
     *   in the service. The old check used `$order->refunds()->sum('amount')`,
     *   which counts every row — including, once failures started being
     *   recorded, refunds that never happened. A failed attempt would have
     *   silently reduced what could still be refunded.
     *
     *   THE STATUS CHANGE. Moved for the same reason: a partial refund that
     *   fails at the gateway must not be what tips an order into `refunded`.
     *
     *   IDEMPOTENCY. The double-click guard is a unique index on
     *   `refunds.idempotency_key`, so it has to be applied by the code that
     *   writes the row. The screen sends a key per form render; a caller that
     *   sends none gets a derived one.
     *
     * What stays here is HTTP: validate, convert the screen's AED into fils
     * once, and translate the outcome into a status code. `amount_fils` is
     * accepted and preferred for a caller that has the integer already —
     * there is then no float on the path at all.
     */
    public function refund(Request $request, int $id, PaymentRefunder $refunder): JsonResponse
    {
        $order = Order::find($id);
        if ($order === null) return response()->json(['error' => 'not_found'], 404);

        $data = $request->validate([
            'amount_fils' => ['nullable', 'integer', 'min:1'],
            'amount_aed' => ['nullable', 'numeric', 'min:0.01'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['nullable', 'string', 'max:191'],
        ]);

        if (($data['amount_fils'] ?? null) === null && ($data['amount_aed'] ?? null) === null) {
            return response()->json(['ok' => false, 'message' => 'Enter a refund amount.'], 422);
        }

        // One conversion, in one place, through the same helper the rest of
        // the app converts money with. Integer fils from here down.
        $amountFils = $data['amount_fils'] ?? Money::fromMajor($data['amount_aed']);

        $outcome = $refunder->refund(
            $order,
            (int) $amountFils,
            $data['reason'] ?? null,
            auth('admin')->user()?->name ?? 'Admin',
            $data['idempotency_key'] ?? null,
        );

        $order->refresh();

        return response()->json([
            'ok' => $outcome->ok,
            'code' => $outcome->code,
            // Written for the admin. Never an API body, a key or a buyer field.
            'message' => $outcome->message,
            'refund_id' => $outcome->refund?->id,
            'refund_status' => $outcome->refund?->status,
            'status' => $order->status,
            'refunded_total_aed' => Money::toAed($refunder->refundedFils($order)),
            'refundable_aed' => Money::toAed(max(0, $refunder->capturedFils($order) - $refunder->refundedFils($order))),
        ], $outcome->ok ? 200 : $outcome->status);
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
     * Every action on this dropdown is now real.
     *
     * Cancel and duplicate always were. 'resend_confirmation' became real when
     * order email shipped but was never added back to REAL_ACTIONS, so the
     * screen stopped offering it at all — it is on the list again above.
     * 'email_invoice' is real as of this package: it allocates the order's
     * invoice number if it has none and mails the document.
     *
     * The placeholder branch below survives them, unused. It is the mechanism
     * for showing Rafi what the page is building toward without ever making a
     * button look like it worked when it did not, and deleting it would mean
     * the next half-built action invents its own way of saying so.
     */
    public function runAction(Request $request, int $id): JsonResponse
    {
        $order = Order::find($id);
        if ($order === null) return response()->json(['error' => 'not_found'], 404);

        $data = $request->validate(['action' => ['required', 'string']]);
        $action = $data['action'];

        if ($action === 'resend_confirmation') {
            // The mailer answers honestly here rather than swallowing, because
            // somebody pressed a button and is waiting for the result. 422 on
            // failure so the screen shows the reason instead of a tick.
            $result = app(\App\Services\Mail\OrderMailer::class)->resendConfirmation($order);

            return response()->json($result, $result['ok'] ? 200 : 422);
        }

        if ($action === 'email_invoice') {
            // Same contract as the resend above, and for the same reason: the
            // mailer reports instead of swallowing, because somebody pressed a
            // button and is waiting for the answer. 422 on failure so the screen
            // prints the reason rather than a tick. The response carries the
            // invoice number the send used, so the screen can show it without a
            // second round trip — and so the owner can see which document went.
            $result = app(\App\Services\Mail\OrderMailer::class)->emailInvoice($order);

            return response()->json($result, $result['ok'] ? 200 : 422);
        }

        if (in_array($action, self::PLACEHOLDER_ACTIONS, true)) {
            // The list is empty today. This stays as the shape of an honest
            // refusal for the next action that is visible before it is built:
            // the copy names what is missing rather than claiming the whole
            // feature is unbuilt, which is what the old invoice message did for
            // months after the sentence stopped being true.
            return response()->json([
                'ok' => false,
                'message' => 'That action is on the screen but not wired up yet.',
            ], 422);
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

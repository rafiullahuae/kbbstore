<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderNote;
use App\Models\Product;
use App\Models\Refund;
use App\Services\ManualOrderBuilder;
use App\Services\Mail\OrderMailer;
use App\Services\SettingsService;
use App\Services\ShippingService;
use App\Services\Payments\PaymentCapturer;
use App\Services\Payments\PaymentRefunder;
use App\Support\AggregatesQueries;
use App\Support\Fils;
use App\Support\Money;
use App\Support\StoreTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
    /**
     * Counting a builder that is also used to fetch a page of rows is how this
     * repo shipped MySQL error 1140 to production twice, and how a page-two
     * total silently read zero on every engine.
     */
    use AggregatesQueries;

    /** How many rows one customer or product search returns. */
    private const PAGE = 20;

    /**
     * The LIKE escape character.
     *
     * Without it, an operator searching for "50% off" or typing an underscore
     * matches every row in the table: % and _ are wildcards inside LIKE, and a
     * bound parameter does not escape them. Binding protects against SQL
     * injection, not against pattern injection.
     */
    private const LIKE_ESCAPE = '!';

    public function __construct(
        private ManualOrderBuilder $builder,
        private ShippingService $shipping,
        private SettingsService $settings,
    ) {}

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
        /*
         * items.product, and only three columns of it.
         *
         * order_items snapshots the name, brand, SKU and price of what was sold
         * but not its photograph, so the line item's `image` below is the LIVE
         * product's. Read lazily that was one SELECT per line every time an
         * order was opened; named here it is one for the whole order. The
         * product may legitimately be gone — order_items.product_id is
         * nullOnDelete — which is exactly the case the screen now draws initials
         * for instead of an empty square.
         */
        $order = Order::withTrashed()
            ->with(['items.product:id,image,slug', 'notes'])
            ->find($id);

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
            /*
             * SHOP-LOCAL, not UTC. This was `toAtomString()` on the Eloquent
             * cast, which is `config('app.timezone')` — UTC — so an order
             * placed at 01:30 in Dubai opened here showing the previous day,
             * both in the "Date created" boxes (which slice this string
             * positionally) and anywhere else the drawer prints it.
             *
             * Same wire SHAPE as before — `Y-m-d\TH:i:sP`, offset included —
             * so every existing slice() in the console keeps working; only the
             * offset it carries has changed, from +00:00 to the shop's. The
             * stored instant is untouched; see App\Support\StoreTime.
             */
            'created_at' => StoreTime::iso($order->created_at),
            'currency' => $order->currency,

            'payment_method' => $order->payment_method,
            'payment_method_title' => $order->payment_method_title,
            'transaction_id' => $order->transaction_id,
            'paid_at' => StoreTime::iso($order->paid_at),
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
            /*
             * THE ORDER'S OWN TAX RECORD FIRST — Lane CU.
             *
             * This computed the line fresh from the live settings, which was
             * right while VAT was a display line at one global rate (D-64).
             * The owner overturned that on 2026-09-16 and rates now vary by
             * country and can be edited, so a recomputation here would show
             * staff a figure the customer was never charged. An order that has
             * a record of its own is read from that record; one that has none
             * — everything placed before this lane — falls back to exactly the
             * computation that was here.
             *
             * VatDisplay's own 'formatted' field is raw HTML meant for
             * server-rendered Blade (Money::format() wraps it in <span> tags)
             * — wrong for this JSON response, so only the label and a
             * converted AED amount are used.
             */
            'vat' => (function () use ($vat, $order) {
                $recorded = \App\Support\OrderTax::recorded($order);

                if ($recorded !== null) {
                    if ($recorded['fils'] <= 0) {
                        return null;
                    }

                    $rule = new \App\Support\TaxRule($recorded['rate'], $recorded['basis']);

                    return [
                        'label' => $recorded['added']
                            ? 'VAT at ' . $rule->printableRate() . '% (added to the total)'
                            : 'VAT at ' . $rule->printableRate() . '% (included in the total)',
                        'amount_aed' => Money::toAed($recorded['fils']),
                    ];
                }

                $line = $vat->line($order->total);

                return $line ? ['label' => $line['label'], 'amount_aed' => Money::toAed($line['amount'])] : null;
            })(),

            'invoice_number' => $order->invoice_number,
            'invoiced_at' => StoreTime::iso($order->invoiced_at),
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
                'created_at' => StoreTime::iso($r->created_at),
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
                'created_at' => StoreTime::iso($n->created_at),
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

            /*
             * What the "Email the customer about this change" tick box beside
             * the status dropdown needs to draw itself: for every status, is
             * there a customer message at all, does the standing rule send it,
             * and if not, why not.
             *
             * Carried on the order payload rather than fetched separately
             * because the screen needs it at the same moment it needs the
             * order, and because one answer read once cannot disagree with
             * itself. It is store configuration, not a fact about this order —
             * the per-order part is the tick the operator makes, which travels
             * back on the status-change request as `notify` and is never
             * stored. See App\Services\Mail\OrderStatusMailPolicy.
             */
            'status_emails' => app(\App\Services\Mail\OrderStatusMailPolicy::class)->all(),
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
            'is_customer_note' => false, 'created_at' => StoreTime::iso($note->created_at),
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
            // The same per-order decision the status dropdown carries, because
            // Cancel is a status change wearing a button. Recorded before the
            // save, which is what fires OrderMailObserver; absent means the
            // standing rule for `cancelled` decides.
            app(\App\Services\Mail\OrderStatusMailPolicy::class)->decideFor(
                $order,
                $request->has('notify') ? $request->boolean('notify') : null,
            );

            // Through the funnel, for the reason the dropdown goes through it:
            // Cancel is a status change wearing a button, and a cancelled order
            // has to give back the coupon use it is holding. See
            // App\Services\Orders\OrderStatus. Pressing Cancel twice returns
            // one use, not two — the second press is not a transition.
            //
            // The units this order was holding go back too. That call used to
            // stand here, one of five copies; it is inside the funnel now, so
            // Cancel here and Cancel on the orders list cannot come to mean
            // different things to the stock room — which is what
            // OrderTransitionStock's own header asked for.
            app(\App\Services\Orders\OrderStatus::class)->moveTo(
                $order,
                'cancelled',
                by: auth('admin')->user()?->name ?: 'Admin',
                reason: 'Cancelled on the order screen.',
            );

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

    /**
     * The ceiling on one line's quantity.
     *
     * Not a guess at what anyone would order: it is the largest quantity that
     * cannot on its own take a line past the money column at any unit price a
     * shop would charge, and it matches the 99 the storefront cart enforces in
     * CartService::add(). A back office that accepted a quantity the shop front
     * clamps would be two different products.
     */
    private const MAX_QUANTITY = 99;

    /**
     * Refuse a line whose money will not fit, before anything is written.
     *
     * order_items.unit_price, .subtotal and .total are all `$t->integer` —
     * signed 32-bit, so 2,147,483,647 fils (AED 21,474,836.47) is the ceiling.
     * Both the Phase 0 schema and 2026_09_15_020000_repair_order_tables declare
     * them that way.
     *
     * Bounding each factor on its own is not enough: a unit price and a
     * quantity can both be perfectly ordinary and their product still be past
     * the column. And the order's own subtotal is a sum across lines, so a
     * tenth line can overflow it while every line including that one fits.
     *
     * Both engines are wrong here, differently. MySQL in strict mode raises and
     * the operator sees a 500 on a save they had no reason to think would fail.
     * SQLite stores the wrapped number without a word — an order that reads as
     * placed, with a total that is negative or nonsense, which is the half that
     * reaches a customer.
     */
    private function refuseOverflowingLine(Order $order, int $unitPrice, int $quantity, ?int $ignoreItemId = null): ?JsonResponse
    {
        $ceiling = Money::plain(Fils::max());

        if (! Fils::productFits($unitPrice, $quantity)) {
            return response()->json([
                'ok' => false,
                'message' => 'That quantity at that unit price comes to more than an order line can hold (' . $ceiling . ').',
            ], 422);
        }

        $line = $unitPrice * $quantity;

        $others = (int) $order->items()
            ->when($ignoreItemId !== null, fn ($q) => $q->whereKeyNot($ignoreItemId))
            ->sum('total');

        if (! Fils::sumFits($others, $line, (int) $order->shipping_total, (int) $order->fee_total, (int) $order->tax_total)) {
            return response()->json([
                'ok' => false,
                'message' => 'That line would take the order total past what it can hold (' . $ceiling . ').',
            ], 422);
        }

        return null;
    }

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
            // Bounded. order_items.quantity is an unsignedInteger and the money
            // columns beside it are signed 32-bit; an unbounded quantity
            // overflows their product long before it overflows itself.
            'quantity' => ['required', 'integer', 'min:1', 'max:' . self::MAX_QUANTITY],
        ]);

        $product = \App\Models\Product::find($data['product_id']);
        $unitPrice = (int) ($product->sale_price ?: $product->price);
        $quantity = (int) $data['quantity'];

        if ($refusal = $this->refuseOverflowingLine($order, $unitPrice, $quantity)) {
            return $refusal;
        }

        $lineTotal = $unitPrice * $quantity;

        $item = $order->items()->create([
            'product_id' => $product->id,
            'name' => $product->name,
            'brand' => $product->brand?->name,
            'sku' => $product->sku,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'subtotal' => $lineTotal,
            'total' => $lineTotal,
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
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:' . self::MAX_QUANTITY],
            /*
             * A STRING, not `numeric`, and parsed by Fils::parse().
             *
             * `numeric` accepts scientific notation and any number of decimals,
             * and the old `(int) round($value * 100)` then routed an operator's
             * keystrokes through a double: (int) (1.15 * 100) is 114. round()
             * happens to rescue two-decimal values at today's magnitudes, so
             * this was latent rather than wrong — the same class of defect the
             * product editor had, and worth removing for the same reason.
             *
             * Fils::parse() reads the digits one at a time, refuses more
             * precision than a fil can hold rather than silently dropping it,
             * and refuses anything past the 32-bit money column.
             */
            'unit_price_aed' => ['sometimes', 'string', 'max:24', function (string $attribute, $value, $fail) {
                $fils = Fils::parse((string) $value);

                if ($fils === null) {
                    $fail('Enter a plain amount with at most two decimals, up to '
                        . Money::plain(Fils::max()) . '.');

                    return;
                }

                if ($fils < 0) {
                    $fail('A unit price cannot be negative.');
                }
            }],
        ]);

        if (isset($data['quantity'])) {
            $item->quantity = (int) $data['quantity'];
        }

        if (isset($data['unit_price_aed'])) {
            $item->unit_price = (int) Fils::parse((string) $data['unit_price_aed']);
        }

        // Both factors are individually in range by now. Their PRODUCT is the
        // thing that actually overflows, and it is checked after both have been
        // applied rather than against whichever one this request happened to
        // change — a sane new quantity against an already-large unit price
        // overflows just as well as the other way round.
        if ($refusal = $this->refuseOverflowingLine($order, (int) $item->unit_price, (int) $item->quantity, $item->id)) {
            return $refusal;
        }

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

    /* ===================================================================
     | GET /admin-api/manual-orders/bootstrap
     |=================================================================== */

    /**
     * Everything the form needs to render: the real vocabularies, not invented
     * ones.
     *
     * A sibling lane found AdminController::updateProduct validating status as
     * in:active,draft,archived when the products column carries
     * publish|draft|private — a save through it hid the product from the whole
     * storefront. So every list below is read from the schema or from the
     * table that owns it, and the same constants feed both this endpoint and
     * the validator in store().
     */
    public function bootstrap(OrderMailer $mailer): JsonResponse
    {
        $countries = $this->shipping->coveredCountries();

        if ($countries === []) {
            // No zone configured at all — the same floor the storefront
            // checkout falls back to, so the two never offer different lists.
            $countries = ['AE' => 'United Arab Emirates'];
        }

        return response()->json([
            'statuses' => ManualOrderBuilder::STATUSES,
            'default_status' => ManualOrderBuilder::DEFAULT_STATUS,
            'channels' => ManualOrderBuilder::CHANNELS,
            'payment_methods' => $this->builder->paymentMethods(),
            'countries' => $countries,
            'default_country' => (string) $this->settings->get('store_country', 'AE'),
            // Free text on the live site, offered as suggestions only — the
            // addresses table does not constrain state.
            'emirates' => [
                'Abu Dhabi', 'Dubai', 'Sharjah', 'Ajman',
                'Umm Al Quwain', 'Ras Al Khaimah', 'Fujairah',
            ],
            'currency' => 'AED',
            'cod_fee_fils' => (int) $this->settings->get('cod_fee', 0),
            'email' => $this->confirmationEmailCapability($mailer),
        ]);
    }

    /* ===================================================================
     | GET /admin-api/manual-orders/customers?q=
     |=================================================================== */

    /** Search the customer list by name, email or phone. */
    public function customers(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1', 'max:5000'],
        ]);

        $term = trim((string) ($data['q'] ?? ''));
        $page = (int) ($data['page'] ?? 1);

        // Customer soft-deletes, so the model's global scope already excludes
        // removed rows; nothing extra is needed here.
        $query = Customer::query();

        if ($term !== '') {
            $like = '%' . $this->escapeLike($term) . '%';

            $query->where(function ($q) use ($like) {
                foreach (['name', 'first_name', 'last_name', 'email', 'phone'] as $column) {
                    $q->orWhereRaw(
                        $column . " LIKE ? ESCAPE '" . self::LIKE_ESCAPE . "'",
                        [$like],
                    );
                }
            });
        }

        // Through App\Support\AggregatesQueries, which strips the select list,
        // the ordering and the page window from a copy of the builder. Counting
        // $query as it stands — after the orderByDesc and forPage below — is
        // MySQL 1140 on the ordering and a silent zero from page two on the
        // offset. Both have shipped from this repo.
        $total = (int) ($this->aggregate($query, 'count(*) as aggregate')?->aggregate ?? 0);

        $rows = (clone $query)
            ->orderByDesc('id')
            ->forPage($page, self::PAGE)
            ->get();

        return response()->json([
            'total' => $total,
            'page' => $page,
            'per_page' => self::PAGE,
            'customers' => $rows->map(fn (Customer $c) => [
                'id' => $c->id,
                'name' => $c->displayName(),
                'email' => $c->email,
                'phone' => $c->phone,
                'orders_count' => (int) $c->orders_count,
                'total_spent_fils' => (int) $c->total_spent,
                'address' => $this->addressOf($c),
            ])->values(),
        ]);
    }

    /* ===================================================================
     | GET /admin-api/manual-orders/products?q=
     |=================================================================== */

    /** Search the real catalogue by name or SKU. */
    public function products(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1', 'max:5000'],
        ]);

        $term = trim((string) ($data['q'] ?? ''));
        $page = (int) ($data['page'] ?? 1);

        // Everything sellable, including products hidden from the catalogue:
        // staff take orders for things that are not on the shop grid. Drafts
        // are excluded — 'publish' and 'private' are the two live values in
        // this schema's products.status (publish | draft | private).
        $query = Product::query()->whereIn('status', ['publish', 'private']);

        if ($term !== '') {
            $like = '%' . $this->escapeLike($term) . '%';

            /*
             * BRAND AND SLUG TOO, not just name and sku.
             *
             * The owner typed "anua" and got "Nothing in the catalogue matches
             * that", while the same search on the order detail screen returned
             * three products. Anua is a BRAND: no product name contains it, so
             * a name-and-sku search finds nothing, and the two screens
             * disagreed about the same catalogue.
             *
             * This is the set CatalogProductsApiController already searches —
             * name, sku, slug, brand name — so the operator gets the same
             * answer wherever they type. A staff member taking an order on the
             * phone reaches for the brand at least as often as the product
             * name.
             *
             * whereHas rather than a join: this query is cloned for a count and
             * then again for the page, and a join would need the select list
             * below to disambiguate every column.
             */
            $query->where(function ($q) use ($like, $term) {
                $q->orWhereRaw("name LIKE ? ESCAPE '" . self::LIKE_ESCAPE . "'", [$like])
                    ->orWhereRaw("sku LIKE ? ESCAPE '" . self::LIKE_ESCAPE . "'", [$like])
                    ->orWhereRaw("slug LIKE ? ESCAPE '" . self::LIKE_ESCAPE . "'", [$like])
                    ->orWhereHas('brand', function ($b) use ($like) {
                        $b->whereRaw("name LIKE ? ESCAPE '" . self::LIKE_ESCAPE . "'", [$like]);
                    });

                // Typing an id finds that product, as it does on the catalogue
                // screen.
                if (ctype_digit($term)) {
                    $q->orWhere('id', '=', (int) $term);
                }
            });
        }

        $total = (int) ($this->aggregate($query, 'count(*) as aggregate')?->aggregate ?? 0);

        /*
         * An explicit allowlist, never the whole row.
         *
         * `products` also carries `description` — a page of HTML per row — plus
         * seo, seo_json, meta_feed and custom_tabs, none of which a suggestion
         * row shows. Selecting * shipped all of it, ten rows at a time, on every
         * keystroke of a type-ahead. The columns here are exactly what the
         * payload below reads: the five identity/display fields, `brand_id` for
         * the brand relation to hang off, and the four price columns
         * effectivePrice() needs to honour a sale window.
         */
        $rows = (clone $query)
            ->select([
                'id', 'name', 'sku', 'image', 'brand_id',
                'price', 'sale_price', 'sale_starts_at', 'sale_ends_at',
                'stock', 'stock_status',
            ])
            ->with(['brand:id,name', 'variants'])
            ->orderBy('name')
            ->forPage($page, self::PAGE)
            ->get();

        return response()->json([
            'total' => $total,
            'page' => $page,
            'per_page' => self::PAGE,
            'products' => $rows->map(fn (Product $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'sku' => $p->sku,
                'brand' => $p->brand?->name,
                'image' => $p->image,
                // effectivePrice() honours the sale window. The quantity-bundle
                // tier is applied later, by CartService, once a quantity exists.
                'price_fils' => $p->effectivePrice(),
                'stock' => $p->stock,
                'stock_status' => $p->stock_status,
                'variants' => $p->variants->map(fn ($v) => [
                    'id' => $v->id,
                    'sku' => $v->sku,
                    'price_fils' => $v->effectivePrice(),
                    'stock_status' => $v->stock_status,
                ])->values(),
            ])->values(),
        ]);
    }

    /* ===================================================================
     | POST /admin-api/manual-orders/quote
     |=================================================================== */

    /**
     * Price a basket without saving anything.
     *
     * Fired whenever a line, quantity, destination or coupon changes, so the
     * operator sees the same total the customer will be charged before they
     * commit to it.
     */
    public function quote(Request $request): JsonResponse
    {
        $input = $this->validatedPayload($request, forCreate: false);

        $priced = $this->builder->quote($input);

        if (! $priced['ok']) {
            return response()->json(['ok' => false, 'error' => $priced['error']], 422);
        }

        return response()->json(['ok' => true] + $this->totalsPayload($priced));
    }

    /* ===================================================================
     | POST /admin-api/manual-orders
     |=================================================================== */

    /** Create the order. */
    public function store(Request $request, OrderMailer $mailer): JsonResponse
    {
        $input = $this->validatedPayload($request, forCreate: true);

        $result = $this->builder->create(
            $input,
            $request->user('admin')?->name ?: $request->user('admin')?->email,
        );

        if (! $result['ok']) {
            return response()->json(['ok' => false, 'error' => $result['error']], 422);
        }

        /** @var Order $order */
        $order = $result['order'];

        return response()->json([
            'ok' => true,
            'order' => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'status' => $order->status,
                'origin' => $order->origin,
                'email' => $order->email,
                'phone' => $order->phone,
                'currency' => $order->currency,
                'subtotal_fils' => (int) $order->subtotal,
                'discount_fils' => (int) $order->discount_total,
                'shipping_fils' => (int) $order->shipping_total,
                'fee_fils' => (int) $order->fee_total,
                'total_fils' => (int) $order->total,
                'coupon_code' => $order->coupon_code,
                'shipping_method' => $order->shipping_method,
                'payment_method' => $order->payment_method,
                'payment_method_title' => $order->payment_method_title,
                'shipping_address' => $order->shipping_address,
                'items' => $order->items->map(fn ($i) => [
                    'name' => $i->name,
                    'sku' => $i->sku,
                    'quantity' => (int) $i->quantity,
                    'unit_price_fils' => (int) $i->unit_price,
                    'line_total_fils' => (int) $i->total,
                ])->values(),
            ],
            // Said plainly rather than left to be discovered.
            'email' => $this->sendConfirmation($order, (bool) ($input['send_confirmation'] ?? false), $mailer),
            'stock' => [
                'adjusted' => false,
                'reason' => 'No order path in this build moves stock — a website '
                    . 'order does not decrement it either. Adjust it in Catalog → Inventory.',
            ],
        ], 201);
    }

    /* ===================================================================
     | Validation
     |=================================================================== */

    /**
     * One validator for quote() and store(), so the two can never disagree
     * about what a valid order is — a quote that prices something the create
     * call then rejects is the worst possible version of this screen.
     */
    private function validatedPayload(Request $request, bool $forCreate): array
    {
        $paymentIds = $this->builder->paymentMethodIds();

        $rules = [
            // Either an existing customer, or enough to make one. The
            // required_without pair is what enforces "one or the other".
            'customer_id' => ['nullable', 'integer', 'required_without:new_customer', 'exists:customers,id'],
            'new_customer' => ['nullable', 'array', 'required_without:customer_id'],
            'new_customer.name' => ['required_with:new_customer', 'string', 'max:120'],
            'new_customer.email' => ['required_with:new_customer', 'email', 'max:160'],
            'new_customer.phone' => ['nullable', 'string', 'max:40'],

            'items' => ['required', 'array', 'min:1', 'max:60'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            // 99 is CartService's own ceiling; asking for more silently becomes
            // 99 there, so it is refused here instead of quietly changed.
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],

            'address' => ['required', 'array'],
            'address.line1' => ['required', 'string', 'max:255'],
            'address.city' => ['required', 'string', 'max:120'],
            // Free text on the live site, not a fixed list — the addresses
            // table does not constrain it either.
            'address.state' => ['required', 'string', 'max:120'],
            'address.country' => ['required', 'string', 'size:2'],
            'address.phone' => ['nullable', 'string', 'max:40'],

            'coupon_code' => ['nullable', 'string', 'max:60'],
            'shipping_method_id' => ['nullable', 'integer'],
            // Typed by the operator in AED. Parsed digit-by-digit below; the
            // rule only checks it is a shape Fils::parse can hold exactly.
            'shipping_override' => ['nullable', 'string', 'max:20', function (string $attribute, $value, $fail) {
                if ($value !== null && $value !== '' && ! Fils::isValid($value)) {
                    $fail('Enter the delivery charge as a plain amount, with at most two decimals.');
                }
            }],

            // payment_providers.id is the vocabulary. Nothing invented.
            'payment_method' => ['required', 'string', Rule::in($paymentIds)],
            'channel' => ['nullable', 'string', Rule::in(ManualOrderBuilder::CHANNELS)],
            'customer_note' => ['nullable', 'string', 'max:2000'],
            'whatsapp_optin' => ['nullable', 'boolean'],
            'send_confirmation' => ['nullable', 'boolean'],
        ];

        if ($forCreate) {
            // orders.status is a free-form string so imported WooCommerce
            // statuses survive; the console's working vocabulary is these
            // seven, and AdminController::updateOrderStatus accepts exactly
            // the same set. An order created with anything else would be one
            // the Orders screen could never edit again.
            $rules['status'] = ['required', 'string', Rule::in(ManualOrderBuilder::STATUSES)];
        } else {
            $rules['status'] = ['nullable', 'string', Rule::in(ManualOrderBuilder::STATUSES)];
        }

        $data = $request->validate($rules);

        // The one operator-typed money field in this screen. Fils::parse reads
        // the string one character at a time and never multiplies by 100 —
        // (int) (1.15 * 100) is 114, and a one-fil error on a delivery charge
        // is exactly the kind nobody spots until it is on an invoice.
        $override = $data['shipping_override'] ?? null;
        $data['shipping_override_fils'] = ($override === null || $override === '')
            ? null
            : Fils::parse((string) $override);

        unset($data['shipping_override']);

        return $data;
    }

    /* ===================================================================
     | Helpers
     |=================================================================== */

    /**
     * Escape the LIKE wildcards in an operator's search term.
     *
     * The escape character itself has to go first, or "!%" would become "!!%"
     * the wrong way round and stop escaping anything.
     */
    private function escapeLike(string $term): string
    {
        return str_replace(
            [self::LIKE_ESCAPE, '%', '_'],
            [self::LIKE_ESCAPE . self::LIKE_ESCAPE, self::LIKE_ESCAPE . '%', self::LIKE_ESCAPE . '_'],
            $term,
        );
    }

    /** The shape the screen renders totals from. All amounts are fils. */
    private function totalsPayload(array $priced): array
    {
        $totals = $priced['totals'];

        return [
            'lines' => $priced['lines'],
            'rates' => $priced['rates'],
            'chosen_rate_id' => $priced['chosen_rate']['id'] ?? null,
            'totals' => [
                'subtotal_fils' => (int) $totals['subtotal'],
                'discount_fils' => (int) $totals['discount'],
                'shipping_fils' => (int) $totals['shipping'],
                'fee_fils' => (int) $priced['fee'],
                'total_fils' => (int) $priced['grand_total'],
                'coupon_code' => $totals['coupon_code'],
                'item_count' => (int) $totals['item_count'],
                'free_shipping_threshold_fils' => $totals['free_shipping_threshold'],
                'free_shipping_remaining_fils' => $totals['free_shipping_remaining'],
                // Display only — never added to the total (D-64).
                'vat' => $totals['vat'],
            ],
        ];
    }

    /**
     * Whether a confirmation email can be sent at all.
     *
     * There is a real one now: App\Mail\OrderConfirmation, sent through
     * App\Services\Mail\OrderMailer, which is also what the storefront
     * checkout fires on placement. The owner can switch it off in
     * Store -> Modules -> Order emails, and this reports that rather than
     * offering a checkbox that would be quietly overruled.
     */
    private function confirmationEmailCapability(OrderMailer $mailer): array
    {
        if (! $mailer->confirmationEnabled()) {
            return [
                'available' => false,
                'reason' => 'Order confirmation emails are switched off in '
                    . 'Store → Modules → Order emails, so nothing would be sent.',
            ];
        }

        return ['available' => true, 'reason' => null];
    }

    /**
     * Send the confirmation, or say why not.
     *
     * resendConfirmation() rather than placed(): placed() also fires the
     * merchant alert, and a "new order" landing in the owner's inbox for an
     * order the owner just keyed in by hand is a lie about what happened. It is
     * also the only method on OrderMailer that REPORTS rather than swallowing
     * the outcome, which is what an operator who ticked a box is owed.
     */
    private function sendConfirmation(Order $order, bool $requested, OrderMailer $mailer): array
    {
        if (! $requested) {
            return [
                'requested' => false,
                'sent' => false,
                'reason' => 'Not requested — the operator left the box unticked.',
            ];
        }

        $result = $mailer->resendConfirmation($order);

        return [
            'requested' => true,
            'sent' => (bool) $result['ok'],
            'reason' => (string) $result['message'],
        ];
    }

    /** The customer's saved address, for prefilling the form. */
    private function addressOf(Customer $customer): ?array
    {
        $address = $customer->defaultAddress('shipping') ?? $customer->defaultAddress('billing');

        if (! $address) {
            return null;
        }

        return [
            'line1' => $address->line1,
            'city' => $address->city,
            'state' => $address->state,
            'country' => $address->country,
            'phone' => $address->phone,
        ];
    }
}

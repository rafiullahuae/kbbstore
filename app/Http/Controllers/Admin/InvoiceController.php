<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Invoices\InvoiceDocument;
use App\Services\Invoices\InvoiceNumbers;
use App\Support\OrderLocale;
use App\Support\Url;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;

/**
 * The four printable documents an order produces.
 *
 * ── WHY HTML AND NOT A PDF ──────────────────────────────────────────────────
 *
 * The host is shared Hostinger with no shell access, and `vendor/` cannot reach
 * the server through the updater at all: it is in BuildPackage::NEVER_SHIP and
 * in UpdateGuard::FORBIDDEN_PREFIXES, so a package containing a composer PDF
 * library would be refused, and installing one by hand is a change the updater
 * could not roll back. A print-ready HTML document has none of that problem and
 * loses nothing the owner needs — every browser's print dialog writes a PDF,
 * with selectable text and real fonts, from the same markup.
 *
 * ── WHY THESE ROUTES LIVE BEHIND auth:admin AND NOWHERE ELSE ────────────────
 *
 * An invoice carries the buyer's full name, their street address, their phone
 * number, what they bought and what they paid. `/api/*` in this app is
 * unauthenticated by design (CLAUDE.md), so these belong in the `admin-api`
 * group, which already carries `web`, `auth:admin` and NoStoreAdminApi.
 * InvoiceDocumentTest asserts the refusal for an anonymous caller, for a
 * signed-in storefront customer and for a `web`-guard user, and reads the
 * middleware stack back off the registered routes rather than trusting the
 * harness that mounted them.
 *
 * THERE IS NO PUBLIC, TOKENISED INVOICE LINK, and that is the design rather
 * than an omission. A URL that carries its own authority is a credential that
 * sits in a browser history, a referrer header and an inbox forever — the exact
 * argument OrderEmailPresenter::trackUrl() makes about the order-received page,
 * and the reason CustomerPasswordReset is the only mailed link in this app that
 * carries one. The admin session is the single answer to "who may read this
 * order", so guessing an order id buys nothing: every one of these paths
 * refuses before it loads a row.
 *
 * ── WHEN THE NUMBER IS ALLOCATED ────────────────────────────────────────────
 *
 * On the first render of the INVOICE, and nowhere else. Not at checkout: an
 * abandoned or failed order would burn a number out of a legal sequence and
 * leave a gap an accountant has to explain. Not on any of the other three
 * either — a packing slip is a picking list, a delivery note is a handover
 * record and a dispatch label is an address on a box. All three are printed for
 * orders that may never be invoiced, and printing one must not consume a
 * number. Allocation is idempotent (see InvoiceNumbers), so reloading the
 * invoice ten times, or two admins opening it at once, still produces exactly
 * one number for the order.
 *
 * ── WHAT EACH OF THE FOUR CARRIES, IN ONE PLACE ─────────────────────────────
 *
 *                     money   address   item names   SKU   gift message   language
 *   invoice             yes     yes         yes      yes        yes         CUSTOMER
 *   packing slip        NO      yes         yes      yes        yes         operator
 *   delivery note       NO      yes         yes      NO         NO          CUSTOMER
 *   dispatch label      COD*    yes         NO       NO         NO          operator
 *
 *   * the cash-on-delivery amount, and only on an unpaid COD order. See
 *     InvoiceDocument::codToCollect().
 *
 * The row that matters is the last one. The label is on the OUTSIDE of the
 * parcel and everybody on the route reads it, so it names no product, no brand
 * and no SKU; the other three travel inside the box or to the customer. Each
 * view's header argues its own line of that table, and DispatchDocumentsTest
 * asserts the NOs over the whole rendered page rather than over a list of
 * fields somebody has to remember to keep up to date.
 *
 * THE LAST COLUMN IS NOT "DOES IT LEAVE THE BUILDING", IT IS WHO READS IT. The
 * dispatch label leaves too, on the outside of the box, and stays in the
 * operator's language because a courier reads it. The invoice and the delivery
 * note are addressed to the customer — one to their inbox and their file, one
 * into the parcel for them to open and sign — so both follow the order's own
 * language through OrderLocale::render(). The packing slip is a picking list
 * for the bench. deliveryNote() below argues that line in full.
 *
 * FOUR, NOT FIVE. The order screen offers "Shipping Label" and "Dispatch Label"
 * as separate buttons. They are one document — see the shipping-label view's
 * header — and both should open shippingLabelUrl().
 */
class InvoiceController extends Controller
{
    public function __construct(
        private InvoiceDocument $documents,
        private InvoiceNumbers $numbers,
    ) {}

    /**
     * The printable invoice. Allocates this order's invoice number if it has none.
     *
     * ── IT PRINTS IN THE CUSTOMER'S LANGUAGE, NOT THE OPERATOR'S ────────────
     *
     * This was the one document of the four that rendered in whatever language
     * the browser that pressed the button happened to be in, which is the
     * operator's, and the previous lane left the choice open on the grounds
     * that an operator is the one looking at the screen.
     *
     * What settles it is that the customer already has this document. The
     * emailed invoice goes out through OrderMailer, which wraps its build in
     * OrderLocale::render() — so an Arabic order's invoice arrived in Arabic,
     * and an operator reprinting the same invoice from this screen would hand
     * over, or re-send, a second sheet that reads differently from the one in
     * the customer's inbox. Both carry the same invoice number. Two documents
     * with one number on them is the failure, and it is a worse one on an
     * invoice than anywhere else in the shop, because an invoice is the sheet
     * a customer files and may have to produce.
     *
     * So it follows the order, exactly as every other thing addressed to the
     * customer now does, and for the same reason: the person who reads it is
     * the customer.
     *
     * AND ONE OTHER OF THE FOUR, WHICH IS NOT WHAT THIS PARAGRAPH USED TO SAY.
     * It read "and only this one of the four", on the grounds that the other
     * three "are read inside the building or by a courier, by people who did
     * not place the order". That is true of the packing slip and of the
     * dispatch label and it was never true of the delivery note, which goes IN
     * THE PARCEL and is handed to the customer to sign. deliveryNote() below
     * carries the argument; the two that genuinely are read by this shop and by
     * the courier stay in the operator's language, which is what they were
     * already in.
     *
     * RENDERED HERE, NOT RETURNED AS A VIEW. A View returned from a controller
     * is rendered during response preparation, long after render()'s `finally`
     * has put the previous locale back — so wrapping the call to view() and
     * returning its result would compile the template in English and look like
     * it had worked. The string is produced inside the closure.
     *
     * The toolbar is the exception inside the exception: it is `.no-print`
     * navigation for the operator standing at the screen, so its two strings
     * are resolved out here, before the locale changes, and passed in.
     */
    public function invoice(int $id): View|Response
    {
        $order = $this->find($id);

        if ($order === null) {
            return $this->missing();
        }

        $this->numbers->allocate($order);

        // Re-read, so the document prints the number and timestamp that are in
        // the database rather than the ones this process hoped to write. Under
        // contention those are not always the same thing.
        $order->refresh();

        // Resolved OUT HERE, before the locale moves. Inside the closure the
        // array literal is built in the order's language along with everything
        // else, which is right for the sheet and wrong for the buttons.
        $toolbar = [
            'toolbarHint' => __('invoice.document.print_hint'),
            'toolbarButton' => __('invoice.document.print_button'),
        ];

        $html = OrderLocale::render($order, fn (): string => view('invoices.invoice', array_merge([
            'doc' => $this->documents->present($order),
            'packingSlipUrl' => self::packingSlipUrl($order->id),
            'deliveryNoteUrl' => self::deliveryNoteUrl($order->id),
            'labelUrl' => self::shippingLabelUrl($order->id),
        ], $toolbar))->render());

        return response($html)->header('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * The packing slip: the same order with no prices on it.
     *
     * Deliberately does NOT allocate a number — see the class header. If the
     * order has one already it is printed, because a warehouse matching a
     * parcel to a document is helped by it.
     */
    public function packingSlip(int $id): View|Response
    {
        $order = $this->find($id);

        if ($order === null) {
            return $this->missing();
        }

        return view('invoices.packing-slip', [
            'doc' => $this->documents->present($order),
            'invoiceUrl' => self::invoiceUrl($order->id),
            'deliveryNoteUrl' => self::deliveryNoteUrl($order->id),
            'labelUrl' => self::shippingLabelUrl($order->id),
        ]);
    }

    /**
     * The delivery note: what was handed over, with a signature block and no
     * prices.
     *
     * Allocates nothing, for the same reason the packing slip does not.
     *
     * ── IT PRINTS IN THE CUSTOMER'S LANGUAGE, AND IT IS THE SECOND OF THE FOUR
     *    TO DO SO ───────────────────────────────────────────────────────────
     *
     * THE WHOLE ARGUMENT IS WHERE THE SHEET ENDS UP. This one GOES IN THE
     * PARCEL. It is read at the door by the person who ordered — the view's own
     * header has said so since it was written, that it "is read at the door,
     * facing the customer, so it leads with what is in the parcel in the
     * customer's own words" — and every word on it was still the operator's.
     * An Arabic shopper who bought under Arabic names, was emailed in Arabic and
     * was invoiced in Arabic opened the box on an English handover sheet and was
     * asked to sign it.
     *
     * AND THE PACKING SLIP SITTING NEXT TO IT IN THIS FILE DOES NOT MOVE, which
     * is the distinction worth stating rather than inferring. A packing slip is
     * a PICKING LIST: it is read at the bench, facing the shelves, by somebody
     * who works here, and it carries the SKU to pick by. A dispatch label is an
     * address on the outside of a box, read by a courier. Neither is addressed
     * to the customer, so neither follows the customer's language. The
     * difference is not "does it leave the building" — the label leaves too —
     * it is WHO READS IT.
     *
     * So the split is now: the two documents the customer reads (invoice,
     * delivery note) follow the order; the two the shop and the courier read
     * (packing slip, dispatch label) stay in the operator's. The class header's
     * table carries that as a column.
     *
     * The line names are the same decision one level down. `order_items.name`
     * keeps the operator's language AND its exact value, which is what the
     * picking list needs; `name_localised` carries the customer's, and
     * InvoiceDocument exposes it as `nameForCustomer`. This sheet reads that
     * one now, and it is the only change of key in the four.
     *
     * RENDERED HERE, NOT RETURNED AS A VIEW, for the reason invoice() gives at
     * length: a View returned from a controller is rendered during response
     * preparation, long after render()'s `finally` has put the previous locale
     * back, so wrapping view() and returning its result compiles the template in
     * English and looks like it worked.
     *
     * The toolbar is the exception inside the exception, exactly as it is on the
     * invoice: it is `.no-print` navigation for the operator standing at the
     * screen, so its two strings are resolved before the locale changes.
     */
    public function deliveryNote(int $id): View|Response
    {
        $order = $this->find($id);

        if ($order === null) {
            return $this->missing();
        }

        // Resolved OUT HERE, before the locale moves — see invoice().
        $toolbar = [
            'toolbarHint' => __('invoice.document.print_hint'),
            'toolbarButton' => __('invoice.document.print_button'),
        ];

        $html = OrderLocale::render($order, fn (): string => view('invoices.delivery-note', array_merge([
            'doc' => $this->documents->present($order),
            'packingSlipUrl' => self::packingSlipUrl($order->id),
            'labelUrl' => self::shippingLabelUrl($order->id),
        ], $toolbar))->render());

        return response($html)->header('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * The dispatch label: the delivery address, the order number as a scannable
     * code, and deliberately nothing that says what is in the box.
     *
     * Allocates nothing. Putting a parcel on a van is not issuing a financial
     * document, and a label printed for an order that is later cancelled must
     * not have consumed an invoice number.
     */
    public function shippingLabel(int $id): View|Response
    {
        $order = $this->find($id);

        if ($order === null) {
            return $this->missing();
        }

        return view('invoices.shipping-label', [
            'doc' => $this->documents->present($order),
            'deliveryNoteUrl' => self::deliveryNoteUrl($order->id),
            'packingSlipUrl' => self::packingSlipUrl($order->id),
        ]);
    }

    /** Where the admin console links to. Built here so one definition exists. */
    public static function invoiceUrl(int $orderId): string
    {
        return Url::to('/admin-api/orders/' . $orderId . '/invoice');
    }

    public static function packingSlipUrl(int $orderId): string
    {
        return Url::to('/admin-api/orders/' . $orderId . '/packing-slip');
    }

    public static function deliveryNoteUrl(int $orderId): string
    {
        return Url::to('/admin-api/orders/' . $orderId . '/delivery-note');
    }

    public static function shippingLabelUrl(int $orderId): string
    {
        return Url::to('/admin-api/orders/' . $orderId . '/shipping-label');
    }

    /**
     * Trashed orders included.
     *
     * A soft-deleted order is still an order that was placed and may still have
     * been paid for; its invoice is a record, and records do not stop existing
     * because a row was tidied away in the admin.
     */
    private function find(int $id): ?Order
    {
        return Order::withTrashed()
            ->with(['items' => fn ($q) => $q->orderBy('id')])
            ->find($id);
    }

    /**
     * A plain 404 page rather than a JSON body.
     *
     * These routes sit in a JSON group but serve documents to a browser
     * window; handing that window `{"error":"not_found"}` as raw text is a
     * worse answer than a sentence.
     */
    private function missing(): Response
    {
        return response(
            '<!doctype html><meta charset="utf-8"><title>Order not found</title>'
            . '<p style="font:14px/1.6 system-ui,sans-serif;padding:24px">'
            . 'That order does not exist, so there is nothing to print.</p>',
            404,
        )->header('Content-Type', 'text/html; charset=utf-8');
    }
}

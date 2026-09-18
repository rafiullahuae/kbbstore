<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Invoices\BulkDocumentRefused;
use App\Services\Invoices\BulkDocumentSelection;
use App\Services\Invoices\InvoiceDocument;
use App\Services\Invoices\InvoiceNumbers;
use App\Support\Locale;
use App\Support\OrderLocale;
use App\Support\Url;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Twenty parcels, one Print.
 *
 * ── THE PROBLEM, IN THE OWNER'S WORDS ───────────────────────────────────────
 *
 * "Bulk printing or download is also fine." Today each of the four documents is
 * printed one order at a time from the order screen, so somebody packing twenty
 * parcels opens twenty orders, presses Print twenty times and answers the print
 * dialog twenty times.
 *
 * ── WHY ONE HTML PAGE AND NOT TWENTY PDFs ───────────────────────────────────
 *
 * There is no PDF library in this application and there cannot be one: vendor/
 * is in BuildPackage::NEVER_SHIP and UpdateGuard::FORBIDDEN_PREFIXES, so a
 * composer package could not reach the host through the updater, and the host
 * has no shell to install one by hand. Lane FZ confirmed the existing documents
 * take the only route available — print-ready HTML whose @page rules the
 * browser's Print → Save as PDF turns into a real PDF, measured at A4
 * 594.96x841.92pt and A6 298.08x420pt.
 *
 * A bulk document has to work the same way, so it is ONE HTML PAGE HOLDING
 * MANY SHEETS with a forced page break between them. One Print gives one PDF
 * with one sheet per order, which is also what "download" means on this host:
 * Save as PDF in the same dialog.
 *
 * THE DEFECT THAT MATTERS IS INVISIBLE IN HTML. Two orders that run into each
 * other across a page boundary look perfect on screen and produce a packing
 * slip with somebody else's address halfway down it. invoices/bulk.blade.php
 * carries the break rule; BulkDocumentsTest pins it, and
 * docs/GC-BULK-PRINTING.md records the page counts read back out of a real
 * Chromium print run.
 *
 * ── THE SHARP EDGE: INVOICE NUMBERS ─────────────────────────────────────────
 *
 * InvoiceNumbers::allocate() mints a number out of a sequence an accountant
 * reconciles. Two things must be true of a bulk run and neither is automatic:
 *
 *   IT MUST NOT ALLOCATE NUMBERS IT THEN THROWS AWAY. A number minted for a
 *   request that is refused — wrong document type, nothing selected, over the
 *   cap, or an id that is not an order — is a gap in the sequence with no
 *   invoice against it. So every refusal is decided BEFORE any order is
 *   loaded (BulkDocumentSelection::from, which never touches the database),
 *   and allocation happens only for orders that are certainly going to be
 *   drawn on this page. allocate() is called after the rows are in hand and
 *   after the "none of these exist" refusal, not before.
 *
 *   IT MUST NOT ALLOCATE THE SAME NUMBER TWICE. It cannot: the ids are
 *   de-duplicated before anything is loaded, allocate() is idempotent per
 *   order, and the UNIQUE index on orders.invoice_number arbitrates between
 *   processes. Lane FZ mutation-tested that service and found the early return
 *   in allocate() to be an equivalent mutant — the loop's own existing()
 *   recovery enforces idempotency either way. This lane depends on the
 *   guarantee, not on which half of allocate() provides it.
 *
 * AND IT IS STILL A WRITE ON A GET, exactly as the single invoice route is, for
 * the reason that route's header gives: first render is the only honest moment
 * to mint an invoice number, and the operation is safe to repeat.
 *
 * WHAT THIS DELIBERATELY DOES NOT DO is refuse to invoice a cancelled or unpaid
 * order. The single-order route does not, and a bulk action that quietly
 * applies a different policy from the button beside it is how two truths about
 * "when is an order invoiced" end up in one codebase. The admin console asks
 * for confirmation on an invoice run instead, naming how many of the selected
 * orders have no number yet, because that is the part that cannot be undone.
 * See docs/GC-BULK-PRINTING.md — whether an uninvoiceable status should be
 * skipped is the owner's call and not a developer's.
 *
 * ── LANGUAGE ────────────────────────────────────────────────────────────────
 *
 * The invoice, and only the invoice, prints in the language the ORDER was
 * placed in — because the customer already has that document in their inbox and
 * two sheets bearing one invoice number must not read differently. A batch can
 * hold Arabic and English orders at once, so each sheet is rendered inside its
 * own OrderLocale::render() and carries its own lang/dir attributes, while the
 * page around them stays in the operator's language. The other three are read
 * inside the building or by a courier and stay in the operator's language, as
 * they already do one at a time.
 */
class BulkDocumentController extends Controller
{
    public function __construct(
        private InvoiceDocument $documents,
        private InvoiceNumbers $numbers,
    ) {}

    /**
     * GET /admin-api/orders-bulk-documents?type=packing-slip&ids=1,2,3
     *
     * A GET with the ids in the query string, so the console can open it with
     * window.open() into a new tab and the operator lands on a printable page
     * carrying the ordinary admin session cookie. A POST would need a form
     * target and would lose the one property that makes this usable: the page
     * is a URL the operator can reload, bookmark for the shift, or re-print.
     * At the cap of 100 the query string is around 700 characters, well inside
     * every limit that matters.
     */
    public function show(Request $request): Response
    {
        try {
            $selection = BulkDocumentSelection::from($request->query('type'), $request->query('ids'));
        } catch (BulkDocumentRefused $refused) {
            return $this->refuse($refused);
        }

        /*
         * withTrashed(), like the single documents: a soft-deleted order is
         * still an order that was placed and may have been paid for, and its
         * paperwork is a record.
         *
         * ONE QUERY FOR THE ORDERS AND ONE FOR THE ITEMS. At the cap this is a
         * hundred orders; loading them one at a time inside the render loop
         * would be two hundred round trips on a shared host.
         */
        $rows = Order::withTrashed()
            ->with(['items' => fn ($q) => $q->orderBy('id')])
            ->whereIn('id', $selection->ids)
            ->get()
            ->keyBy('id');

        $orders = [];
        $missing = [];

        foreach ($selection->ids as $id) {
            $order = $rows->get($id);

            if ($order === null) {
                $missing[] = $id;

                continue;
            }

            $orders[] = $order;
        }

        if ($orders === []) {
            return $this->refuse(new BulkDocumentRefused(
                'None of those orders exist.',
                'They may have been deleted for good since this list was loaded. Reload the Orders '
                . 'screen and pick again.',
            ));
        }

        /*
         * ALLOCATION HAPPENS HERE AND NOWHERE EARLIER.
         *
         * Everything above this line can refuse, and everything above this line
         * has left the invoice sequence alone. From here on every order in
         * $orders is going onto the page, so a number minted for one of them is
         * a number that gets printed.
         */
        if ($selection->allocatesInvoiceNumbers()) {
            foreach ($orders as $order) {
                $this->numbers->allocate($order);

                // Re-read for the same reason the single invoice route does:
                // print the number and timestamp that are in the database, not
                // the ones this process hoped to write. Under contention those
                // are not always the same.
                $order->refresh();
            }
        }

        return response(
            view('invoices.bulk', [
                'selection' => $selection,
                'sheets' => $this->sheets($selection, $orders),
                'missing' => $missing,
                'subject' => $this->subject(count($orders)),
                'singleUrl' => fn (int $id): string => self::singleUrl($selection->type, $id),
            ])->render(),
        )->header('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * One rendered sheet per order, each with the language it must be read in.
     *
     * THE SHEET HTML IS BUILT HERE, AS A STRING, rather than left for the view
     * to loop over — because of the invoice, and the same trap InvoiceController
     * documents. A View returned from anywhere is rendered during response
     * preparation, long after OrderLocale::render()'s `finally` has put the
     * previous locale back, so an Arabic order's invoice would compile in
     * English and look as though it had worked. Producing the string inside the
     * closure is what makes the locale real.
     *
     * The three non-invoice documents go through the same path with no closure
     * at all, so they render in the operator's language exactly as they do one
     * at a time.
     *
     * @param  list<Order>  $orders
     * @return list<array{html: string, lang: string, dir: string, orderNumber: string}>
     */
    private function sheets(BulkDocumentSelection $selection, array $orders): array
    {
        $sheets = [];

        foreach ($orders as $order) {
            $draw = fn (): array => [
                'html' => view($selection->partial(), [
                    'doc' => $this->documents->present($order),
                ])->render(),
                'lang' => Locale::htmlLang(),
                'dir' => Locale::direction(),
                'orderNumber' => (string) $order->order_number,
            ];

            $sheets[] = $selection->allocatesInvoiceNumbers()
                ? OrderLocale::render($order, $draw)
                : $draw();
        }

        return $sheets;
    }

    /**
     * What the tab is called: "12 orders", so the saved PDF has a useful name.
     *
     * Deliberately the count of orders ACTUALLY DRAWN, not the count asked for.
     * A batch of twenty that found nineteen says nineteen, and the page itself
     * names the one it could not find.
     */
    private function subject(int $count): string
    {
        return __('invoice.bulk.subject', ['count' => $count]);
    }

    /** The single-order document this batch is a run of, for one order. */
    public static function singleUrl(string $type, int $orderId): string
    {
        $path = $type === 'dispatch-label' ? 'shipping-label' : $type;

        return Url::to('/admin-api/orders/' . $orderId . '/' . $path);
    }

    /** Where the admin console sends the operator. */
    public static function bulkUrl(string $type, array $ids): string
    {
        return Url::to('/admin-api/orders-bulk-documents')
            . '?type=' . rawurlencode($type)
            . '&ids=' . implode(',', array_map('intval', $ids));
    }

    /**
     * A refusal the operator can read, carrying a 400 and having printed and
     * allocated nothing.
     */
    private function refuse(BulkDocumentRefused $refused): Response
    {
        return response(
            view('invoices.bulk-refused', [
                'headline' => $refused->headline,
                'advice' => $refused->advice,
            ])->render(),
            400,
        )->header('Content-Type', 'text/html; charset=utf-8');
    }
}

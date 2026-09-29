<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Order;
use App\Models\Setting;
use App\Services\SettingsService;
use App\Support\Locale;
use Illuminate\Support\Facades\Cache;
use Tests\Support\CssDirection;
use Tests\Support\InvoiceAdminRoutes;

/**
 * THE PRINTED INVOICE MIRRORS WITH ITS OWN TEXT. (Lane CX, task 3 — predicted by
 * docs/GC-BULK-PRINTING.md and recorded at docs/rtl-audit.md Sec. 5.)
 *
 * ── WHAT THE DEFECT WOULD HAVE LOOKED LIKE, ON PAPER ────────────────────────
 *
 * `invoices/document.blade.php` takes its `dir` from `Locale::direction()` —
 * correctly, because Admin\InvoiceController renders the invoice inside
 * `OrderLocale::render()`, so an order placed in Arabic prints an invoice whose
 * furniture is Arabic. That has been true since Lane FK.
 *
 * Its stylesheet was fully physical: fourteen declarations naming `left` and
 * `right`. Those two facts are harmless separately and a mess together, and the
 * day the owner turns the mirrored layout on they meet. The text would run
 * right to left over rules laid out left to right:
 *
 *   - the money column right-aligned while the table read from the right, so
 *     every figure hugged the middle of the sheet;
 *   - the first line-item cell flush to the left-hand edge it no longer sat on,
 *     and an 8px gutter on the edge it did;
 *   - the totals block's 26px gap between label and figure on the wrong side,
 *     closing the gap and jamming "الإجمالي" against "AED 473.50";
 *   - the facts strip's dividers on the outside of the row instead of between
 *     its cells.
 *
 * Half mirrored, which is worse than either whole answer, on a document a
 * customer keeps.
 *
 * ── THE RENDERER, CHECKED RATHER THAN ASSUMED ───────────────────────────────
 *
 * There is no PDF library in this project: none in composer.json, none in
 * composer.lock, none in vendor/, and none can ever arrive — vendor/ is in
 * BuildPackage::NEVER_SHIP and UpdateGuard::FORBIDDEN_PREFIXES. The renderer is
 * the operator's own browser through its print dialog, which is the engine the
 * storefront's logical properties already target. Verified by printing rather
 * than by reading: the sheet was rendered under `@media print` and put through
 * Chromium's own `page.pdf()`, and the engine resolved each logical rule to the
 * physical edge it should.
 *
 *                             English (dir=ltr)      Arabic (dir=rtl)
 *   .fact border               right 1px, left 0      left 1px, right 0
 *   lines td:first-child       padding-left 0         padding-right 0
 *   lines td:last-child        padding-right 0        padding-left 0
 *   totals td.num              padding-left 26px      padding-right 26px
 *   .label letter-spacing      1.1px                  normal
 *   PDF produced               52,503 bytes, %PDF-    122,446 bytes, %PDF-
 *
 * The English column is exactly what the physical rules produced before this
 * change, which is rule 1 measured rather than asserted.
 *
 * ── AND THE ONE THING THAT MUST NOT MIRROR ──────────────────────────────────
 *
 * The Code 128 barcode on the packing slip and the dispatch label. Its bars are
 * `<i>` elements in a flex row, and a flex row follows the document's
 * direction — so a right-to-left sheet would lay the bars out backwards and the
 * symbol would no longer encode the order number. `.bc { direction: ltr }` pins
 * it, and its two `border-left-*` declarations stay physical because the bar
 * WIDTHS are written into each element's `style=""` as `border-left-width` and
 * an inline declaration beats any author rule.
 *
 * Measured, same run, on the packing slip forced to dir="rtl": `.bc` still
 * computes `direction: ltr`, 37 bars, first bar at x=1112.91 and last at
 * x=1265.16 — ascending, the same order as the ltr sheet (12.84 → 165.09). The
 * container moves to the other side of the page with the layout; the symbol
 * inside it does not turn round.
 *
 * ── MUTATION NOTE (run, not asserted) ───────────────────────────────────────
 *
 * Put any one of the fourteen physical declarations back — `text-align: right`
 * on `table.totals td.num`, say — and case 2 here is red on the rendered sheet,
 * and RtlReadinessTest is red on the template. Delete `direction: ltr` from
 * `.bc` and case 3 is red. Take the file out of `CssDirection::SCOPE` and case 4
 * is red, which is what stops the guard being switched off quietly.
 */
const CX_INVOICE_VIEW = 'resources/views/invoices/document.blade.php';

/** The two barcode declarations that are allowed to stay physical, and why. */
const CX_INVOICE_PHYSICAL_ALLOWED = [
    'border-left-style: solid',
    'border-left-color: #000',
];

function cxInvoiceOrder(string $locale, string $number): Order
{
    $order = Order::create([
        'order_number' => $number,
        'email' => 'buyer@example.test',
        'status' => 'processing',
        'currency' => 'AED',
        'locale' => $locale,
        'subtotal' => 45000,
        'shipping_total' => 2000,
        'discount_total' => 0,
        'tax_total' => 0,
        'total' => 47000,
        'payment_method' => 'stripe',
        'billing_address' => [
            'first_name' => 'Aisha', 'last_name' => 'Khan',
            'line1' => 'Apartment 1204, Marina Heights', 'city' => 'Dubai',
            'state' => 'Dubai', 'country' => 'AE', 'phone' => '+971 50 123 4567',
        ],
        'shipping_method' => 'Standard delivery',
    ]);

    $order->items()->create([
        'name' => 'Rice Probiotics Toner', 'sku' => '01000',
        'quantity' => 2, 'unit_price' => 22500, 'subtotal' => 45000, 'total' => 45000,
    ]);

    return $order->fresh('items');
}

function cxInvoiceAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'CX', 'email' => 'cx-invoice-'.uniqid().'@example.test',
        'password' => 'secret-secret', 'role' => 'owner',
    ]);
}

/** Arabic AND the mirrored layout, which is the state this test is about. */
function cxInvoiceMirroredOn(): void
{
    Setting::query()->updateOrCreate(['key' => Locale::SETTING_ENABLED], ['value' => '1', 'autoload' => true]);
    Setting::query()->updateOrCreate(['key' => Locale::SETTING_RTL], ['value' => '1', 'autoload' => true]);

    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
    Cache::flush();
}

/** Every physical direction declaration in one rendered document's <style>. */
function cxInvoicePhysicalInHtml(string $html): array
{
    $found = [];

    foreach (CssDirection::declarations(
        implode("\n", cxInvoiceStyleBlocks($html))
    ) as $d) {
        if (CssDirection::isPhysical($d['property'], $d['value'])) {
            $found[] = $d['property'].': '.$d['value'];
        }
    }

    return $found;
}

/** @return list<string> */
function cxInvoiceStyleBlocks(string $html): array
{
    preg_match_all('#<style[^>]*>(.*?)</style>#is', $html, $m);

    return $m[1];
}

it('gives an Arabic order a right-to-left invoice once the mirrored layout is on', function () {
    InvoiceAdminRoutes::wire(app());
    cxInvoiceMirroredOn();

    $order = cxInvoiceOrder('ar', 'KBB-CX-RTL');

    $html = test()->actingAs(cxInvoiceAdmin(), 'admin')
        ->get('/admin-api/orders/'.$order->id.'/invoice')
        ->assertOk()
        ->getContent();

    // The premise, so the next case cannot be green about a document that never
    // turns round in the first place.
    expect($html)->toContain('lang="ar"');
    expect($html)->toContain('dir="rtl"');
});

it('sends that sheet with a stylesheet that turns round with it', function () {
    InvoiceAdminRoutes::wire(app());
    cxInvoiceMirroredOn();

    $order = cxInvoiceOrder('ar', 'KBB-CX-RTL2');

    $html = test()->actingAs(cxInvoiceAdmin(), 'admin')
        ->get('/admin-api/orders/'.$order->id.'/invoice')
        ->assertOk()
        ->getContent();

    /*
     * ASSERTED ON THE RENDERED SHEET, not on the template. The customer's copy
     * is what is at stake, and the invoice inherits @yield('style') from
     * whichever document extends this layout — so a physical rule reintroduced
     * one file along would reach the paper without touching the layout at all.
     */
    $blocks = cxInvoiceStyleBlocks($html);

    expect($blocks)->not->toBe([], 'the sheet arrived with no <style> at all, so the sweep below would be vacuous');
    expect(strlen(implode('', $blocks)))->toBeGreaterThan(3000);

    $physical = cxInvoicePhysicalInHtml($html);

    sort($physical);
    $allowed = CX_INVOICE_PHYSICAL_ALLOWED;
    sort($allowed);

    expect($physical)->toBe(
        $allowed,
        "A physical direction declaration is in the printed invoice.\n"
        ."On an Arabic sheet it lays left-to-right rules under right-to-left text — half mirrored,\n"
        .'which is worse than either. The only two allowed are the Code 128 bars; see `.bc`.'
    );
});

it('pins the barcode to one direction, because a mirrored barcode is a wrong one', function () {
    $source = (string) file_get_contents(base_path(CX_INVOICE_VIEW));

    // `.bc` is a flex row; a flex row follows the document's direction, so
    // without this the bars come out backwards on a right-to-left sheet and the
    // symbol no longer encodes the order number.
    expect($source)->toMatch('/\.bc \{[^}]*direction: ltr;/s');

    // And the two declarations that ride on that decision are still marked, so
    // the next reader is told why they are physical rather than "fixing" them.
    expect($source)->toContain('RTL-PHYSICAL');
    expect($source)->toContain('border-left-style: solid');
});

it('keeps the file inside the guard that would have caught this', function () {
    /*
     * The guard is the durable half. docs/rtl-audit.md Sec. 5 listed this file
     * as out of scope for a year, which is precisely why nobody noticed that its
     * `dir` had started following the locale.
     */
    expect(CssDirection::SCOPE)->toContain(CX_INVOICE_VIEW);

    $floors = CssDirection::markdownTable(
        (string) file_get_contents(base_path('docs/rtl-audit.md')),
        'rtl-audit:floors'
    );

    $named = array_map(static fn (array $row): string => trim($row[0], '` '), $floors);

    expect($named)->toContain(CX_INVOICE_VIEW);

    // And the floor is a real number, so the row cannot be satisfied by zero.
    expect(CssDirection::logicalCount(base_path(), CX_INVOICE_VIEW))->toBeGreaterThanOrEqual(12);
});

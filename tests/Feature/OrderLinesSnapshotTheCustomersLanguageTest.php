<?php

declare(strict_types=1);

use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Translation;
use App\Services\Invoices\InvoiceDocument;
use App\Services\Mail\OrderEmailPresenter;
use App\Services\SettingsService;
use App\Services\Translation\TranslationStore;
use App\Support\Locale;
use Illuminate\Support\Facades\DB;

/**
 * What an order line is CALLED, on the two documents that two different people
 * read. (Lane F)
 *
 * ── WHAT WAS WRONG ON THE SHOP ──────────────────────────────────────────────
 *
 * Store\CheckoutController writes `'name' => $p?->name` onto every order line —
 * the English column, always, whatever language the shopper was in. Both
 * families of document then read that one column.
 *
 * But the two families are deliberately NOT in the same language.
 * Admin\InvoiceController renders the invoice inside OrderLocale::render(), and
 * Services\Mail\OrderMailer does the same for every order email, so an Arabic
 * order's paperwork has Arabic headings, Arabic labels and Arabic totals. The
 * packing slip, the delivery note and the bulk print are NOT wrapped —
 * resources/views/invoices/document.blade.php states that split in as many
 * words — so they render in the operator's English, which is right, because the
 * admin is deliberately not localised at all.
 *
 * So an Arabic shopper browsed /ar/shop, put a product in the bag under its
 * Arabic name, saw that Arabic name in the basket drawer, on the cart page and
 * in the checkout summary, pressed Pay — and got a confirmation email and an
 * invoice, Arabic in every other respect, calling it something else.
 *
 * ── THE FIX IS A SECOND SNAPSHOT, NOT A READ OF THE LIVE PRODUCT ────────────
 *
 * InvoiceDocument's header states the rule: "the lines are the snapshot, never
 * the live product". Translating at document time would break it — reprinting
 * today's catalogue onto a record of what happened — and would be undefined for
 * a line whose product has since been deleted. So `name_localised` is written
 * once, at creation, beside `name`, and every document reads a column.
 *
 * ── MUTATION NOTES, ALL FOUR CHECKED ────────────────────────────────────────
 *
 *  1. Delete the OrderItem::creating hook from OrderLocale::listen() and the
 *     first three tests go red: the column stays null and the invoice and the
 *     email print English on an Arabic order.
 *  2. Point sheet-invoice.blade.php back at $item['name'] and "the invoice
 *     reads in the customer's language" goes red while the packing-slip test
 *     stays green.
 *  3. Point the packing slip at nameForCustomer and "the operator's documents
 *     stay in the operator's language" goes red — which is the half that a fix
 *     aimed only at the customer would quietly break.
 *
 * ── ONE DOCUMENT CHANGED SIDES AFTER THIS FILE WAS WRITTEN ──────────────────
 *
 * The DELIVERY NOTE now reads `nameForCustomer` and renders inside
 * OrderLocale::render(), so the assertion below that pinned it to `name` is
 * inverted. The reason is the one this file's own split is made of, applied
 * more carefully: the question is not whether a document leaves the building —
 * the dispatch label leaves too — it is WHO READS IT. A packing slip is a
 * picking list read at the bench by somebody who works here; a delivery note
 * goes IN THE PARCEL and is opened and signed by the person who ordered.
 *
 * Nothing else in this file moved. `order_items.name` still keeps the
 * operator's language and its exact value, `name_localised` still carries the
 * customer's, and the packing slip still reads the first. See
 * Admin\InvoiceController::deliveryNote() and
 * tests/Feature/DeliveryNoteSpeaksToTheCustomerTest.php, which owns the
 * delivery note's assertions now.
 *  4. Drop the `enabledCodes() < 2` guard and the last test goes red: the hook
 *     starts issuing lookups on a shop that serves one language.
 */

/* ------------------------------------------------------------------ fixtures */

function olsArabicOn(): void
{
    Setting::query()->updateOrCreate(['key' => Locale::SETTING_ENABLED], ['value' => '1', 'autoload' => true]);

    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
    TranslationStore::flush();
}

/** A product with an English name and, optionally, an Arabic one. */
function olsProduct(string $english, ?string $arabic = null): Product
{
    static $n = 0;
    $n++;

    $product = Product::create([
        'name' => $english,
        'slug' => 'ols-' . $n . '-' . uniqid(),
        'price' => 19900,
        'status' => 'published',
    ]);

    if ($arabic !== null) {
        TranslationStore::put(
            'ar', 'products', $product->id, 'name', $arabic,
            Translation::STATUS_PUBLISHED, Translation::SOURCE_MANUAL,
        );
        TranslationStore::flush();
    }

    return $product;
}

/** An order in one language, with one line pointing at a real product. */
function olsOrder(string $locale, Product $product): Order
{
    static $n = 0;
    $n++;

    $order = Order::create([
        'order_number' => 'KBB-OLS-' . $n,
        'email' => 'ols-' . $n . '@example.com',
        'status' => 'processing',
        'currency' => 'AED',
        'locale' => $locale,
        'billing_address' => [
            'first_name' => 'Aisha', 'last_name' => 'Khan',
            'line1' => 'Apartment 1204', 'city' => 'Dubai',
            'state' => 'Dubai', 'country' => 'AE',
        ],
        'shipping_address' => [
            'first_name' => 'Aisha', 'last_name' => 'Khan',
            'line1' => 'Apartment 1204', 'city' => 'Dubai',
            'state' => 'Dubai', 'country' => 'AE',
        ],
        'subtotal' => 19900,
        'total' => 19900,
        'payment_method' => 'cod',
        'payment_method_title' => 'Cash on delivery',
    ]);

    // Exactly the shape Store\CheckoutController writes: the English column.
    $order->items()->create([
        'product_id' => $product->id,
        'name' => $product->name,
        'quantity' => 1,
        'unit_price' => 19900,
        'subtotal' => 19900,
        'total' => 19900,
    ]);

    return $order->fresh('items');
}

/* ------------------------------------------------------------------- the tests */

it('snapshots the line name in the language the order was placed in', function () {
    olsArabicOn();

    $product = olsProduct('Rice Daily Moisturizing Toner 150ml', 'تونر الأرز المرطب اليومي');
    $order = olsOrder('ar', $product);

    $line = $order->items->first();

    // Both snapshots, side by side, on the row.
    expect($line->name)->toBe('Rice Daily Moisturizing Toner 150ml');
    expect($line->name_localised)->toBe('تونر الأرز المرطب اليومي');
});

it('reads the customer language off the ORDER, not off the request', function () {
    olsArabicOn();

    $product = olsProduct('Centella Ampoule', 'أمبول سنتيلا');

    // The request is English throughout -- this is the operator keying a phone
    // order taken in Arabic into the English admin, which is the case
    // OrderLocale's own docblock says the explicit locale exists for.
    expect(Locale::current())->toBe(Locale::DEFAULT);

    $order = olsOrder('ar', $product);

    expect($order->items->first()->name_localised)->toBe('أمبول سنتيلا');
});

it('gives the customer their language and the warehouse English, on the same order', function () {
    olsArabicOn();

    $product = olsProduct('Rice Daily Moisturizing Toner 150ml', 'تونر الأرز المرطب اليومي');
    $order = olsOrder('ar', $product);

    $doc = app(InvoiceDocument::class)->present($order->fresh('items'));
    $line = $doc['items'][0];

    // The invoice is the customer's copy.
    expect($line['nameForCustomer'])->toBe('تونر الأرز المرطب اليومي');

    // The packing slip is the warehouse's.
    expect($line['name'])->toBe('Rice Daily Moisturizing Toner 150ml');

    // And the templates really do read those two keys, rather than all of them
    // reading whichever one happens to be right.
    $invoice = file_get_contents(base_path('resources/views/invoices/partials/sheet-invoice.blade.php'));
    $slip = file_get_contents(base_path('resources/views/invoices/partials/sheet-packing-slip.blade.php'));
    $note = file_get_contents(base_path('resources/views/invoices/partials/sheet-delivery-note.blade.php'));

    expect($invoice)->toContain("\$item['nameForCustomer']");

    /*
     * THE PICKING LIST IS THE ONE THAT KEEPS `name`, and it is the whole of the
     * operator's side now. This used to assert the same of the delivery note;
     * that sheet goes in the parcel and is signed by the customer, so it moved
     * — see this file's header and DeliveryNoteSpeaksToTheCustomerTest.
     */
    expect($slip)->toContain("\$item['name']")->not->toContain("nameForCustomer");
    expect($note)->toContain("\$item['nameForCustomer']");
});

it('sends the customer an email that calls the product what they called it', function () {
    olsArabicOn();

    $product = olsProduct('Centella Ampoule', 'أمبول سنتيلا');
    $order = olsOrder('ar', $product);

    $lines = app(OrderEmailPresenter::class)->present($order->fresh('items'))['items'];

    // Every mail this presenter feeds is the customer's, so there is one name
    // and it is theirs.
    expect($lines[0]['name'])->toBe('أمبول سنتيلا');
});

it('leaves an English order identical in every document', function () {
    olsArabicOn();

    $product = olsProduct('Rice Daily Moisturizing Toner 150ml', 'تونر الأرز المرطب اليومي');
    $order = olsOrder('en', $product);

    $line = $order->items->first();

    // Nothing written, because there is nothing to write: the customer's
    // language IS the column.
    expect($line->name_localised)->toBeNull();

    $doc = app(InvoiceDocument::class)->present($order->fresh('items'));
    expect($doc['items'][0]['nameForCustomer'])->toBe($doc['items'][0]['name']);
    expect($doc['items'][0]['name'])->toBe('Rice Daily Moisturizing Toner 150ml');
});

it('falls back to English for a product with no name in the customer language', function () {
    olsArabicOn();

    // Translated in no field at all -- the ordinary case while the owner is
    // part-way through the catalogue.
    $product = olsProduct('Snail 96 Mucin Power Essence');
    $order = olsOrder('ar', $product);

    $line = $order->items->first();

    /*
     * NULL rather than a second copy of the English string. t() already falls
     * back to the column, so storing its answer would duplicate `name` on every
     * untranslated line; null therefore means exactly one thing -- this line has
     * no name of its own in the customer's language -- and the reader's
     * fallback is the single place that decision lives.
     */
    expect($line->name_localised)->toBeNull();

    $doc = app(InvoiceDocument::class)->present($order->fresh('items'));
    expect($doc['items'][0]['nameForCustomer'])->toBe('Snail 96 Mucin Power Essence');
});

it('costs nothing at all while the shop serves one language', function () {
    // Arabic deliberately NOT switched on: this is how the shop ships.
    expect(Locale::enabledCodes())->toBe([Locale::DEFAULT]);

    $product = olsProduct('Rice Daily Moisturizing Toner 150ml', 'تونر الأرز المرطب اليومي');

    $order = Order::create([
        'order_number' => 'KBB-OLS-BUDGET',
        'email' => 'budget@example.com',
        'status' => 'processing',
        'currency' => 'AED',
        'locale' => 'ar',
        'subtotal' => 19900,
        'total' => 19900,
    ]);

    DB::enableQueryLog();
    DB::flushQueryLog();

    $order->items()->create([
        'product_id' => $product->id,
        'name' => $product->name,
        'quantity' => 1,
        'unit_price' => 19900,
        'subtotal' => 19900,
        'total' => 19900,
    ]);

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    /*
     * ONE statement: the insert. The hook returns on enabledCodes() before it
     * looks up the order, the product or the translation -- so a shop with one
     * language pays nothing for a column it will never fill, even on an order
     * row whose `locale` says 'ar'.
     */
    expect($queries)->toHaveCount(1);
    expect(strtolower($queries[0]['query']))->toStartWith('insert');

    expect($order->items()->first()->name_localised)->toBeNull();
});

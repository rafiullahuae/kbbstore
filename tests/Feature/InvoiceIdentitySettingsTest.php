<?php

declare(strict_types=1);

/**
 * The owner putting his own business on his own invoices — Lane DG.
 *
 * ── WHAT WAS WRONG, IN HIS TERMS ────────────────────────────────────────────
 *
 * App\Services\Invoices\InvoiceDocument::seller() and ::docType() read eight
 * settings between them. Every one of them had a READER AND NO WRITER: not one
 * appeared in AdminController::SETTING_RULES, so PUT /admin-api/settings
 * counted each as an unknown key and dropped it, and no field for any of them
 * was drawn anywhere in the admin console. So:
 *
 *   - his legal business name and his trading address were not on the invoices
 *     he sends, and there was no box anywhere to put them in;
 *   - `invoice_trn` could not be entered, and docType() prints "Tax Invoice"
 *     only when tax was really charged or contained AND a TRN is recorded — so
 *     the heading read "Invoice" for ever, whatever his accountant told him.
 *
 * ── WHY THESE TESTS READ THE INVOICE AND NOT THE SETTINGS TABLE ─────────────
 *
 * THE ROUND TRIP IS THE PROPERTY, NOT THE ROW. A test that saved through the
 * endpoint and then asserted `Setting::where('key', …)->value('value')` would
 * go green against a build where the value was stored and never printed —
 * which is half of the defect it is supposed to pin. It would also go green
 * against a `seller()` that had stopped reading the key at all.
 *
 * So every assertion below goes in through the real endpoint, as a real
 * signed-in admin, over the real payload the Invoice tab posts, and comes back
 * out of the HTML of the rendered document at
 * /admin-api/orders/{id}/invoice. Nothing in between is inspected.
 *
 * The one test that does NOT read an invoice is the last one, and it is the
 * standing guard: it discovers the keys by tokenising InvoiceDocument rather
 * than listing them here, so a NINTH identity setting added to that class with
 * no rule behind it fails this file instead of shipping as another silent
 * "Saved".
 *
 * ── TWO TRAPS THIS FILE STEPS AROUND ON PURPOSE ─────────────────────────────
 *
 * `token_get_all` and not a regex. CLAUDE.md records six lanes bitten by a
 * guard that read a comment as code, and InvoiceDocument's header NAMES all
 * eight of these keys in prose. A regex over that file would "find" every key
 * whether or not a single line of code still read one.
 *
 * And `str_contains(...)->toBeTrue('message')` rather than
 * `toContain($needle, $message)`. Pest's second argument to toContain is
 * ANOTHER NEEDLE, so a message passed there is silently asserted as a
 * substring of a 40KB document — a check that fails for a reason that has
 * nothing to do with what it meant to say.
 */

use App\Http\Controllers\Admin\AdminController;
use App\Models\AdminUser;
use App\Models\Order;
use App\Models\Setting;
use App\Services\Invoices\InvoiceDocument;
use App\Services\SettingsService;
use App\Support\TaxRule;
use Tests\Support\InvoiceAdminRoutes;

beforeEach(function () {
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
});

afterEach(function () {
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
});

/** A signed-in owner, which is the only thing that may read an invoice. */
function iidAdmin(): AdminUser
{
    $admin = AdminUser::create([
        'name' => 'Invoice Identity Owner',
        'email' => 'invoice-identity-' . uniqid() . '@example.test',
        'password' => 'password-long-enough',
        'role' => 'owner',
    ]);

    test()->actingAs($admin, 'admin');

    return $admin;
}

/**
 * One order, deliberately ordinary: nothing below turns on its figures, and a
 * previous lane has already audited those at length.
 */
function iidOrder(array $attributes = []): Order
{
    static $n = 0;
    $n++;

    $order = Order::create(array_merge([
        'order_number' => 'KBB-ID-' . $n,
        'email' => 'buyer-' . $n . '@example.com',
        'phone' => '+971 50 123 4567',
        'status' => 'processing',
        'currency' => 'AED',
        'billing_address' => [
            'first_name' => 'Aisha', 'last_name' => 'Khan',
            'line1' => 'Apartment 1204, Marina Heights',
            'city' => 'Dubai', 'state' => 'Dubai', 'country' => 'AE',
        ],
        'subtotal' => 20000,
        'shipping_total' => 2000,
        'tax_total' => 0,
        'total' => 22000,
        'payment_method' => 'cod',
        'payment_method_title' => 'Cash on delivery',
    ], $attributes));

    $order->items()->create([
        'name' => 'Rice Daily Moisturizing Toner 150ml',
        'sku' => 'HH-RT-150',
        'quantity' => 1,
        'unit_price' => 20000,
        'subtotal' => 20000,
        'total' => 20000,
    ]);

    return $order->fresh('items');
}

/** Save through the endpoint the Invoice tab actually posts to. */
function iidSave(array $settings): \Illuminate\Testing\TestResponse
{
    return test()->putJson('/admin-api/settings', ['settings' => $settings]);
}

/** Render the printable invoice and hand back its HTML. */
function iidInvoiceHtml(?Order $order = null): string
{
    InvoiceAdminRoutes::wire(app());

    $order ??= iidOrder();

    return test()->get('/admin-api/orders/' . $order->id . '/invoice')
        ->assertOk()
        ->getContent();
}

/** The eight boxes, filled in the way a real shop would fill them. */
function iidFilledIn(): array
{
    return [
        'invoice_business_name' => 'K Beauty Bliss Trading LLC',
        'invoice_address' => "Office 1902, Burlington Tower\nBusiness Bay, Dubai\nUnited Arab Emirates",
        'invoice_trn' => '100123456700003',
        'invoice_email' => 'accounts@kbeautybliss.com',
        'invoice_phone' => '+971 58 505 2611',
        'invoice_website' => 'kbeautybliss.com',
        'invoice_footer' => "Payment received in full — no further amount is due.\nReturns accepted within 14 days on unopened items.",
        'invoice_doctype' => '',
    ];
}

/* ═══ the defect itself ═══════════════════════════════════════════════════ */

/**
 * THE ONE THAT WOULD HAVE CAUGHT IT. Every value the owner types, saved the way
 * the screen saves it, read back off the document it is supposed to reach.
 */
it('puts every business detail the owner types onto his printed invoice', function () {
    iidAdmin();

    $typed = iidFilledIn();

    $response = iidSave($typed)->assertOk();

    // A key with no rule is DROPPED while this endpoint still answers ok — the
    // standing warning in SETTING_RULES, and exactly how all eight of these
    // came to be unwritable. `rejected` is where that shows.
    expect($response->json('rejected'))->toBeNull(
        'PUT /admin-api/settings rejected an invoice identity key as unknown, '
        . 'so Save reported success and wrote nothing.'
    );

    $html = iidInvoiceHtml();

    $expected = [
        'the legal business name' => 'K Beauty Bliss Trading LLC',
        'the first line of the address' => 'Office 1902, Burlington Tower',
        'the second line of the address' => 'Business Bay, Dubai',
        'the country' => 'United Arab Emirates',
        'the tax registration number' => 'TRN 100123456700003',
        'the email address' => 'accounts@kbeautybliss.com',
        'the phone number' => '+971 58 505 2611',
        'the website' => 'kbeautybliss.com',
        'the footer' => 'Returns accepted within 14 days on unopened items.',
    ];

    foreach ($expected as $what => $needle) {
        expect(str_contains($html, $needle))->toBeTrue(
            "the owner saved {$what} through PUT /admin-api/settings and it is not on his invoice: “{$needle}”"
        );
    }
});

/**
 * And the heading, which is the consequence that is not cosmetic.
 *
 * docType() needs BOTH halves: tax that was really charged or contained, and a
 * registration number under the seller's name. The order below carries a real
 * inclusive tax record, so the TRN is the only thing standing between "Invoice"
 * and "Tax Invoice" — and until this lane there was no way to supply it.
 */
it('lets a saved TRN turn the heading into a tax invoice, and nothing else does', function () {
    iidAdmin();

    $order = iidOrder([
        'tax_basis' => TaxRule::INCLUSIVE,
        'tax_rate' => '5',
        'tax_total' => 1048,
    ]);

    // Before: tax really is contained, and the document still declines the
    // stronger heading because no registration number has been stated.
    $before = iidInvoiceHtml($order);

    expect(str_contains($before, 'Tax Invoice'))->toBeFalse(
        'the document called itself a tax invoice with no registration number behind it'
    );

    iidSave(['invoice_trn' => '100123456700003'])->assertOk();

    $after = iidInvoiceHtml($order);

    expect(str_contains($after, 'Tax Invoice'))->toBeTrue(
        'a TRN saved through the real endpoint did not reach InvoiceDocument::docType(), '
        . 'so the heading is still "Invoice" — which is the whole reason that box exists.'
    );
});

/**
 * The owner's exact words, printed exactly. `invoice_doctype` overrides
 * docType()'s own rule in every state, because the person who asked an
 * accountant knows something this code does not — and a phrase that has a
 * legal meaning may not be case-folded, re-worded or held to a list this
 * project invented.
 */
it('prints the heading the owner typed, word for word, and does not tidy it', function () {
    iidAdmin();

    iidSave(['invoice_doctype' => 'Simplified Tax Invoice'])->assertOk();

    $html = iidInvoiceHtml();

    expect(str_contains($html, 'Simplified Tax Invoice'))->toBeTrue(
        'the heading the owner typed is not on the document'
    );

    // Read back through the reader rather than off the page, because the page's
    // own CSS upper-cases .doctype: the bytes are what must be untouched.
    expect(app(InvoiceDocument::class)->docType(iidOrder()))
        ->toBe('Simplified Tax Invoice', 'the heading was normalised on the way through');
});

/**
 * And the document is FILED under the name it is headed with.
 *
 * invoices/document.blade.php prints @yield('title') into <title>, which is what
 * a browser offers as the default filename in its Save-as-PDF dialog — and
 * invoices/invoice.blade.php passed the literal 'Invoice'. So an owner who had
 * answered the doctype question printed a sheet headed "Tax Invoice" and saved
 * it to his disk as "Invoice". The two names come from one string now.
 */
it('names the saved file what the document calls itself', function () {
    iidAdmin();

    iidSave(['invoice_doctype' => 'Simplified Tax Invoice'])->assertOk();

    $html = iidInvoiceHtml();

    expect(preg_match('~<title>\s*Simplified Tax Invoice~', $html))->toBe(
        1,
        'the browser will offer "Invoice" as the filename for a document headed otherwise'
    );
});

/**
 * And the heading is escaped EXACTLY ONCE where the title prints it.
 *
 * ASSERTED ON THE <title> ELEMENT AND NOT ON THE DOCUMENT, and that distinction
 * is the test. `@yield` compiles to a bare `echo $__env->yieldContent(...)` and
 * escapes nothing; what covers it is `@section('title', $value)`, which Laravel
 * puts through e() inside ManagesLayouts::startSection(). A check that searched
 * the whole page would be satisfied by the masthead's own {{ }} whatever the
 * title did — which is precisely how a check like this goes green over a hole.
 *
 * BOTH FAILURES ARE PINNED, because they are opposite mistakes and the obvious
 * repair for one causes the other: raw output, and a defensive e() on top of
 * Laravel's that prints `&amp;lt;script&amp;gt;` in the browser tab and in the
 * saved PDF's filename.
 */
it('escapes the owner heading once in the title, neither raw nor twice', function () {
    iidAdmin();

    iidSave(['invoice_doctype' => '<script>alert(1)</script>Invoice'])->assertOk();

    $html = iidInvoiceHtml();

    expect(preg_match('~<title>(.*?)</title>~s', $html, $m))->toBe(1, 'the invoice has no title element');

    $title = $m[1];

    expect(str_contains($title, '<script>'))->toBeFalse(
        "the heading is raw in <title>: {$title}"
    );

    expect(str_contains($title, '&amp;lt;'))->toBeFalse(
        "the heading is escaped twice in <title>, so the browser tab and the saved PDF's "
        . "filename will read &lt;script&gt; literally: {$title}"
    );

    expect(str_contains($title, '&lt;script&gt;alert(1)&lt;/script&gt;Invoice'))->toBeTrue(
        "the heading is not in <title> in any recognisable form: {$title}"
    );
});

/* ═══ blank is a value ════════════════════════════════════════════════════ */

/**
 * Every box ships empty and the invoice must be unchanged by this lane until
 * he fills one in. Asserted against the document, not against the settings
 * table, and asserted for the absences as well as the fallback.
 */
it('changes nothing on an invoice while the boxes are empty', function () {
    iidAdmin();

    $html = iidInvoiceHtml();

    // The store identity row, which is what seller() falls back to.
    expect(str_contains($html, 'K-Beauty Bliss'))->toBeTrue(
        'a fresh install no longer prints the store name on its invoice'
    );

    // An invoice showing an invented tax registration number is worse than one
    // showing none, and "Tax Invoice" is a claim with nothing behind it.
    expect(str_contains($html, 'TRN'))->toBeFalse(
        'a shop that has entered no registration number is printing one'
    );
    expect(str_contains($html, 'Tax Invoice'))->toBeFalse(
        'an untaxed order with no TRN is calling itself a tax invoice'
    );
});

/**
 * And clearing a box has to take the line back off. If a blank were refused or
 * skipped, every one of these would be a one-way door: typed once, printed for
 * ever, with the screen showing an empty field over it.
 */
it('takes a line off the invoice when the owner clears the box', function () {
    iidAdmin();

    iidSave(iidFilledIn())->assertOk();

    expect(str_contains(iidInvoiceHtml(), 'TRN 100123456700003'))->toBeTrue(
        'the fixture did not save — this check would be blind'
    );

    /*
     * The email is cleared alongside the website because the fixture's address
     * is `accounts@kbeautybliss.com` and the website is `kbeautybliss.com`: one
     * is a substring of the other, so a check that cleared only the website
     * would fail against a document that had correctly dropped it. Clearing
     * both makes the domain's absence unambiguous.
     */
    iidSave([
        'invoice_trn' => '',
        'invoice_footer' => '',
        'invoice_website' => '',
        'invoice_email' => '',
    ])->assertOk();

    $html = iidInvoiceHtml();

    expect(str_contains($html, 'TRN'))->toBeFalse('clearing the TRN box left it on the invoice');
    expect(str_contains($html, 'Returns accepted within 14 days'))->toBeFalse('clearing the footer box left it on the invoice');
    expect(str_contains($html, 'kbeautybliss.com'))->toBeFalse('clearing the website and email boxes left the domain on the invoice');

    // And the rest of what he typed is untouched — a blank for one key must not
    // be a blank for its neighbours.
    expect(str_contains($html, 'K Beauty Bliss Trading LLC'))->toBeTrue(
        'clearing four boxes also cleared the business name'
    );
    expect(str_contains($html, '+971 58 505 2611'))->toBeTrue(
        'clearing four boxes also cleared the phone number'
    );
});

/* ═══ what is refused, and what refusing costs ════════════════════════════ */

/**
 * The endpoint validates everything and writes NOTHING when one value is wrong.
 * Checked on the document, because the failure mode that matters is a half
 * applied payload: a 422 on screen naming one field while the others landed.
 */
it('writes none of the payload when one invoice value is malformed', function () {
    iidAdmin();

    iidSave(iidFilledIn())->assertOk();

    iidSave([
        'invoice_business_name' => 'Something Else Entirely LLC',
        'invoice_email' => 'not an address',
    ])->assertStatus(422);

    $html = iidInvoiceHtml();

    expect(str_contains($html, 'Something Else Entirely LLC'))->toBeFalse(
        'a refused payload was applied in part — the name landed and the address did not'
    );
    expect(str_contains($html, 'K Beauty Bliss Trading LLC'))->toBeTrue(
        'a refused payload overwrote the name that was already saved'
    );
});

/**
 * A TRN is the one box here that changes a heading, so what may go in it is
 * checked. The rule is deliberately not "fifteen digits" — this shop delivers
 * across the GCC and beyond, and a rule written for one country would refuse a
 * legitimate registration number from another.
 */
it('takes a registration number from any jurisdiction and refuses a sentence', function () {
    iidAdmin();

    foreach (['100123456700003', 'GB123456789', 'AE-100 123 456'] as $real) {
        iidSave(['invoice_trn' => $real])->assertOk();
        SettingsService::forgetMemo();

        expect(app(InvoiceDocument::class)->seller()['trn'])->toBe(
            $real,
            "a legitimate registration number was refused: {$real}"
        );
    }

    // Not an identifier: a line of prose, which unchecked would re-head every
    // invoice in the shop as a tax document.
    iidSave(['invoice_trn' => 'I will ask my accountant about this next week'])
        ->assertStatus(422);
});

it('refuses a website that is not one, and takes the form an owner actually types', function () {
    iidAdmin();

    // No scheme is the commonest correct answer and the one that belongs on a
    // letterhead. Demanding https:// would refuse it.
    foreach (['kbeautybliss.com', 'www.kbeautybliss.com', 'https://kbeautybliss.com/shop'] as $real) {
        iidSave(['invoice_website' => $real])->assertOk();
        SettingsService::forgetMemo();
        expect(app(InvoiceDocument::class)->seller()['website'])->toBe($real);
    }

    foreach (['javascript:alert(1)', 'come and visit us in Business Bay'] as $notAWebsite) {
        iidSave(['invoice_website' => $notAWebsite])->assertStatus(422);
    }
});

/* ═══ the standing guard ══════════════════════════════════════════════════ */

/**
 * EVERY invoice identity setting InvoiceDocument reads must be writable.
 *
 * Discovered from the class rather than listed here, so a ninth key added to
 * seller() or docType() tomorrow fails this test rather than shipping as one
 * more reader with no writer — which is the entire defect this file exists for,
 * and one this repo has now recorded for `delivery_texts`, `vat_country_rates`
 * and `store_timezone` as well.
 *
 * TOKENISED, NOT GREPPED. InvoiceDocument's own header names all eight keys in
 * prose. A regex would find them in the comment and pass against a class that
 * had stopped reading a single one.
 */
it('leaves no invoice identity setting that the admin cannot write', function () {
    iidAdmin();

    $tokens = token_get_all((string) file_get_contents(
        app_path('Services/Invoices/InvoiceDocument.php')
    ));

    // Comments and doc comments dropped before anything is read as code.
    $code = array_values(array_filter(
        $tokens,
        static fn ($t) => ! is_array($t) || ! in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true),
    ));

    $keys = [];

    foreach ($code as $i => $token) {
        // ->setting( 'some_key' )
        if (! is_array($token) || $token[0] !== T_STRING || $token[1] !== 'setting') {
            continue;
        }

        $literal = $code[$i + 2] ?? null;

        if (is_array($literal) && $literal[0] === T_CONSTANT_ENCAPSED_STRING) {
            $keys[] = trim($literal[1], "'\"");
        }
    }

    $invoiceKeys = array_values(array_unique(array_filter(
        $keys,
        static fn (string $k) => str_starts_with($k, 'invoice_'),
    )));

    expect(count($invoiceKeys))->toBeGreaterThanOrEqual(
        8,
        'InvoiceDocument no longer reads the invoice identity settings by name — this check is blind'
    );

    foreach ($invoiceKeys as $key) {
        expect(array_key_exists($key, AdminController::SETTING_RULES))->toBeTrue(
            "InvoiceDocument reads {$key} and AdminController::SETTING_RULES has no line for it, "
            . 'so PUT /admin-api/settings will drop it and answer ok anyway.'
        );

        // And proven through the endpoint, not only against the constant: blank
        // passes every rule here, so the only thing this can fail on is the key
        // being unknown.
        expect(iidSave([$key => ''])->assertOk()->json('rejected'))->toBeNull(
            "PUT /admin-api/settings rejected {$key} as an unknown key."
        );
    }
});

/**
 * And the screen. A rule with no box behind it is the same dead end pointed the
 * other way — `store_timezone` was in SETTING_RULES with no field anywhere in
 * the admin for months, and this repo has a test about that too.
 *
 * Asserted on ELEMENT MARKUP with preg_match_all rather than on a bare
 * substring. This console draws its screens from JavaScript, so the fields are
 * <input> tags inside string literals in the document — which is why
 * AdminScreenSectionsTest reads the same source the same way — and a plain
 * substring search of admin HTML is the check that has gone green against a
 * page's own inlined CSS more than once here. Requiring the id to sit on an
 * `<input` or `<textarea` tag is the difference. That the screen then paints is
 * proven in Chromium, in the commit, not here: Pest has no layout engine.
 */
it('draws a box in the admin console for every invoice identity setting', function () {
    $html = (string) view('admin.app')->render();

    foreach (array_keys(iidFilledIn()) as $key) {
        $id = 'set_' . $key;

        $found = preg_match_all(
            '/<(?:input|textarea)\b[^>]*\bid="' . preg_quote($id, '/') . '"/',
            $html
        );

        expect($found)->toBeGreaterThan(
            0,
            "nothing in the admin console draws a field for {$key}, so the owner has no way to enter it."
        );
    }

    // And the tab that holds them is reachable — one button in the strip, and
    // the pointer the Tax tab carries for the TRN.
    expect(preg_match_all('/<button\b[^>]*data-bdtab="invoice"[^>]*>/', $html))->toBeGreaterThanOrEqual(
        2,
        'the Invoice tab has no button in the tab strip, or the Tax tab no longer points at it.'
    );
});

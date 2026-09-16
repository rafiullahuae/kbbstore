<?php

declare(strict_types=1);

/**
 * A blank box that blocked the whole Save, and two settings nobody could
 * change — Lane DI.
 *
 * ── WHAT WAS WRONG, IN THE OWNER'S TERMS ────────────────────────────────────
 *
 * ONE. On a fresh shop he typed his store name into Store → Business Details,
 * pressed Save changes, and was told:
 *
 *     Nothing was saved. "Currency decimals" must be a whole number.
 *
 * about a box he had never touched. Nothing seeds `currency_decimals`, the box
 * carries the placeholder "blank — whole numbers", App\Support\Money documents
 * "no `currency_decimals` row" as a supported state, and the screen posts ''
 * for it every time. `int` refused '' — and updateSettings() validates the
 * whole tab and writes NONE of it when one value fails, so his store name, his
 * currency, his delivery charges and his whole invoice identity went down with
 * a box that was correct as it stood.
 *
 * TWO. `support_email` and `brand_whatsapp` are SEEDED with this shop's real
 * address and real number, are read in five places, and were written in none.
 * His WhatsApp number was printed on every page of his storefront from a
 * literal in three Blade templates.
 *
 * THREE. Five subject lines across four mailables spelled the shop's name out.
 * Rename the shop and every other part of the email followed; the subject — the
 * one part a customer reads before deciding whether to open it — did not.
 *
 * ── HOW THESE TESTS ARE WRITTEN ─────────────────────────────────────────────
 *
 * THE CONSEQUENCE, NOT THE STATUS CODE. The test that matters for the first
 * defect does not assert that the endpoint returned 200 to a blank decimals
 * box. It asserts that a Save CONTAINING a blank decimals box STORED THE OTHER
 * FIELDS — because a 200 over a payload that wrote nothing is precisely the
 * failure mode SETTING_RULES' own header warns about, and it would pass a test
 * that only read the status line.
 *
 * AND THE MONEY PROPERTY IS ASSERTED SEPARATELY AND IN BOTH DIRECTIONS.
 * `currency_decimals` decides how stored integers are READ BACK as well as how
 * they are printed, so "accept blank" has a wrong answer that also returns 200:
 * storing '0'. Money::minorExponent() would become 0, every fils amount in the
 * database would read back a hundred times too large, and AED 199 would print
 * as 19,900. There is a test below for each half — blank behaves as an absent
 * row, and '0' deliberately does not.
 *
 * `str_contains(...)->toBeTrue('message')` and never `toContain($needle, $msg)`.
 * Pest's second argument to toContain is ANOTHER NEEDLE, so a message passed
 * there is silently asserted as a substring of the subject.
 *
 * `token_get_all()` and never a regex, for the guard at the foot of this file.
 * CLAUDE.md records seven lanes bitten by a guard that read a comment as code,
 * and app/Mail/BrandedSubject.php's own header QUOTES all five of the old
 * subject lines in prose. A regex over app/Mail would "find" every one of them
 * in a tree where not a single line of code still contained one.
 */

use App\Http\Controllers\Admin\AdminController;
use App\Mail\OrderConfirmation;
use App\Mail\OrderInvoice;
use App\Mail\OrderRefunded;
use App\Mail\OrderStatusChanged;
use App\Models\AdminUser;
use App\Models\Order;
use App\Models\Refund;
use App\Models\Setting;
use App\Services\SettingsService;
use App\Support\Money;
use App\Support\SupportContact;

beforeEach(function () {
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
    Money::forgetConfig();
});

afterEach(function () {
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
    Money::forgetConfig();
});

/** A signed-in owner — the only role that may write settings. */
function sbiAdmin(): AdminUser
{
    $admin = AdminUser::create([
        'name' => 'Settings Blanks Owner',
        'email' => 'settings-blanks-' . uniqid() . '@example.test',
        'password' => 'password-long-enough',
        'role' => 'owner',
    ]);

    test()->actingAs($admin, 'admin');

    return $admin;
}

/** Save through the endpoint the Business Details tab actually posts to. */
function sbiSave(array $settings): \Illuminate\Testing\TestResponse
{
    return test()->putJson('/admin-api/settings', ['settings' => $settings]);
}

/** Read a setting back the way the storefront reads it. */
function sbiStored(string $key): ?string
{
    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();

    $row = Setting::query()->where('key', $key)->value('value');

    return $row === null ? null : (string) $row;
}

/**
 * The Currency half of the Business Details payload, as the screen builds it on
 * a fresh shop: every box empty except the ones a fresh shop has values for.
 *
 * Taken from the real handler in admin/app.blade.php rather than invented, and
 * it carries NONE of this lane's own keys — the defect reproduces on a payload
 * that is entirely somebody else's.
 */
function sbiFreshShopPayload(array $overrides = []): array
{
    return array_merge([
        'store_name' => 'Aisha Beauty Co',
        'currency' => 'AED',
        'store_timezone' => 'Asia/Dubai',
        'currency_symbol' => '',
        'currency_symbol_render' => 'unicode',
        'currency_position' => 'before',
        // The box the owner never touched. sval() posts '' for an empty
        // <input type=number>; this is the whole defect.
        'currency_decimals' => '',
        'free_ship' => '0',
        'delivery_flat' => '0',
        'cod_fee' => '1000',
    ], $overrides);
}

/*
|--------------------------------------------------------------------------
| 1. The blank box, and what it costs
|--------------------------------------------------------------------------
*/

it('saves the store name on a fresh shop whose decimals box is blank', function () {
    sbiAdmin();

    // The shop as it ships: no currency_decimals row at all.
    expect(sbiStored('currency_decimals'))->toBeNull();

    $response = sbiSave(sbiFreshShopPayload(['store_name' => 'Aisha Beauty Co']));

    /*
     * THE CONSEQUENCE, WHICH IS THE POINT OF THIS TEST. Not "the endpoint
     * answered 200" — a 200 over a payload that wrote nothing is the failure
     * this file exists to catch. The store name is read back out of the
     * settings table, which is what the browser tab, every email and every
     * invoice go on to print.
     */
    expect(sbiStored('store_name'))->toBe('Aisha Beauty Co');

    // And the rest of the tab landed with it, rather than one field being
    // rescued while the others were dropped.
    expect(sbiStored('cod_fee'))->toBe('1000')
        ->and(sbiStored('store_timezone'))->toBe('Asia/Dubai')
        ->and(sbiStored('currency'))->toBe('AED');

    $response->assertOk();

    expect($response->json('ok'))->toBeTrue()
        ->and($response->json('errors'))->toBeNull();
});

it('does not tell the owner his decimals box is wrong when it is blank', function () {
    sbiAdmin();

    $response = sbiSave(sbiFreshShopPayload());

    $body = (string) $response->getContent();

    expect(str_contains($body, 'Nothing was saved'))->toBeFalse(
        'A blank decimals box must not refuse the whole Business Details tab.'
    );
    expect(str_contains($body, 'must be a whole number'))->toBeFalse(
        'The blank the placeholder invites must not be reported as a bad number.'
    );
});

it('stores a blank decimals box as a value the reader treats as no row at all', function () {
    sbiAdmin();

    sbiSave(sbiFreshShopPayload())->assertOk();

    // The row exists and is empty. It records that the owner looked at the box
    // and chose the blank, which an absent row cannot say.
    expect(sbiStored('currency_decimals'))->toBe('');

    Money::forgetConfig();

    /*
     * AND MONEY READS IT EXACTLY AS IT READS AN ABSENT ROW. Money::config()
     * collapses null and '' to null before anything else, so:
     *
     *   displayDecimals() 0  — the storefront prints whole dirhams, which is
     *                          what the live WooCommerce site does and what
     *                          the placeholder "blank — whole numbers" says;
     *   minorExponent()   2  — the stored integers are still hundredths, which
     *                          is what every amount in the database means.
     */
    expect(Money::displayDecimals())->toBe(0)
        ->and(Money::minorExponent())->toBe(2);

    // The property the two together produce: AED 199, stored as 19900 fils,
    // still prints as 199.
    expect(Money::amount(19900))->toBe('199');
});

it('keeps blank and zero different, because zero would reprice the whole store', function () {
    sbiAdmin();

    /*
     * THE WRONG FIX, PINNED SO NOBODY REACHES FOR IT. "Allow empty" could have
     * been implemented by coercing a blank box to '0' — the endpoint would
     * answer 200 either way and the storefront would go on printing whole
     * numbers, so a test that only checked displayDecimals() would not tell
     * the two apart.
     *
     * They are not the same. A typed 0 says this currency HAS no minor unit,
     * which makes minorExponent() 0 — and then 19900, which is AED 199 in
     * fils, reads back as nineteen thousand nine hundred dirhams.
     */
    sbiSave(sbiFreshShopPayload(['currency_decimals' => '0']))->assertOk();

    expect(sbiStored('currency_decimals'))->toBe('0');

    Money::forgetConfig();

    expect(Money::minorExponent())->toBe(0)
        ->and(Money::amount(19900))->toBe('19,900');

    // Back to blank, and the store is repriced correctly again.
    sbiSave(sbiFreshShopPayload(['currency_decimals' => '']))->assertOk();

    Money::forgetConfig();

    expect(Money::minorExponent())->toBe(2)
        ->and(Money::amount(19900))->toBe('199');
});

it('still refuses a decimals value that is not a number, and one out of range', function () {
    sbiAdmin();

    sbiSave(sbiFreshShopPayload(['store_name' => 'Should Not Land', 'currency_decimals' => 'two']))
        ->assertStatus(422);

    expect(sbiStored('store_name'))->not->toBe('Should Not Land');

    sbiSave(sbiFreshShopPayload(['store_name' => 'Should Not Land', 'currency_decimals' => '9']))
        ->assertStatus(422);

    expect(sbiStored('store_name'))->not->toBe('Should Not Land');
});

/*
|--------------------------------------------------------------------------
| 2. The same shape, on the other screen the sweep found
|--------------------------------------------------------------------------
*/

it('saves the SEO tab when the return-window box has been cleared', function () {
    sbiAdmin();

    /*
     * The Google Merchant block on Store → Search appearance. Clearing the
     * return-window box posts '' and, under `int`, refused the WHOLE SEO tab:
     * the owner's title template and descriptions discarded because he emptied
     * a number box whose own help text says a zero there publishes no policy.
     */
    sbiSave([
        'seo_home_title' => 'Korean skincare, delivered across the UAE',
        'enable_merchant' => '1',
        'merchant_ship_cost' => '20',
        'merchant_return_days' => '',
    ])->assertOk();

    expect(sbiStored('seo_home_title'))->toBe('Korean skincare, delivered across the UAE')
        ->and(sbiStored('merchant_return_days'))->toBe('');

    /*
     * And the empty row means to App\Support\Seo exactly what an absent one
     * means: `(int) ($s['merchant_return_days'] ?? 0)` is 0 both ways, and 0
     * publishes no return policy. Asserted through the cast the reader uses
     * rather than by re-stating the rule.
     */
    expect((int) (string) sbiStored('merchant_return_days'))->toBe(0);
});

it('keeps int itself strict, so opting into blank stays a property of the key', function () {
    /*
     * REFLECTION, DELIBERATELY, AND THE ONLY TEST HERE THAT USES IT. Both keys
     * that used `int` now use `optint`, so there is no longer a payload that
     * reaches the `int` branch — and the thing worth pinning is exactly that
     * the branch was not loosened on its way out of use. A rule that accepted
     * blank everywhere would be a new way for any future settings screen to
     * store nothing and report success, which is the failure this repo has
     * recorded more than once.
     */
    $method = new ReflectionMethod(AdminController::class, 'checkSetting');
    $method->setAccessible(true);

    $controller = (new ReflectionClass(AdminController::class))->newInstanceWithoutConstructor();

    $strict = $method->invoke($controller, 'int', 'Some number', '', [0, 10]);
    $opt = $method->invoke($controller, 'optint', 'Some number', '', [0, 10]);

    expect($strict['error'])->not->toBeNull()
        ->and($opt['error'])->toBeNull()
        ->and($opt['value'])->toBe('');

    // And `optint` is not a way to sneak past the bounds either.
    expect($method->invoke($controller, 'optint', 'Some number', '11', [0, 10])['error'])
        ->not->toBeNull();
});

it('gives every key whose control can be blank a rule that accepts blank', function () {
    /*
     * THE STANDING SWEEP. A future lane adding a number box to a settings
     * screen and reaching for `int` reintroduces the whole defect: one empty
     * box, the entire tab refused. This walks SETTING_RULES and names the rules
     * that refuse '' so that the list of them stays a decision somebody made.
     */
    $refusesBlank = [];

    $method = new ReflectionMethod(AdminController::class, 'checkSetting');
    $method->setAccessible(true);
    $controller = (new ReflectionClass(AdminController::class))->newInstanceWithoutConstructor();

    foreach (AdminController::SETTING_RULES as $key => $rule) {
        $result = $method->invoke($controller, $rule[0], $rule[1], '', $rule[2] ?? null);

        if ($result['error'] !== null) {
            $refusesBlank[$key] = $rule[0];
        }
    }

    /*
     * The rules that legitimately refuse a blank, and why each one may:
     *
     *   enum / flag / tz / code  the screen posts a <select> or a fixed-width
     *                            code box, which cannot be empty.
     *   fils / aed / pct         the screen always computes a number — the
     *                            Business Details handler wraps each in
     *                            `parseFloat(...)||0`, so '' never leaves it.
     *   rows                     an array type; '' is a malformed payload.
     *   int                      no key uses it; see the test above.
     *
     * A key appearing here whose control is a free text or number box is the
     * defect this file opened with.
     */
    // array_values AFTER array_unique: array_unique preserves the original
    // keys, so the inner call must be the one that is de-duplicated or the
    // comparison is against a sparsely-keyed array.
    expect(array_values(array_unique($refusesBlank)))->toEqualCanonicalizing(
        ['enum', 'flag', 'tz', 'code', 'fils', 'aed', 'pct', 'rows']
    );

    // And the two this lane fixed are no longer among them.
    expect($refusesBlank)->not->toHaveKey('currency_decimals')
        ->and($refusesBlank)->not->toHaveKey('merchant_return_days');
});

/*
|--------------------------------------------------------------------------
| 3. The two settings with a reader and no writer
|--------------------------------------------------------------------------
*/

it('lets the owner change the support email, the phone and the WhatsApp number', function () {
    sbiAdmin();

    sbiSave([
        'support_email' => 'care@aishabeauty.ae',
        'support_phone' => '+971 4 000 1111',
        'brand_whatsapp' => '+971 50 999 8888',
    ])->assertOk();

    expect(sbiStored('support_email'))->toBe('care@aishabeauty.ae')
        ->and(sbiStored('support_phone'))->toBe('+971 4 000 1111')
        ->and(sbiStored('brand_whatsapp'))->toBe('+971 50 999 8888');

    // Read back through the accessor the storefront uses, not off the row.
    expect(SupportContact::phone())->toBe('+971 4 000 1111')
        ->and(SupportContact::whatsapp())->toBe('+971 50 999 8888')
        ->and(SupportContact::whatsappDigits())->toBe('971509998888')
        ->and(SupportContact::email())->toBe('care@aishabeauty.ae');
});

it('refuses a support email that is not an address, and takes a blank one', function () {
    sbiAdmin();

    sbiSave(['store_name' => 'Should Not Land', 'support_email' => 'not an address'])
        ->assertStatus(422);

    expect(sbiStored('store_name'))->not->toBe('Should Not Land');

    // Blank is a way of taking the line off the invoice, as it is everywhere
    // else on this screen.
    sbiSave(['support_email' => ''])->assertOk();

    expect(SupportContact::email())->toBe('');
});

it('shows the shipped number on a shop that has never opened the box', function () {
    /*
     * THE NO-CHANGE PROPERTY. Three templates each carried the shop's phone
     * number as a literal and they did not agree about which setting it came
     * from — `whatsapp` and `support_phone` are seeded by nothing and written
     * by nothing, so THEIR literals fired on every request of every shop. The
     * literals now live once, in SupportContact, and these are the exact
     * strings the header, the footer and the mobile menu printed before.
     */
    expect(SupportContact::phone())->toBe('+971 58 505 2611')
        ->and(SupportContact::whatsappDigits())->toBe('971585052611');

    // With the seeded row present, which is the state of a real install.
    app(SettingsService::class)->set('brand_whatsapp', '+971585052611');
    Setting::flushMap();
    SettingsService::forgetMemo();

    expect(SupportContact::whatsapp())->toBe('+971585052611')
        ->and(SupportContact::phone())->toBe('+971 58 505 2611')
        ->and(SupportContact::whatsappDigits())->toBe('971585052611');
});

it('treats a cleared box as going back to what the shop shipped with', function () {
    /*
     * SettingsService::get() returns its default only when the ROW IS ABSENT,
     * so a row holding '' — which is what clearing the admin box stores — would
     * print an empty line where a phone number used to be on every page of the
     * storefront. Every value in SupportContact is trimmed and an empty one
     * falls through.
     */
    sbiAdmin();

    sbiSave(['brand_whatsapp' => '', 'support_phone' => ''])->assertOk();

    expect(sbiStored('brand_whatsapp'))->toBe('')
        ->and(SupportContact::whatsapp())->toBe('+971585052611')
        ->and(SupportContact::phone())->toBe('+971 58 505 2611');
});

it('prints the owner’s number in the storefront chrome once he has set it', function () {
    sbiAdmin();

    sbiSave([
        'support_phone' => '+971 4 000 1111',
        'brand_whatsapp' => '+971 50 999 8888',
    ])->assertOk();

    $html = test()->get('/')->assertOk()->getContent();

    expect(str_contains($html, '+971 4 000 1111'))->toBeTrue(
        'The header chip and the footer contact line must print the phone number the owner saved.'
    );
    expect(str_contains($html, 'wa.me/971509998888'))->toBeTrue(
        'Every WhatsApp button must dial the number the owner saved.'
    );

    // And the number that was baked into three templates is gone from the page.
    expect(str_contains($html, 'wa.me/971585052611'))->toBeFalse(
        'No storefront template may still carry the shipped WhatsApp number as a literal.'
    );
});

it('puts the support email on an invoice that has no address of its own', function () {
    sbiAdmin();

    sbiSave(['support_email' => 'care@aishabeauty.ae', 'invoice_email' => ''])->assertOk();

    $doc = app(\App\Services\Invoices\InvoiceDocument::class)->present(sbiOrder());

    expect($doc['seller']['email'])->toBe('care@aishabeauty.ae');
});

/*
|--------------------------------------------------------------------------
| 4. The five subject lines
|--------------------------------------------------------------------------
*/

/** One ordinary order. Nothing below turns on its figures. */
function sbiOrder(): Order
{
    static $n = 0;
    $n++;

    $order = Order::create([
        'order_number' => 'KBB-SBI-' . $n,
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
    ]);

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

/** Every customer-facing subject this store sends, as [label => subject]. */
function sbiSubjects(Order $order): array
{
    $refund = new Refund(['amount' => 19900, 'status' => 'succeeded']);
    $refund->order_id = $order->id;

    return [
        'confirmation' => (new OrderConfirmation($order))->envelope()->subject,
        'invoice' => (new OrderInvoice($order))->envelope()->subject,
        'refunded' => (new OrderRefunded($order, $refund))->envelope()->subject,
        'shipped' => (new OrderStatusChanged($order, 'shipped'))->envelope()->subject,
        'cancelled' => (new OrderStatusChanged($order, 'cancelled'))->envelope()->subject,
    ];
}

it('renames every order-email subject when the shop is renamed', function () {
    app(SettingsService::class)->set('store_name', 'Aisha Beauty Co');
    Setting::flushMap();
    SettingsService::forgetMemo();

    $subjects = sbiSubjects(sbiOrder());

    // Five subjects, which is the count the brief names. A sixth mailable
    // growing a hardcoded name is caught by the guard at the foot of this file.
    expect($subjects)->toHaveCount(5);

    foreach ($subjects as $label => $subject) {
        expect(str_contains((string) $subject, 'Aisha Beauty Co'))->toBeTrue(
            "The {$label} subject must carry the shop's own name."
        );
        expect(str_contains((string) $subject, 'K Beauty Bliss'))->toBeFalse(
            "The {$label} subject must not still claim the name the shop used to have."
        );
    }
});

it('keeps the order number in every subject it was already in', function () {
    app(SettingsService::class)->set('store_name', 'Aisha Beauty Co');
    Setting::flushMap();
    SettingsService::forgetMemo();

    $order = sbiOrder();

    /*
     * OrderStatusChanged's WORDING subjects went from one `%s` to numbered
     * `%1$s` / `%2$s`. Getting that pair the wrong way round would produce a
     * subject reading "Your KBB-SBI-1 order Aisha Beauty Co is on its way" —
     * grammatical, plausible, and wrong — so both halves are checked in order
     * rather than merely both being present.
     */
    foreach (sbiSubjects($order) as $label => $subject) {
        $subject = (string) $subject;

        expect(str_contains($subject, $order->order_number))->toBeTrue(
            "The {$label} subject must still carry the order number."
        );

        expect(strpos($subject, 'Aisha Beauty Co'))->toBeLessThan(
            (int) strpos($subject, $order->order_number),
            "The {$label} subject names the shop before the order number."
        );
    }
});

it('falls back to the application name when the store name has been cleared', function () {
    app(SettingsService::class)->set('store_name', '');
    Setting::flushMap();
    SettingsService::forgetMemo();

    $expected = (string) config('app.name', 'K Beauty Bliss');

    foreach (sbiSubjects(sbiOrder()) as $label => $subject) {
        expect(str_contains((string) $subject, $expected))->toBeTrue(
            "The {$label} subject must not go out nameless when the name box is empty."
        );
    }
});

it('signs both halves of the emailed invoice the same way', function () {
    app(SettingsService::class)->set('store_name', 'Aisha Beauty Co');
    Setting::flushMap();
    SettingsService::forgetMemo();

    app(\App\Services\Mail\MailSettings::class)->save([
        'mail_signature' => 'Warmly, Sara|Founder, Aisha Beauty Co',
    ]);

    $mailable = new OrderInvoice(sbiOrder());

    $html = (string) $mailable->render();

    $content = $mailable->content();
    $text = (string) view($content->text, array_merge(
        $mailable->buildViewData(),
        $content->with,
    ))->render();

    /*
     * The text half signed `— K Beauty Bliss`, spelled out, while the HTML half
     * printed $brand['signature']. One message, signed two ways, and an owner
     * who set a signature changed only one of them.
     */
    foreach (['Warmly, Sara', 'Founder, Aisha Beauty Co'] as $line) {
        expect(str_contains($html, $line))->toBeTrue(
            "The HTML half of the emailed invoice must print the signature line “{$line}”."
        );
        expect(str_contains($text, $line))->toBeTrue(
            "The text half of the emailed invoice must print the same signature line “{$line}”."
        );
    }

    expect(str_contains($text, '— K Beauty Bliss'))->toBeFalse(
        'The text half must not carry a sign-off of its own.'
    );

    // No markup leaked into the text/plain part on the way.
    expect(str_contains($text, '<br'))->toBeFalse('The text part must contain no markup.');
});

it('leaves no mailable spelling the shop’s name out in code', function () {
    /*
     * THE STANDING GUARD, AND IT TOKENISES.
     *
     * app/Mail/BrandedSubject.php's header quotes all five of the old subject
     * lines in prose, deliberately, so that what changed is readable next to
     * the change. A regex over app/Mail would find every one of them and fail
     * against a tree that is entirely correct — which is the trap CLAUDE.md
     * records seven lanes walking into. So: token_get_all(), T_COMMENT and
     * T_DOC_COMMENT dropped, and only real string literals examined.
     */
    $offenders = [];

    /*
     * ONE LITERAL IS ALLOWED, AND IT IS NOT A SUBJECT LINE.
     * BrandedSubject::brandName() ends in `config('app.name', 'K Beauty Bliss')`
     * — the same last resort EmailBranding::forMailable() falls back to when
     * branding could not be read at all, quoted here so the two agree about it.
     * It is reached only when the store-name box is empty AND config/app.php
     * has no name, which is the state in which the alternative is an email
     * addressed from nobody. Counted rather than skipped, so a SECOND literal
     * appearing in that file still fails.
     */
    $allowed = 0;

    foreach (glob(app_path('Mail/*.php')) ?: [] as $file) {
        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (! is_array($token)) {
                continue;
            }

            [$id, $text] = $token;

            if ($id === T_COMMENT || $id === T_DOC_COMMENT || $id === T_INLINE_HTML) {
                continue;
            }

            if ($id !== T_CONSTANT_ENCAPSED_STRING && $id !== T_ENCAPSED_AND_WHITESPACE) {
                continue;
            }

            foreach (['K Beauty Bliss', 'K-Beauty Bliss', 'KBeautyBliss'] as $name) {
                if (! str_contains($text, $name)) {
                    continue;
                }

                if (basename($file) === 'BrandedSubject.php' && $allowed === 0) {
                    $allowed++;
                    continue;
                }

                $offenders[] = basename($file) . ': ' . trim($text);
            }
        }
    }

    // A second argument to toBe is the MESSAGE, not a needle — unlike
    // toContain, which is why the rest of this file avoids that matcher.
    expect($offenders)->toBe([], 'A mailable still spells the shop’s name out: ' . implode('; ', $offenders));

    // The allowance was used exactly once, so it cannot quietly cover a second
    // literal that appears in that file later.
    expect($allowed)->toBe(1);
});

it('leaves no storefront partial carrying the shop’s phone number', function () {
    /*
     * The same guard for the three templates. Blade, so there is no PHP token
     * stream over the whole file; the check is on the rendered page instead,
     * which is the surface that mattered anyway. A literal left in a template
     * would survive the owner changing the setting, so the test changes it and
     * looks for the old number.
     */
    sbiAdmin();

    sbiSave(['support_phone' => '+971 4 000 1111', 'brand_whatsapp' => '+971 50 999 8888'])
        ->assertOk();

    $html = test()->get('/')->assertOk()->getContent();

    foreach (['+971 58 505 2611', '+971585052611', '971585052611'] as $shipped) {
        expect(str_contains($html, $shipped))->toBeFalse(
            "The storefront still prints “{$shipped}” after the owner changed his number."
        );
    }
});

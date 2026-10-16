<?php

declare(strict_types=1);

use App\Mail\OrderInvoice;
use App\Mail\OrderRefunded;
use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Setting;
use App\Notifications\CustomerPasswordReset;
use App\Services\Mail\EmailBranding;
use App\Services\Mail\OrderEmailPresenter;
use App\Services\Marketing\Audience;
use App\Services\SettingsService;
use App\Services\Translation\ArabicInterfaceDrafts;
use App\Services\Translation\InterfaceStrings;
use App\Services\Translation\TranslationStore;
use App\Support\AdminCapabilities;
use App\Support\ArabicCustomers;
use App\Support\CustomerLocale;
use App\Support\Locale;
use App\Support\OrderLocale;
use App\Support\StoreTime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\CustomersAdminRoutes;

/**
 * Lane AE -- a customer who shops in Arabic is written to in Arabic, and the
 * owner can list them.
 *
 * ── WHAT IT LOOKED LIKE ON THE SHOP (16 October 2026, the preview, /ar) ────
 *
 *   * An order placed on /ar, refunded from the admin: an Arabic email under
 *     the subject "Refund sent for your K-Beauty Bliss UAE order 10513".
 *   * Its invoice email: "Invoice 01000 for your … order 10513", the eyebrow
 *     "INVOICE", and the dates "17 October 2026".
 *   * Every Arabic order email: "Oct 2026 17" in the order box, the address
 *     ending "United Arab Emirates", "Cash on delivery" as the payment, "Email
 *     us" under the address, and the sign-off "With love, / the K-Beauty Bliss
 *     UAE team".
 *   * The password reset requested on /ar: Arabic body, subject "Reset your K
 *     Beauty Bliss password".
 *   * Signing in, registering or signing out on /ar landed on the ENGLISH page.
 *   * Nothing recorded which customers shop in Arabic, so there was no list.
 *
 * MUTATION NOTES (each checked): put the literal subject back in OrderRefunded
 * and "refund subject" is red; drop the `ar` branch of StoreTime::formatDate
 * and "dates" is red; remove the locale_admin guard in remember() and "never
 * overwrites the owner" is red; change HEX_PATTERN to an unanchored 'D[89AB]'
 * and "Korean is not Arabic" is red; move the reason columns onto every list
 * and "flat" stays green but "unfiltered list unchanged" is red.
 */

function aeArabicOn(): void
{
    Setting::query()->updateOrCreate(['key' => Locale::SETTING_ENABLED], ['value' => '1', 'autoload' => true]);
    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
    TranslationStore::flush();
    CustomerLocale::forgetReady();
}

function aeCustomer(array $a = []): Customer
{
    static $n = 0;
    $n++;

    return Customer::create(array_merge(['name' => 'Ae Customer ' . $n, 'email' => 'ae-' . $n . '-' . uniqid() . '@example.test'], $a));
}

function aeOrder(?Customer $c, string $locale, array $a = []): Order
{
    static $n = 0;
    $n++;

    return Order::create(array_merge([
        'customer_id' => $c?->id,
        'order_number' => 'AE' . $n . substr(uniqid(), -5),
        'email' => $c?->email ?? 'g' . $n . '@example.test',
        'status' => 'processing',
        'total' => 68100,
        'subtotal' => 66100,
        'locale' => $locale,
        'payment_method' => 'cod',
        'payment_method_title' => 'Cash on delivery',
        'shipping_address' => ['first_name' => 'Mariam', 'line1' => 'Villa 14', 'city' => 'Dubai', 'state' => 'Dubai', 'country' => 'AE'],
    ], $a));
}

function aeAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create(['name' => 'Ae ' . $role, 'email' => 'ae-' . $role . '-' . uniqid() . '@example.test', 'password' => 'secret-secret', 'role' => $role]);
}

beforeEach(function () {
    CustomerLocale::forgetReady();
});

/* ------------------------------------------------------------ the migration */

it('ships its Arabic as literals equal to the strings classes, and names no App class', function () {
    $file = (string) file_get_contents(database_path('migrations/2027_11_08_100000_lane_ae_customer_language.php'));

    expect($file)->not->toMatch('/\\\\?App\\\\[A-Z]/');

    $m = require database_path('migrations/2027_11_08_100000_lane_ae_customer_language.php');
    $rows = (new ReflectionClassConstant($m, 'ROWS'))->getValue();
    $english = InterfaceStrings::flat();
    $arabic = ArabicInterfaceDrafts::all();

    foreach ($rows as $key => [$en, $ar]) {
        expect($english[$key] ?? null)->toBe($en, $key)
            ->and($arabic[$key] ?? null)->toBe($ar, $key);
    }

    // The ready group's rules are the TemplateLibrary preset's, word for word.
    $rules = json_decode((new ReflectionClassConstant($m, 'GROUP_RULES'))->getValue(), true);
    expect($rules)->toBe(\App\Services\Marketing\TemplateLibrary::groups()['arabic-customers']['rules']);

    // Published, and the flag that lets the code read the columns.
    expect(DB::table('translations')->where('locale', 'ar')->where('field', 'email.refund.subject')->value('status'))->toBe('published')
        ->and(DB::table('settings')->where('key', 'customer_locale_schema')->value('value'))->toBe('1')
        ->and(DB::table('mkt_segments')->where('key', 'arabic-customers')->exists())->toBeTrue();
});

/* ------------------------------------------------------ the customer's language */

it('records the language a customer registers, signs in and orders in -- and never overwrites the owner', function () {
    aeArabicOn();

    $c = aeCustomer(['password' => 'secret-secret-1']);
    CustomerLocale::remember($c, 'ar');
    expect($c->fresh()->locale)->toBe('ar');

    CustomerLocale::remember($c, 'en');
    expect($c->fresh()->locale)->toBe('en', 'the latest language wins');

    // An order placed on /ar, through the model hook every checkout uses.
    aeOrder($c, 'ar');
    expect($c->fresh()->locale)->toBe('ar');

    // The owner fixes English by hand: neither a sign-in nor an order moves it.
    DB::table('customers')->where('id', $c->id)->update(['locale' => 'en', 'locale_admin' => 1]);
    CustomerLocale::remember($c->id, 'ar');
    aeOrder($c, 'ar');
    expect($c->fresh()->locale)->toBe('en');

    // An imported WooCommerce order is history, not a choice made today.
    $d = aeCustomer();
    aeOrder($d, 'en', ['wc_order_id' => 991]);
    expect($d->fresh()->locale)->toBeNull();
});

it('costs no query at all while the shop serves one language', function () {
    $c = aeCustomer();
    CustomerLocale::forgetReady();
    CustomerLocale::ready();
    Locale::enabledCodes(); // the settings map, read once per request anyway

    DB::enableQueryLog();
    DB::flushQueryLog();
    CustomerLocale::remember($c, 'ar');
    expect(DB::getQueryLog())->toBe([])
        ->and($c->fresh()->locale)->toBeNull();
});

it('signs in, registers and signs out on /ar without dropping the shopper onto English', function () {
    aeArabicOn();
    aeCustomer(['email' => 'ae-login@example.test', 'password' => 'secret-secret-1']);

    $this->post('/ar/my-account/login', ['email' => 'ae-login@example.test', 'password' => 'secret-secret-1'])
        ->assertRedirect('/ar/my-account/');

    expect(Customer::where('email', 'ae-login@example.test')->value('locale'))->toBe('ar');

    $this->post('/ar/my-account/logout')->assertRedirect('/ar/');

    // English is exactly what it was.
    $this->post('/my-account/login', ['email' => 'ae-login@example.test', 'password' => 'secret-secret-1'])
        ->assertRedirect('/my-account/');
    expect(Customer::where('email', 'ae-login@example.test')->value('locale'))->toBe('en');
});

/* ------------------------------------------------------------- the emails */

it('gives an Arabic order an Arabic refund subject, invoice subject and invoice heading; English unchanged', function () {
    aeArabicOn();

    $ar = aeOrder(aeCustomer(), 'ar');
    $en = aeOrder(aeCustomer(), 'en');
    $refundAr = $ar->refunds()->create(['amount' => 1000, 'status' => 'succeeded', 'provider' => 'manual']);
    $refundEn = $en->refunds()->create(['amount' => 1000, 'status' => 'succeeded', 'provider' => 'manual']);

    $subject = fn (Order $o, $r) => OrderLocale::render($o, fn () => (string) (new OrderRefunded($o, $r))->envelope()->subject);

    expect($subject($ar, $refundAr))->toContain('تم إرسال المبلغ المسترد')->not->toContain('Refund sent')
        ->and($subject($en, $refundEn))->toStartWith('Refund sent for your ')->toEndWith(' order ' . $en->order_number);

    $invoice = fn (Order $o) => OrderLocale::render($o, fn () => [(string) (new OrderInvoice($o))->envelope()->subject, (new OrderInvoice($o))->doc['docType']]);

    [$s, $doc] = $invoice($ar);
    expect($s)->toContain('فاتورة')->not->toContain('Invoice')->and($doc)->toBe('فاتورة');

    [$s, $doc] = $invoice($en);
    expect($s)->toStartWith('Invoice ')->and($doc)->toBe('Invoice');
});

it('prints dates, the country, the payment and the sign-off in Arabic on an Arabic order email', function () {
    aeArabicOn();

    $ar = aeOrder(aeCustomer(), 'ar');
    DB::table('orders')->where('id', $ar->id)->update(['created_at' => '2026-10-16 20:43:00']);
    $ar->refresh();

    $p = OrderLocale::render($ar, fn () => app(OrderEmailPresenter::class)->present($ar));

    expect($p['placedShort'])->toBe('17 أكتوبر 2026')
        ->and($p['address'])->toContain('الإمارات العربية المتحدة')
        ->and($p['paymentLabel'])->not->toBe('Cash on delivery');

    [$sign, $support] = OrderLocale::render($ar, fn () => [app(EmailBranding::class)->signature(), app(EmailBranding::class)->support()]);
    expect($sign[0])->toBe('مع حبّنا،')
        ->and(implode(' ', $sign))->not->toMatch('/With love|team/');

    foreach ($support as $channel) {
        expect($channel['label'])->not->toBe('Email us');
    }

    // The owner's own Arabic box wins over the built-in one.
    app(SettingsService::class)->set('mail_signature_ar', 'مع الحب | فريقنا');
    SettingsService::forgetMemo();
    expect(OrderLocale::render($ar, fn () => app(EmailBranding::class)->signature()))->toBe(['مع الحب', 'فريقنا']);

    // English: byte for byte.
    $en = aeOrder(aeCustomer(), 'en');
    DB::table('orders')->where('id', $en->id)->update(['created_at' => '2026-10-16 20:43:00']);
    $en->refresh();
    $p = OrderLocale::render($en, fn () => app(OrderEmailPresenter::class)->present($en));
    expect($p['placedShort'])->toBe('17 Oct 2026')
        ->and($p['address'])->toContain('United Arab Emirates')
        ->and($p['paymentLabel'])->toBe('Cash on delivery')
        ->and(StoreTime::formatDate('2026-10-16 20:43:00'))->toBe('17 October 2026')
        ->and(app(EmailBranding::class)->signature())->toBe(['With love,', 'the ' . app(EmailBranding::class)->storeName() . ' team']);
});

it('sends the password reset in the customer\'s language, with an /ar link', function () {
    aeArabicOn();
    Notification::fake();

    $c = aeCustomer(['locale' => 'ar']);
    $c->sendPasswordResetNotification('tok-123');

    Notification::assertSentTo($c, CustomerPasswordReset::class, function ($n, $channels, $notifiable, $locale) use ($c) {
        expect($locale)->toBe('ar');
        $mail = CustomerLocale::render($locale, fn () => $n->toMail($c));
        expect($mail->subject)->toBe('إعادة تعيين كلمة المرور في كي بيوتي بليس')
            ->and($mail->viewData['url'])->toContain('/ar/my-account/reset/');

        return true;
    });

    // English customer: the subject it always had.
    $e = aeCustomer(['locale' => 'en']);
    expect($e->preferredLocale())->toBe('en')
        ->and((new CustomerPasswordReset('x'))->toMail($e)->subject)->toBe('Reset your K Beauty Bliss password');
});

it('writes the basket reminder and the back-in-stock alert in the shopper\'s language once the English is written', function () {
    aeArabicOn();
    $s = app(SettingsService::class);
    $s->set('module_abandoned_cart', true);

    $r = app(\App\Services\CartRecovery::class);

    // English blank: nothing, in any language -- an Arabic default never switches it on.
    expect(\App\Support\ArabicTwin::pick('cart_recovery_subject', \App\Services\CartRecovery::AR_SUBJECT, 'ar'))->toBe('');

    $s->set('cart_recovery_subject', 'Your basket is waiting');
    $s->set('cart_recovery_body', 'Come back.');
    SettingsService::forgetMemo();

    expect(\App\Support\ArabicTwin::pick('cart_recovery_subject', \App\Services\CartRecovery::AR_SUBJECT, 'ar'))->toBe(\App\Services\CartRecovery::AR_SUBJECT)
        ->and(\App\Support\ArabicTwin::pick('cart_recovery_subject', \App\Services\CartRecovery::AR_SUBJECT, 'en'))->toBe('Your basket is waiting');

    // The owner empties the Arabic box: the Arabic shopper gets his English.
    $s->set('cart_recovery_subject_ar', '');
    SettingsService::forgetMemo();
    expect(\App\Support\ArabicTwin::pick('cart_recovery_subject', \App\Services\CartRecovery::AR_SUBJECT, 'ar'))->toBe('Your basket is waiting');
});

/* ------------------------------------------------ Store -> Customers -> Arabic */

function aeArabicFixture(): array
{
    $chose = aeCustomer(['name' => 'Chose Arabic', 'locale' => 'ar']);
    $ordered = aeCustomer(['name' => 'Ordered Arabic']);
    aeOrder($ordered, 'ar');
    DB::table('customers')->where('id', $ordered->id)->update(['locale' => null]);
    $name = aeCustomer(['name' => 'نورة الكعبي']);
    $address = aeCustomer(['name' => 'Address Arabic']);
    $address->addresses()->create(['type' => 'shipping', 'is_default' => true, 'line1' => 'فيلا 14', 'city' => 'Dubai', 'country' => 'AE']);
    $orderAddress = aeCustomer(['name' => 'Order Address']);
    aeOrder($orderAddress, 'en', ['shipping_address' => ['first_name' => 'Huda', 'line1' => 'شارع 23', 'city' => 'دبي', 'country' => 'AE']]);
    DB::table('customers')->where('id', $orderAddress->id)->update(['locale' => null]);

    // Not Arabic: English, Korean (Hangul shares no lead byte with Arabic, but
    // an unanchored hex search finds "D8" inside it), an accented name.
    $english = aeCustomer(['name' => 'Plain English', 'locale' => 'en']);
    $korean = aeCustomer(['name' => '김지은 강']);
    $accent = aeCustomer(['name' => 'Zoë Ðorđević ]8']);

    return compact('chose', 'ordered', 'name', 'address', 'orderAddress', 'english', 'korean', 'accent');
}

it('lists Arabic customers with a reason on each row, and counts them on the chip', function () {
    aeArabicOn();
    $f = aeArabicFixture();

    CustomersAdminRoutes::wire(app());
    $this->actingAs(aeAdmin(), 'admin');

    $res = $this->getJson('/admin-api/customers/list?filter=arabic&per_page=100')->assertOk();
    $rows = collect($res->json('customers'))->keyBy('id');

    expect($rows->keys()->sort()->values()->all())->toBe(collect([$f['chose'], $f['ordered'], $f['name'], $f['address'], $f['orderAddress']])->pluck('id')->sort()->values()->all())
        ->and($res->json('counts.arabic'))->toBe(5)
        ->and($rows[$f['chose']->id]['arabic_reasons'])->toBe(['chose'])
        ->and($rows[$f['ordered']->id]['arabic_reasons'])->toBe(['ordered'])
        ->and($rows[$f['name']->id]['arabic_reasons'])->toBe(['name'])
        ->and($rows[$f['address']->id]['arabic_reasons'])->toBe(['address'])
        ->and($rows[$f['orderAddress']->id]['arabic_reasons'])->toBe(['address']);

    // The unfiltered list carries no reasons and runs no extra statement.
    $all = $this->getJson('/admin-api/customers/list')->assertOk();
    expect($all->json('customers.0.arabic_reasons'))->toBeNull()
        ->and($all->json('counts.arabic'))->toBe(5);
});

it('Korean is not Arabic: the hex test is anchored to character starts', function () {
    ArabicCustomers::registerSqlite();

    $match = fn (string $text) => (int) DB::selectOne('SELECT ' . ArabicCustomers::script('?') . ' AS m', [$text])->m;

    expect($match('نورة'))->toBe(1)
        ->and($match('Villa 14, شارع'))->toBe(1)
        ->and($match('김지은 강'))->toBe(0)
        ->and($match('中文 テスト'))->toBe(0)
        ->and($match('Zoë ]8 Ðorđević'))->toBe(0)
        ->and($match(''))->toBe(0);
});

it('costs the same number of queries at 3 Arabic customers as at 40', function () {
    aeArabicOn();
    CustomersAdminRoutes::wire(app());
    $this->actingAs(aeAdmin(), 'admin');

    $count = function (): int {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson('/admin-api/customers/list?filter=arabic&per_page=100')->assertOk();

        return count(DB::getQueryLog());
    };

    for ($i = 0; $i < 3; $i++) {
        aeOrder(aeCustomer(['name' => 'عميلة ' . $i]), 'ar');
    }
    $count(); // warm: the session, the guard and the settings map, read once
    $three = $count();

    for ($i = 0; $i < 37; $i++) {
        aeOrder(aeCustomer(['name' => 'عميلة ب' . $i]), 'ar');
    }
    // Warm again: creating orders queues the owner app's new-order push, which
    // the NEXT request flushes (one owner_app_push_subscriptions read). That
    // is the order's side effect, not the list's.
    $count();
    $forty = $count();

    expect($forty)->toBe($three, "3 Arabic customers ran {$three} queries, 40 ran {$forty}");
});

it('exports exactly the Arabic list, with the language and the reasons', function () {
    aeArabicOn();
    $f = aeArabicFixture();
    CustomersAdminRoutes::wire(app());
    $this->actingAs(aeAdmin(), 'admin');

    $csv = $this->get('/admin-api/customers/export?filter=arabic')->assertOk()->streamedContent();
    $lines = array_values(array_filter(explode("\n", trim(ltrim($csv, "\xEF\xBB\xBF")))));

    expect(count($lines))->toBe(6)
        ->and($lines[0])->toEndWith('currency,language,arabic_reasons')
        ->and($csv)->toContain('نورة الكعبي')->not->toContain('Plain English')->not->toContain('김지은');

    // The ordinary export is the file it always was.
    $plain = $this->get('/admin-api/customers/export')->assertOk()->streamedContent();
    expect(explode("\n", ltrim($plain, "\xEF\xBB\xBF"))[0])->toEndWith(',country,currency');
});

it('lets the owner fix a customer\'s language, under its own capability', function () {
    aeArabicOn();
    $c = aeCustomer(['locale' => 'en']);
    CustomersAdminRoutes::wire(app());
    $this->actingAs(aeAdmin(), 'admin');

    $this->postJson("/admin-api/customers/{$c->id}/language", ['locale' => 'ar'])->assertOk()->assertJson(['locale' => 'ar', 'locale_admin' => true]);
    expect($c->fresh()->only(['locale', 'locale_admin']))->toBe(['locale' => 'ar', 'locale_admin' => 1]);

    CustomerLocale::remember($c->id, 'en');
    expect($c->fresh()->locale)->toBe('ar', 'a sign-in must not undo the owner');

    $this->postJson("/admin-api/customers/{$c->id}/language", ['locale' => 'fr'])->assertStatus(422);
    $this->postJson("/admin-api/customers/{$c->id}/language", ['locale' => 'auto'])->assertOk();
    expect((int) $c->fresh()->locale_admin)->toBe(0);
    $this->postJson('/admin-api/customers/999999/language', ['locale' => 'ar'])->assertNotFound();

    $detail = $this->getJson("/admin-api/customers/{$c->id}")->assertOk();
    expect($detail->json('customer.locale'))->toBe('ar');

    // Its own capability; support may read customers but not change this; an
    // unknown role gets nothing.
    expect(AdminCapabilities::forPath('POST', 'admin-api/customers/5/language'))->toBe('customers.language')
        ->and(AdminCapabilities::roleCan('owner', 'customers.language'))->toBeTrue()
        ->and(AdminCapabilities::roleCan('manager', 'customers.language'))->toBeTrue()
        ->and(AdminCapabilities::roleCan('support', 'customers.language'))->toBeFalse()
        ->and(AdminCapabilities::roleCan('nobody', 'customers.language'))->toBeFalse();
});

/* --------------------------------------------- Marketing Emails ready group */

it('offers "Arabic customers" as a ready group that keeps every consent rule', function () {
    aeArabicOn();
    $f = aeArabicFixture();

    // One of them unsubscribed from marketing: matched, never emailable.
    DB::table('email_suppressions')->insert(['email' => strtolower($f['name']->email), 'reason' => 'unsubscribe', 'source' => 'campaign:1', 'created_at' => now()]);

    $rules = \App\Services\Marketing\TemplateLibrary::groups()['arabic-customers']['rules'];
    $n = app(Audience::class)->count('customers', $rules);

    $ids = collect(app(Audience::class)->recipientsAfter('customers', $rules, 'all', 0))->pluck('email')->all();

    expect($n['matched'])->toBe(5)
        ->and($n['reasons']['unsubscribed'])->toBe(1)
        ->and($ids)->not->toContain(strtolower($f['name']->email))->not->toContain($f['name']->email)
        ->and($ids)->not->toContain($f['english']->email);

    // And the opposite rule is everybody else.
    $not = app(Audience::class)->count('customers', [['field' => 'arabic', 'op' => 'no', 'value' => null]]);
    expect($not['matched'])->toBe(3);
});

/* ------------------------------------------------------- the account invite */

it('sends the built-in invite in Arabic to an Arabic customer, and the owner\'s own words as written', function () {
    $t = \App\Services\CustomerInvites\InviteTemplate::class;

    expect($t::forLocale($t::DEFAULT_SUBJECT, $t::DEFAULT_BODY, 'ar'))->toBe([$t::DEFAULT_SUBJECT_AR, $t::DEFAULT_BODY_AR])
        ->and($t::forLocale('My own words', $t::DEFAULT_BODY, 'ar'))->toBe(['My own words', $t::DEFAULT_BODY_AR])
        ->and($t::forLocale($t::DEFAULT_SUBJECT, $t::DEFAULT_BODY, null))->toBe([$t::DEFAULT_SUBJECT, $t::DEFAULT_BODY])
        ->and($t::problems($t::DEFAULT_SUBJECT_AR, $t::DEFAULT_BODY_AR))->toBe([]);
});

/* ------------------------------------------- the invoice and delivery note */

it('prints the emirate, the country and the status in Arabic on an Arabic invoice and delivery note', function () {
    aeArabicOn();

    // What it looked like: "Abu Dhabi / United Arab Emirates" on the receipt,
    // and the delivery note -- which goes IN THE PARCEL -- read "completed".
    $addr = fn (string $name) => ['first_name' => $name, 'line1' => 'Villa 7', 'city' => 'Abu Dhabi', 'state' => 'Abu Dhabi', 'country' => 'AE'];
    $ar = aeOrder(aeCustomer(), 'ar', ['status' => 'completed', 'shipping_address' => $addr('نورة')]);
    $doc = OrderLocale::render($ar, fn () => app(\App\Services\Invoices\InvoiceDocument::class)->present($ar));

    expect(implode(' | ', $doc['shipTo']))->toContain('أبو ظبي')->toContain('الإمارات العربية المتحدة')->not->toContain('Abu Dhabi')
        ->and($doc['paymentLabel'])->not->toBe('Cash on delivery');

    \Tests\Support\InvoiceAdminRoutes::wire(app());
    $html = $this->actingAs(aeAdmin(), 'admin')->get('/admin-api/orders/' . $ar->id . '/delivery-note')->assertOk()->getContent();
    expect($html)->toContain('أبو ظبي')->not->toMatch('/>\s*completed\s*</');

    // English: the stored word and the English names, as before.
    $en = aeOrder(aeCustomer(), 'en', ['status' => 'completed', 'shipping_address' => $addr('Ann')]);
    $doc = OrderLocale::render($en, fn () => app(\App\Services\Invoices\InvoiceDocument::class)->present($en));
    expect(implode(' | ', $doc['shipTo']))->toContain('Abu Dhabi')->toContain('United Arab Emirates')
        ->and($doc['paymentLabel'])->toBe('Cash on delivery')
        ->and(\App\Support\AddressRegions::localName('AE', 'Abu Dhabi'))->toBe('Abu Dhabi')
        ->and(OrderLocale::render($ar, fn () => \App\Support\AddressRegions::localName('AE', 'Ras al Khaimah')))->toBe('رأس الخيمة')
        ->and(OrderLocale::render($ar, fn () => \App\Support\AddressRegions::localName('AE', 'Al Barsha')))->toBe('Al Barsha');

    $html = $this->get('/admin-api/orders/' . $en->id . '/delivery-note')->assertOk()->getContent();
    expect($html)->toMatch('/>\s*completed\s*</')->toContain('Abu Dhabi');
});

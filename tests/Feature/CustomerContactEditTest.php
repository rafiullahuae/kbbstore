<?php

declare(strict_types=1);

use App\Mail\OrderConfirmation;
use App\Models\AdminUser;
use App\Models\Customer;
use App\Support\AdminCapabilities;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\CustomersAdminRoutes;

/**
 * Store -> Customers -> (a customer) -> Edit email / phone. (Lane CE)
 *
 * THE DEFECT ON THE SHOP: a guest typed jovana8b@yahoo.co instead of
 * yahoo.com. Her receipt went nowhere, she asked on WhatsApp, and the owner
 * had no way to correct the address or send the receipt again: the customer
 * record had no edit at all, and Resend confirmation on the order sends to
 * the address on the order, which was the wrong one.
 *
 * Every test names the mutation that turns it red.
 */

function ceAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create([
        'name' => 'CE ' . ucfirst($role),
        'email' => 'ce-' . $role . '-' . uniqid() . '@example.test',
        'password' => 'secret-secret',
        'role' => $role,
    ]);
}

function ceCustomer(array $attributes = []): Customer
{
    return Customer::create(array_merge([
        'name' => 'Jovana Belenzada',
        'first_name' => 'Jovana',
        'last_name' => 'Belenzada',
        'email' => 'jovana8b@yahoo.co',
        'phone' => '00971504697062',
    ], $attributes));
}

function ceOrder(?int $customerId, array $attributes = []): int
{
    static $n = 0;
    $n++;

    $address = ['first_name' => 'Jovana', 'last_name' => 'Belenzada', 'line1' => 'Villa 3', 'city' => 'Dubai', 'country' => 'AE', 'phone' => '00971504697062'];

    return (int) DB::table('orders')->insertGetId(array_merge([
        'order_number' => 'CE-' . $n . '-' . uniqid(),
        'customer_id' => $customerId,
        'email' => 'jovana8b@yahoo.co',
        'phone' => '00971504697062',
        'status' => 'processing',
        'currency' => 'AED',
        'billing_address' => json_encode($address),
        'shipping_address' => json_encode($address),
        'subtotal' => 15000,
        'total' => 17000,
        'shipping_total' => 2000,
        'payment_method' => 'cod',
        'payment_method_title' => 'Cash on delivery',
        'transaction_id' => 'tx-' . $n,
        'created_at' => now()->subDays(10 - min($n, 9)),
        'updated_at' => now()->subDays(10 - min($n, 9)),
    ], $attributes));
}

function ceSave(int $id, array $body)
{
    return test()->postJson('/admin-api/customers/' . $id . '/contact', $body);
}

beforeEach(function () {
    CustomersAdminRoutes::wire(app());
});

/* ================================================================ THE EDIT */

it('corrects a guest\'s email, and their orders follow with a note on each', function () {
    // MUTATION: drop followOrders() from update() -- the orders keep yahoo.co
    // and the receipt can never reach her. Drop the OrderNote::create in it and
    // the note count is red.
    test()->actingAs(ceAdmin(), 'admin');
    $c = ceCustomer();
    $o1 = ceOrder($c->id);
    $o2 = ceOrder($c->id, ['billing_address' => json_encode(['email' => 'jovana8b@yahoo.co', 'phone' => '00971504697062'])]);

    $r = ceSave($c->id, ['email' => 'jovana8b@yahoo.com', 'phone' => '00971504697062', 'orders_follow' => true])->assertOk();

    expect($r->json('orders_updated'))->toBe(2)
        ->and($c->fresh()->email)->toBe('jovana8b@yahoo.com')
        ->and(DB::table('orders')->whereIn('id', [$o1, $o2])->pluck('email')->unique()->all())->toBe(['jovana8b@yahoo.com'])
        ->and(json_decode((string) DB::table('orders')->where('id', $o2)->value('billing_address'), true)['email'])->toBe('jovana8b@yahoo.com');

    $note = (string) DB::table('order_notes')->where('order_id', $o1)->value('content');
    expect($note)->toContain('jovana8b@yahoo.co → jovana8b@yahoo.com')->toContain('CE Owner')
        ->and(DB::table('order_notes')->whereIn('order_id', [$o1, $o2])->count())->toBe(2);

    // The audit row keeps the old value so a mistake can be put back.
    $row = DB::table('customer_contact_changes')->where('customer_id', $c->id)->first();
    expect($row->old_email)->toBe('jovana8b@yahoo.co')->and($row->new_email)->toBe('jovana8b@yahoo.com')
        ->and($row->actor_name)->toBe('CE Owner')->and((int) $row->orders_updated)->toBe(2);
});

it('leaves the orders alone when "Also correct it on their orders" is unticked', function () {
    // MUTATION: default orders_follow to true regardless of the request -- red.
    test()->actingAs(ceAdmin(), 'admin');
    $c = ceCustomer();
    $o = ceOrder($c->id);

    ceSave($c->id, ['email' => 'jovana8b@yahoo.com', 'phone' => '00971504697062', 'orders_follow' => false])->assertOk()
        ->assertJson(['orders_updated' => 0]);

    expect($c->fresh()->email)->toBe('jovana8b@yahoo.com')
        ->and(DB::table('orders')->where('id', $o)->value('email'))->toBe('jovana8b@yahoo.co')
        ->and(DB::table('order_notes')->where('order_id', $o)->count())->toBe(0);
});

it('never touches another customer\'s orders, nor anything but the contact on its own', function () {
    // MUTATION: in CustomerContact::ordersOf() drop the whereNull('customer_id')
    // half of the email branch -- the stranger's order filed under another
    // customer with the same mistyped address is rewritten. Add `total` or
    // `status` to the update and the byte-compare is red.
    test()->actingAs(ceAdmin(), 'admin');
    $c = ceCustomer();
    $other = ceCustomer(['email' => 'someone@else.test', 'name' => 'Other']);
    $mine = ceOrder($c->id);
    $legacy = ceOrder(null);                                             // an old import, her address, no customer_id
    $strangers = ceOrder($other->id);                                    // same typo'd address, someone else's record
    $unrelated = ceOrder(null, ['email' => 'nobody@else.test']);
    $deliberate = ceOrder($c->id, ['email' => 'work@company.test']);     // she chose another address here

    $before = DB::table('orders')->whereIn('id', [$mine, $legacy])->get()->keyBy('id');

    ceSave($c->id, ['email' => 'jovana8b@yahoo.com', 'phone' => '00971504697062'])->assertOk()->assertJson(['orders_updated' => 2]);

    expect(DB::table('orders')->where('id', $strangers)->value('email'))->toBe('jovana8b@yahoo.co')
        ->and(DB::table('orders')->where('id', $unrelated)->value('email'))->toBe('nobody@else.test')
        ->and(DB::table('orders')->where('id', $deliberate)->value('email'))->toBe('work@company.test')
        ->and(DB::table('order_notes')->whereIn('order_id', [$strangers, $unrelated, $deliberate])->count())->toBe(0);

    foreach ([$mine, $legacy] as $id) {
        $after = (array) DB::table('orders')->where('id', $id)->first();
        $was = (array) $before[$id];
        unset($after['email'], $after['updated_at'], $was['email'], $was['updated_at']);
        expect($after)->toBe($was);
    }
});

it('moves the phone on the customer and on the orders that carried the old one', function () {
    // MUTATION: drop the phone branch in followOrders() -- orders keep the old
    // number while the customer card shows the new one.
    test()->actingAs(ceAdmin(), 'admin');
    $c = ceCustomer();
    $o = ceOrder($c->id);
    $other = json_encode(['first_name' => 'Jovana', 'phone' => '+971 55 000 0000']);
    $keep = ceOrder($c->id, ['phone' => '+971 55 000 0000', 'billing_address' => $other, 'shipping_address' => $other]);

    ceSave($c->id, ['email' => 'jovana8b@yahoo.co', 'phone' => '+971 50 469 7062'])->assertOk()->assertJson(['orders_updated' => 1]);

    expect($c->fresh()->phone)->toBe('+971 50 469 7062')
        ->and(DB::table('orders')->where('id', $o)->value('phone'))->toBe('+971 50 469 7062')
        ->and(json_decode((string) DB::table('orders')->where('id', $o)->value('shipping_address'), true)['phone'])->toBe('+971 50 469 7062')
        ->and(DB::table('orders')->where('id', $keep)->value('phone'))->toBe('+971 55 000 0000');
});

it('normalises exactly as the checkout and the order editor do', function () {
    // MUTATION: drop the strtolower() on the domain in normaliseEmail(), or the
    // whitespace collapse in normalisePhone() -- red.
    test()->actingAs(ceAdmin(), 'admin');
    $c = ceCustomer();

    ceSave($c->id, ['email' => "  Jovana8B@YAHOO.COM \t", 'phone' => '  +971   50 469  7062 '])->assertOk();

    expect($c->fresh()->email)->toBe('Jovana8B@yahoo.com')
        ->and($c->fresh()->phone)->toBe('+971 50 469 7062');

    // The checkout's own email rule, and its limits.
    foreach (['jovana8b@yahoo', 'two@@at.com', "crlf@x.com\r\nBcc: a@b.com", str_repeat('a', 150) . '@example.com'] as $bad) {
        ceSave($c->id, ['email' => $bad])->assertStatus(422);
    }
    ceSave($c->id, ['email' => 'jovana8b@yahoo.com', 'phone' => 'call me'])->assertStatus(422)->assertJsonValidationErrors('phone');
    ceSave($c->id, ['email' => 'jovana8b@yahoo.com', 'phone' => str_repeat('5', 41)])->assertStatus(422);
    // A crafted body is a refusal, not a 500 from casting an array to a string.
    ceSave($c->id, ['email' => ['a@b.com'], 'phone' => ['1']])->assertStatus(422);

    expect($c->fresh()->email)->toBe('Jovana8B@yahoo.com');
});

/* ========================================================== NEVER MERGE */

it('refuses an address another customer already holds, guest or account, in any case, and changes nothing', function () {
    // MUTATION: delete the $owner lookup in update() -- the save reaches the
    // UNIQUE index and 500s (or, on a case-insensitive collation, merges two
    // people under one address). Drop withTrashed() and the trashed holder is
    // missed.
    test()->actingAs(ceAdmin(), 'admin');
    $c = ceCustomer();
    $o = ceOrder($c->id);
    $account = ceCustomer(['email' => 'taken@yahoo.com', 'password' => 'secret-secret']);
    $guest = ceCustomer(['email' => 'guest@yahoo.com']);
    $trashed = ceCustomer(['email' => 'gone@yahoo.com']);
    $trashed->delete();

    ceSave($c->id, ['email' => 'TAKEN@yahoo.com'])->assertStatus(422)
        ->assertJson(['in_use' => true, 'other_id' => $account->id])
        ->assertJsonFragment(['message' => 'That address already belongs to another customer account (customer #' . $account->id . '). Nothing was changed: two customers are never merged from here.']);
    ceSave($c->id, ['email' => 'guest@yahoo.com'])->assertStatus(422)->assertJson(['other_id' => $guest->id]);
    expect(ceSave($c->id, ['email' => 'gone@yahoo.com'])->assertStatus(422)->json('message'))->toContain('in the trash');

    expect($c->fresh()->email)->toBe('jovana8b@yahoo.co')
        ->and(DB::table('orders')->where('id', $o)->value('email'))->toBe('jovana8b@yahoo.co')
        ->and(DB::table('customer_contact_changes')->count())->toBe(0);

    // A case-only change of her OWN address is not a collision.
    ceSave($c->id, ['email' => 'Jovana8b@yahoo.co'])->assertOk();
});

it('refuses a save made over somebody else\'s newer change', function () {
    // MUTATION: drop the was_email check -- the second admin silently wins.
    test()->actingAs(ceAdmin(), 'admin');
    $c = ceCustomer();
    ceSave($c->id, ['email' => 'jovana8b@yahoo.com', 'was_email' => 'old@x.com'])->assertStatus(409);
    expect($c->fresh()->email)->toBe('jovana8b@yahoo.co');
});

/* =========================================================== SIGN-IN */

it('moves a registered customer\'s sign-in: verification reset, old reset link and invite retired, sessions kept', function () {
    // MUTATION: drop 'email_verified_at' => null from update() -- the new,
    // unproven address reads as verified. Drop the token delete -- a reset link
    // mailed to the old address still sets the password. The remember_token
    // pin goes red if somebody adds invalidateSessions() here.
    test()->actingAs(ceAdmin(), 'admin');
    $c = ceCustomer(['email' => 'acct@yahoo.co', 'password' => 'secret-secret', 'remember_token' => 'keep-me']);
    $c->forceFill(['email_verified_at' => now(), 'invite_token_hash' => str_repeat('a', 64), 'invite_expires_at' => now()->addDay()])->save();
    DB::table('customer_password_reset_tokens')->insert([
        ['email' => 'acct@yahoo.co', 'token' => 'hash', 'created_at' => now()],
        ['email' => 'other@kbb.test', 'token' => 'hash', 'created_at' => now()],
    ]);
    $linkHash = $c->verificationHash();

    ceSave($c->id, ['email' => 'acct@yahoo.com', 'phone' => $c->phone])->assertOk()->assertJson(['sign_in_changed' => true]);

    $c->refresh();
    expect($c->email)->toBe('acct@yahoo.com')
        ->and($c->email_verified_at)->toBeNull()
        ->and($c->invite_token_hash)->toBeNull()
        ->and($c->remember_token)->toBe('keep-me')
        ->and(Illuminate\Support\Facades\Hash::check('secret-secret', (string) $c->password))->toBeTrue()
        ->and(DB::table('customer_password_reset_tokens')->pluck('email')->all())->toBe(['other@kbb.test'])
        // A verification link minted for the old address no longer matches.
        ->and($c->verificationHash())->not->toBe($linkHash);
});

/* =========================================================== THE RESEND */

it('re-sends exactly one confirmation, with the existing mailable, to the corrected address, and notes it', function () {
    // MUTATION: send a new Mailable instead of resendConfirmation() -- the
    // assertSent on OrderConfirmation is red. Drop the OrderNote::create --
    // the note pin is red.
    Mail::fake();
    test()->actingAs(ceAdmin(), 'admin');
    $c = ceCustomer();
    $o = ceOrder($c->id);

    ceSave($c->id, ['email' => 'jovana8b@yahoo.com', 'phone' => '00971504697062'])->assertOk();

    // There is no "send to" box: an address in the body is ignored.
    $r = test()->postJson('/admin-api/customers/' . $c->id . '/resend-confirmation', ['order_id' => $o, 'confirm' => true, 'email' => 'attacker@evil.test', 'to' => 'attacker@evil.test'])->assertOk();

    Mail::assertSent(OrderConfirmation::class, 1);
    Mail::assertSent(OrderConfirmation::class, fn ($m) => $m->hasTo('jovana8b@yahoo.com') && ! $m->hasTo('jovana8b@yahoo.co') && ! $m->hasTo('attacker@evil.test'));

    $note = (string) DB::table('order_notes')->where('order_id', $o)->orderByDesc('id')->value('content');
    expect($note)->toBe('Order confirmation re-sent to jovana8b@yahoo.com by CE Owner after correcting the email from jovana8b@yahoo.co.')
        ->and($r->json('message'))->toBe($note)
        ->and(DB::table('customer_contact_changes')->where('kind', 'resend')->count())->toBe(1);
});

it('refuses the resend without confirmation, for another customer\'s order, for an order still on the old address, and for an unplaced order', function () {
    // MUTATION: drop the 'confirm' => accepted rule, the $ours check, the
    // sameEmail(order, customer) check or the PLACED_STATUSES check -- each
    // turns one of these into a sent email.
    Mail::fake();
    test()->actingAs(ceAdmin(), 'admin');
    $c = ceCustomer(['email' => 'jovana8b@yahoo.com']);
    $mine = ceOrder($c->id, ['email' => 'jovana8b@yahoo.com']);
    $stillOld = ceOrder($c->id);
    $pending = ceOrder($c->id, ['email' => 'jovana8b@yahoo.com', 'status' => 'pending']);
    $other = ceCustomer(['email' => 'x@kbb.test']);
    $theirs = ceOrder($other->id, ['email' => 'x@kbb.test']);

    $url = '/admin-api/customers/' . $c->id . '/resend-confirmation';
    test()->postJson($url, ['order_id' => $mine])->assertStatus(422);
    test()->postJson($url, ['order_id' => $mine, 'confirm' => false])->assertStatus(422);
    test()->postJson($url, ['order_id' => $theirs, 'confirm' => true])->assertStatus(404);
    test()->postJson($url, ['order_id' => 999999, 'confirm' => true])->assertStatus(404);
    expect(test()->postJson($url, ['order_id' => $stillOld, 'confirm' => true])->assertStatus(422)->json('message'))->toContain('still carries');
    test()->postJson($url, ['order_id' => $pending, 'confirm' => true])->assertStatus(422);

    Mail::assertNothingSent();
    expect(DB::table('order_notes')->whereIn('order_id', [$stillOld, $pending, $theirs])->count())->toBe(0);
});

it('throttles the resend on a limiter of its own', function () {
    // MUTATION: drop ->middleware('throttle:6,1,customer-resend') from
    // routes/customers-admin.php -- the seventh send goes out. Remove
    // the 'customer-resend' prefix and the source pin is red.
    Mail::fake();
    test()->actingAs(ceAdmin(), 'admin');
    $c = ceCustomer(['email' => 'jovana8b@yahoo.com']);
    $o = ceOrder($c->id, ['email' => 'jovana8b@yahoo.com']);

    for ($i = 0; $i < 6; $i++) {
        test()->postJson('/admin-api/customers/' . $c->id . '/resend-confirmation', ['order_id' => $o, 'confirm' => true])->assertOk();
    }
    test()->postJson('/admin-api/customers/' . $c->id . '/resend-confirmation', ['order_id' => $o, 'confirm' => true])->assertStatus(429);

    Mail::assertSent(OrderConfirmation::class, 6);
    expect((string) file_get_contents(base_path('routes/customers-admin.php')))
        ->toContain("'throttle:6,1,customer-resend'")
        ->toContain("'throttle:30,1,customer-contact'");
});

/* ========================================================= CAPABILITY */

it('is customers.edit, owner and manager only, and fails closed for support and anonymous callers', function () {
    // MUTATION: delete the three Lane CE rows from AdminCapabilities::RULES --
    // the GET falls to customers.view (support reads the dialog) and the POSTs
    // to unmapped. Add 'support' to customers.edit -- the 403s are red.
    foreach (['GET admin-api/customers/7/contact', 'POST admin-api/customers/7/contact', 'POST admin-api/customers/7/resend-confirmation'] as $line) {
        [$m, $uri] = explode(' ', $line);
        expect(AdminCapabilities::forPath($m, $uri))->toBe('customers.edit');
    }
    expect(AdminCapabilities::CAPABILITIES['customers.edit'])->toBe(['owner', 'manager']);

    Mail::fake();
    $c = ceCustomer();
    $o = ceOrder($c->id);

    expect(test()->getJson('/admin-api/customers/' . $c->id . '/contact')->getStatusCode())->toBe(401);
    expect(ceSave($c->id, ['email' => 'jovana8b@yahoo.com'])->getStatusCode())->toBe(401);

    test()->actingAs(ceAdmin('support'), 'admin');
    test()->getJson('/admin-api/customers/' . $c->id . '/contact')->assertStatus(403);
    ceSave($c->id, ['email' => 'jovana8b@yahoo.com'])->assertStatus(403);
    test()->postJson('/admin-api/customers/' . $c->id . '/resend-confirmation', ['order_id' => $o, 'confirm' => true])->assertStatus(403);
    expect($c->fresh()->email)->toBe('jovana8b@yahoo.co');
    Mail::assertNothingSent();

    test()->actingAs(ceAdmin('manager'), 'admin');
    test()->getJson('/admin-api/customers/' . $c->id . '/contact')->assertOk()->assertJsonPath('customer.email', 'jovana8b@yahoo.co');
});

/* ======================================================= THE DIALOG'S GET */

it('answers the dialog with one flat-cost read, however many orders the customer has', function () {
    // MUTATION: count the orders with a query per order -- the 40-order count
    // is higher than the 3-order one.
    test()->actingAs(ceAdmin(), 'admin');
    $count = function (int $orders): int {
        $c = ceCustomer(['email' => 'flat' . $orders . '@yahoo.co']);
        for ($i = 0; $i < $orders; $i++) {
            ceOrder($c->id, ['email' => 'flat' . $orders . '@yahoo.co']);
        }
        test()->getJson('/admin-api/customers/' . $c->id . '/contact')->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $r = test()->getJson('/admin-api/customers/' . $c->id . '/contact')->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();
        expect(count($r->json('orders_with_email')))->toBe($orders);

        return $n;
    };

    expect($count(40))->toBe($count(3));
});

/* =============================================================== WIRING */

it('is mounted once, inside the customers file that sits in the admin-api group, and the dialog is included once', function () {
    // The finished state, which is green today AND after the integrator: zero is
    // "built, never wired"; two registers the routes and the dialog twice.
    $customers = (string) file_get_contents(base_path('routes/customers-admin.php'));
    $web = (string) file_get_contents(base_path('routes/web.php'));
    $app = (string) file_get_contents(resource_path('views/admin/app.blade.php'));

    expect(substr_count($customers, "Route::post('/customers/{id}/resend-confirmation'"))->toBe(1)
        ->and(substr_count($customers, "Route::post('/customers/{id}/contact'"))->toBe(1)
        ->and(substr_count($customers, "Route::get('/customers/{id}/contact'"))->toBe(1)
        ->and(substr_count($web, "require __DIR__.'/customers-admin.php';"))->toBe(1)
        ->and(substr_count($app, "@include('admin.partials.customer-contact-edit')"))->toBe(1)
        ->and(substr_count($app, 'id="cuEditContact"'))->toBe(1)
        ->and(substr_count($app, 'id="odCustContact"'))->toBe(1);
});

it('ships migrations that reference nothing this lane adds', function () {
    // MUTATION: call App\Support\CustomerContact (or the controller) from a
    // migration -- the updater runs it under the OLD classes and rolls back
    // (2.60.512).
    foreach (['2027_11_03_100000_create_customer_contact_changes.php', '2027_11_03_100100_clear_caches_customer_contact_edit.php'] as $file) {
        $src = implode('', array_map(
            fn ($t) => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t,
            token_get_all((string) file_get_contents(database_path('migrations/' . $file)))
        ));
        // File paths in the opcache list are strings; a class reference is not.
        expect($src)->not->toContain('CustomerContact::')->not->toContain('App\\')->not->toContain('::class')->not->toContain('new Customer');
    }
});

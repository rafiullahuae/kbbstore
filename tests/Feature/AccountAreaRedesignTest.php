<?php

declare(strict_types=1);

/*
 * THE REDESIGNED ACCOUNT AREA (Lane ACC). The owner, 25 October:
 *
 *   "redesign the Account dashboard properly like big ecommerce stores, also
 *    more easy to use on mobile ... orders list page, by showing capsules type
 *    rows, having products thumbnails inside with order number + order data +
 *    order value + order status ... top beautiful menu ... each page will have
 *    proper go back button and dashboard button ... search function or by
 *    date / month filter ... order detail page ... with proper road-map /
 *    steps ... change / update password option"
 *
 * and, on applying it: "DO it with EXTRA care as the site is live ... links
 * must go to the exact place / page".
 *
 * Each test says what the defect would look like on the shop.
 */

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Support\OrderProgress;
use App\Support\WordPressHasher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

function acrKey(): string
{
    return 'login_customer_' . sha1(\Illuminate\Auth\SessionGuard::class);
}

function acrAs(Customer $c)
{
    // The guard keeps the last customer for the whole test; forget it, so a
    // second customer in the same test is really someone else.
    app('auth')->forgetGuards();

    return test()->withSession([acrKey() => $c->id]);
}

function acrCustomer(string $email = 'acr@example.com', array $o = []): Customer
{
    return Customer::create(array_merge(['name' => 'Sabina Dev', 'first_name' => 'Sabina', 'last_name' => 'Dev', 'email' => $email, 'password' => 'password123'], $o));
}

function acrOrder(Customer $c, array $o = []): Order
{
    static $n = 0;
    $n++;

    return Order::create(array_merge([
        'order_number' => 'ACR' . str_pad((string) $n, 5, '0', STR_PAD_LEFT), 'customer_id' => $c->id, 'email' => $c->email,
        'status' => 'processing', 'currency' => 'AED', 'subtotal' => 20000, 'discount_total' => 0, 'shipping_total' => 0,
        'fee_total' => 0, 'gift_fee' => 0, 'tax_total' => 0, 'total' => 20000, 'payment_method' => 'cod', 'payment_method_title' => 'Cash on delivery',
        'shipping_address' => ['first_name' => 'Sabina', 'line1' => 'Villa 12', 'city' => 'Dubai', 'country' => 'AE'],
    ], $o));
}

function acrLine(Order $order, string $name = 'Rice Toner', ?Product $p = null): void
{
    $order->items()->create(['name' => $name, 'brand' => 'Beauty of Joseon', 'quantity' => 1, 'unit_price' => 10000, 'subtotal' => 10000, 'total' => 10000, 'product_id' => $p?->id]);
}

function acrProduct(string $name, array $o = []): Product
{
    return Product::create(array_merge(['slug' => Str::slug($name) . '-' . Str::random(5), 'name' => $name, 'status' => 'publish', 'is_visible' => true, 'price' => 5000, 'stock_status' => 'instock', 'image' => '/storage/media/' . Str::slug($name) . '.jpg'], $o));
}

function acrQueries(callable $fn): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $fn();
    // Not the page's: the owner app's new-order push, fired after the orders
    // this test just created, lands in whichever request runs next.
    $n = count(array_filter(DB::getQueryLog(), static fn ($q) => ! str_contains($q['query'], 'owner_app_push')));
    DB::disableQueryLog();

    return $n;
}

/* ------------------------------------------------------- the bar and menu */

it('puts Back, the dashboard button and the whole menu on every account page, each link to its exact page', function () {
    // What it looked like: no way back from an order but one text link, and no
    // way from the address book to the orders without the header panel.
    $c = acrCustomer();
    $order = acrOrder($c);
    $pages = [
        '/my-account/' => ['back' => '/', 'on' => '/my-account/'],
        '/my-account/orders/' => ['back' => '/my-account/', 'on' => '/my-account/orders/'],
        '/my-account/orders/' . $order->id => ['back' => '/my-account/orders/', 'on' => '/my-account/orders/'],
        '/my-account/profile/' => ['back' => '/my-account/', 'on' => '/my-account/profile/'],
        '/my-account/edit-address/' => ['back' => '/my-account/', 'on' => '/my-account/edit-address/'],
        '/my-wishlist/' => ['back' => '/my-account/', 'on' => '/my-wishlist/'],
        '/track-my-order/' => ['back' => '/my-account/', 'on' => '/track-my-order/'],
    ];

    foreach ($pages as $path => $want) {
        $html = acrAs($c)->get($path)->assertOk()->getContent();

        expect(substr_count($html, 'class="kac-top"'))->toBe(1, $path)
            ->and(substr_count($html, 'class="kac-menu"'))->toBe(1, $path)
            ->and($html)->toContain('class="kac-tb kac-back" href="' . $want['back'] . '"')
            ->and($html)->toContain('href="/my-account/" aria-label="Account dashboard"')
            ->and(preg_match('#<a class="kac-pill on" href="([^"]+)" aria-current="page">#', $html, $m))->toBe(1, $path)
            ->and($m[1])->toBe($want['on'], $path)
            // One main heading per page, even where the page had its own (Track).
            ->and(substr_count($html, '<h1'))->toBe(1, $path);

        foreach (['/my-account/', '/my-account/orders/', '/my-wishlist/', '/my-account/edit-address/', '/my-account/profile/', '/track-my-order/'] as $href) {
            expect($html)->toContain('href="' . $href . '"');
        }
        expect($html)->toContain('<form class="kac-out" method="post" action="/my-account/logout">');
    }
});

it('leaves a guest\'s wishlist and track page exactly as they were', function () {
    // The menu is for signed-in shoppers; a guest page must not grow one.
    expect(test()->get('/my-wishlist/')->assertOk()->getContent())->not->toContain('kac-')
        ->and(test()->get('/track-my-order/')->assertOk()->getContent())->not->toContain('kac-');
});

it('links every menu and dashboard row to a page that answers, and the profile anchor to its card', function () {
    $c = acrCustomer();
    $html = acrAs($c)->get('/my-account/')->assertOk()->getContent();
    preg_match_all('#<a class="kac-(?:pill|li|stat)[^"]*" href="([^"]+)"#', $html, $m);

    expect(array_unique($m[1]))->toContain('/my-account/profile/#password');
    foreach (array_unique($m[1]) as $href) {
        acrAs($c)->get(strtok($href, '#'))->assertOk();
    }
    expect(acrAs($c)->get('/my-account/profile/')->getContent())->toContain('id="password"');
});

/* ------------------------------------------------------------ dashboard */

it('counts orders, orders in progress and live wishlist items on the dashboard, in the same queries for 3 orders as for 30', function () {
    // Mutation note: count the wishlist cookie without the visible() scope and
    // the hidden product makes it 3 -- red.
    $c = acrCustomer();
    foreach (['processing', 'shipped', 'completed', 'cancelled'] as $s) {
        acrLine(acrOrder($c, ['status' => $s]));
    }
    $a = acrProduct('Seen One');
    $b = acrProduct('Seen Two');
    $gone = acrProduct('Hidden', ['status' => 'draft']);

    $html = acrAs($c)->withoutMiddleware(\Illuminate\Cookie\Middleware\EncryptCookies::class)->withUnencryptedCookie('kbb_wishlist', $a->id . ',' . $b->id . ',' . $gone->id)->get('/my-account/')->assertOk()->getContent();
    preg_match_all('#<a class="kac-stat"[^>]*><b>(\d+)</b>#', $html, $m);
    expect($m[1])->toBe(['4', '2', '2'])
        ->and($html)->toContain('Hello, Sabina')
        ->and($html)->toContain('Change password');

    $few = acrQueries(fn () => acrAs($c)->get('/my-account/')->assertOk());
    for ($i = 0; $i < 26; $i++) {
        acrLine(acrOrder($c));
    }
    expect(acrQueries(fn () => acrAs($c)->get('/my-account/')->assertOk()))->toBe($few);
});

/* ----------------------------------------------------------- orders list */

it('finds an order by its number or by a product on it, and never by a wildcard', function () {
    $c = acrCustomer();
    $toner = acrOrder($c, ['order_number' => 'ACR-TONER']);
    acrLine($toner, 'Glow Deep Serum');
    $mask = acrOrder($c, ['order_number' => 'ACR-MASK']);
    acrLine($mask, 'Tea Tree Mask');

    $byName = acrAs($c)->get('/my-account/orders/?q=tea+tree')->assertOk()->getContent();
    expect($byName)->toContain('ACR-MASK')->not->toContain('ACR-TONER');
    $byNo = acrAs($c)->get('/my-account/orders/?q=TONER')->assertOk()->getContent();
    expect($byNo)->toContain('ACR-TONER')->not->toContain('ACR-MASK');
    // A bare % is a character searched for, not "everything".
    expect(acrAs($c)->get('/my-account/orders/?q=%25')->assertOk()->getContent())->not->toContain('ACR-TONER')->toContain('No orders match your search.');
});

it('filters by this month, a picked month and a year, and ignores a made-up date', function () {
    $c = acrCustomer();
    $now = acrOrder($c, ['order_number' => 'ACR-NOW']);
    $old = acrOrder($c, ['order_number' => 'ACR-OLD']);
    $old->forceFill(['created_at' => now()->subYear()->startOfYear()->addDays(40)])->save();

    expect(acrAs($c)->get('/my-account/orders/?when=month')->getContent())->toContain('ACR-NOW')->not->toContain('ACR-OLD')
        ->and(acrAs($c)->get('/my-account/orders/?when=' . now()->subYear()->format('Y'))->getContent())->toContain('ACR-OLD')->not->toContain('ACR-NOW')
        ->and(acrAs($c)->get('/my-account/orders/?when=' . now()->format('Y-m'))->getContent())->toContain('ACR-NOW')->not->toContain('ACR-OLD')
        // Not one of the choices: the whole list, no error.
        ->and(acrAs($c)->get('/my-account/orders/?when=1999-01;drop')->assertOk()->getContent())->toContain('ACR-NOW')->toContain('ACR-OLD');
});

it('keeps the search on the next page and never shows another customer\'s order', function () {
    $c = acrCustomer();
    $other = acrCustomer('other@example.com');
    acrLine(acrOrder($other, ['order_number' => 'ACR-NOT-YOURS']), 'Rice Toner');
    for ($i = 1; $i <= 12; $i++) {
        acrLine(acrOrder($c, ['order_number' => 'ACR-T' . str_pad((string) $i, 2, '0', STR_PAD_LEFT)]), 'Rice Toner');
    }

    $one = acrAs($c)->get('/my-account/orders/?q=rice')->assertOk()->getContent();
    expect($one)->not->toContain('ACR-NOT-YOURS')->toContain('q=rice&amp;page=2');
    expect(acrAs($c)->get('/my-account/orders/?q=rice&page=2')->getContent())->toContain('ACR-T01')->not->toContain('ACR-NOT-YOURS');
});

it('draws each order as a row with its status strip, photos and total, in the same queries for 3 orders as for 10', function () {
    $c = acrCustomer();
    $p = acrProduct('Glow Serum');
    $o = acrOrder($c, ['status' => 'shipped', 'order_number' => 'ACR-ROW']);
    foreach (range(1, 7) as $i) {
        acrLine($o, 'Line ' . $i, $p);
    }

    $html = acrAs($c)->get('/my-account/orders/')->assertOk()->getContent();
    expect($html)->toContain('class="kac-band kac-t-ship"')->toContain('On its way to you')
        ->toContain('<span class="kac-more">+3</span>')
        ->toContain('loading="lazy"')
        ->toContain('href="/my-account/orders/' . $o->id . '"');
    // Drawn at a fixed 50px box (the img-cache copy where one exists, as every shop photo).
    expect($html)->toContain('width="50" height="50" alt="Line 1" loading="lazy" decoding="async"');

    $few = acrQueries(fn () => acrAs($c)->get('/my-account/orders/')->assertOk());
    for ($i = 0; $i < 9; $i++) {
        $x = acrOrder($c);
        acrLine($x, 'A', $p);
        acrLine($x, 'B', $p);
    }
    expect(acrQueries(fn () => acrAs($c)->get('/my-account/orders/')->assertOk()))->toBe($few);
});

/* ----------------------------------------------------------- one order */

it('marks the step an order is at, never one it has not reached', function () {
    // What it would look like: a processing order with a ring on "Shipped",
    // read by a shopper as "it has shipped".
    $states = fn (string $s) => array_column(OrderProgress::steps(new Order(['status' => $s, 'created_at' => now()])), 'state');

    expect($states('pending'))->toBe(['hold', 'wait', 'wait', 'wait'])
        ->and($states('processing'))->toBe(['done', 'now', 'wait', 'wait'])
        ->and($states('onhold'))->toBe(['hold', 'wait', 'wait', 'wait'])
        ->and($states('shipped'))->toBe(['done', 'done', 'now', 'wait'])
        ->and($states('completed'))->toBe(['done', 'done', 'done', 'done'])
        ->and(OrderProgress::steps(new Order(['status' => 'cancelled'])))->toBe([])
        ->and(OrderProgress::steps(new Order(['status' => 'refunded'])))->toBe([]);
});

it('shows the order\'s steps, a signed Track link and the unchanged receipt, and 404s another customer\'s order', function () {
    $c = acrCustomer();
    $o = acrOrder($c, ['status' => 'processing']);
    acrLine($o);

    $html = acrAs($c)->get('/my-account/orders/' . $o->id)->assertOk()->getContent();
    expect($html)->toContain('<ol class="kac-steps"')->toContain('<li class="now" aria-current="step">')
        ->toMatch('#href="[^"]*/track-my-order/\?order=' . $o->order_number . '&amp;t=[^"]+"#')
        ->toContain('class="kbbod-totals"');

    $cancelled = acrOrder($c, ['status' => 'cancelled']);
    expect(acrAs($c)->get('/my-account/orders/' . $cancelled->id)->getContent())->not->toContain('<ol class="kac-steps"')->not->toContain('Track this order');

    acrAs(acrCustomer('else@example.com'))->get('/my-account/orders/' . $o->id)->assertNotFound();
});

/* ---------------------------------------------------- profile & password */

it('keeps the profile page and its writes for signed-in customers only', function () {
    test()->get('/my-account/profile/')->assertRedirect();
    test()->post('/my-account/profile', ['first_name' => 'X'])->assertRedirect();
    test()->post('/my-account/profile/password', ['current_password' => 'x', 'password' => 'abcdefgh', 'password_confirmation' => 'abcdefgh'])->assertRedirect();
});

it('saves her name and mobile, refuses a bad number, and never changes the email from this form', function () {
    $c = acrCustomer();

    $r = acrAs($c)->post('/my-account/profile', ['first_name' => ' Mariam ', 'last_name' => 'S', 'phone' => '+971 50 123 4567', 'email' => 'hijack@example.com'])
        ->assertStatus(302)->assertSessionHas('kac_saved', 'details');
    // Back to the same page (assertRedirect() would trim the slash the shop's links keep).
    expect((string) $r->headers->get('Location'))->toEndWith('/my-account/profile/');
    $c->refresh();
    expect([$c->first_name, $c->last_name, $c->name, $c->phone, $c->email])->toBe(['Mariam', 'S', 'Mariam S', '+971 50 123 4567', 'acr@example.com']);

    acrAs($c)->post('/my-account/profile', ['first_name' => 'Mariam', 'phone' => '<script>'])->assertSessionHasErrors('phone');
    acrAs($c)->post('/my-account/profile', ['first_name' => ''])->assertSessionHasErrors('first_name');
    expect($c->fresh()->phone)->toBe('+971 50 123 4567');
});

it('changes the password only with the current one, keeps her signed in, and retires the old one', function () {
    $c = acrCustomer();

    acrAs($c)->post('/my-account/profile/password', ['current_password' => 'wrong-one', 'password' => 'newpass456', 'password_confirmation' => 'newpass456'])
        ->assertSessionHasErrors(['current_password' => 'That is not your current password.']);
    expect(Hash::check('password123', (string) $c->fresh()->password))->toBeTrue();

    acrAs($c)->post('/my-account/profile/password', ['current_password' => 'password123', 'password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
    acrAs($c)->post('/my-account/profile/password', ['current_password' => 'password123', 'password' => 'newpass456', 'password_confirmation' => 'other456x'])->assertSessionHasErrors('password');

    $r = acrAs($c)->post('/my-account/profile/password', ['current_password' => 'password123', 'password' => 'newpass456', 'password_confirmation' => 'newpass456']);
    $r->assertRedirect('/my-account/profile/#password')->assertSessionHas('kac_saved', 'password');
    expect(Hash::check('newpass456', (string) $c->fresh()->password))->toBeTrue()
        ->and(Hash::check('password123', (string) $c->fresh()->password))->toBeFalse()
        ->and(auth('customer')->id())->toBe($c->id);
});

it('accepts an imported customer\'s WordPress password as the current one and clears it', function () {
    // The oldest WordPress form the importer meets: a bare MD5 (WordPressHasher::check()).
    $legacy = md5('oldwp-pass');
    expect(app(WordPressHasher::class)->check('oldwp-pass', $legacy))->toBeTrue();
    $c = acrCustomer('wp@example.com', ['password' => null]);
    $c->forceFill(['password' => null, 'legacy_password' => $legacy])->save();

    acrAs($c)->post('/my-account/profile/password', ['current_password' => 'oldwp-pass', 'password' => 'brandnew99', 'password_confirmation' => 'brandnew99'])
        ->assertSessionHas('kac_saved', 'password');
    expect($c->fresh()->legacy_password)->toBeNull()->and(Hash::check('brandnew99', (string) $c->fresh()->password))->toBeTrue();
});

it('stops guessing the current password after five tries', function () {
    $c = acrCustomer();
    for ($i = 0; $i < 5; $i++) {
        acrAs($c)->post('/my-account/profile/password', ['current_password' => 'nope' . $i, 'password' => 'newpass456', 'password_confirmation' => 'newpass456']);
    }
    acrAs($c)->post('/my-account/profile/password', ['current_password' => 'password123', 'password' => 'newpass456', 'password_confirmation' => 'newpass456'])
        ->assertSessionHasErrors('current_password');
    expect(Hash::check('password123', (string) $c->fresh()->password))->toBeTrue();
});

it('offers an email link instead of a form to a customer who never had a password', function () {
    $c = acrCustomer('nopw@example.com');
    $c->forceFill(['password' => null, 'legacy_password' => null])->save();

    $html = acrAs($c)->get('/my-account/profile/')->assertOk()->getContent();
    expect($html)->toContain('href="/my-account/forgot/"')->not->toContain('name="current_password"');
});

/* -------------------------------------------------------------- Arabic */

it('ships every new word in Arabic, published by its migration', function () {
    $rows = \App\Services\Translation\AccountAreaStrings::ROWS;
    foreach ($rows as $key => [$en, $ar]) {
        expect(\App\Services\Translation\InterfaceStrings::flat()['store.' . $key] ?? null)->toBe($en, $key)
            ->and(preg_match('/\p{Arabic}/u', $ar) === 1 || str_starts_with($ar, '+'))->toBeTrue($key);
    }

    DB::table('translations')->where('field', 'like', 'store.account.%')->where('locale', 'ar')->delete();
    (require database_path('migrations/2027_12_18_100000_account_area_arabic.php'))->up();
    expect(DB::table('translations')->where('locale', 'ar')->where('field', 'store.account.nav_orders')->value('value'))->toBe('طلباتي')
        ->and(DB::table('translations')->where('locale', 'ar')->where('field', 'store.account.nav_orders')->value('status'))->toBe('published');
});

it('writes no physical left or right into the account styles, and flips the pointing chevrons in Arabic', function () {
    $css = (string) file_get_contents(resource_path('views/partials/account/styles.blade.php'));
    preg_match('#<style>(.*)</style>#s', $css, $m);

    expect($m[1])->not->toMatch('/(?<![-\w])(margin|padding|border)-(left|right)\s*:/')
        ->not->toMatch('/[;{](left|right)\s*:/')
        ->toContain('[dir="rtl"] .kac-back svg,[dir="rtl"] .kac-chev{transform:scaleX(-1)}')
        // Inputs at 16px: a phone does not zoom the page when she taps one.
        ->toContain('.kac-search input{flex:1 1 auto;min-width:0;border:0;outline:0;background:transparent;font:inherit;font-size:16px');
});

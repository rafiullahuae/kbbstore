<?php

declare(strict_types=1);

/**
 * Lane Z — adversarial sweep of the surface added since tests/Feature/
 * ApiSecurityTest.php was written.
 *
 * That file pinned the first sweep: an IDOR on quiz leads, an unmoderated-review
 * oracle, vote stuffing, and a public endpoint that handed back other people's
 * carts. Everything the app grew afterwards — payment capture and refund, the
 * Customers and Orders admin screens, customer auth with password reset and
 * email verification, module toggles and three CSV exports — had never had a
 * security review of its own. This is that review's residue.
 *
 * Two of these cases are bugs this lane found and fixed. The rest are pins:
 * properties that already held and that a future edit must not quietly take
 * away. Both kinds are worth the same amount here — the reason ApiSecurityTest
 * exists at all is that this repo has proved it can lose work it had already
 * done (packages 2.60.102–.106 reverted three files on the live server).
 */

use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;

/* ------------------------------------------------------------------ actors */

function zAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'Z Owner',
        'email' => 'z-owner-' . uniqid() . '@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

function zCustomer(array $overrides = []): Customer
{
    return Customer::create(array_merge([
        'name' => 'Z Shopper',
        'email' => 'z-shopper-' . uniqid() . '@example.test',
        'password' => Hash::make('password123'),
    ], $overrides));
}

function zWebUser(): User
{
    return User::create([
        'name' => 'Z Web User',
        'email' => 'z-web-' . uniqid() . '@example.test',
        'password' => 'secret-secret',
    ]);
}

/* ------------------------------------------------------- the guarded surface */

/*
 * Read off Route::getRoutes(), never off a harness's intent.
 *
 * RouteRegistrar::middleware() REPLACES the pending middleware rather than
 * appending to it, so a test helper that chains it twice registers routes
 * carrying no `auth:admin` at all while reading as though it had asked for it —
 * and every 401 assertion written against such a helper passes against no guard
 * whatsoever. These routes are the ones web.php really registered, so there is
 * no harness to be fooled by.
 */
it('puts every admin-api route behind the admin guard, with no exceptions', function () {
    $unguarded = [];

    foreach (Route::getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'admin-api/')) {
            continue;
        }

        if (! in_array('auth:admin', $route->gatherMiddleware(), true)) {
            $unguarded[] = implode('|', $route->methods()) . ' ' . $route->uri();
        }
    }

    expect($unguarded)->toBe([]);

    // And the group is not empty — an assertion that passes because nothing
    // matched would be worse than no assertion.
    $count = collect(Route::getRoutes())
        ->filter(fn ($r) => str_starts_with($r->uri(), 'admin-api/'))
        ->count();

    expect($count)->toBeGreaterThan(100);
});

/*
 * The same question asked from the other end: not "is everything under this
 * prefix guarded" but "is every admin CONTROLLER reached only through a guard".
 * A future route mounted at some path other than /admin-api would slip past the
 * test above and be caught here.
 */
it('never routes an admin controller through anything but the admin guard', function () {
    $open = [];

    foreach (Route::getRoutes() as $route) {
        $action = (string) ($route->getActionName() ?? '');

        if (! str_starts_with($action, 'App\Http\Controllers\Admin\\')) {
            continue;
        }

        // The login screen and the logout post are the two that must stay
        // reachable without being signed in — a guarded login form cannot be
        // signed into.
        if (str_starts_with($action, 'App\Http\Controllers\Admin\AdminAuthController@')) {
            continue;
        }

        if (! in_array('auth:admin', $route->gatherMiddleware(), true)) {
            $open[] = implode('|', $route->methods()) . ' ' . $route->uri() . ' -> ' . $action;
        }
    }

    expect($open)->toBe([]);
});

/*
 * PaymentSettlementTest already proves capture and refund refuse an anonymous
 * caller. It does not ask what happens for somebody who IS signed in, just not
 * as an admin — and "signed in" is three different things in this app, because
 * config/auth.php defines three guards. A shopper holding a storefront session
 * must be exactly as unwelcome on a money endpoint as a stranger.
 */
it('refuses capture and refund to a signed-in shopper and to a non-admin site user', function () {
    $shopper = zCustomer();

    $this->actingAs($shopper, 'customer');

    expect($this->postJson('/admin-api/orders/1/capture')->getStatusCode())->toBe(401)
        ->and($this->postJson('/admin-api/orders/1/refund', ['amount_fils' => 1])->getStatusCode())->toBe(401)
        ->and($this->getJson('/admin-api/orders/1/settlement')->getStatusCode())->toBe(401);

    $this->app['auth']->forgetGuards();
    $this->actingAs(zWebUser(), 'web');

    expect($this->postJson('/admin-api/orders/1/capture')->getStatusCode())->toBe(401)
        ->and($this->postJson('/admin-api/orders/1/refund', ['amount_fils' => 1])->getStatusCode())->toBe(401)
        ->and($this->getJson('/admin-api/orders/1/settlement')->getStatusCode())->toBe(401);
});

it('refuses all three CSV exports to a signed-in shopper and to a non-admin site user', function () {
    $exports = [
        '/admin-api/customers/export',
        '/admin-api/orders-export',
        '/admin-api/newsletter/export',
    ];

    $this->actingAs(zCustomer(), 'customer');

    foreach ($exports as $uri) {
        expect($this->getJson($uri)->getStatusCode())->toBe(401, $uri);
    }

    $this->app['auth']->forgetGuards();
    $this->actingAs(zWebUser(), 'web');

    foreach ($exports as $uri) {
        expect($this->getJson($uri)->getStatusCode())->toBe(401, $uri);
    }
});

/* ------------------------------------------------- CSV formula injection */

/*
 * THE BUG THIS LANE FOUND.
 *
 * `subscribers.source` is written by Store\SubscribeController straight from
 * the request — `substr((string) $request->input('source', 'homepage'), 0, 40)`
 * — and POST /subscribe is public and unauthenticated. `nl_source_tag` defaults
 * to on, so the column is populated on a stock install.
 *
 * NewsletterApiController::export() wrote both text columns out raw while its
 * two sibling exports (Orders and Customers) had guarded their cells since the
 * day they were written. So any stranger could put `=cmd|'/c calc'!A1` into a
 * cell of a file the store owner opens in Excel.
 *
 * fputcsv() is not a defence: it quotes the field for CSV transport, and Excel
 * strips that quoting before it decides the cell is a formula.
 */
it('neutralises a formula an anonymous subscriber planted in the source tag', function () {
    $this->post('/subscribe', [
        'email' => 'z-victim@example.test',
        'source' => '=cmd|\'/c calc\'!A1',
    ]);

    // The payload really did reach the database — otherwise the export assertion
    // below would be passing for the wrong reason.
    expect(DB::table('subscribers')->where('email', 'z-victim@example.test')->value('source'))
        ->toBe('=cmd|\'/c calc\'!A1');

    $csv = $this->actingAs(zAdmin(), 'admin')
        ->get('/admin-api/newsletter/export')
        ->streamedContent();

    // Quoted by fputcsv AND prefixed, so the spreadsheet reads it as text.
    expect($csv)->toContain("'=cmd|'")
        ->and($csv)->not->toContain(",\"=cmd")
        ->and($csv)->not->toContain(',=cmd');
});

it('neutralises every leading character a spreadsheet will execute', function () {
    // Straight into the table: the point here is the export, and this covers the
    // characters the public signup form's own validation would not let through.
    foreach (['=', '+', '-', '@', "\t", "\r"] as $i => $lead) {
        DB::table('subscribers')->insert([
            'email' => 'z-lead-' . $i . '@example.test',
            'source' => $lead . 'HYPERLINK("http://evil.test")',
            'status' => 'subscribed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $csv = $this->actingAs(zAdmin(), 'admin')
        ->get('/admin-api/newsletter/export')
        ->streamedContent();

    foreach (['=', '+', '-', '@', "\t", "\r"] as $lead) {
        expect($csv)->toContain("'" . $lead . 'HYPERLINK');
    }
});

it('leaves an ordinary subscriber row exactly as it was', function () {
    DB::table('subscribers')->insert([
        'email' => 'z-plain@example.test',
        'source' => 'homepage',
        'status' => 'subscribed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $csv = $this->actingAs(zAdmin(), 'admin')
        ->get('/admin-api/newsletter/export')
        ->streamedContent();

    // No stray quote in front of anything harmless.
    expect($csv)->toContain('z-plain@example.test,homepage,subscribed')
        ->and($csv)->not->toContain("'z-plain")
        ->and($csv)->not->toContain("'homepage");
});

/*
 * The property, stated once for all three exports so a fourth cannot be added
 * without a decision about it. Orders and Customers already held this; the
 * newsletter one is the reason the test exists.
 */
it('gives all three exports the same idea of a dangerous leading character', function () {
    $controllers = [
        App\Http\Controllers\Admin\NewsletterApiController::class,
        App\Http\Controllers\Admin\OrdersApiController::class,
        App\Http\Controllers\Admin\CustomersApiController::class,
    ];

    foreach ($controllers as $class) {
        $method = new ReflectionMethod($class, 'csvCell');
        $method->setAccessible(true);

        $instance = app($class);

        foreach (['=', '+', '-', '@', "\t", "\r"] as $lead) {
            expect($method->invoke($instance, $lead . 'SUM(1)'))
                ->toBe("'" . $lead . 'SUM(1)', $class . ' / ' . json_encode($lead));
        }

        expect($method->invoke($instance, 'safe value'))->toBe('safe value')
            ->and($method->invoke($instance, ''))->toBe('')
            ->and($method->invoke($instance, null))->toBe('');
    }
});

/* ------------------------------------------- cross-guard identity bleed */

/*
 * THE SECOND BUG THIS LANE FOUND.
 *
 * Both sign-in paths flashed a greeting built from
 * `auth()->guard()->user()->name` — the DEFAULT guard, which config/auth.php
 * says is `web`, the admin-side `users` table. SessionGuard::login() does not
 * make `customer` the default, so the name came from whoever held the `web`
 * session in that browser.
 *
 * On a browser with an admin signed in — the owner checking their own
 * storefront, any shared back-office machine — the shopper was greeted with the
 * ADMIN USER'S NAME. That is a back-office identity rendered onto a storefront
 * page for a different person.
 *
 * Exactly the trap the AccountController class docblock was written about,
 * repeated two files over.
 */
it('greets a shopper by their own name and never by the admin\'s', function () {
    $admin = zAdmin();
    $admin->forceFill(['name' => 'Back Office Owner'])->save();

    $customer = zCustomer(['name' => 'Nadia Shopper']);

    // Both sessions in one browser, which is the whole point.
    $this->actingAs($admin, 'admin');

    $this->post('/my-account/login', [
        'email' => $customer->email,
        'password' => 'password123',
    ])->assertRedirect();

    expect(session('kbb.greet'))->toMatchArray(['kind' => 'back', 'name' => 'Nadia'])
        ->and(session('kbb.greet')['name'])->not->toBe('Back Office Owner');
});

it('greets an ordinary shopper by name when no admin session exists at all', function () {
    $customer = zCustomer(['name' => 'Yusuf Al Mansoori']);

    $this->post('/my-account/login', [
        'email' => $customer->email,
        'password' => 'password123',
    ])->assertRedirect();

    // Before the fix this was '' — the web guard had nobody, so `?? ''` fired
    // and every "Welcome back" on the site rendered with a blank name.
    expect(session('kbb.greet'))->toMatchArray(['kind' => 'back', 'name' => 'Yusuf']);
});

it('greets a newly registered shopper by their own name too', function () {
    $admin = zAdmin();
    $admin->forceFill(['name' => 'Back Office Owner'])->save();

    $this->actingAs($admin, 'admin');

    // Registration is gated by the arithmetic human-check (`account_check`
    // defaults to on), so seed a question this request can answer.
    $token = 'z-token';
    $this->withSession(['kbb.human.' . $token => ['answer' => 7, 'at' => time()]]);

    $this->post('/my-account/register', [
        'name' => 'Layla Newcomer',
        'email' => 'z-new-' . uniqid() . '@example.test',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'hc_token' => $token,
        'hc_answer' => 7,
    ])->assertRedirect('/my-account/');

    expect(session('kbb.greet'))->toMatchArray(['kind' => 'new', 'name' => 'Layla'])
        ->and(session('kbb.greet')['name'])->not->toBe('Back Office Owner');
});

/* ------------------------------------------------ pins on what already held */

/*
 * The account area reaches orders through the customer, never by id. Pinned
 * here as well as trusted there: it is the single control standing between a
 * sequential order number and somebody else's address and basket.
 */
it('will not serve one shopper the order of another, by id', function () {
    $mine = zCustomer();
    $theirs = zCustomer();

    $victimOrder = App\Models\Order::create([
        'order_number' => '20001',
        'customer_id' => $theirs->id,
        'email' => $theirs->email,
        'status' => 'processing',
        'currency' => 'AED',
        'subtotal' => 10000,
        'total' => 10000,
    ]);

    $this->actingAs($mine, 'customer');

    // 404, not 403: order numbers are sequential, and a 403 would confirm the
    // row exists, which is the thing being defended against.
    $this->get('/my-account/orders/' . $victimOrder->id)->assertNotFound();
});

/*
 * The JSON-LD escaping fix, pinned. An org_name of "</script><script>…" once
 * executed on every page of the site because JSON_UNESCAPED_SLASHES was set;
 * the HEX flags that replaced it are what keeps a string from closing the
 * block it sits in.
 */
it('keeps a script tag in an SEO text field from closing the JSON-LD block', function () {
    App\Models\Setting::updateOrCreate(
        ['key' => 'org_name'],
        ['value' => '</script><script>alert(1)</script>']
    );

    App\Models\Setting::flushMap();

    $html = $this->get('/')->getContent();

    expect($html)->not->toContain('</script><script>alert(1)')
        ->and($html)->not->toContain('<script>alert(1)</script>');
});

/* ------------------------------------------------- integrator: the throttles */

/**
 * Lane Z reported these and could not fix them: the rate limits live on routes,
 * and routes/web.php is the integrator's file. Read off the REGISTERED routes
 * rather than from the source, for the same reason the guard assertions are —
 * what matters is what the router ended up with.
 */
it('rate-limits the endpoints that answer differently for things that exist', function (string $uri, string $limit) {
    $route = collect(app('router')->getRoutes()->getRoutes())
        ->first(fn ($r) => $r->uri() === $uri && in_array('POST', $r->methods(), true));

    expect($route)->not->toBeNull("route {$uri} is not registered")
        ->and($route->gatherMiddleware())->toContain($limit);
})->with([
    // CouponService distinguishes "no such code" from "not active yet",
    // "expired" and "fully redeemed" — unthrottled that enumerates the coupon
    // namespace, future promotions included.
    ['api/cart/coupon', 'throttle:20,1'],
    ['checkout/coupon', 'throttle:20,1'],
    // unique:customers,email makes registration a yes/no on whether an address
    // shops here.
    ['my-account/register', 'throttle:10,1'],
]);

/**
 * The Customers error path must never return the statement or its bindings.
 *
 * QueryException::getMessage() appends "(Connection: mysql, SQL: … where email
 * = someone@example.com)" — the whole statement with bindings interpolated, and
 * the bindings there carry the operator's search term. The handler falls back
 * to a fixed sentence instead. Latent rather than live (getPrevious() is
 * normally the PDOException), which is exactly why it needed pinning.
 */
it('never returns SQL or bindings from the customers error path', function () {
    $source = file_get_contents(app_path('Http/Controllers/Admin/CustomersApiController.php'));

    $code = implode('', array_map(
        static fn (array $t): string => (string) (is_array($t) ? ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT ? '' : $t[1]) : $t),
        array_map(static fn ($t) => is_array($t) ? $t : [0, $t], token_get_all($source)),
    ));

    expect($code)->not->toContain('?? $e->getMessage()')
        ->and($code)->toContain("'The database driver gave no further detail.'");
});

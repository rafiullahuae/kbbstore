<?php

declare(strict_types=1);

/*
 * THE TYPED-AT-CHECKOUT REMINDER THAT NEVER WENT (the owner, 23 October 2026,
 * a Cart Tracking row reading "sudiptanayak030@gmail.com · Typed at checkout ·
 * Left at Checkout"): "the abandoned cart email not sending. i have checked
 * multiple times, but nothing showing in sent emails. make sure such customers
 * must get abandoned cart emails, and i need proper record for those in the
 * cart tracking page as new tab".
 *
 * What it looked like: the address was recorded, the basket was left, and no
 * email ever followed, because the Abandoned Cart Recovery module, the English
 * subject/message and the "When to send" schedule each ship empty and each one
 * stops every reminder without a word on any screen.
 */

use App\Mail\CartRecoveryReminder;
use App\Models\AdminUser;
use App\Models\Cart;
use App\Models\Product;
use App\Models\Setting;
use App\Services\CartRecovery;
use App\Services\CartTracking\ReminderLog;
use App\Services\OutboundTick;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Support\CartTrackingRoutes;

beforeEach(function () {
    Mail::fake();
    Cache::flush();
    brmForget();
});

function brmForget(): void
{
    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
}

function brmMigration(): object
{
    return require database_path('migrations/2027_11_26_100100_basket_reminders_on.php');
}

function brmTrading(): void
{
    DB::table('orders')->insert(['order_number' => 'BRM-'.Str::random(6), 'email' => 'old@example.test', 'created_at' => now(), 'updated_at' => now()]);
}

/** A basket left with an address typed at checkout, as CheckoutEmailCapture leaves it. */
function brmTypedCart(string $email, int $hoursAgo = 0, int $bot = 0, int $lines = 1): Cart
{
    $cart = Cart::create(['token' => (string) Str::uuid(), 'currency' => 'AED', 'status' => 'active', 'shipping_country' => 'AE', 'last_activity_at' => now()]);

    for ($i = 0; $i < $lines; $i++) {
        $p = Product::create(['slug' => 'brm-'.Str::random(10), 'name' => 'Collagen Patch', 'status' => 'publish', 'is_visible' => true, 'price' => 23900, 'stock_status' => 'instock']);
        $cart->items()->create(['product_id' => $p->id, 'quantity' => 1, 'unit_price' => 23900]);
    }

    DB::table('carts')->where('id', $cart->id)->update(['ct_email' => $email, 'ct_bot_score' => $bot, 'updated_at' => now()->subHours($hoursAgo)]);

    return $cart->fresh();
}

function brmTick(): void
{
    Cache::flush();
    brmForget();
    app(OutboundTick::class)->run();
}

it('switches reminders on as asked, fills only blank wording, and keeps his own', function () {
    brmTrading();
    app(SettingsService::class)->set(CartRecovery::KEY_SUBJECT, 'His own subject');
    brmForget();

    brmMigration()->up();
    brmForget();

    // MUTATION: drop the module_toggles write. RED (the module is still off).
    expect(app(SettingsService::class)->moduleEnabled('abandoned_cart', false))->toBeTrue()
        ->and(app(SettingsService::class)->get(CartRecovery::KEY_SUBJECT))->toBe('His own subject')
        ->and(app(SettingsService::class)->get(CartRecovery::KEY_BODY))->toStartWith('We noticed you left a few things in your basket')
        ->and(app(CartRecovery::class)->schedule())->toBe([1.0]);
});

it('does nothing on a shop that has never sold', function () {
    brmMigration()->up();
    brmForget();

    expect(app(SettingsService::class)->moduleEnabled('abandoned_cart', false))->toBeFalse()
        ->and(DB::table('settings')->where('key', CartRecovery::KEY_SCHEDULE)->exists())->toBeFalse();
});

it('queues the baskets typed in the last 48 hours, and only the ones a reminder is for', function () {
    brmTrading();
    $recent = brmTypedCart('recent@example.com', 3);
    $old = brmTypedCart('old@example.com', 72);
    $bot = brmTypedCart('bot@example.com', 3, 90);
    $empty = brmTypedCart('empty@example.com', 3, 0, 0);

    brmMigration()->up();

    $queued = DB::table('cart_recoveries')->pluck('cart_id')->all();
    expect($queued)->toBe([$recent->id])
        ->and(DB::table('cart_recoveries')->where('cart_id', $recent->id)->value('source'))->toBe(CartRecovery::SOURCE_TYPED);

    // ... and that one is actually emailed with the next visit (it left 3 hours ago, past the hour).
    brmForget();
    brmTick();
    Mail::assertSent(CartRecoveryReminder::class, fn ($m) => $m->hasTo('recent@example.com'));
    Mail::assertSent(CartRecoveryReminder::class, 1);
});

it('shows every basket with an email and what happened to its reminder, in one flat query', function () {
    brmTrading();
    brmMigration()->up();
    brmForget();

    $sent = brmTypedCart('sent@example.com', 3);
    DB::table('cart_recoveries')->insert(['cart_id' => $sent->id, 'email' => 'sent@example.com', 'source' => 'checkout_email', 'stage' => 1,
        'consented_at' => now()->subHours(3), 'last_sent_at' => now()->subHours(2), 'created_at' => now(), 'updated_at' => now()]);
    $waiting = brmTypedCart('wait@example.com');
    DB::table('cart_recoveries')->insert(['cart_id' => $waiting->id, 'email' => 'wait@example.com', 'source' => 'checkout_email', 'stage' => 0,
        'consented_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $missed = brmTypedCart('missed@example.com', 100);   // typed while reminders were off, too old to queue

    $count = function () {
        $q = 0;
        DB::listen(function () use (&$q) { $q++; });
        $rows = app(ReminderLog::class)->rows();

        return [$q, $rows];
    };
    app(ReminderLog::class)->rows();   // settings and schedule loaded once, as on any admin request
    [$q3, $rows] = $count();
    $by = collect($rows['rows'])->keyBy('email');

    expect($by['sent@example.com']['state'])->toBe('sent')
        ->and($by['wait@example.com']['state'])->toBe('waiting')
        ->and($by['wait@example.com']['status'])->toStartWith('Due ')
        ->and($by['missed@example.com']['state'])->toBe('none')
        ->and($by['missed@example.com']['how'])->toBe('Typed at checkout')
        ->and($by['sent@example.com']['value_label'])->toContain('239');

    for ($i = 0; $i < 40; $i++) {
        brmTypedCart('more'.$i.'@example.com');
    }
    [$q40] = $count();

    // Flat: the page is one query and one count, however many baskets.
    expect($q40)->toBe($q3);
});

it('names each shut gate and where to open it', function () {
    $gates = collect(app(ReminderLog::class)->gates())->keyBy('key');

    // Shipped state on a fresh install: module off, wording and schedule empty.
    expect($gates['module']['ok'])->toBeFalse()
        ->and($gates['module']['where'])->toBe('Store → Modules → Abandoned Cart Recovery')
        ->and($gates['schedule']['ok'])->toBeFalse()
        ->and($gates['subject']['ok'])->toBeFalse();

    brmTrading();
    brmMigration()->up();
    brmForget();
    $open = collect(app(ReminderLog::class)->gates())->keyBy('key');

    expect($open->every(fn ($g) => $g['ok']))->toBeTrue()
        ->and($open['schedule']['label'])->toBe('Sent 1 hour after the email is typed');
});

it('serves the tab to the admin only', function () {
    CartTrackingRoutes::wire(app());
    brmTypedCart('tab@example.com');

    test()->getJson('/admin-api/cart-tracking/reminders')->assertStatus(401);

    $owner = AdminUser::create(['name' => 'O', 'email' => 'brm-owner@example.com', 'password' => Hash::make('secret-secret'), 'role' => 'owner']);
    $body = test()->actingAs($owner, 'admin')->getJson('/admin-api/cart-tracking/reminders')->assertOk()->json();

    expect($body['rows'][0]['email'])->toBe('tab@example.com')
        ->and(array_keys($body['rows'][0]))->toBe(['cart', 'email', 'how', 'value', 'value_label', 'items', 'state', 'status', 'at'])
        ->and($body['gates'])->toHaveCount(6);
});

it('puts the tab on the Cart Tracking screen once, next to Blocked', function () {
    $view = (string) file_get_contents(resource_path('views/admin/partials/cart-tracking-screen.blade.php'));

    expect(substr_count($view, "['reminders', 'Reminder emails']"))->toBe(1)
        ->and(substr_count($view, "Route::get('/cart-tracking/reminders'"))->toBe(0)
        ->and(substr_count((string) file_get_contents(base_path('routes/cart-tracking-admin.php')), "Route::get('/cart-tracking/reminders'"))->toBe(1);
});

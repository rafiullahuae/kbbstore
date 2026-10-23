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
        ->and(array_keys($body['rows'][0]))->toBe(['cart', 'email', 'how', 'lang', 'value', 'value_label', 'items', 'state', 'status', 'at'])
        ->and($body['gates'])->toHaveCount(6);
});

it('puts the tab on the Cart Tracking screen once, next to Blocked', function () {
    $view = (string) file_get_contents(resource_path('views/admin/partials/cart-tracking-screen.blade.php'));

    expect(substr_count($view, "['reminders', 'Reminder emails']"))->toBe(1)
        ->and(substr_count($view, "Route::get('/cart-tracking/reminders'"))->toBe(0)
        ->and(substr_count((string) file_get_contents(base_path('routes/cart-tracking-admin.php')), "Route::get('/cart-tracking/reminders'"))->toBe(1);
});

/* ── 23 October, second ask: older baskets on demand, and the right language ── */

function brmOn(): void
{
    brmTrading();
    brmMigration()->up();
    brmForget();
}

function brmSeen(Cart $cart, string $path): void
{
    DB::table('carts')->where('id', $cart->id)->update(['ct_seen_path' => $path]);
}

it('reads the language a basket was left in from its page, not from the /api request', function () {
    // The defect: /api/checkout-contact has no /ar, so every typed reminder was English.
    expect(CartRecovery::localeFromPath('https://kbeautybliss.com/ar/checkout/'))->toBe('ar')
        ->and(CartRecovery::localeFromPath('/ar'))->toBe('ar')
        ->and(CartRecovery::localeFromPath('/checkout/'))->toBe('en')
        ->and(CartRecovery::localeFromPath('/arabic-skincare/'))->toBe('en')
        ->and(CartRecovery::localeFromPath(null))->toBeNull();
});

it('schedules reminders for older baskets: one per address, never to a buyer, an unsubscriber or a bot', function () {
    brmOn();
    \Tests\Support\ArabicShop::on();
    $en = brmTypedCart('en@example.com', 24 * 5);
    $ar = brmTypedCart('ar@example.com', 24 * 12);
    brmSeen($ar, '/ar/checkout/');
    $older = brmTypedCart('en@example.com', 24 * 8);          // same address, older basket: skipped
    $tooOld = brmTypedCart('old@example.com', 24 * 40);       // outside 30 days
    $bought = brmTypedCart('bought@example.com', 24 * 6);
    DB::table('orders')->insert(['order_number' => 'BRM-B', 'email' => 'Bought@Example.com', 'created_at' => now()->subDays(2), 'updated_at' => now()]);
    $gone = brmTypedCart('gone@example.com', 24 * 3);
    DB::table('outbound_optouts')->insert(['email' => 'gone@example.com', 'created_at' => now(), 'updated_at' => now()]);
    brmTypedCart('bot@example.com', 24 * 3, 95);

    $recovery = app(CartRecovery::class);
    $count = $recovery->scheduleLeft(30, now(), false);

    // MUTATION: drop the "ordered since" clause and bought@ is counted -- RED.
    expect($count)->toMatchArray(['ok' => true, 'baskets' => 2, 'en' => 1, 'ar' => 1])
        ->and(DB::table('cart_recoveries')->count())->toBe(0);   // counting writes nothing

    $done = $recovery->scheduleLeft(30, now(), true);
    expect($done['baskets'])->toBe(2)
        ->and(DB::table('cart_recoveries')->orderBy('cart_id')->pluck('cart_id')->all())->toBe([$en->id, $ar->id])
        ->and(DB::table('cart_recoveries')->where('cart_id', $ar->id)->value('locale'))->toBe('ar');

    // Both go with the next sweep, each in its own language.
    brmTick();
    Mail::assertSent(CartRecoveryReminder::class, 2);
    Mail::assertSent(CartRecoveryReminder::class, fn ($m) => $m->hasTo('ar@example.com') && str_contains($m->envelope()->subject, 'سلّتكِ'));
    Mail::assertSent(CartRecoveryReminder::class, fn ($m) => $m->hasTo('en@example.com') && $m->envelope()->subject === 'Your basket is waiting 🛍️');

    // Pressing it twice queues nothing new.
    expect($recovery->scheduleLeft(30, now(), true)['baskets'])->toBe(0);
});

it('holds a reminder scheduled for later until its time', function () {
    brmOn();
    brmTypedCart('later@example.com', 24 * 4);

    app(CartRecovery::class)->scheduleLeft(10, now()->addDay(), true);
    brmTick();
    Mail::assertNothingSent();

    $this->travel(25)->hours();
    brmTick();
    Mail::assertSent(CartRecoveryReminder::class, fn ($m) => $m->hasTo('later@example.com'));
});

it('refuses to schedule while reminders are off, and takes only the offered windows', function () {
    brmTypedCart('x@example.com', 24 * 3);

    expect(app(CartRecovery::class)->scheduleLeft(30, now(), true))->toMatchArray(['ok' => false])
        ->and(DB::table('cart_recoveries')->count())->toBe(0);

    brmOn();
    expect(app(CartRecovery::class)->scheduleLeft(45, now(), true)['ok'])->toBeFalse();
});

it('records an Arabic checkout\'s typed address as an Arabic reminder', function () {
    brmOn();
    \Tests\Support\ArabicShop::on();
    $cart = brmTypedCart('typed-ar@example.com');
    DB::table('carts')->where('id', $cart->id)->update(['ct_email' => null]);

    // The capture itself, as CheckoutEmailCapture calls it with the page's language.
    app(CartRecovery::class)->captureTyped($cart->id, 'typed-ar@example.com', CartRecovery::localeFromPath('/ar/checkout/'));

    expect(DB::table('cart_recoveries')->where('cart_id', $cart->id)->value('locale'))->toBe('ar');
});

it('previews the email in English and Arabic, as it is sent', function () {
    brmOn();
    \Tests\Support\ArabicShop::on();
    Product::create(['slug' => 'brm-pv', 'name' => 'Collagen Patch', 'status' => 'publish', 'is_visible' => true, 'price' => 23900, 'stock_status' => 'instock']);

    $en = app(CartRecovery::class)->preview('en');
    $ar = app(CartRecovery::class)->preview('ar');

    expect($en['subject'])->toBe('Your basket is waiting 🛍️')
        ->and($en['html'])->toContain('We noticed you left a few things in your basket')
        ->and($en['html'])->toContain('Collagen Patch')
        ->and($ar['subject'])->toBe(CartRecovery::AR_SUBJECT)
        ->and($ar['html'])->toContain('لاحظنا أنكِ تركتِ بعض المنتجات');
});

it('guards the two new endpoints: view for the preview, block for scheduling', function () {
    CartTrackingRoutes::wire(app());
    brmOn();

    test()->postJson('/admin-api/cart-tracking/reminders/schedule', ['days' => 30])->assertStatus(401);

    $owner = AdminUser::create(['name' => 'O', 'email' => 'brm-o2@example.com', 'password' => Hash::make('secret-secret'), 'role' => 'owner']);
    test()->actingAs($owner, 'admin')->postJson('/admin-api/cart-tracking/reminders/schedule', ['days' => 30])->assertOk()->assertJson(['ok' => true]);
    test()->actingAs($owner, 'admin')->postJson('/admin-api/cart-tracking/reminders/schedule', ['days' => 7])->assertStatus(422);
    test()->actingAs($owner, 'admin')->postJson('/admin-api/cart-tracking/reminders/schedule', ['days' => 30, 'at' => now()->subDay()->format('Y-m-d\TH:i')])->assertStatus(422);
    test()->actingAs($owner, 'admin')->getJson('/admin-api/cart-tracking/reminders/preview?lang=ar')->assertOk()->assertJsonStructure(['subject', 'html']);
});

it('answers every button the Cart Tracking screen draws: each one is on the click handler\'s list', function () {
    /*
     * THE DEFECT (2.60.575): the Reminder emails tab's page arrows were drawn
     * with data-ctk-rpage and handled by name, but the handler only reacts to
     * attributes on its closest() list, and that one was never added -- so the
     * arrows did nothing and baskets past the first fifty could not be reached.
     * The count, schedule and preview buttons were the same mistake, caught in
     * Chromium before they shipped.
     * MUTATION: take [data-ctk-remcount] off the list -- red.
     */
    $view = (string) file_get_contents(resource_path('views/admin/partials/cart-tracking-screen.blade.php'));
    preg_match("/var t = e\\.target\\.closest\\('([^']+)'\\);/", $view, $list);
    preg_match_all("/hasAttribute\\('(data-ctk-[a-z-]+)'\\)/", $view, $handled);

    expect($list)->not->toBeEmpty();

    foreach (array_unique($handled[1]) as $attr) {
        expect(str_contains($list[1], '['.$attr.']'))->toBeTrue($attr.' is handled but the click never reaches it');
    }
});

it('says why each basket with an email is not emailed, in the count and in the list', function () {
    /*
     * The owner, 23 October, a screen of "Not queued" rows: "I have a lot of
     * abandoned carts with emails, but the system shows me only 11 in last 30
     * days, why is that". The count skipped buyers, checked-out and emptied
     * baskets, repeat addresses and the already-reminded without a word, and
     * the list called all of them "reminders were off".
     * MUTATION: return null from skipReason() for 'ordered'. RED.
     */
    brmOn();
    brmTypedCart('send@example.com', 24 * 5);
    brmTypedCart('bought@example.com', 24 * 6);
    DB::table('orders')->insert(['order_number' => 'BRM-W', 'email' => 'bought@example.com', 'created_at' => now()->subDays(2), 'updated_at' => now()]);
    $done = brmTypedCart('done@example.com', 24 * 4);
    DB::table('carts')->where('id', $done->id)->update(['status' => 'converted']);
    brmTypedCart('send@example.com', 24 * 9);            // same address, older basket
    brmTypedCart('empty@example.com', 24 * 3, 0, 0);

    $r = app(CartRecovery::class)->scheduleLeft(30, now(), false);
    $why = collect($r['skipped'])->pluck('n', 'why')->all();

    expect($r['baskets'])->toBe(1)
        ->and($r['found'])->toBe(5)
        ->and($why)->toBe([
            CartRecovery::SKIP_WORDS['checked_out'] => 1,
            CartRecovery::SKIP_WORDS['empty'] => 1,
            CartRecovery::SKIP_WORDS['ordered'] => 1,
            CartRecovery::SKIP_WORDS['newer'] => 1,
        ]);

    app(ReminderLog::class)->rows();
    $rows = collect(app(ReminderLog::class)->rows()['rows'])->groupBy('email');
    expect($rows['bought@example.com'][0]['status'])->toBe('Not needed: they ordered later')
        ->and($rows['done@example.com'][0]['status'])->toBe('Not needed: checked out (an order was placed from it)')
        ->and($rows['send@example.com']->pluck('status')->all())->toContain('Not sent yet: use “Send to baskets already left” above')
        ->and($rows['send@example.com']->pluck('status')->all())->toContain('Not needed: a newer basket from the same email gets the reminder');
});

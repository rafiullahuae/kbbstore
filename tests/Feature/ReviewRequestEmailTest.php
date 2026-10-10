<?php

declare(strict_types=1);

/**
 * Lane RV — the review request email, N days after an order is completed.
 *
 * The owner, 10 October: "i need another email template, which will be sent
 * after 2 days of order completion, as feedback ask ... give me full controls
 * also, and make sure on front-end real reviews submitted from this feedback
 * email and stored in the list to approve etc."
 *
 * This file is the email half: when it goes, to whom, about which products,
 * and what it looks like. ReviewRequestPageTest is the page its stars open.
 *
 * MUTATION NOTES, each one run:
 *   - ReviewRequests::due(): drop the `completed_at <= cutoff` condition
 *       -> 'goes two days after completion' is red (sent at once).
 *   - ReviewRequests::record(): remove the enabled() check
 *       -> 'records nothing while the switch is off' is red.
 *   - ReviewRequests::register(): fire on `created` as well as `updated`
 *       -> 'starts no clock for an order that was already completed' is red.
 *   - ReviewRequests::refusal(): delete the refund branch
 *       -> 'skips an order refunded or cancelled since' is red.
 *   - ReviewRequests::productIdsFor(): drop the ->visible() filter
 *       -> 'asks about at most N products still on sale' is red.
 *   - review-cards.blade.php: one href for all five stars
 *       -> 'makes each of the five stars its own link' is red.
 *   - ReviewRequestMail::headers(): drop List-Unsubscribe-Post
 *       -> 'carries a one-click unsubscribe' is red.
 */

use App\Mail\ReviewRequestMail;
use App\Models\Brand;
use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Models\Setting;
use App\Services\Mail\CustomerEmails;
use App\Services\Mail\Kit\EmailWording;
use App\Services\Mail\Kit\KitSamples;
use App\Services\Mail\Kit\KitSections;
use App\Services\Mail\OrderReminders;
use App\Services\Reviews\ReviewRequests;
use App\Services\Reviews\ReviewRequestSettings;
use App\Services\SettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
    KitSections::forget();
});

afterEach(function () {
    Carbon::setTestNow();
});

function rvProduct(string $name, array $over = []): Product
{
    $brand = Brand::firstOrCreate(['slug' => 'anua'], ['name' => 'Anua']);

    return Product::create(array_merge([
        'name' => $name, 'slug' => 'rv-' . \Illuminate\Support\Str::slug($name) . '-' . uniqid(), 'brand_id' => $brand->id,
        'status' => 'publish', 'is_visible' => 1, 'price' => 8900, 'stock_status' => 'instock', 'type' => 'simple',
    ], $over));
}

/** An order with one line per product, still processing. */
function rvOrder(array $products, array $over = []): Order
{
    $address = ['first_name' => 'Rafi', 'last_name' => 'Ullah', 'line1' => 'Marina Heights', 'city' => 'Dubai', 'country' => 'AE'];
    $order = Order::create(array_merge([
        'order_number' => 'KBB-RV-' . uniqid(), 'email' => 'rafi@example.com', 'status' => 'processing', 'currency' => 'AED',
        'billing_address' => $address, 'shipping_address' => $address,
        'subtotal' => 17800, 'discount_total' => 0, 'shipping_total' => 0, 'fee_total' => 0, 'tax_total' => 0, 'total' => 17800,
        'shipping_method' => 'Free UAE delivery', 'payment_method' => 'cod', 'payment_method_title' => 'Cash on delivery', 'paid_at' => now(),
    ], $over));

    foreach ($products as $p) {
        $order->items()->create(['product_id' => $p->id, 'name' => $p->name, 'brand' => 'Anua', 'quantity' => 1, 'unit_price' => 8900,
            'subtotal' => 8900, 'total' => 8900, 'variant_attributes' => ['size' => '50 ml']]);
    }

    return $order->fresh('items');
}

function rvComplete(Order $order): Order
{
    $order->update(['status' => 'completed']);

    return $order->fresh();
}

function rvSet(string $key, mixed $value): void
{
    app(SettingsService::class)->set($key, $value);
    SettingsService::forgetMemo();
    Setting::flushMap();
}

function rvSwitch(bool $on): void
{
    app(SettingsService::class)->setModule(ReviewRequestSettings::MODULE, $on);
    SettingsService::forgetMemo();
    Setting::flushMap();
}

it('starts no clock for an order that was already completed before the package — only a transition after it', function () {
    /*
     * THE DEFECT THIS PREVENTS: applying the package mails the shop's whole
     * history. An order that is completed already has no row, and the sweep
     * reads only rows — so it is never asked, however long the sweep runs.
     */
    Mail::fake();
    $old = rvOrder([rvProduct('Old Toner')], ['status' => 'completed']);

    expect(DB::table('review_requests')->count())->toBe(0);

    Carbon::setTestNow(now()->addDays(30));
    expect(app(OrderReminders::class)->sweep(50))->toBe(0);
    Mail::assertNotSent(ReviewRequestMail::class);

    Carbon::setTestNow();
    $new = rvComplete(rvOrder([rvProduct('New Toner')]));

    expect(DB::table('review_requests')->pluck('order_id')->all())->toBe([$new->id])
        ->and(DB::table('review_requests')->where('order_id', $old->id)->exists())->toBeFalse();
});

it('goes two days after completion by default, once per order, through the reminders sweep', function () {
    Mail::fake();
    // The 3-hour feedback request rides the same sweep; off here so the
    // counts below are this email's alone.
    app(SettingsService::class)->setModule('email_order_feedback', false);
    Carbon::setTestNow('2026-10-10 09:00:00');
    $order = rvComplete(rvOrder([rvProduct('Heartleaf Toner')]));

    Carbon::setTestNow('2026-10-12 08:59:00');
    expect(app(OrderReminders::class)->sweep(10))->toBe(0);

    Carbon::setTestNow('2026-10-12 09:00:00');
    expect(app(OrderReminders::class)->sweep(10))->toBe(1)
        ->and(app(OrderReminders::class)->sweep(10))->toBe(0);

    // Completed again after being reopened: still once — unique(order_id).
    $order->update(['status' => 'processing']);
    $order->update(['status' => 'completed']);
    Carbon::setTestNow('2026-10-20 09:00:00');
    expect(app(OrderReminders::class)->sweep(10))->toBe(0);

    Mail::assertSent(ReviewRequestMail::class, 1);
    $row = DB::table('review_requests')->where('order_id', $order->id)->first();
    expect($row->status)->toBe('sent')
        ->and(strlen((string) $row->token_hash))->toBe(64)
        ->and(json_decode((string) $row->products, true))->toHaveCount(1)
        ->and($order->notes()->where('content', 'like', '%review request%')->exists())->toBeTrue();
});

it('follows the days the owner sets, clamped to 1–60', function () {
    rvSet('review_request_days', 999);
    expect(app(ReviewRequestSettings::class)->int('review_request_days'))->toBe(60);
    rvSet('review_request_days', -4);
    expect(app(ReviewRequestSettings::class)->int('review_request_days'))->toBe(1);
    rvSet('review_request_star_colour', 'url(javascript:alert(1))');
    expect(app(ReviewRequestSettings::class)->starHex())->toBe('#F2A900');

    Mail::fake();
    rvSet('review_request_days', 5);
    Carbon::setTestNow('2026-10-10 09:00:00');
    rvComplete(rvOrder([rvProduct('Cleanser')]));

    Carbon::setTestNow('2026-10-14 09:00:00');
    expect(app(ReviewRequests::class)->sweep(10))->toBe(0);
    Carbon::setTestNow('2026-10-15 09:00:00');
    expect(app(ReviewRequests::class)->sweep(10))->toBe(1);
});

it('ships ON, and records nothing while the switch is off', function () {
    expect(app(ReviewRequestSettings::class)->enabled())->toBeTrue();

    rvSwitch(false);
    Mail::fake();
    rvComplete(rvOrder([rvProduct('Serum')]));
    expect(DB::table('review_requests')->count())->toBe(0);

    rvSwitch(true);
    rvComplete(rvOrder([rvProduct('Cream')], ['email' => 'b@example.com']));
    expect(DB::table('review_requests')->count())->toBe(1);
});

it('skips an order refunded or cancelled since, an unsubscribed address, and a closed review form — and says why', function () {
    Mail::fake();
    Carbon::setTestNow('2026-10-10 09:00:00');

    $cancelled = rvComplete(rvOrder([rvProduct('A')], ['email' => 'c@example.com']));
    $cancelled->update(['status' => 'cancelled']);

    $refunded = rvComplete(rvOrder([rvProduct('B')], ['email' => 'r@example.com']));
    \App\Models\Refund::create(['order_id' => $refunded->id, 'amount' => 1000, 'status' => 'succeeded', 'provider_ref' => 'rv']);

    $gone = rvComplete(rvOrder([rvProduct('C')], ['email' => 'u@example.com']));
    ReviewRequests::optOut('U@Example.com ');

    Carbon::setTestNow('2026-10-13 09:00:00');
    expect(app(ReviewRequests::class)->sweep(10))->toBe(0);
    Mail::assertNotSent(ReviewRequestMail::class);

    $reasons = DB::table('review_requests')->pluck('reason', 'order_id')->all();
    expect($reasons[$cancelled->id])->toBe('not_completed')
        ->and($reasons[$refunded->id])->toBe('refunded')
        ->and($reasons[$gone->id])->toBe('unsubscribed');

    // Store → Reviews → Accept new reviews off: no request for a form that would refuse.
    rvSet('sr_allow_submit', false);
    Carbon::setTestNow('2026-10-13 10:00:00');
    $closed = rvComplete(rvOrder([rvProduct('D')], ['email' => 'z@example.com']));
    Carbon::setTestNow('2026-10-16 10:00:00');
    expect(app(ReviewRequests::class)->sweep(10))->toBe(0)
        ->and(DB::table('review_requests')->where('order_id', $closed->id)->value('reason'))->toBe('reviews_closed');
});

it('gives up rather than writing to last month\'s customers after a long quiet spell', function () {
    Mail::fake();
    Carbon::setTestNow('2026-10-01 09:00:00');
    $order = rvComplete(rvOrder([rvProduct('Quiet')]));
    Carbon::setTestNow('2026-10-30 09:00:00');

    expect(app(ReviewRequests::class)->sweep(10))->toBe(0)
        ->and(DB::table('review_requests')->where('order_id', $order->id)->value('reason'))->toBe('too_late');
});

it('asks about at most N products still on sale, once each, leaving out ones this customer already reviewed', function () {
    $a = rvProduct('One');
    $hidden = rvProduct('Hidden', ['is_visible' => 0]);
    $b = rvProduct('Two');
    $reviewed = rvProduct('Reviewed');
    $c = rvProduct('Three');
    $d = rvProduct('Four');
    Review::create(['product_id' => $reviewed->id, 'author_name' => 'Rafi', 'author_email' => 'rafi@example.com', 'rating' => 5, 'content' => 'x', 'status' => 'approved']);

    $order = rvOrder([$a, $hidden, $b, $b, $reviewed, $c, $d]);

    expect(app(ReviewRequests::class)->productIdsFor($order))->toBe([$a->id, $b->id, $c->id]);

    rvSet('review_request_max_products', 6);
    expect(app(ReviewRequests::class)->productIdsFor($order))->toBe([$a->id, $b->id, $c->id, $d->id]);

    rvSet('review_request_skip_reviewed', false);
    expect(app(ReviewRequests::class)->productIdsFor($order))->toBe([$a->id, $b->id, $reviewed->id, $c->id, $d->id]);
});

it('makes each of the five stars its own link to the review page, with that product and rating, and no order id anywhere', function () {
    $p = rvProduct('PDRN Pink Collagen Jelly Eye Mask');
    $order = rvOrder([$p]);
    $token = ReviewRequests::newToken();
    $mail = new ReviewRequestMail($order, $token, [$p->id]);
    $html = (string) $mail->render();

    preg_match_all('#href="([^"]*/review-order/[^"]*[?&](?:amp;)?r=(\d)[^"]*)"#', $html, $m);
    expect($m[2])->toBe(['1', '2', '3', '4', '5']);

    foreach ($m[1] as $href) {
        $href = html_entity_decode($href);
        expect($href)->toContain('/review-order/' . $token . '/')
            ->toContain('p=' . $p->id)
            ->not->toContain('order=')
            ->not->toContain((string) $order->order_number);
    }

    expect($html)->toContain('PDRN Pink Collagen Jelly Eye Mask')
        ->toContain('50 ml')
        ->toContain('Write a review')
        ->toContain('Let us know what you think!')
        ->toContain('Hey Rafi, we would love to hear what you think about your purchase from')
        ->toContain('Unsubscribe from review request emails')
        ->toContain('prefers-color-scheme:dark')
        ->toContain('max-width:600px')
        // The colour is the palette's, never a typed value.
        ->toContain('color:#F2A900');

    expect((string) $mail->envelope()->subject)->toBe('⭐ Rafi, what did you think of PDRN Pink Collagen Jelly Eye Mask?');

    // And a plain-text part with every star's link.
    $text = (string) view('emails.review-request-text', $mail->buildViewData())->render();
    expect(substr_count($text, '/review-order/' . $token . '/?p=' . $p->id . '&r='))->toBe(5)
        ->and($text)->toContain('Unsubscribe from review request emails: ');
});

it('carries a one-click unsubscribe that is this email\'s own link', function () {
    $p = rvProduct('Mask');
    $token = ReviewRequests::newToken();
    $mail = new ReviewRequestMail(rvOrder([$p]), $token, [$p->id]);
    $headers = $mail->headers()->text;

    expect($headers['List-Unsubscribe'])->toBe('<' . ReviewRequests::stopUrl($token) . '>')
        ->and($headers['List-Unsubscribe-Post'])->toBe('List-Unsubscribe=One-Click');
});

it('is worded in Arabic through the same wording system, right to left', function () {
    $p = rvProduct('Toner');
    $order = rvOrder([$p], ['locale' => 'ar']);
    \Tests\Support\ArabicShop::on();
    rvSet(\App\Support\Locale::SETTING_RTL, '1');
    EmailWording::save('review_request', ['ar' => ['subject' => 'ما رأيكِ يا {name}؟', 'heading' => 'شاركينا رأيكِ']], null);

    app()->setLocale('ar');

    try {
        $mail = new ReviewRequestMail($order, ReviewRequests::newToken(), [$p->id]);
        $html = (string) $mail->render();
        $subject = (string) $mail->envelope()->subject;
    } finally {
        app()->setLocale('en');
    }

    expect($subject)->toBe('ما رأيكِ يا Rafi؟')
        ->and($html)->toContain('dir="rtl"')
        ->and($html)->toContain('شاركينا رأيكِ');
});

it('is one more row on Customer emails with its own switch, sections the editor can move, and a test that goes to the admin only', function () {
    rvOrder([rvProduct('Sample')]);
    $row = collect(app(CustomerEmails::class)->rows())->firstWhere('template', 'review_request');

    expect($row)->not->toBeNull()
        ->and($row['switchable'])->toBeTrue()
        ->and($row['on'])->toBeTrue()
        ->and($row['name'])->toBe('Review request');

    expect(app(CustomerEmails::class)->set('review_request', false))->toBeTrue();
    SettingsService::forgetMemo();
    expect(app(ReviewRequestSettings::class)->enabled())->toBeFalse();

    // The sample draws every default section, so the editor shows the truth.
    KitSamples::html('review_request');
    expect(KitSections::seen('review_request'))->toBe(array_keys(KitSections::TEMPLATES['review_request']['sections']));

    // And the sample's links are inert: they open the "not valid" page.
    expect(KitSamples::html('review_request'))->toContain('/review-order/preview/');
});

it('keeps the unsubscribe line in the email whatever layout the owner saves', function () {
    $p = rvProduct('Kept');
    KitSections::save('review_request', ['sections' => array_map(fn ($k) => ['key' => $k, 'on' => false], array_keys(KitSections::TEMPLATES['review_request']['sections']))]);

    $html = (string) (new ReviewRequestMail(rvOrder([$p]), ReviewRequests::newToken(), [$p->id]))->render();

    expect($html)->toContain('Unsubscribe from review request emails')
        ->and($html)->not->toContain('Write a review');
});

it('ships its schema with a guarded migration and a cache-clearing one', function () {
    expect(glob(base_path('database/migrations/*_create_review_requests.php')))->toHaveCount(1)
        ->and(glob(base_path('database/migrations/*_clear_caches_review_requests.php')))->toHaveCount(1);

    $src = (string) file_get_contents(glob(base_path('database/migrations/*_create_review_requests.php'))[0]);
    expect(substr_count($src, "Schema::hasTable("))->toBe(2);
});

<?php

/**
 * The admin's "Resend confirmation" button, now that there is something to
 * resend.
 *
 * It refused with "outbound email is not configured for this store" — written
 * when the store sent no email at all. That message became false the moment
 * order email shipped, and a button that refuses for a reason that is no
 * longer true is worse than one that is simply missing.
 */

use App\Models\AdminUser;
use App\Models\Order;
use App\Services\Mail\OrderMailer;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Mail;

function resendAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'Resend Owner',
        'email' => 'resend-' . uniqid() . '@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

function resendOrder(): Order
{
    return Order::create([
        'order_number' => 'RS-' . uniqid(),
        'email' => 'shopper@kbb.test',
        'status' => 'processing',
        'currency' => 'AED',
        'subtotal' => 20000,
        'total' => 20000,
    ]);
}

it('re-sends the receipt to the customer and says so', function () {
    Mail::fake();

    $result = app(OrderMailer::class)->resendConfirmation(resendOrder());

    expect($result['ok'])->toBeTrue()
        ->and($result['message'])->toContain('shopper@kbb.test');

    Mail::assertSent(\App\Mail\OrderConfirmation::class);
});

it('does not fire a second merchant alert', function () {
    // placed() sends both; a resend must not tell the owner a new order arrived.
    Mail::fake();

    app(OrderMailer::class)->resendConfirmation(resendOrder());

    Mail::assertNotSent(\App\Mail\NewOrderAlert::class);
});

it('refuses when the owner has switched confirmations off', function () {
    Mail::fake();

    app(SettingsService::class)->setModule('email_order_confirmation', false);

    $result = app(OrderMailer::class)->resendConfirmation(resendOrder());

    expect($result['ok'])->toBeFalse()
        ->and($result['message'])->toContain('Store → Modules');

    Mail::assertNotSent(\App\Mail\OrderConfirmation::class);
});

it('refuses an order with no address rather than throwing', function () {
    Mail::fake();

    $order = resendOrder();
    $order->forceFill(['email' => ''])->save();

    $result = app(OrderMailer::class)->resendConfirmation($order);

    expect($result['ok'])->toBeFalse()
        ->and($result['message'])->toContain('no email address');
});

it('reports failure through the admin endpoint instead of claiming success', function () {
    $admin = resendAdmin();
    $order = resendOrder();

    // Confirmations off is the simplest honest refusal to drive end to end.
    app(SettingsService::class)->setModule('email_order_confirmation', false);

    $this->actingAs($admin, 'admin')
        ->postJson('/admin-api/orders/' . $order->id . '/action', ['action' => 'resend_confirmation'])
        ->assertStatus(422)
        ->assertJson(['ok' => false]);
});

it('no longer claims outbound email is not configured', function () {
    $source = file_get_contents(app_path('Http/Controllers/Admin/AdminOrderController.php'));

    expect($source)->not->toContain('outbound email is not configured');
});

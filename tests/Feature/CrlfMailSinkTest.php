<?php

/**
 * A CR in a stored address must not reach the mail stack, now that one exists.
 *
 * Lane AC established that the Laravel 11 `email` rule accepts CRLF in two
 * shapes and concluded the advisory was unreachable — explicitly because
 * "there is no Mailable anywhere in app/". That was true when it looked. Lane
 * AB then added five, and OrderMailer sends to orders.email, which checkout
 * writes from the request. So the conclusion has to be re-established against
 * the tree that actually ships, not the one that was audited.
 *
 * Two independent defences are asserted, because either alone would be a
 * single point of failure and one of them is a transitive dependency nobody
 * chose:
 *   1. checkout refuses the address (StorefrontEmail, added here)
 *   2. symfony/mime refuses it at the sink even if something else writes it
 */

use App\Models\Order;
use Illuminate\Support\Facades\Mail;

/** The two payloads the installed Laravel 11 rule lets through. */
dataset('crlf addresses', [
    'CR in a quoted local part' => ["\"us\r\ner\"@example.com"],
    'CR inside folding whitespace' => ["user\r\n @example.com"],
]);

it('refuses a CRLF address at checkout', function (string $address) {
    // The rule is what decides; assert on the rule rather than driving a whole
    // checkout, so this cannot be broken by an unrelated checkout change.
    expect(\App\Rules\StorefrontEmail::passes($address))->toBeFalse();
})->with('crlf addresses');

it('cannot put a CRLF address into a mail header even if one is stored', function (string $address) {
    // Bypass validation entirely: write it straight onto the order, which is
    // what an import or a pre-existing row could do.
    $order = Order::create([
        'order_number' => 'CRLF-' . substr(md5($address), 0, 8),
        'email' => $address,
        'status' => 'processing',
        'currency' => 'AED',
        'subtotal' => 1000,
        'total' => 1000,
    ]);

    expect($order->email)->toBe($address);

    // The mail stack must refuse it rather than emit a folded header.
    $threw = false;

    try {
        Mail::to($order->email)->send(new class extends \Illuminate\Mail\Mailable {
            public function build()
            {
                return $this->subject('x')->html('<p>x</p>');
            }
        });
    } catch (\Throwable $e) {
        $threw = true;
        expect($e->getMessage())->toMatch('/control character|line break/i');
    }

    expect($threw)->toBeTrue('a CRLF address reached the mailer without being refused');
})->with('crlf addresses');

it('does not let a CRLF address break order placement', function () {
    // And the belt-and-braces that matters operationally: OrderMailer swallows
    // whatever the sink throws, so a bad stored address cannot take checkout out.
    $order = Order::create([
        'order_number' => 'CRLF-SAFE',
        'email' => "\"us\r\ner\"@example.com",
        'status' => 'processing',
        'currency' => 'AED',
        'subtotal' => 1000,
        'total' => 1000,
    ]);

    app(\App\Services\Mail\OrderMailer::class)->placed($order);

    expect(Order::where('order_number', 'CRLF-SAFE')->exists())->toBeTrue();
});

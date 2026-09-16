<?php

declare(strict_types=1);

/**
 * "Email us · no-reply@kbeautybliss.com" — Lane DG.
 *
 * ── THE CLAIM, AND WHY IT HAD TO BE MEASURED BEFORE IT COULD BE FIXED ───────
 *
 * App\Services\Mail\EmailBranding::support() ended its email chain at
 * MailSettings::fromAddress(), and that method DERIVES `no-reply@<domain>` from
 * APP_URL whenever the From box is empty — which is the shipped state of
 * Store → Mail, deliberately, because the server transport needs nothing filled
 * in. So a block headed "We are here if you need us" offered the customer a
 * live mailto: to a mailbox named for not being read.
 *
 * THAT IS THE SAME CLAIM THE FOOTER'S INVITATION TO REPLY WAS REMOVED FOR. The
 * sentence "reply to this message and it reaches us" was taken out of
 * emails/layout.blade.php by the audit lane for pointing at exactly this
 * address. It was removed from the footer and left standing two rows above it.
 *
 * ── AND WHY THIS SUITE COULD NOT SEE IT ─────────────────────────────────────
 *
 * APP_URL is `http://localhost` in the test environment. fromAddress() requires
 * a host containing a dot, so on the test box it returns '' and the email
 * channel is omitted for an entirely different reason — the right answer by
 * accident. EmailBrandingTest's own "shows no support block at all when nothing
 * is configured anywhere" says so in as many words.
 *
 * EVERY TEST BELOW THEREFORE SETS A REALISTIC APP_URL FIRST. Without that line
 * each of them passes against the broken code, which makes them worse than
 * having none: they would read as coverage of the thing they cannot see. This
 * is the difference between a code reading and a measurement, and the reason
 * the defect survived the lane that first spotted it.
 *
 * ── WHAT WAS DECIDED ────────────────────────────────────────────────────────
 *
 * `mail_reply_to` joins the chain ahead of the From address, because it is a
 * real mailbox the owner named for this exact purpose. And the DERIVED address
 * leaves the chain entirely, while a From address the owner TYPED stays: the
 * chain now reads the stored `mail_from_address` row rather than the method
 * that synthesises one. A shop that has set a real support email is untouched —
 * that value is still first.
 */

use App\Mail\OrderConfirmation;
use App\Models\Order;
use App\Models\Setting;
use App\Services\Mail\EmailBranding;
use App\Services\Mail\MailSettings;
use App\Services\SettingsService;

beforeEach(function () {
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();

    /*
     * THE LINE WITHOUT WHICH NONE OF THIS FILE MEANS ANYTHING. See the header:
     * the suite's own APP_URL has no dot in it, fromAddress() answers '' and
     * the channel is dropped for the wrong reason.
     */
    config(['app.url' => 'https://kbeautybliss.com']);
});

afterEach(function () {
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
});

function sbnOrder(): Order
{
    static $n = 0;
    $n++;

    $order = Order::create([
        'order_number' => 'KBB-SBN-' . $n,
        'email' => 'buyer-' . $n . '@example.com',
        'status' => 'processing',
        'currency' => 'AED',
        'billing_address' => ['first_name' => 'Aisha', 'last_name' => 'Khan', 'city' => 'Dubai', 'country' => 'AE'],
        'subtotal' => 20000,
        'shipping_total' => 0,
        'tax_total' => 0,
        'total' => 20000,
        'payment_method' => 'cod',
        'payment_method_title' => 'Cash on delivery',
    ]);

    $order->items()->create([
        'name' => 'Rice Daily Moisturizing Toner 150ml',
        'quantity' => 1, 'unit_price' => 20000, 'subtotal' => 20000, 'total' => 20000,
    ]);

    return $order->fresh('items');
}

/** Store → Mail, saved the way that screen saves it. */
function sbnMail(array $values): void
{
    app(MailSettings::class)->save($values);

    Setting::flushMap();
    SettingsService::forgetMemo();
}

/** The email channel of the support block, or null when there is none. */
function sbnEmailChannel(): ?array
{
    foreach (app(EmailBranding::class)->support() as $channel) {
        if ($channel['kind'] === 'email') {
            return $channel;
        }
    }

    return null;
}

/* ═══ the defect ══════════════════════════════════════════════════════════ */

/**
 * THE SHIPPED STATE. Nothing filled in, on a real domain.
 *
 * Asserted on the RENDERED EMAIL as well as on the array, because the array is
 * not what the customer reads: the mailto: href is, and it is the href that
 * turns a printed string into an invitation somebody acts on.
 */
it('never offers a derived no-reply mailbox as the address to write to', function () {
    expect(app(MailSettings::class)->fromAddress())->toBe(
        'no-reply@kbeautybliss.com',
        'the derived address is gone, so this test can no longer see what it was written for'
    );

    expect(sbnEmailChannel())->toBeNull(
        'the support block is advertising a no-reply mailbox as "Email us"'
    );

    $html = (string) (new OrderConfirmation(sbnOrder()))->render();

    expect(str_contains($html, 'no-reply@'))->toBeFalse(
        'a no-reply address reached the rendered email'
    );
    expect(str_contains($html, 'mailto:'))->toBeFalse(
        'the email carries a mailto: link to an address nobody reads'
    );
});

/**
 * And the state that made the fix a chain change rather than a deletion: the
 * owner HAS named the mailbox he reads, in the box whose help says "Where a
 * customer's reply to an order email goes", and the block still printed
 * no-reply@ over the top of it.
 */
it('writes to the reply-to address the owner named, rather than to nobody', function () {
    sbnMail(['mail_reply_to' => 'hello@kbeautybliss.com']);

    $channel = sbnEmailChannel();

    expect($channel)->not->toBeNull('an address the owner gave for replies is not being offered');
    expect($channel['value'])->toBe('hello@kbeautybliss.com');
    expect($channel['url'])->toBe('mailto:hello@kbeautybliss.com');

    $html = (string) (new OrderConfirmation(sbnOrder()))->render();

    expect(str_contains($html, 'mailto:hello@kbeautybliss.com'))->toBeTrue();
    expect(str_contains($html, 'no-reply@'))->toBeFalse();
});

/* ═══ and what must not move ══════════════════════════════════════════════ */

/**
 * A SHOP THAT ANSWERED THE SUPPORT BOX IS UNAFFECTED, in both directions: with
 * a reply-to set and without one. That value is first in the chain and nothing
 * below it is consulted, which is what makes this change safe to apply to a
 * shop that has already configured itself.
 */
it('leaves a shop that has set a real support email exactly as it was', function () {
    sbnMail(['mail_support_email' => 'care@kbeautybliss.com']);

    expect(sbnEmailChannel()['value'])->toBe('care@kbeautybliss.com');

    // And it still wins when a reply-to exists beside it.
    sbnMail([
        'mail_support_email' => 'care@kbeautybliss.com',
        'mail_reply_to' => 'hello@kbeautybliss.com',
    ]);

    expect(sbnEmailChannel()['value'])->toBe(
        'care@kbeautybliss.com',
        'the reply-to address displaced the support address the owner chose'
    );
});

/**
 * A From address the owner TYPED is a mailbox he chose and still stands. Only
 * the synthesised one was dropped, and the difference between the two is the
 * whole precision of this change: reading the stored row instead of the method
 * that derives from APP_URL.
 */
it('still uses a from address the owner typed himself', function () {
    sbnMail(['mail_from_address' => 'orders@kbeautybliss.com']);

    expect(sbnEmailChannel()['value'])->toBe(
        'orders@kbeautybliss.com',
        'an address the owner typed into the From box is no longer offered'
    );
});

/**
 * The other two channels are untouched by any of this. A change to one row of a
 * support block is exactly when the rest of the block stops being checked.
 */
it('leaves whatsapp and instagram alone', function () {
    app(SettingsService::class)->set('brand_whatsapp', '+971585052611');
    app(SettingsService::class)->set('social_instagram', '@kbeauty.bliss');
    SettingsService::forgetMemo();

    $kinds = array_column(app(EmailBranding::class)->support(), 'value', 'kind');

    expect($kinds)->toHaveKey('whatsapp');
    expect($kinds)->toHaveKey('instagram');
    expect(array_key_exists('email', $kinds))->toBeFalse(
        'the email row came back with no address configured anywhere'
    );
});

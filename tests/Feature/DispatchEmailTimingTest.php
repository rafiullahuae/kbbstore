<?php

declare(strict_types=1);

/**
 * The delivery window in the dispatch email is the owner's to write — Lane DE.
 *
 * ── WHAT WAS WRONG, AND WHAT WAS NOT ────────────────────────────────────────
 *
 * App\Mail\OrderStatusChanged carried a UAE delivery window as a hardcoded
 * constant. It is not false today. It is a promise about how long a parcel
 * takes, kept in a PHP file, while the owner's own editable delivery wording
 * lives in `delivery_default_text` / `delivery_texts` and is read by exactly one
 * class, App\Support\DeliveryLine. Rewrite the line on Store → Delivery &
 * Shipping and the dispatch email goes on saying the old thing, for ever, with
 * nothing anywhere to say it disagrees.
 *
 * ── WHY THE SETTING WAS NOT SIMPLY SUBSTITUTED IN ───────────────────────────
 *
 * THE TWO SENTENCES ARE ANCHORED TO DIFFERENT EVENTS, and that is not a
 * pedantic distinction on this shop. The email's window is measured FROM
 * DISPATCH — it is sent at the moment the parcel leaves. The storefront's line
 * is shown under Place order, before the order exists, and is therefore
 * measured FROM THE ORDER. The gap between the two is a real, configured
 * quantity here: Store → Ecommerce → Delivery carries `dispatch_cutoff_hour`
 * (15) and `dispatch_days`, and the product page builds an arrival date out of
 * them precisely because ordering and dispatching are not the same moment. An
 * order placed at 16:00 on a Thursday is not dispatched that day.
 *
 * So substituting one for the other would have made the dispatch email say
 * something subtly different from what it means, in the direction that shortens
 * a promise. The distinction survives contact with how this shop is actually
 * configured, so it is kept.
 *
 * ── WHAT IS BUILT INSTEAD ───────────────────────────────────────────────────
 *
 * A second box, on Store → Mail, labelled for what it is: what the dispatch
 * email says about timing, measured from dispatch. Blank by default, and blank
 * means the email says exactly what it says today, to the byte — asserted below
 * against OrderStatusChanged::WORDING itself rather than against a copy of the
 * sentence, so the "nothing changes until he edits it" claim is checked against
 * the thing it is a claim about.
 *
 * It governs the HOME-COUNTRY branch only. The Gulf branch quotes no window at
 * all, deliberately, because nobody has measured one; a box that filled that
 * silence in would be the invention the previous lane refused to make.
 */

use App\Mail\OrderStatusChanged;
use App\Models\Order;
use App\Models\Setting;
use App\Services\Mail\MailSettings;
use App\Services\SettingsService;

beforeEach(function () {
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
});

afterEach(function () {
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
});

/** Save through MailSettings, then the flush this codebase needs to be believed. */
function dsSave(array $values): void
{
    app(MailSettings::class)->save($values);
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
}

function dsOrder(string $country): Order
{
    $order = Order::create([
        'order_number' => 'KBB-DS-' . uniqid(),
        'email' => 'buyer@example.com',
        'status' => 'shipped',
        'currency' => 'AED',
        'billing_address' => ['first_name' => 'Aisha', 'last_name' => 'Khan', 'line1' => '12 Marina Walk', 'city' => 'Dubai', 'country' => $country],
        'shipping_address' => ['first_name' => 'Aisha', 'last_name' => 'Khan', 'line1' => '12 Marina Walk', 'city' => 'Dubai', 'country' => $country],
        'subtotal' => 20000,
        'shipping_total' => 2000,
        'total' => 22000,
        'shipping_method' => 'Standard delivery',
        'payment_method' => 'cod',
        'payment_method_title' => 'Cash on delivery',
    ]);

    $order->items()->create(['name' => 'Rice Toner', 'quantity' => 1, 'unit_price' => 20000, 'total' => 20000]);

    return $order->fresh('items');
}

/** The body sentence the dispatch email really renders, out of the message. */
function dsBody(string $country): string
{
    $mailable = new OrderStatusChanged(dsOrder($country), 'shipped');
    $content = $mailable->content();

    return (string) ($content->with['body'] ?? '');
}

/* ------------------------------------------------------ nothing moves by itself */

it('says today\'s exact sentence to a UAE customer while the box is blank', function () {
    /*
     * Asserted against the constant, not against a copy of it. A test that
     * quotes the sentence proves only that two strings in two files match; this
     * one proves the default IS the shipped wording, whatever that wording is.
     */
    expect(app(MailSettings::class)->get('mail_shipped_timing_note'))
        ->toBe('', 'the timing box does not ship blank');

    expect(dsBody('AE'))->toBe(OrderStatusChanged::WORDING['shipped'][2]);
});

it('still says nothing about timing to a Gulf customer while the box is blank', function () {
    $body = dsBody('SA');

    expect($body)->not->toBe(OrderStatusChanged::WORDING['shipped'][2]);
    expect(str_contains($body, 'working days'))
        ->toBeFalse('the Gulf dispatch email has grown a delivery window nobody measured');
});

/* ------------------------------------------------------------ once he writes one */

it('says what the owner wrote once he fills the box in', function () {
    dsSave(['mail_shipped_timing_note' => 'Most Dubai orders arrive the next working day once they leave us.']);

    $body = dsBody('AE');

    expect(str_contains($body, 'Most Dubai orders arrive the next working day once they leave us.'))
        ->toBeTrue('the owner rewrote the dispatch timing and the email ignored him');

    // The half that is true whatever he writes is still there, and the old
    // window is gone rather than printed beside the new one.
    expect(str_starts_with($body, 'Your order has left us and is with the courier.'))
        ->toBeTrue('the dispatch email stopped saying the parcel has left');
    expect(str_contains($body, 'one to three working days'))
        ->toBeFalse('the email now carries two delivery windows at once');
});

it('does not put the owner\'s UAE timing on a parcel going to Saudi Arabia', function () {
    // The reason the setting is not simply `delivery_default_text`: this line
    // describes one country, and the dispatch email already knows which
    // country it is writing about.
    dsSave(['mail_shipped_timing_note' => 'Most Dubai orders arrive the next working day once they leave us.']);

    expect(str_contains(dsBody('SA'), 'Most Dubai orders'))
        ->toBeFalse('a Saudi customer was told the UAE window');
});

it('leaves an order with no recorded destination with no window at all', function () {
    dsSave(['mail_shipped_timing_note' => 'Most Dubai orders arrive the next working day once they leave us.']);

    $order = Order::create([
        'order_number' => 'KBB-DS-' . uniqid(),
        'email' => 'buyer@example.com',
        'status' => 'shipped',
        'currency' => 'AED',
        'subtotal' => 20000, 'shipping_total' => 0, 'total' => 20000,
        'payment_method' => 'cod', 'payment_method_title' => 'Cash on delivery',
    ]);
    $order->items()->create(['name' => 'Rice Toner', 'quantity' => 1, 'unit_price' => 20000, 'total' => 20000]);

    $body = (string) ((new OrderStatusChanged($order->fresh('items'), 'shipped'))->content()->with['body'] ?? '');

    expect($body)->toBe('Your order has left us and is with the courier.');
});

/* ---------------------------------------------------------------- the screen */

it('offers the box on Store → Mail and says which clock it is measured on', function () {
    /*
     * A settings key that is not on this screen is a key the owner cannot
     * reach, which is the same as not having built it. And the help text has to
     * carry the anchor: the whole reason this is a second box rather than a
     * reuse of the storefront's line is that one is measured from dispatch and
     * the other from the order, and a box that does not say so invites exactly
     * the substitution that was refused.
     */
    expect(MailSettings::SCHEMA)->toHaveKey('mail_shipped_timing_note');

    [$type, $label, $help] = MailSettings::SCHEMA['mail_shipped_timing_note'];

    expect($type)->toBe('text');
    expect(stripos($label . ' ' . $help, 'dispatch'))->not->toBeFalse('the box never says what it is measured from');
    expect(stripos($help, 'order'))->not->toBeFalse('the help text does not distinguish the two anchors');
});

it('saves the box through the real Store → Mail endpoint and reads it back out of an email', function () {
    /*
     * THROUGH THE CONTROLLER, because a key missing from a validation allowlist
     * makes Save report success and write nothing — the fault this repo has
     * recorded twice. MailApiController checks what it is given against
     * MailSettings::SCHEMA, so a key added there needs no controller change;
     * that is the claim, and this is the proof of it.
     */
    $admin = App\Models\AdminUser::create([
        'name' => 'Owner', 'email' => 'ds-owner@example.com',
        'password' => 'secret-secret', 'role' => 'owner',
    ]);

    test()->actingAs($admin, 'admin')
        ->postJson('/admin-api/mail', ['settings' => [
            'mail_shipped_timing_note' => 'Abu Dhabi and Dubai next working day, other emirates two.',
        ]])
        ->assertOk()
        ->assertJson(['ok' => true]);

    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();

    expect(str_contains(dsBody('AE'), 'Abu Dhabi and Dubai next working day, other emirates two.'))
        ->toBeTrue('Store → Mail reported success and the dispatch email never saw the value');
});

it('reaches both parts of the message, not only the HTML one', function () {
    dsSave(['mail_shipped_timing_note' => 'Next working day across the UAE once dispatched.']);

    $mailable = new OrderStatusChanged(dsOrder('AE'), 'shipped');

    $html = (string) $mailable->render();

    $content = $mailable->content();
    $text = (string) view($content->text, array_merge($mailable->buildViewData(), $content->with))->render();

    expect(str_contains($html, 'Next working day across the UAE once dispatched.'))
        ->toBeTrue('the HTML dispatch email ignored the owner\'s wording');
    // The text part wraps the body at 72 columns, so the line breaks fall
    // wherever the sentence happens to reach them. Whitespace is collapsed
    // before the match rather than the needle being trimmed to fit, so this
    // asserts the whole sentence arrived and not a fragment of it.
    expect(str_contains(preg_replace('/\s+/', ' ', $text) ?? '', 'Next working day across the UAE once dispatched.'))
        ->toBeTrue('the text dispatch email ignored the owner\'s wording');
});

it('is capped, like every other operator sentence rendered into an email', function () {
    expect(MailSettings::MAX_LENGTHS)->toHaveKey('mail_shipped_timing_note');

    dsSave(['mail_shipped_timing_note' => str_repeat('x', 5000)]);

    expect(mb_strlen(app(MailSettings::class)->get('mail_shipped_timing_note')))
        ->toBeLessThanOrEqual(MailSettings::MAX_LENGTHS['mail_shipped_timing_note']);
});

it('escapes the owner\'s sentence rather than rendering it as markup', function () {
    dsSave(['mail_shipped_timing_note' => '<b>Next day</b> in the UAE.']);

    expect(str_contains((string) (new OrderStatusChanged(dsOrder('AE'), 'shipped'))->render(), '<b>Next day</b>'))
        ->toBeFalse('an operator sentence is being rendered into the email as markup');
});

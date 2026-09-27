<?php

declare(strict_types=1);

use App\Models\Order;
use App\Models\Payment;
use App\Services\Payments\PaymentRefunder;

/**
 * A released authorisation is not money the shop can give back.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * ── THE DEFECT ──────────────────────────────────────────────────────────────
 *
 * `PaymentRefunder::capturedFils()` never looked at `orders.voided_at`, and
 * every other condition it DOES look at is true of an order whose hold was
 * released:
 *
 *   paid_at         set — the authorisation was confirmed
 *   captured_at     null — it was never captured
 *   payments        carries a `paid` row for the authorisation
 *
 * So the ceiling came back non-zero and Store → Orders offered a refund of
 * money the shop never took, against a hold the provider had already given
 * back. It does not merely fail at the provider: RefundConsole and two
 * customer-facing emails treat that ceiling as the truth, so the shopper is
 * told a refund is on its way for money that never left his card.
 *
 * ── WHY ZEROING IT IS SAFE ──────────────────────────────────────────────────
 *
 * The two states cannot coexist. `PaymentVoider:127` refuses to release an
 * order carrying `captured_at` or a `capture_ref` — "This order has been
 * captured, so there is no authorisation to release. Refund it instead." So a
 * voided order has no captured money to protect, and the capture branch still
 * wins wherever there is some. The second case below pins exactly that, because
 * a fix that zeroed the ceiling for captured money would be a far worse bug
 * than the one being fixed.
 */
function voidOrder(array $attributes = []): Order
{
    return Order::create(array_merge([
        'order_number' => 'VOID-'.uniqid(),
        'status' => 'processing',
        'currency' => 'AED',
        'total' => 20000,
        'email' => 'void-'.uniqid().'@example.test',
        'paid_at' => now(),
    ], $attributes));
}

function voidPayment(Order $order, int $amount = 20000): Payment
{
    return Payment::create([
        'order_id' => $order->getKey(),
        'provider' => 'tabby',
        'provider_ref' => 'auth_'.uniqid(),
        'amount' => $amount,
        'currency' => 'AED',
        'status' => 'paid',
    ]);
}

it('offers nothing back on an authorisation that was released', function () {
    $order = voidOrder(['voided_at' => now()]);
    voidPayment($order);

    /*
     * AED 200.00 was authorised and then released. Not one fil of it ever left
     * the shopper, so not one fil of it can be given back.
     */
    expect(app(PaymentRefunder::class)->capturedFils($order->fresh()))->toBe(0);
});

/*
 * MUTATION: delete the `if ($voided) { return 0; }` branch from ceilingFrom()
 * and this is red with 20000 — the reported defect, to the fil. RUN: red.
 */

it('still offers captured money back, which a void can never have taken', function () {
    /*
     * THE HALF THAT MATTERS MORE. A fix that zeroed the ceiling whenever
     * `voided_at` was set would break every refund on a captured order, which
     * is a far worse bug than the one above. The capture branch runs FIRST and
     * this proves it, with both columns set at once — a state PaymentVoider
     * refuses to create, constructed here precisely because the ordering must
     * hold even if something else ever writes it.
     */
    $order = voidOrder([
        'captured_at' => now(),
        'captured_total' => 15000,
        'voided_at' => now(),
    ]);
    voidPayment($order);

    expect(app(PaymentRefunder::class)->capturedFils($order->fresh()))->toBe(15000);
});

/*
 * MUTATION: move the `if ($voided)` branch ABOVE the capture branch and this
 * is red with 0 — a captured order made unrefundable. RUN: red.
 */

it('is unchanged for an ordinary paid order that was never released', function () {
    /*
     * Rule 1: the fix must move nothing that already worked. This is the
     * ordinary path — paid, not captured through this lane, not voided — and it
     * still reads its ceiling off the confirmed payment row.
     */
    $order = voidOrder();
    voidPayment($order, 20000);

    expect(app(PaymentRefunder::class)->capturedFils($order->fresh()))->toBe(20000);
});

it('keeps the outstanding sweep in step with the single-order reader', function () {
    /*
     * THE SECOND CALL SITE, and the one that was easy to miss: the sweep that
     * reports how much is owed back across the shop calls ceilingFrom() too.
     *
     * Its query names its columns explicitly, and a column left out of an
     * explicit select() comes back NULL on the model rather than raising — so
     * omitting `voided_at` there would have left the rule silently inert in the
     * sweep while working in capturedFils(). A quiet wrong answer from a
     * missing word, which is why this case reads the select list itself.
     */
    $src = (string) file_get_contents(app_path('Services/Payments/PaymentRefunder.php'));

    $from = strpos($src, "->select(['id', 'order_number'");
    expect($from)->not->toBeFalse('the sweep no longer names its columns; check this still holds');

    expect(substr($src, (int) $from, 220))->toContain("'voided_at'");
});

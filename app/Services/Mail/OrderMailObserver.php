<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Models\Order;
use App\Models\Refund;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * What turns a status change into an email, without touching the code that makes
 * the change.
 *
 * WHY AN OBSERVER AND NOT A CALL IN THE CONTROLLER. `orders.status` is written
 * from four different places — AdminController::updateOrderStatus,
 * AdminOrderController::runAction, the cash-on-delivery gateway and
 * PaymentConfirmer — and every one of them lives in a directory this lane does
 * not own. More to the point, a notification wired into one of four call sites is
 * a notification that stops working the day a fifth appears. Eloquent's own event
 * is the single place all of them pass through.
 *
 * WHAT THIS DOES NOT CATCH, RECORDED SO IT IS NOT MISTAKEN FOR COVERAGE.
 * PaymentRefunder flips a fully-refunded order with a query-builder update
 * (`Order::query()->whereKey(...)->update([...])`), which fires no model events at
 * all. That is deliberate on both sides: the refund email is driven by the refunds
 * row settling — see the Refund half of this file — so the missing event costs
 * nothing, and a `refunded` status typed by hand in the admin mails nothing
 * because money typed into a status column has not moved.
 *
 * EVERYTHING GOES THROUGH DB::afterCommit(). If a status change ever happens
 * inside a transaction, sending from the observer would hold that transaction
 * open across an SMTP conversation — the same mistake CheckoutController's
 * comment warns about for gateway calls, and on a 20-second SMTP timeout it is a
 * 20-second table lock. afterCommit() runs the closure immediately when there is
 * no transaction, so the ordinary path is unchanged.
 *
 * AND NOTHING HERE MAY THROW. An exception in an `updated` observer propagates
 * into whatever saved the model: a failed email would turn the admin's "mark as
 * dispatched" button into a 500, and a failed refund email would surface as a
 * failed refund. OrderMailer already swallows transport failures; this catches
 * everything else — a missing view, a broken presenter, a null relation.
 */
class OrderMailObserver
{
    public function __construct(private OrderMailer $mailer) {}

    /** A status column that changed to something worth telling the customer about. */
    public function updated(Order $order): void
    {
        if (! $order->wasChanged('status')) {
            return;
        }

        $status = (string) $order->status;

        DB::afterCommit(function () use ($order, $status): void {
            try {
                $this->mailer->statusChanged($order, $status);
            } catch (\Throwable $e) {
                Log::error('order status mail failed', [
                    'order' => $order->order_number,
                    'status' => $status,
                    'exception' => class_basename($e),
                ]);
            }
        });
    }

    /**
     * Register both halves. Called from MailServiceProvider::boot().
     *
     * The Refund half is a closure rather than a second class because it is four
     * lines and because keeping it beside the comment that explains why the order
     * status does NOT drive the refund email is worth more than the symmetry.
     */
    public static function register(): void
    {
        Order::observe(static::class);

        /*
         * Money actually returned.
         *
         * TWO EVENTS, NOT `saved`, AND THE DIFFERENCE IS A DUPLICATE EMAIL.
         *
         * What stood here was one `Refund::saved` listener guarded by
         *
         *     if (! $refund->wasRecentlyCreated && ! $refund->wasChanged('status'))
         *
         * whose stated purpose was to stop "a later unrelated save (a provider
         * reference arriving, say) from sending a second one". It could not do
         * that, because `wasRecentlyCreated` is not scoped to the save that
         * created the row — Eloquent sets it true on insert and NEVER clears it
         * for the life of that instance. So on a refund created and then saved
         * again in the same request, which is every refund PaymentRefunder
         * handles, the first half of the guard stayed true forever and the
         * `wasChanged` half was never reached. Any subsequent save of a
         * succeeded refund — writing the provider reference, a retry stamping a
         * field, anything — mailed the customer a second "we have sent your
         * money back" for one refund. The one guard written to prevent the
         * duplicate was the reason it happened.
         *
         * Splitting the listener says what was meant without needing a flag:
         *
         *   created — the row arrived already `succeeded`. That is the
         *             synchronous-provider case the old comment was written for,
         *             and it is a genuine settlement. Mail it.
         *   updated — the row moved TO `succeeded` from something else. That is
         *             PaymentRefunder's ordinary pending → succeeded settle.
         *             `wasChanged('status')` is exact here: it is false for a
         *             save that touched other columns, so the provider
         *             reference arriving sends nothing.
         *
         * A failed refund mails nothing at all, from either event: telling a
         * customer their money is on the way when the gateway refused is worse
         * than telling them nothing.
         */
        Refund::created(static function (Refund $refund): void {
            if ((string) $refund->status !== 'succeeded') {
                return;
            }

            self::mailRefund($refund);
        });

        Refund::updated(static function (Refund $refund): void {
            if ((string) $refund->status !== 'succeeded' || ! $refund->wasChanged('status')) {
                return;
            }

            self::mailRefund($refund);
        });
    }

    /**
     * One settled refund, mailed after the transaction that settled it.
     *
     * Shared by both listeners above so the two cannot drift. Everything
     * DB::afterCommit() and the try/catch are here for is set out in this
     * class's header: never hold a transaction open across SMTP, and never let
     * a mail failure surface as a failed refund.
     */
    private static function mailRefund(Refund $refund): void
    {
        /*
         * A REFUND WOOCOMMERCE ALREADY MADE IS NOT NEWS.
         *
         * App\Services\Import\Entities\RefundImporter writes rows that arrive
         * already `succeeded` -- which is exactly the shape the `created`
         * listener above exists for -- so importing a five-year refund history
         * would tell several hundred real people, today, that their money is on
         * its way back. It is not: WooCommerce moved it years ago and emailed
         * them at the time.
         *
         * THE GUARD IS ON THE ROW, NOT ON THE IMPORT. OrderStatusMailPolicy's
         * suppression is process-scoped and, as its own header says, refund mail
         * has never passed through it. `wc_refund_id` is a permanent property of
         * the row instead: a refund that came from WooCommerce never mails, from
         * this importer, from a delta re-run, from a row touched by hand, or
         * from any later save that moves its status. That is the difference
         * between a guard that holds and one that holds while somebody remembers
         * to wrap the call.
         *
         * A refund this shop performed carries no `wc_refund_id` and is
         * unaffected -- see RefundImporter's header for why the two are told
         * apart this way and not by `provider`.
         */
        if ($refund->wc_refund_id !== null) {
            return;
        }

        DB::afterCommit(static function () use ($refund): void {
            try {
                $order = $refund->order;

                if ($order === null) {
                    return;
                }

                app(OrderMailer::class)->refunded($order, $refund);
            } catch (\Throwable $e) {
                Log::error('refund mail failed', [
                    'refund' => $refund->getKey(),
                    'exception' => class_basename($e),
                ]);
            }
        });
    }
}

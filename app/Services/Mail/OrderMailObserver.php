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
         * `saved` rather than `updated`, because PaymentRefunder writes the row
         * `pending` inside its locking transaction and settles it to `succeeded`
         * afterwards — but a provider that settles synchronously could in
         * principle create one already succeeded, and a refund email that depends
         * on which of two paths the gateway took is a refund email that goes
         * missing. The wasChanged guard keeps a later unrelated save (a provider
         * reference arriving, say) from sending a second one.
         */
        Refund::saved(static function (Refund $refund): void {
            if ((string) $refund->status !== 'succeeded') {
                return;
            }

            if (! $refund->wasRecentlyCreated && ! $refund->wasChanged('status')) {
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
        });
    }
}

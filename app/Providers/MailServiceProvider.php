<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Mail\MailConfigurator;
use App\Services\Mail\MailCredentials;
use App\Services\Mail\MailSettings;
use App\Services\Mail\MailTester;
use App\Services\Mail\OrderMailObserver;
use App\Services\Mail\OrderMailer;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the database-backed mail configuration into the framework's mailer.
 *
 * The whole reason this provider exists rather than a call in the one
 * controller that sends a test message: the five features this unblocks
 * (password reset, email verification, newsletter double opt-in, abandoned
 * cart, back-in-stock) mostly send through Laravel's own plumbing --
 * `Password::sendResetLink()` builds its own notification and reaches for the
 * DEFAULT mailer. If the configuration were only applied by whoever remembered
 * to apply it, each of those would need its own call and the first one that
 * forgot would silently log instead of send. That is exactly the failure mode
 * this package was created to end.
 *
 * Cost when nothing sends mail: none. The hook is `afterResolving`, so the
 * lookup happens the first time something asks the container for the mail
 * manager and never on a storefront page that does not.
 */
class MailServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
         * Scoped, not singleton. These memoise -- MailCredentials caches the
         * decrypted row -- and a singleton on a queue worker would keep serving
         * the configuration read during the first job after the owner changed
         * it. Same reasoning AppServiceProvider gives for CartService, and the
         * same trap CLAUDE.md records against Setting::map().
         */
        $this->app->scoped(MailCredentials::class);
        $this->app->scoped(MailSettings::class);
        $this->app->scoped(MailConfigurator::class);
        $this->app->scoped(MailTester::class);
        $this->app->scoped(OrderMailer::class);

        /*
         * SCOPED IS LOAD-BEARING HERE TOO, for the same shape of reason as
         * OrderStatusMailPolicy below.
         *
         * MailLog holds the id of the row it opened for the message currently
         * in flight, and the label the next message should carry. Bound
         * transient, the listener that opens the row and the listener that
         * closes it would be handed two different instances, every send would
         * be left recorded as 'sending', and the screen would report every
         * email the shop has ever sent as a failure.
         *
         * A singleton would be worse in the other direction: one open row
         * carried between requests under anything long-lived.
         */
        $this->app->scoped(\App\Services\Mail\MailLog::class);

        /*
         * SCOPED IS LOAD-BEARING HERE, not a performance choice like the rest.
         *
         * OrderStatusMailPolicy carries the operator's "email the customer
         * about this change" tick for the order in front of them, recorded by
         * the controller BEFORE the status is saved and read by
         * OrderMailObserver a moment later — and an Eloquent observer never
         * sees the request. One instance per request is the whole of what
         * connects the two. Bound transient, the controller would decide on one
         * copy and the observer would consult an empty one, and the tick box
         * would silently do nothing: the exact fault CLAUDE.md records this
         * project shipping three times.
         */
        $this->app->scoped(\App\Services\Mail\OrderStatusMailPolicy::class);

        $this->app->afterResolving('mail.manager', function ($manager) {
            /*
             * First, because it must happen whatever the settings lookup below
             * does. `kbb-server` is not one of MailManager's built-in drivers,
             * so without this the manager would throw "Unsupported mail
             * transport [kbb-server]" the moment anything tried to send -- and
             * the swallow in OrderMailer would turn that into a logged line and
             * a customer who never heard from the store. Registering a creator
             * is a single array write and builds nothing.
             *
             * The manager arrives as the callback's argument rather than being
             * fetched from the container, because this runs during that
             * container resolution.
             */
            try {
                MailConfigurator::registerTransports($manager);
            } catch (\Throwable) {
                // An older framework build without extend(). Leave `log` in place.
            }

            /*
             * Guarded, and guarded loudly in the comment rather than quietly in
             * the code: this runs during a request that wants to send mail, and
             * `mail_credentials` does not exist until this package's migration
             * has run. On a server where the package landed but the migration
             * did not -- which has happened here before, see
             * PackageMigrationFlagTest -- the table read throws. Swallowing it
             * leaves the framework's own `log` fallback in place, which is the
             * behaviour the app already had.
             */
            try {
                $this->app->make(MailConfigurator::class)->apply();
            } catch (\Throwable) {
                // Leave config/mail.php's inert 'kbb' => log entry in place.
            }
        });
    }

    /**
     * Hook the order emails to the models that trigger them.
     *
     * Registered here rather than in AppServiceProvider because this is mail
     * wiring and this is the mail provider: the order emails, the transport they
     * go out on and the settings that configure them are one feature, and a
     * reader looking for "what sends mail in this app" should find all of it in
     * one place. OrderMailObserver's own header explains why the trigger is a
     * model event and not a call in each of the four controllers that change an
     * order's status — none of which this lane owns.
     *
     * Cheap: registering an observer attaches listeners and reads nothing. No
     * settings lookup, no database query, and no mail manager resolved, so a
     * storefront page that changes no order still pays nothing for this.
     */
    public function boot(): void
    {
        OrderMailObserver::register();

        /*
         * The delivery record.
         *
         * Hooked to the framework's own mail events rather than to the senders,
         * for the reason MailLog's header sets out: mail leaves this
         * application from six places and several of them build their own
         * notification and reach for the default mailer without consulting
         * anything in app/Services/Mail. These two events are the only point
         * all of them pass through.
         *
         * Resolved out of the container inside the closure, not injected, so
         * registering the listener costs nothing on a storefront page that
         * sends no mail -- the same property the afterResolving hook above is
         * written to keep.
         */
        Event::listen(MessageSending::class, function (MessageSending $event): void {
            $this->app->make(\App\Services\Mail\MailLog::class)->recordSending($event);
        });

        Event::listen(MessageSent::class, function (MessageSent $event): void {
            $this->app->make(\App\Services\Mail\MailLog::class)->recordSent($event);
        });

        $this->bootOutbound();
    }

    /**
     * The two shopper-triggered emails: back-in-stock alerts and basket
     * reminders (Lane EN).
     *
     * REGISTERED HERE for the reason the paragraph above OrderMailObserver
     * gives about the order emails: this is mail wiring, and a reader looking
     * for "what sends mail in this app" should find all of it in one provider.
     * It is also the only place they CAN be registered — `bootstrap/` does not
     * ship in an update package on this host, so a new provider in
     * bootstrap/providers.php would never be loaded on the server.
     */
    private function bootOutbound(): void
    {
        /*
         * ── THE TRIGGER ─────────────────────────────────────────────────────
         *
         * There is no queue worker, no cron and no shell on this host, so a web
         * request is the only thing that ever executes PHP here. Every request
         * the application finishes asks whether a sweep is due.
         * Services\OutboundTick's header sets out what that forces, what it
         * costs, and what happens when nothing triggers for a week.
         *
         * RequestHandled and not a middleware: registering middleware means
         * editing bootstrap/app.php, which cannot ship. The listener does no
         * work itself — it hands off to `defer()`, so the sweep runs after the
         * response has gone to the browser and no shopper waits for SMTP.
         *
         * Resolved out of the container INSIDE the closure, not injected, so
         * attaching this listener costs nothing on a page that never reaches
         * it — the same property the afterResolving hook above is written to
         * keep. And with both modules off, which is the shipped state,
         * onRequest() is one array lookup against a settings cache the request
         * has already loaded.
         *
         * Guarded, because this is attached to the end of EVERY request in the
         * application including the checkout's: an exception escaping here
         * would surface as a 500 on a page that has already been rendered.
         */
        Event::listen(\Illuminate\Foundation\Http\Events\RequestHandled::class, function (): void {
            try {
                $this->app->make(\App\Services\OutboundTick::class)->onRequest();
            } catch (\Throwable) {
                // Deliberately silent. See OutboundTick::onRequest().
            }
        });

        /*
         * ── AN ORDER STOPS THE CHASE ────────────────────────────────────────
         *
         * A recovery email for an order already placed is worse than sending
         * nothing, and this is the barrier that matters most against it.
         *
         * `created` on Order, so it fires INSIDE Store\CheckoutController's
         * DB::transaction() — the cancellation commits with the order or rolls
         * back with it, and there is no instant at which an order exists and a
         * live recovery row for its address does not.
         *
         * An Eloquent event and not a call in the checkout for the reason
         * OrderMailObserver's header gives about order status: orders are
         * created from more than one place (the checkout and
         * Services\ManualOrderBuilder), several of them in directories this
         * lane does not own, and a hook wired into one call site stops working
         * the day a second appears.
         *
         * NOT wrapped in DB::afterCommit(), and that is the one place this
         * deliberately differs from OrderMailObserver. That class defers
         * because it SENDS, and sending inside a transaction holds it open
         * across an SMTP conversation. This writes one indexed UPDATE and must
         * happen inside the transaction, because being atomic with the order is
         * the entire point.
         *
         * NOTHING HERE MAY THROW: it runs inside the transaction that is
         * writing a customer's order, and CartRecovery::cancelForEmail()
         * swallows a missing table for exactly that reason. This catch is the
         * belt to that braces — a failed marketing suppression must never be
         * the thing that fails a sale.
         */
        \App\Models\Order::created(function (\App\Models\Order $order): void {
            try {
                $this->app->make(\App\Services\CartRecovery::class)
                    ->cancelForEmail((string) $order->email, 'ordered');
            } catch (\Throwable) {
                // See above. A sale must not fail because a reminder could not
                // be cancelled; the sweep's own cart-status check (barrier 4)
                // still catches the converted cart.
            }
        });
    }
}

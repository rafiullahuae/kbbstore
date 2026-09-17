<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Order;

/**
 * Records, and later restores, the language an order was placed in.
 *
 * ── WRITING IT: A MODEL HOOK, NOT A LINE IN THE CHECKOUT ────────────────────
 *
 * Orders are created in five places in this application —
 * Store\CheckoutController, Api\CheckoutController, ManualOrderBuilder,
 * DemoContentController and Payments\GatewayPreflight. A rule applied in four
 * of them is not a rule, and the one that gets missed is always the one a real
 * customer used. A `creating` hook covers all five, and the sixth that gets
 * written next month.
 *
 * It does not overwrite a locale the caller set explicitly, so the admin can
 * key in a phone order taken in Arabic and say so.
 *
 * ── READING IT BACK: THE HALF THAT ACTUALLY MATTERS ─────────────────────────
 *
 * The column is only worth having because of render(). An order confirmation is
 * sent in the request that placed the order, so it would be in the right
 * language by accident. Everything AFTERWARDS would not: the shipped email, the
 * refund notice, the invoice PDF regenerated months later from an admin screen.
 * Those run in a process whose locale is whatever it was last set to — English
 * — and the customer would get an Arabic checkout followed by English
 * paperwork forever.
 *
 * render() restores the order's language for the duration of one closure and
 * puts the previous one back afterwards, in a finally, so a throw inside the
 * closure cannot leave a queue worker set to Arabic for every later job.
 */
final class OrderLocale
{
    public static function listen(): void
    {
        Order::creating(static function (Order $order): void {
            /*
             * Only when nothing has been set. `isDirty` rather than a null
             * check, because the column has a database default of 'en': an
             * unsaved model reads 'en' whether that was chosen or merely not
             * chosen, and overwriting a deliberate 'en' is harmless while
             * overwriting a deliberate 'ar' is the bug this guard prevents.
             */
            if ($order->isDirty('locale')) {
                return;
            }

            $order->locale = Locale::current();
        });
    }

    /**
     * Run a closure in the language this order was placed in.
     *
     * The one line every later email, invoice and PDF needs:
     *
     *     OrderLocale::render($order, fn () => Mail::send(...));
     *
     * or, for a Mailable, Laravel's own $mailable->locale($order->locale),
     * which does the same thing through the framework.
     *
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    public static function render(Order $order, \Closure $callback): mixed
    {
        $locale = (string) ($order->locale ?? Locale::DEFAULT);

        if (! Locale::isSupported($locale)) {
            $locale = Locale::DEFAULT;
        }

        $previous = app()->getLocale();

        app()->setLocale($locale);

        try {
            return $callback();
        } finally {
            // In a finally, so a throw inside the closure cannot leave a queue
            // worker set to Arabic for every job after it.
            app()->setLocale($previous);
        }
    }
}

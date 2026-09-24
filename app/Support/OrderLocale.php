<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Order;
use App\Models\OrderItem;

/**
 * Records, and later restores, the language an order was placed in.
 *
 * TWO HOOKS AND ONE READER. listen() registers `Order::creating`, which records
 * the language, and `OrderItem::creating`, which snapshots each line's name in
 * it -- both for the same stated reason, that a rule applied in some of the
 * places that create these rows is not a rule. render() is the reader.
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

        /*
         * AND THE LINE NAMES, for the same reason and in the same place.
         *
         * `order_items.name` is a snapshot of the ENGLISH product name, always,
         * because Store\CheckoutController writes `$p?->name` -- the column.
         * Both checkout controllers, ManualOrderBuilder, AdminOrderController,
         * DemoContentController and AdminOrderController's re-order do it in six
         * separate places, which is the same argument the hook above is made of.
         *
         * WHAT THIS SNAPSHOT IS FOR, AND WHO READS THE OTHER ONE. The customer's
         * documents -- the invoice, the DELIVERY NOTE and every order email --
         * render inside render() below, so their furniture is already the
         * customer's language; the operator's (packing slip, dispatch label)
         * are deliberately not wrapped and stay English. `name` serves the
         * operator and keeps its exact meaning and value. `name_localised`
         * serves the customer, and is what they actually saw on the page they
         * bought from.
         *
         * The delivery note joined the first list after this comment was
         * written, and it is the clearest case of the rule rather than an
         * exception to it: it goes IN THE PARCEL, so the person who reads it is
         * the person who ordered. Admin\InvoiceController::deliveryNote()
         * carries that argument in full.
         *
         * IT COSTS NOTHING UNTIL THERE IS A SECOND LANGUAGE. With Arabic off --
         * how this ships -- enabledCodes() is ['en'], the guard returns, and no
         * query, no translation lookup and no write happens on any order. The
         * same test Locale::alternatePaths() applies, for the same reason.
         */
        OrderItem::creating(static function (OrderItem $item): void {
            if ($item->name_localised !== null) {
                return;
            }

            if (count(Locale::enabledCodes()) < 2) {
                return;
            }

            $locale = self::localeOf($item);

            if ($locale === Locale::DEFAULT) {
                return;
            }

            $product = $item->relationLoaded('product') ? $item->getRelation('product') : $item->product;

            if ($product === null) {
                return;
            }

            $translated = (string) $product->t('name', $locale);

            /*
             * A blank translation means untranslated and untranslated falls back
             * to English -- t() already does that, so `$translated` can only
             * equal the English column here. Storing that would be a second copy
             * of `name`, so the column stays NULL and the reader's `?? name`
             * answers. Null therefore means exactly one thing: this line has no
             * name of its own in the customer's language.
             */
            if ($translated === '' || $translated === (string) $item->name) {
                return;
            }

            $item->name_localised = $translated;
        });
    }

    /**
     * The language a line's order was placed in.
     *
     * The ORDER's locale, not the request's. An operator keying a phone order
     * taken in Arabic into the English admin is the case that separates the two,
     * and ManualOrderBuilder can set `locale` explicitly for exactly that.
     *
     * One primary-key lookup per line when the relation is not loaded, and only
     * ever inside the guard above -- so it is zero on this shop today, and on a
     * bilingual one it is N indexed reads on a checkout POST rather than on any
     * page StorefrontQueryBudgetTest measures.
     */
    private static function localeOf(OrderItem $item): string
    {
        $order = $item->relationLoaded('order') ? $item->getRelation('order') : $item->order;

        $locale = (string) ($order?->locale ?? Locale::DEFAULT);

        return Locale::isSupported($locale) ? $locale : Locale::DEFAULT;
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

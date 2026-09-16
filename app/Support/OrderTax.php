<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Order;

/**
 * What an order's OWN record says about its tax. One reader, for every document.
 *
 * ── WHY THIS IS READ FROM THE ORDER AND NEVER FROM THE SETTINGS ─────────────
 *
 * The invoice and the receipt both used to ask App\Support\VatDisplay, live, at
 * the moment of printing: `$this->vat->line((int) $order->total)`. That was
 * harmless while VAT was a display line at one global rate. It is not harmless
 * now. The owner can set Saudi Arabia to 5% today and 15% next year, and an
 * invoice that recomputes at print time would reprint every one of last year's
 * Saudi invoices at 15% — a misstatement of a tax figure on a document people
 * file with an authority. So the rate, the basis and the amount are snapshotted
 * onto the order when it is placed, and this class reads them back.
 *
 * ── NULL MEANS "THIS ORDER PREDATES THE TAX ENGINE", AND MUST KEEP MEANING IT ─
 *
 * Every order placed before this lane, and every order placed while the shop is
 * in the shipped default mode (tax_mode = 'display'), has `tax_basis` NULL.
 * recorded() answers null for those and every caller falls back to precisely
 * the code path it used before — including imported WooCommerce orders, which
 * carry a real `tax_total` with no rate beside it and print it as a row exactly
 * as they always have. Nothing is backfilled onto a historical order.
 *
 * ── THE TAXABLE BASE IS RECONSTRUCTED, NOT STORED ───────────────────────────
 *
 * subtotal - discount_total + shipping_total. That is the identical expression
 * CartService::totals() taxes, built from three columns the order already has,
 * so there is no fourth column to keep in step with them and no way for a
 * stored base to drift from the figures beside it. The fees (COD surcharge,
 * gift wrapping) are outside it there and outside it here.
 */
final class OrderTax
{
    /**
     * The base the tax was computed on, in fils.
     *
     * Exactly CartService::totals()'s `$taxableBase`, rebuilt from the columns
     * that method's own figures were written to.
     */
    public static function base(Order $order): int
    {
        return max(0, (int) $order->subtotal - (int) $order->discount_total) + (int) $order->shipping_total;
    }

    /**
     * The order's own tax record, or null when it has none.
     *
     *   rate    the percentage charged on the day
     *   basis   inclusive | exclusive | flat
     *   fils    the tax figure to print
     *   added   true when that figure is PART OF `total` as a row above it,
     *           false when it is a portion OF `total` and belongs under it as
     *           an "of which" note
     *
     * @return array{rate:float,basis:string,fils:int,added:bool}|null
     */
    public static function recorded(Order $order): ?array
    {
        $basis = $order->tax_basis;

        if (! is_string($basis) || ! in_array($basis, TaxRule::BASES, true)) {
            return null;
        }

        $rule = TaxRule::make($order->tax_rate, $basis);

        return [
            'rate' => $rule->rate,
            'basis' => $rule->basis,
            /*
             * `flat` charges nothing and contains nothing, so `tax_total` is 0
             * for it by design — the figure it prints has to be recomputed from
             * the rate the order recorded. Every other basis reads the column,
             * which is the figure that was actually charged or contained and
             * therefore the only one an invoice may state.
             */
            'fils' => $rule->basis === TaxRule::FLAT
                ? $rule->taxOn(self::base($order))
                : (int) $order->tax_total,
            'added' => $rule->addsToTotal(),
        ];
    }

    /** 5.00 -> "5", 7.50 -> "7.5" — the rate as the document should print it. */
    public static function printableRate(Order $order): ?string
    {
        $recorded = self::recorded($order);

        return $recorded === null ? null : (new TaxRule($recorded['rate'], $recorded['basis']))->printableRate();
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Import\Entities;

use App\Models\Customer;
use App\Models\Order;
use App\Services\Import\AddressWriter;
use App\Services\Import\Emails;
use App\Services\Import\ImportContext;
use App\Services\Import\Row;
use App\Services\Import\RowRejected;

/**
 * Orders, matched on `wc_order_id`.
 *
 * THE DATES ARE THE MOST IMPORTANT THING IN THIS FILE. Store -> Customers
 * derives "last order" as MAX(CASE WHEN status IN (...) THEN created_at END).
 * created_at, not paid_at. An importer that lets Eloquent stamp it does not
 * merely lose the order date: it makes every customer in the store look like
 * they bought something today, which reverses the meaning of every recency
 * segment and sort on that screen at once — silently, because the rows look
 * perfectly well-formed. So created_at, updated_at, paid_at and completed_at
 * are all set explicitly from the Woo dates, and an order with no readable
 * creation date is rejected rather than given one.
 *
 * EMAIL, AND WHERE THE PLACEHOLDER COMES FROM (D1). `orders.email` is NOT NULL.
 * An order with no address gets `wc-order-<wc_order_id>@import.invalid`:
 * deterministic, so the delta pass finds the same row rather than making
 * another, and on a TLD RFC 2606 reserves so that no receipt re-send, no
 * newsletter and no abandoned-cart sequence can ever reach a real person. Every
 * one is counted in the report, because an order nobody can be contacted about
 * is a fact the owner should see rather than discover.
 *
 * GUESTS (D3). A Woo guest order imports with customer_id NULL and then sits
 * outside every per-customer figure, because each of those is a join through
 * `customers`. On a store where most checkouts are guest checkouts that is most
 * of the money. Default: synthesise a customer row per distinct guest email and
 * link the order, which is what this application's own checkout already does —
 * Customer::firstOrCreate() makes a row for a shopper who does not sign in, so
 * a guest here is a customer with no usable credential rather than a missing
 * row. `--guests=unlinked` keeps them NULL for an owner who wants the older
 * shape. Orders whose email is a PLACEHOLDER never get one either way: that
 * would cluster every emailless order in the store onto a single synthetic
 * customer and invent a shopper who bought all of them.
 *
 * STATUS is free-form on purpose and stays verbatim — production carries
 * `shipped` and `tamara-p-failed` alongside the core set, and only
 * Order::REAL_STATUSES counts as revenue. The `wc-` prefix Woo writes in its
 * exports IS stripped, because the application's own statuses have no prefix
 * and `wc-completed` would be counted as revenue by nothing. A status this
 * application does not define is kept and NOTED, not rejected.
 */
final class OrderImporter extends EntityImporter
{
    /**
     * The statuses this application defines. Anything else is kept verbatim and
     * reported, because the column is free-form by design.
     *
     * @var list<string>
     */
    private const KNOWN_STATUSES = [
        'pending', 'processing', 'onhold', 'on-hold', 'completed', 'cancelled',
        'refunded', 'failed', 'shipped', 'draft', 'checkout-draft',
    ];

    public function name(): string
    {
        return 'orders';
    }

    public function conventionalFile(): string
    {
        return 'orders.csv';
    }

    public function import(Row $row, ImportContext $context): void
    {
        $wcOrderId = $row->requireId('order_id', 'order_id', 'id', 'wc_order_id', 'post_id');

        $createdAt = $row->date('date_created', $context->timezone(), 'date_created', 'order_date', 'post_date', 'created_at');

        if ($createdAt === null) {
            throw RowRejected::because(
                'date_created is empty. It is the column the Customers screen reads as "last order", so importing '
                .'this order without it would make its customer look like they bought something today.'
            );
        }

        $orderNumber = $this->orderNumber($row, $wcOrderId, $context);

        $order = Order::query()->withTrashed()->where('wc_order_id', $wcOrderId)->first();

        $this->guardOrderNumber($orderNumber, $wcOrderId);

        $email = Emails::normalise($row->raw('billing_email', 'email', 'customer_email'), 'billing_email');
        $synthesised = false;

        if ($email === null) {
            $email = Emails::placeholderForOrder($wcOrderId);
            $synthesised = true;

            $context->report->for($this->name())->note(
                'no email on the order; a reserved, undeliverable wc-order-<id>@import.invalid address was '
                .'synthesised so the NOT NULL column could be satisfied'
            );
        }

        $customerId = $this->resolveCustomer($row, $context, $email, $synthesised, $wcOrderId, $createdAt);

        $status = $this->status($row, $context);

        $attributes = [
            'wc_order_id' => $wcOrderId,
            'order_number' => $orderNumber,
            'customer_id' => $customerId,
            'email' => $email,
            'phone' => $row->text('billing_phone', 'phone'),
            'status' => $status,
            'currency' => $this->currency($row),
            'subtotal' => $row->moneyOrZero('subtotal', 'subtotal', 'order_subtotal', 'cart_subtotal'),
            'discount_total' => $row->moneyOrZero('discount_total', 'discount_total', 'cart_discount', 'order_discount'),
            'shipping_total' => $row->moneyOrZero('shipping_total', 'shipping_total', 'order_shipping'),
            'fee_total' => $row->moneyOrZero('fee_total', 'fee_total', 'order_fees'),
            'tax_total' => $row->moneyOrZero('tax_total', 'tax_total', 'order_tax'),
            'total' => $row->moneyOrZero('total', 'total', 'order_total'),
            'shipping_method' => $row->text('shipping_method', 'shipping_method_title'),
            'payment_method' => $row->text('payment_method'),
            'payment_method_title' => $row->text('payment_method_title'),
            'transaction_id' => $row->text('transaction_id'),
            'coupon_code' => $row->text('coupon_code', 'coupons', 'used_coupons'),
            'customer_note' => $row->text('customer_note', 'order_note', 'customer_message'),
            'origin' => $row->text('origin', 'created_via') ?? 'woocommerce-import',
            'billing_address' => $this->addressSnapshot($row, 'billing'),
            'shipping_address' => $this->addressSnapshot($row, 'shipping'),
            'paid_at' => $row->date('date_paid', $context->timezone(), 'date_paid', 'paid_date', 'paid_at'),
            'completed_at' => $row->date('date_completed', $context->timezone(), 'date_completed', 'completed_date', 'completed_at'),
            'created_at' => $createdAt,
            // Explicitly, and not left to Eloquent: an updated_at of now() on
            // every imported order makes "recently modified" meaningless the
            // moment the import finishes.
            'updated_at' => $row->date('date_modified', $context->timezone(), 'date_modified', 'post_modified', 'updated_at') ?? $createdAt,
        ];

        $invoiceNumber = $row->id('invoice_number', 'invoice_number', 'wf_invoice_number');

        if ($invoiceNumber !== null) {
            $this->guardInvoiceNumber($invoiceNumber, $wcOrderId);
            $attributes['invoice_number'] = $invoiceNumber;
            $attributes['invoiced_at'] = $row->date('invoiced_at', $context->timezone(), 'invoiced_at', 'invoice_date');
        }

        $order ??= new Order;

        $outcome = $context->apply($order, $attributes);

        $context->record($this->name(), $outcome);
        $context->remember('orders', $wcOrderId, (int) $order->id);

        // An order address can only be filed against a customer, because
        // addresses.customer_id is NOT NULL. An unlinked order's addresses live
        // in the order's own JSON snapshot above, which is what the order page
        // reads anyway, so nothing is lost.
        if ($customerId !== null) {
            AddressWriter::fromRow($row, $context, $customerId, 'order:'.$wcOrderId, $this->name());
        }
    }

    /**
     * Find, or under D3 create, the customer this order belongs to.
     */
    private function resolveCustomer(
        Row $row,
        ImportContext $context,
        string $email,
        bool $emailWasSynthesised,
        int $wcOrderId,
        \Carbon\CarbonImmutable $createdAt,
    ): ?int {
        $wpUserId = $row->id('customer_id', 'customer_id', 'customer_user', 'user_id', 'wp_user_id');

        if ($wpUserId !== null) {
            $existing = $context->localId('customers', $wpUserId);

            if ($existing !== null) {
                return $existing;
            }

            // The order names a WordPress user this import has never seen. Not
            // a rejection: an order whose customer was deleted in WordPress is
            // real money and belongs in the store's revenue. It falls through to
            // the guest path below, which either attaches it to the address it
            // does carry or leaves it unlinked — and either way it is noted, so
            // the owner can decide whether their customers export was complete.
            $context->report->for($this->name())->note(
                'the order names a WordPress user who is not in the customers import; '
                .'it was linked by email instead, or left unlinked'
            );
        }

        // A placeholder address is not a person. Linking these would gather
        // every emailless order in the store onto one synthetic customer.
        if ($emailWasSynthesised) {
            $context->report->for($this->name())->note(
                'left unlinked because its only address is the synthesised placeholder'
            );

            return null;
        }

        $existing = $context->customerIdForEmail($email);

        if ($existing !== null) {
            return $existing;
        }

        if (! $context->options->synthesiseGuests) {
            $context->report->for($this->name())->note(
                'guest order left with customer_id NULL (--guests=unlinked); it will not appear in any '
                .'per-customer figure, though Store -> Customers does report it as an unlinked order'
            );

            return null;
        }

        $customer = new Customer;

        $context->apply($customer, [
            'email' => $email,
            'name' => trim(($row->text('billing_first_name') ?? '').' '.($row->text('billing_last_name') ?? '')) ?: null,
            'first_name' => $row->text('billing_first_name'),
            'last_name' => $row->text('billing_last_name'),
            'phone' => $row->text('billing_phone'),
            // No wp_user_id: this shopper has no WordPress identity, and
            // inventing one would collide with a real user id later.
            'wp_user_id' => null,
            'notes' => 'Created by the WooCommerce import for guest order '.$wcOrderId.'.',
            // The date they first bought, not the date of the import.
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $context->record('customers', 'created');
        $context->report->for('customers')->note('synthesised from a guest order (decision D3)');
        $context->rememberEmail($email, (int) $customer->id);

        return (int) $customer->id;
    }

    /**
     * D5: the Woo order number or the Woo order id.
     *
     * @throws RowRejected
     */
    private function orderNumber(Row $row, int $wcOrderId, ImportContext $context): string
    {
        if ($context->options->orderNumberFrom === 'id') {
            return (string) $wcOrderId;
        }

        return $row->text('order_number', 'number') ?? (string) $wcOrderId;
    }

    /**
     * `orders.order_number` is UNIQUE NOT NULL.
     *
     * A store with a sequential-number plugin can have two orders whose display
     * numbers collide after a plugin reset; the driver would refuse the second
     * insert with a message naming neither order. Caught here so the reason
     * names both and points at the flag that resolves it.
     *
     * @throws RowRejected
     */
    private function guardOrderNumber(string $orderNumber, int $wcOrderId): void
    {
        $holder = Order::query()
            ->withTrashed()
            ->where('order_number', $orderNumber)
            ->where(function ($q) use ($wcOrderId): void {
                $q->whereNull('wc_order_id')->orWhere('wc_order_id', '!=', $wcOrderId);
            })
            ->first(['id', 'wc_order_id']);

        if ($holder === null) {
            return;
        }

        throw RowRejected::because(
            "order_number '".$orderNumber."' is already held by "
            .($holder->wc_order_id === null
                ? 'an order with no WooCommerce id (id '.$holder->id.')'
                : 'WooCommerce order '.$holder->wc_order_id)
            .'. orders.order_number is unique — run with --order-number=id to use the WooCommerce post id, '
            .'which cannot collide (decision D5 in docs/IMPORT-READINESS.md).'
        );
    }

    /**
     * @throws RowRejected
     */
    private function guardInvoiceNumber(int $invoiceNumber, int $wcOrderId): void
    {
        $exists = Order::query()
            ->withTrashed()
            ->where('invoice_number', $invoiceNumber)
            ->where(function ($q) use ($wcOrderId): void {
                $q->whereNull('wc_order_id')->orWhere('wc_order_id', '!=', $wcOrderId);
            })
            ->exists();

        if ($exists) {
            throw RowRejected::because(
                'invoice_number '.$invoiceNumber.' is already held by another order, and the column is unique. '
                .'The WebToffee sequence continues from MAX(invoice_number), so a duplicate here would also put '
                .'the next invoice on a collision course (decision D6).'
            );
        }
    }

    private function status(Row $row, ImportContext $context): string
    {
        $raw = mb_strtolower(trim((string) ($row->text('status', 'order_status', 'post_status') ?? 'pending')));

        // Woo writes wc-completed in exports; this application's statuses have
        // no prefix, and `wc-completed` would be counted as revenue by nothing.
        $status = str_starts_with($raw, 'wc-') ? substr($raw, 3) : $raw;

        if ($status === '') {
            return 'pending';
        }

        if (! in_array($status, self::KNOWN_STATUSES, true)) {
            $context->report->for($this->name())->note(
                "status '".$status."' is not one this application defines; kept verbatim (the column is free-form), "
                .'but note it is not in Order::REAL_STATUSES so it will NOT be counted as revenue'
            );
        }

        return $status;
    }

    private function currency(Row $row): string
    {
        $raw = mb_strtoupper((string) ($row->text('currency', 'order_currency') ?? 'AED'));

        // varchar(3) in Phase 0. A longer value truncates silently there and
        // fails loudly under MySQL strict mode on a repaired server; neither is
        // a good outcome, so anything that is not a three-letter code falls
        // back to the store currency rather than corrupting the column.
        return preg_match('/^[A-Z]{3}$/', $raw) === 1 ? $raw : 'AED';
    }

    /**
     * The JSON snapshot the order page renders from.
     *
     * A snapshot and not a join, deliberately: an order must keep saying what it
     * said on the day, even after the shopper edits their address book.
     *
     * @return array<string, string>|null
     */
    private function addressSnapshot(Row $row, string $type): ?array
    {
        $fields = [
            'first_name' => $row->text($type.'_first_name'),
            'last_name' => $row->text($type.'_last_name'),
            'company' => $row->text($type.'_company'),
            'line1' => $row->text($type.'_address_1', $type.'_line1'),
            'line2' => $row->text($type.'_address_2', $type.'_line2'),
            'city' => $row->text($type.'_city'),
            'state' => $row->text($type.'_state'),
            'postcode' => $row->text($type.'_postcode'),
            'country' => $row->text($type.'_country'),
            'phone' => $row->text($type.'_phone'),
        ];

        $fields = array_filter($fields, static fn (?string $v): bool => $v !== null);

        return $fields === [] ? null : $fields;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Import\Entities;

use App\Models\Customer;
use App\Services\Import\AddressWriter;
use App\Services\Import\Emails;
use App\Services\Import\ImportContext;
use App\Services\Import\Row;
use App\Services\Import\RowRejected;

/**
 * WordPress users who are shoppers, matched on `wp_user_id`.
 *
 * THREE THINGS THIS FILE IS CAREFUL ABOUT, ALL OF THEM THE EMAIL COLUMN.
 *
 * 1. It is lowercased, always. Checkout stores mb_strtolower($billing_email),
 *    so an imported `Buyer@Example.com` would not match that shopper's next
 *    checkout and they would silently acquire a second customer row with none
 *    of their order history on it.
 *
 * 2. Two WordPress users sharing one address cannot both become customer rows
 *    (D2). `customers.email` is UNIQUE NOT NULL, and `wp_user_id` is unique
 *    too, so they cannot be collapsed onto one row without discarding one of
 *    the two WordPress identities. The documented recommendation is to keep the
 *    lower wp_user_id and record the discarded one — but that is lossy and
 *    IMPORT-READINESS lists it as the owner's decision, so this importer does
 *    not make it: the second user is REJECTED, by name, with both ids in the
 *    reason. The owner reconciles the pair and re-runs. An importer that merges
 *    two people's accounts on its own initiative and mentions it in a log line
 *    is not something anyone should build.
 *
 * 3. The collision is detected in the importer's own bookkeeping rather than by
 *    letting the unique index raise it. This is the sharpest MySQL/SQLite
 *    divergence in the whole import: MySQL's utf8mb4_..._ci collation is
 *    case-INsensitive so `A@x.com` and `a@x.com` collide on the index there,
 *    while SQLite's BINARY collation is case-sensitive and accepts both. Doing
 *    the check here, against values that have already been lowercased, makes
 *    the behaviour identical on both engines instead of green in the suite and
 *    broken at cutover.
 *
 * PASSWORDS. `legacy_password` takes the WordPress phpass or wp-bcrypt hash and
 * `password` is left NULL, which is what CustomerAuthController expects: the
 * shopper signs in with their existing password and is upgraded to bcrypt on
 * first success. Writing the WP hash into `password` instead would be worse
 * than useless — the column is cast `hashed`, so assigning it re-hashes the
 * hash and nobody can ever sign in.
 *
 * NOT WRITTEN, EVER: orders_count, total_spent, last_order_at. They are
 * Customer::UNMAINTAINED_COLUMNS. Store -> Customers computes all three from
 * `orders` at query time using Order::REAL_STATUSES, which is correct by
 * construction; filling the columns would create a second source of truth that
 * agrees most of the time, which is worse than no second one because it gets
 * believed. assertNotWritingDerived() below makes that a hard error rather than
 * a convention.
 */
final class CustomerImporter extends EntityImporter
{
    public function name(): string
    {
        return 'customers';
    }

    public function conventionalFile(): string
    {
        return 'customers.csv';
    }

    public function import(Row $row, ImportContext $context): void
    {
        $wpUserId = $row->requireId('user_id', 'user_id', 'id', 'wp_user_id', 'customer_id');

        $email = Emails::normalise($row->raw('email', 'user_email', 'billing_email'), 'email');

        if ($email === null) {
            throw RowRejected::because(
                'email is empty, and customers.email is UNIQUE NOT NULL — this user cannot become a customer row. '
                .'If they have orders, those import with a synthesised wc-order-<id>@import.invalid address instead.'
            );
        }

        $holder = $context->customerIdForEmail($email);
        $customer = Customer::query()->withTrashed()->where('wp_user_id', $wpUserId)->first();

        if ($holder !== null && ($customer === null || (int) $customer->id !== $holder)) {
            $other = Customer::query()->withTrashed()->whereKey($holder)->value('wp_user_id');

            throw RowRejected::because(
                "email '".$email."' already belongs to "
                .($other === null ? 'a customer with no WordPress id (id '.$holder.')' : 'WordPress user '.$other)
                .'. customers.email is UNIQUE NOT NULL and wp_user_id is unique too, so these two users cannot '
                .'both exist here and cannot be merged without discarding one WordPress identity. '
                .'Decide which one keeps the address (decision D2 in docs/IMPORT-READINESS.md) and re-run.'
            );
        }

        $customer ??= new Customer;

        $first = $row->text('first_name', 'billing_first_name');
        $last = $row->text('last_name', 'billing_last_name');
        $name = $row->text('name', 'display_name', 'user_nicename')
            ?? trim(($first ?? '').' '.($last ?? ''));

        $attributes = [
            'wp_user_id' => $wpUserId,
            'email' => $email,
            'name' => $name === '' ? null : $name,
            'first_name' => $first,
            'last_name' => $last,
            'phone' => $row->text('phone', 'billing_phone'),
            // The WordPress hash, so the shopper keeps their password and is
            // upgraded to bcrypt on first sign-in. `password` stays NULL: it is
            // cast `hashed`, so assigning a hash to it would hash the hash.
            'legacy_password' => $row->text('password_hash', 'user_pass', 'legacy_password'),
        ];

        /*
         * created_at from user_registered. Store -> Customers shows this as
         * "registered" and offers date filters and oldest/newest sorts over it,
         * so an import that stamps today makes a four-year-old customer base
         * look like it was all acquired this morning.
         */
        $registered = $row->date('registered', $context->timezone(), 'registered', 'user_registered', 'date_created', 'created_at');

        if ($registered !== null) {
            $attributes['created_at'] = $registered;
        }

        $this->assertNotWritingDerived($attributes);

        $outcome = $context->apply($customer, $attributes);

        $context->record($this->name(), $outcome);
        $context->remember('customers', $wpUserId, (int) $customer->id);
        $context->rememberEmail($email, (int) $customer->id);

        // Billing and shipping arrive as columns on the same row: WooCommerce
        // has no addresses table, they are loose keys in wp_usermeta.
        AddressWriter::fromRow(
            $row,
            $context,
            (int) $customer->id,
            'user:'.$wpUserId,
            $this->name(),
        );
    }

    /**
     * The three columns Customer::UNMAINTAINED_COLUMNS names.
     *
     * A hard failure, not a filter: if a future edit adds one of these to the
     * attribute array, the run stops and says so rather than quietly beginning
     * to maintain a figure the Customers screen already derives correctly.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function assertNotWritingDerived(array $attributes): void
    {
        $offending = array_intersect(array_keys($attributes), Customer::UNMAINTAINED_COLUMNS);

        if ($offending !== []) {
            throw new \LogicException(
                'The importer must not write '.implode(', ', $offending).'. '
                .'Store -> Customers derives all of these from `orders` with Order::REAL_STATUSES; '
                .'a second, cached source of truth that agrees most of the time is worse than none, '
                .'because it gets believed. See Customer::UNMAINTAINED_COLUMNS.'
            );
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Models\Address;

/**
 * Billing and shipping addresses, written from whichever row carries them.
 *
 * WooCommerce has no addresses table. A user's billing and shipping addresses
 * are loose keys in wp_usermeta, and an order's are order meta, so in every
 * export they arrive as `billing_*` and `shipping_*` COLUMNS on the customer or
 * order row. Both callers therefore have the same shape and share this writer;
 * only the key prefix differs.
 *
 * `source_key` IS THE WHOLE POINT. Before it existed, a second pass of the
 * address import duplicated every row, because there was nothing to match on.
 * There is no integer that identifies a WooCommerce address, so the key is
 * composed, and the composition rule is the importer's contract with
 * docs/IMPORT-READINESS.md:
 *
 *     user:<wp_user_id>:billing      order:<wc_order_id>:billing
 *     user:<wp_user_id>:shipping     order:<wc_order_id>:shipping
 *
 * Any deterministic scheme would work; this one is readable in a database
 * client, which is what matters when something has gone wrong at 2am.
 *
 * COUNTRY IS THE TRAP, and it is worth stating plainly. Phase 0 declares
 * `addresses.country` as varchar(2) with a default of 'AE'; the repair
 * migration declares it varchar(255) nullable. Which one a given server has
 * depends on when the column was created. So a three-letter code like 'ARE'
 * succeeds on a repaired server and is SILENTLY TRUNCATED to 'AR' — Argentina —
 * on a Phase 0 one, and the shipping-zone tables, which match on alpha-2, then
 * quietly stop matching. Anything that is not two letters is rejected here so
 * the behaviour is the same on both.
 *
 * is_default: the first address of each type for a customer gets it, because
 * the account area has nothing to show as a default otherwise. Set only when no
 * address of that type already has it, so a customer who has since chosen a
 * different default in their account does not have that choice overwritten by
 * the next delta pass.
 */
final class AddressWriter
{
    /**
     * Write whichever of the two addresses the row actually carries.
     *
     * @param  string  $keyPrefix  "user:412" or "order:10233"
     *
     * @throws RowRejected
     */
    public static function fromRow(
        Row $row,
        ImportContext $context,
        int $customerId,
        string $keyPrefix,
        string $reportEntity,
    ): void {
        foreach (['billing', 'shipping'] as $type) {
            $fields = self::readType($row, $type);

            // An address with nothing in it is not an address. Woo rows
            // routinely carry empty shipping columns for a billing-only order,
            // and writing a row of nulls would put an empty card in the
            // shopper's address book.
            if (self::isEmpty($fields)) {
                continue;
            }

            /*
             * A bad address refuses the ADDRESS, not the customer or the order
             * that carried it. Letting it propagate would roll back the row's
             * savepoint and lose a real customer over a three-letter country
             * code — a refusal wildly out of proportion to the defect, and one
             * that would take their orders' only link with it. Recorded against
             * `addresses` with the same line number and the same reason as any
             * other refusal, so it is just as visible.
             */
            try {
                $fields['country'] = self::country($fields['country'], $type);
            } catch (RowRejected $e) {
                $context->report->for('addresses')->reject(
                    $row->line,
                    $keyPrefix.':'.$type,
                    $e->getMessage().' (the '.$reportEntity.' row itself was imported)',
                );

                continue;
            }

            $sourceKey = $keyPrefix.':'.$type;

            $address = Address::query()->where('source_key', $sourceKey)->first() ?? new Address;

            $attributes = $fields + [
                'source_key' => $sourceKey,
                'type' => $type,
                'customer_id' => $customerId,
            ];

            if (! $address->exists) {
                $attributes['is_default'] = ! Address::query()
                    ->where('customer_id', $customerId)
                    ->where('type', $type)
                    ->where('is_default', true)
                    ->exists();
            }

            $outcome = $context->apply($address, $attributes);

            $context->record('addresses', $outcome);
        }
    }

    /**
     * @return array<string, string|null>
     */
    private static function readType(Row $row, string $type): array
    {
        return [
            'first_name' => $row->text($type.'_first_name', $type.'_firstname'),
            'last_name' => $row->text($type.'_last_name', $type.'_lastname'),
            'company' => $row->text($type.'_company'),
            'line1' => $row->text($type.'_address_1', $type.'_line1', $type.'_address1', $type.'_street'),
            'line2' => $row->text($type.'_address_2', $type.'_line2', $type.'_address2'),
            'city' => $row->text($type.'_city'),
            'state' => $row->text($type.'_state', $type.'_emirate'),
            'postcode' => $row->text($type.'_postcode', $type.'_zip'),
            'country' => $row->text($type.'_country'),
            // Woo carries no shipping phone on older orders; the billing one is
            // NOT borrowed for it, because a shipping card showing a number the
            // shopper never gave for that address is worse than showing none.
            'phone' => $row->text($type.'_phone'),
        ];
    }

    /**
     * @param  array<string, string|null>  $fields
     */
    private static function isEmpty(array $fields): bool
    {
        foreach (['line1', 'line2', 'city', 'postcode', 'state'] as $key) {
            if (($fields[$key] ?? null) !== null) {
                return false;
            }
        }

        return true;
    }

    /**
     * ISO 3166-1 alpha-2, or a rejection. See the class comment.
     *
     * @throws RowRejected
     */
    private static function country(?string $raw, string $type): ?string
    {
        if ($raw === null) {
            return null;
        }

        $value = mb_strtoupper(trim($raw));

        if (preg_match('/^[A-Z]{2}$/', $value) === 1) {
            return $value;
        }

        throw RowRejected::because(
            $type."_country: '".$raw."' is not an ISO 3166-1 alpha-2 code. "
            .'addresses.country is varchar(2) on a Phase 0 server, so a longer code would be truncated to its '
            .'first two letters and the shipping zones, which match on alpha-2, would silently stop matching.'
        );
    }
}

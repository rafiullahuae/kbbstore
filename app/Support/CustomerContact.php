<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Store -> Customers -> (a customer) -> Edit email / phone. (Lane CE)
 *
 * The rules the correction is made under, in one place so the controller and
 * its tests cannot hold two versions of them.
 *
 * EMAIL. Validated with App\Rules\StorefrontEmail, the exact rule the checkout
 * accepts an address with, after trimming and lower-casing the DOMAIN. The
 * local part keeps its case: it is the mailbox's business, and every lookup in
 * this shop compares LOWER(email) anyway.
 *
 * PHONE. The checkout stores the typed number as it came (string, max 40,
 * trimmed by the framework) and does not reformat it. The order screen's own
 * phone edit (OrderAddress::rules / merge) adds a character class and collapses
 * runs of whitespace; this is that rule, so a phone corrected here looks
 * exactly like one corrected on an order.
 */
final class CustomerContact
{
    /** The checkout's own limit on billing_email. */
    public const EMAIL_MAX = 160;

    /** The checkout's own limit on billing_phone. */
    public const PHONE_MAX = 40;

    /** OrderAddress::rules()'s phone character class. */
    public const PHONE_REGEX = '/^[0-9+()\-.\s]*$/';

    public static function normaliseEmail(mixed $value): string
    {
        $value = trim((string) $value);
        $at = strrpos($value, '@');

        if ($at === false) {
            return $value;
        }

        return substr($value, 0, $at) . '@' . strtolower(substr($value, $at + 1));
    }

    /** Null for blank, else trimmed with whitespace runs collapsed (OrderAddress::merge). */
    public static function normalisePhone(mixed $value): ?string
    {
        $value = trim(preg_replace('/\s+/u', ' ', (string) ($value ?? '')) ?? '');

        return $value === '' ? null : $value;
    }

    /**
     * This customer's orders, and nobody else's.
     *
     * Every order the checkout writes carries customer_id, guest or not
     * (CheckoutController::customerForGuestOrder files a guest under the row
     * for their address). An order with NO customer_id -- an old import -- is
     * this person's only when it carries their old address. An order filed
     * under ANOTHER customer is never reached, whatever email it carries.
     */
    public static function ordersOf(int $customerId, string $oldEmail): Builder
    {
        $old = mb_strtolower(trim($oldEmail));

        return DB::table('orders')
            ->whereNull('deleted_at')
            ->where(function (Builder $q) use ($customerId, $old) {
                $q->where('customer_id', $customerId)
                    ->orWhere(function (Builder $q) use ($old) {
                        $q->whereNull('customer_id')->whereRaw('LOWER(email) = ?', [$old]);
                    });
            });
    }

    /** Case-insensitive equality, the way every email lookup here compares. */
    public static function sameEmail(?string $a, ?string $b): bool
    {
        return mb_strtolower(trim((string) $a)) === mb_strtolower(trim((string) $b));
    }

    /** Phones compare after the same normalisation they are saved with. */
    public static function samePhone(?string $a, ?string $b): bool
    {
        return self::normalisePhone($a) === self::normalisePhone($b);
    }
}

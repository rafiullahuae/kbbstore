<?php

declare(strict_types=1);

namespace App\Services\Import;

/**
 * The one place an email address is allowed to be normalised, and the one place
 * a placeholder address is allowed to be invented.
 *
 * LOWERCASING IS NOT COSMETIC HERE. The storefront's checkout stores
 * mb_strtolower($data['billing_email']). An imported `Buyer@Example.com` would
 * therefore not match that shopper's next checkout as `buyer@example.com`, and
 * they would end up with a second customer row and an order history that had
 * apparently vanished. Every address goes through normalise() before it reaches
 * `customers.email`, no exceptions.
 *
 * AND IT IS THE SHARPEST MYSQL/SQLITE DIVERGENCE IN THE WHOLE IMPORT.
 * `customers.email` is UNIQUE NOT NULL. MySQL's default utf8mb4_..._ci
 * collation is case-INsensitive, so on production `A@x.com` and `a@x.com`
 * collide on that index and the second insert is refused. SQLite's default
 * BINARY collation is case-sensitive, so under the test suite both insert
 * happily and the suite stays green. An importer that lowercases everything on
 * the way in cannot tell the difference, which is exactly the point: it behaves
 * identically on both engines instead of discovering the divergence on the live
 * server at cutover. WooImportTest asserts the collision is caught by the
 * importer's own bookkeeping, so the assertion holds on both engines rather
 * than relying on the index to raise it.
 *
 * PLACEHOLDERS (decision D1 in docs/IMPORT-READINESS.md). An order with no
 * email cannot produce a customer row, because the column is NOT NULL, and
 * cannot be left blank either, because `orders.email` is NOT NULL too. So one
 * is synthesised: `wc-order-<wc_order_id>@import.invalid`.
 *
 *  - `.invalid` is reserved by RFC 2606 and is guaranteed never to resolve, so
 *    a stray mailing — a receipt re-send, a newsletter, an abandoned-cart
 *    sequence — cannot reach a real person. A made-up address on a real domain
 *    can, and would be somebody else's inbox.
 *  - It is deterministic, so the delta pass and the cutover pass find the row
 *    the first pass made instead of inventing a second one.
 *  - It is recognisable, which is what isPlaceholder() is for: these orders are
 *    counted separately in the report, and they deliberately do NOT get a
 *    synthetic customer, because clustering every emailless order onto one
 *    customer row would invent a shopper who bought all of them.
 */
final class Emails
{
    /** RFC 2606 reserves this TLD precisely so it can never be delivered to. */
    public const PLACEHOLDER_DOMAIN = 'import.invalid';

    /** `customers.email` and `orders.email` are both varchar(255). */
    public const MAX_LENGTH = 255;

    /**
     * Trim, lowercase, and validate. Null for an empty cell.
     *
     * @throws RowRejected when the cell holds something that is not an address
     */
    public static function normalise(mixed $raw, string $field): ?string
    {
        if ($raw === null) {
            return null;
        }

        $value = trim((string) $raw);

        if ($value === '') {
            return null;
        }

        $value = mb_strtolower($value);

        if (mb_strlen($value) > self::MAX_LENGTH) {
            throw RowRejected::because(
                $field.": '".$value."' is longer than the ".self::MAX_LENGTH.'-character column'
            );
        }

        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw RowRejected::because($field.": '".$raw."' is not an email address");
        }

        return $value;
    }

    /**
     * The deterministic stand-in for an order that carries no address (D1).
     */
    public static function placeholderForOrder(int $wcOrderId): string
    {
        return 'wc-order-'.$wcOrderId.'@'.self::PLACEHOLDER_DOMAIN;
    }

    public static function isPlaceholder(?string $email): bool
    {
        return $email !== null && str_ends_with($email, '@'.self::PLACEHOLDER_DOMAIN);
    }
}

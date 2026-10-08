<?php

declare(strict_types=1);

namespace App\Services\Payments;

/**
 * The words Stripe puts on a card statement and in its own dashboard. (Lane SR.)
 *
 * Pure functions only: validation for the admin's save, and the strings
 * StripeGateway::openIntent() sends. Nothing here reads a setting or a request.
 *
 * ── WHAT STRIPE ALLOWS, AND WHERE EACH RULE COMES FROM ────────────────────
 *
 * Stripe's documentation on statement descriptors and the PaymentIntent API
 * reference (both read for this change; this repository cannot reach Stripe,
 * so none of it is proven against the live API):
 *
 *   statement_descriptor        5–22 characters, Latin characters only, at
 *                               least one letter, none of  < > \ ' " *
 *                               On a PaymentIntent it is for NON-CARD charges:
 *                               "Setting this value for a card charge returns
 *                               an error. For card charges, set the
 *                               statement_descriptor_suffix instead."
 *   statement_descriptor_suffix appended to the account's descriptor PREFIX
 *                               (2–10 characters, set in the Stripe Dashboard)
 *                               as  PREFIX* SUFFIX ; the whole line is at most
 *                               22 characters, same character rules.
 *
 * This shop takes cards only (payment_method_types = ['card']), so the suffix
 * is what it sends and the full descriptor is never put on a card intent.
 */
final class StripePaymentText
{
    public const FULL_MIN = 5;

    public const MAX = 22;

    /** Stripe's longest shortened-descriptor prefix, assumed when ours is unknown. */
    public const PREFIX_MAX = 10;

    public const PLACEHOLDERS = ['{number}', '{order_number}', '{shop}'];

    public const DEFAULT_DESCRIPTION = 'Order {number}';

    private const FORBIDDEN = ['<', '>', '\\', "'", '"', '*'];

    /** Null when acceptable, else a sentence for the owner. */
    public static function fullDescriptorError(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $length = strlen($value);

        if ($length < self::FULL_MIN || $length > self::MAX) {
            return sprintf('Statement descriptor must be %d to %d characters (it is %d).', self::FULL_MIN, self::MAX, mb_strlen($value));
        }

        return self::characterError('Statement descriptor', $value);
    }

    /** Null when acceptable. $prefixLength is the account's prefix, when known. */
    public static function suffixError(string $value, ?int $prefixLength = null): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $room = self::MAX - 2 - ($prefixLength ?? 2);

        if (strlen($value) > $room) {
            return $prefixLength === null
                ? sprintf('Statement descriptor suffix can be at most %d characters.', $room)
                : sprintf('Statement descriptor suffix can be at most %d characters: your Stripe prefix is %d, and "PREFIX* SUFFIX" must fit in 22.', $room, $prefixLength);
        }

        return self::characterError('Statement descriptor suffix', $value);
    }

    /** The order-reference prefix ("KBB-"): short, and plain enough for any screen. */
    public static function referencePrefixError(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (strlen($value) > 12 || ! preg_match('/^[A-Za-z0-9#\/._-]+$/', $value)) {
            return 'Order number prefix: up to 12 letters, digits or # / . _ - (no spaces).';
        }

        return null;
    }

    public static function descriptionError(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > 200) {
            return 'Payment description can be at most 200 characters.';
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $value)) {
            return 'Payment description must be one line of plain text.';
        }

        preg_match_all('/\{[^}]*\}/', $value, $found);

        $unknown = array_diff($found[0] ?? [], self::PLACEHOLDERS);

        if ($unknown !== []) {
            return 'Payment description: unknown placeholder ' . implode(', ', array_unique($unknown))
                . '. Use ' . implode(', ', self::PLACEHOLDERS) . '.';
        }

        return null;
    }

    /**
     * A valid full descriptor made from the shop's name, or '' if none can be.
     *
     * "K-Beauty Bliss" stays "K-Beauty Bliss"; accents are transliterated,
     * reserved characters dropped, runs of spaces folded, and the result cut
     * to 22 characters on a word boundary where one exists.
     */
    public static function defaultFullDescriptor(string $shopName): string
    {
        $value = self::latin($shopName);

        if (strlen($value) > self::MAX) {
            $cut = substr($value, 0, self::MAX);
            $space = strrpos($cut, ' ');
            $value = rtrim($space !== false && $space >= self::FULL_MIN ? substr($cut, 0, $space) : $cut);
        }

        return self::fullDescriptorError($value) === null && $value !== '' ? $value : '';
    }

    /**
     * The suffix for one card payment, or null to send none.
     *
     * The order number is never the part that is cut: it is the reason the
     * option exists, so when the line is too long the owner's own text gives
     * way first, then (only if the number alone overflows) the number is cut
     * from the left so its distinguishing tail survives.
     */
    public static function suffix(string $text, bool $withOrderNumber, string $orderNumber, ?int $prefixLength = null): ?string
    {
        $room = self::MAX - 2 - ($prefixLength ?? self::PREFIX_MAX);
        $text = self::latin($text);
        $number = $withOrderNumber ? self::latin($orderNumber) : '';

        if ($number !== '' && strlen($number) > $room) {
            $number = substr($number, -$room);
        }

        if ($number !== '') {
            $budget = $room - strlen($number) - 1;
            $text = $budget > 0 ? rtrim(substr($text, 0, $budget)) : '';
            $suffix = trim($text . ' ' . $number);
        } else {
            $suffix = rtrim(substr($text, 0, $room));
        }

        if ($suffix !== '' && ! preg_match('/[A-Za-z]/', $suffix)) {
            $suffix = self::lettered($suffix, $room);
        }

        return $suffix !== '' ? $suffix : null;
    }

    /**
     * A suffix with no letter in it, given one. (Lane ST.)
     *
     * Stripe's statement-descriptor rules: "If you use a prefix and a suffix,
     * both require at least one letter." "Add the order number to card
     * statements" with no suffix text sent the bare number -- "10234" -- and
     * Stripe refused the PaymentIntent, which the shopper read as "We could
     * not reach our card processor." The typed suffix was always held to the
     * rule (suffixError()); the order-number path skipped it.
     *
     * The longest label that fits beside the WHOLE number wins; only when not
     * even one letter fits is the number cut, from the left as above.
     */
    private static function lettered(string $digits, int $room): string
    {
        if ($room < 2) {
            return '';
        }

        foreach (['ORDER ', 'ORD ', 'NO ', 'O'] as $label) {
            if (strlen($label . $digits) <= $room) {
                return $label . $digits;
            }
        }

        return 'O' . substr($digits, -($room - 1));
    }

    /** The description Stripe shows beside the payment. */
    public static function description(string $template, string $reference, string $orderNumber, string $shop): string
    {
        $template = trim($template) !== '' && self::descriptionError($template) === null
            ? trim($template)
            : self::DEFAULT_DESCRIPTION;

        return mb_substr(strtr($template, [
            '{number}' => $reference,
            '{order_number}' => $orderNumber,
            '{shop}' => $shop,
        ]), 0, 500);
    }

    /** Printable ASCII without Stripe's reserved characters, one space between words. */
    public static function latin(string $value): string
    {
        $ascii = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) : false;
        $value = is_string($ascii) ? $ascii : preg_replace('/[^\x20-\x7E]/', '', $value);
        $value = str_replace(self::FORBIDDEN, '', (string) $value);
        $value = preg_replace('/[^\x20-\x7E]/', '', $value) ?? '';

        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }

    private static function characterError(string $label, string $value): ?string
    {
        if (preg_match('/[^\x20-\x7E]/', $value)) {
            return $label . ': Latin letters, digits and plain punctuation only.';
        }

        foreach (self::FORBIDDEN as $character) {
            if (str_contains($value, $character)) {
                return $label . ' cannot contain any of  < > \\ \' " *';
            }
        }

        if (! preg_match('/[A-Za-z]/', $value)) {
            return $label . ' must contain at least one letter.';
        }

        return null;
    }
}

<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The email rule used on the public "forgot password" form, instead of `email`.
 *
 * WHY NOT `email`
 * ---------------
 * CLAUDE.md records CRLF injection in the default email validation rule as one
 * of the three open Laravel 11 advisories on this install, unfixable short of a
 * 12.x upgrade. Laravel's `email` rule delegates to egulias/email-validator's
 * RFC validation, which accepts forms this store has no use for — quoted local
 * parts, comments, folding whitespace — and it is inside those forms that a
 * carriage return can survive validation. An address carrying CR or LF that
 * then reaches a mail header is header injection: an attacker-chosen `Bcc:` on
 * a message this shop's own SMTP credentials send, which is how a sending
 * domain gets blacklisted.
 *
 * The forgot-password form is the one place on this site where an unauthenticated
 * stranger hands us an address and we put an address into a message, so it is
 * the one that needed its own rule.
 *
 * TWO LAYERS, NOT ONE
 * -------------------
 * This rule is the outer layer. The inner layer is that nothing built from the
 * SUBMITTED string is ever handed to the mailer: the reset notification is sent
 * to `$customer->email` read back out of the database, which was written through
 * this same rule at registration. Even a bypass here cannot reach a header.
 * CustomerPasswordResetTest pins both halves.
 *
 * WHAT IS ACCEPTED
 * ----------------
 * The ordinary, unquoted `local@domain` shape and nothing else. Every address in
 * the imported customer set is that shape; so is every address a checkout form
 * has ever produced. Narrowing to it costs this shop nothing and removes the
 * entire category.
 */
class StorefrontEmail implements ValidationRule
{
    /** RFC 5321: 254 characters for the whole address, 64 for the local part. */
    private const MAX_LENGTH = 254;

    private const MAX_LOCAL = 64;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::passes($value)) {
            // One message for every failure mode. A validator that says
            // "that address contains a control character" is a validator that
            // teaches an attacker which byte got through.
            $fail('Enter a valid email address.');
        }
    }

    /**
     * Reusable outside the validator — the verification flow checks a stored
     * address with it before sending, and the tests assert against it directly.
     */
    public static function passes(string $value): bool
    {
        // 1. Anything outside printable ASCII is out. This is the advisory:
        //    \r and \n are the header-injection bytes, and \0 and the rest of
        //    the C0 range have no business in an address either. Checked FIRST,
        //    before any parsing, so no cleverer layer can normalise them away.
        if (preg_match('/[^\x21-\x7E]/', $value) === 1) {
            return false;
        }

        // 2. No whitespace of any kind. Rule 1 already excludes space, tab, CR
        //    and LF; this states the intent so a later edit to the character
        //    class cannot quietly re-admit folding whitespace.
        if (preg_match('/\s/', $value) === 1) {
            return false;
        }

        if ($value === '' || strlen($value) > self::MAX_LENGTH) {
            return false;
        }

        // 3. Exactly one @, and no quoting or commenting syntax anywhere. These
        //    are the constructs inside which a CR is permitted by the RFC, so
        //    they are refused outright rather than parsed.
        if (substr_count($value, '@') !== 1) {
            return false;
        }

        if (preg_match('/["(),:;<>\[\]\\\\]/', $value) === 1) {
            return false;
        }

        [$local, $domain] = explode('@', $value, 2);

        if ($local === '' || strlen($local) > self::MAX_LOCAL) {
            return false;
        }

        // 4. A domain with at least one dot, no leading/trailing/doubled dots
        //    and no leading/trailing hyphen in a label.
        if (! preg_match('/^(?:[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?\.)+[A-Za-z]{2,}$/', $domain)) {
            return false;
        }

        if (str_starts_with($local, '.') || str_ends_with($local, '.') || str_contains($local, '..')) {
            return false;
        }

        // 5. Last, and only last, PHP's own parser. filter_var is stricter than
        //    the RFC validator the framework reaches for, and by this point it
        //    is confirming a shape already proven safe rather than deciding it.
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }
}

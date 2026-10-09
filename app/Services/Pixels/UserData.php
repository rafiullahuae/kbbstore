<?php

declare(strict_types=1);

namespace App\Services\Pixels;

/**
 * Normalise, then SHA-256, the customer fields each platform asks for. (Lane MP)
 *
 * Every platform hashes the same way (lowercase hex SHA-256 of the normalised
 * UTF-8 string) and normalises almost the same way. The differences are
 * exactly the ones written below, each from the platform's own documentation
 * (docs/MARKETING-PIXELS-RESEARCH.md has the sources):
 *
 *   email   trim, lowercase — all three. Google Ads additionally drops the
 *           dots before @gmail.com / @googlemail.com.
 *   phone   Meta: digits only, country code included, no "+".
 *           Google and TikTok: E.164, i.e. "+" then the digits.
 *   names   Meta fn/ln: lowercase, trimmed, punctuation removed.
 *   city    Meta ct: lowercase, no spaces or punctuation.
 *   country Meta: two-letter ISO code, lowercase.
 *
 * The shop is in the UAE, so a local mobile number is completed with 971:
 * "050 123 4567" → 971501234567, "00971…" → 971…, "+971…" → 971…. A number
 * that cannot be made into 8–15 digits returns null and is not sent at all —
 * a wrong hash is worse than none, because it matches nobody and still counts
 * against Event Match Quality.
 *
 * Nothing here is reversible and nothing here stores anything.
 */
final class UserData
{
    public static function hash(string $normalised): string
    {
        return hash('sha256', $normalised);
    }

    public static function email(?string $email, bool $google = false): ?string
    {
        $email = mb_strtolower(trim((string) $email));

        if ($email === '' || ! str_contains($email, '@') || preg_match('/\s/', $email) === 1) {
            return null;
        }

        if ($google) {
            [$local, $domain] = explode('@', $email, 2);

            if (in_array($domain, ['gmail.com', 'googlemail.com'], true)) {
                $email = str_replace('.', '', $local) . '@' . $domain;
            }
        }

        return $email;
    }

    /** Digits with the country code, no "+": 971501234567. */
    public static function phoneDigits(?string $phone, string $countryCode = '971'): ?string
    {
        $raw = trim((string) $phone);

        if ($raw === '') {
            return null;
        }

        $plus = str_starts_with($raw, '+');
        $digits = (string) preg_replace('/\D+/', '', $raw);

        if ($digits === '') {
            return null;
        }

        if (! $plus) {
            if (str_starts_with($digits, '00')) {
                $digits = substr($digits, 2);
            } elseif (str_starts_with($digits, $countryCode)) {
                // already international
            } elseif (str_starts_with($digits, '0')) {
                $digits = $countryCode . substr($digits, 1);
            } elseif (strlen($digits) === 9) {
                $digits = $countryCode . $digits;
            }
        }

        $len = strlen($digits);

        return ($len >= 8 && $len <= 15) ? $digits : null;
    }

    /** E.164: +971501234567. */
    public static function phoneE164(?string $phone, string $countryCode = '971'): ?string
    {
        $digits = self::phoneDigits($phone, $countryCode);

        return $digits === null ? null : '+' . $digits;
    }

    /** Meta fn / ln. */
    public static function name(?string $name): ?string
    {
        $name = mb_strtolower(trim((string) $name));
        $name = (string) preg_replace('/[\p{P}\p{S}]+/u', '', $name);
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));

        return $name === '' ? null : $name;
    }

    /** Meta ct. */
    public static function city(?string $city): ?string
    {
        $city = mb_strtolower(trim((string) $city));
        $city = (string) preg_replace('/[\s\p{P}\p{S}]+/u', '', $city);

        return $city === '' ? null : $city;
    }

    public static function country(?string $code): ?string
    {
        $code = mb_strtolower(trim((string) $code));

        return preg_match('/^[a-z]{2}$/', $code) === 1 ? $code : null;
    }

    /** Hash of a normalised value, or null when there was nothing to hash. */
    public static function hashed(?string $normalised): ?string
    {
        return $normalised === null || $normalised === '' ? null : self::hash($normalised);
    }
}

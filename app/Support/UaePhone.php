<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A UAE mobile number: is this one, and the one canonical way to write it.
 *
 * The owner, 20 October 2026, on order #56272 (+20507272773, a UAE number typed
 * after Egypt's code): "we want one button / option to require the local UAE's
 * number from the customer, so the delivery customer can reach out to deliver
 * the order." The courier calls UAE numbers only.
 *
 * A UAE mobile is 5 then one of 0 2 4 5 6 8 then seven digits, written with the
 * country code (+971 / 00971 / 971) or the trunk 0 (05x) or bare (5x). Spaces,
 * dashes, dots and brackets are formatting. Anything else is not one: a
 * landline (04 …) cannot take the courier's WhatsApp, and a number from another
 * country is the very case this exists for.
 */
final class UaePhone
{
    private const MOBILE = '/^5[024568]\d{7}$/';

    /** The nine national digits (5XXXXXXXX), or null when it is not a UAE mobile. */
    public static function national(?string $phone): ?string
    {
        $digits = preg_replace('/[\s().\-\x{00A0}]+/u', '', trim((string) $phone)) ?? '';

        if ($digits === '' || strlen($digits) > 20 || ! preg_match('/^\+?\d+$/', $digits)) {
            return null;
        }

        $digits = ltrim($digits, '+');

        foreach (['00971', '971'] as $code) {
            if (str_starts_with($digits, $code)) {
                $digits = substr($digits, strlen($code));
                break;
            }
        }

        if (str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        return preg_match(self::MOBILE, $digits) === 1 ? $digits : null;
    }

    public static function isMobile(?string $phone): bool
    {
        return self::national($phone) !== null;
    }

    /** +971501234567, or null. What the order keeps. */
    public static function e164(?string $phone): ?string
    {
        $n = self::national($phone);

        return $n === null ? null : '+971' . $n;
    }

    /** +971 50 123 4567, or null. What a person reads. */
    public static function pretty(?string $phone): ?string
    {
        $n = self::national($phone);

        return $n === null ? null : '+971 ' . substr($n, 0, 2) . ' ' . substr($n, 2, 3) . ' ' . substr($n, 5);
    }
}
